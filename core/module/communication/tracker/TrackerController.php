<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Core\JsonResponse;
use Core\Tracker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * link trackers (xcore_tracker, \Core\Tracker): a code counted by the public url /go/<code>/ (GoController) or by \Core\Tracker::hit()
 * type redirect = counted then sent to the target url, action = counted without leaving the page (tel:, mailto:, form sent)
 * hits in xcore_tracker_hit (hidden module tracker-hit), opened from the count of a tracker: tracker-hit/?search[]=xcore_tracker_id||<id>
 * statistics: GET /api/tracker/{id}/stats/?period=today|7|30|12|custom&from=&to= (list right)
 * globals: stats.exclude.robots, stats.exclude.ips (shared by the statistics), tracker.exclude.ips, tracker.retention.months
 */
#[Route('/api/tracker', name: 'api.tracker.')]
class TrackerController extends \Core\CrudController
{

	public string $table = 'xcore_tracker';
	public string $title = 'Trackers';
	public string $object_label = 'tracker';

	// the code is a key: no uppercase initial
	public array $ucfirst = ['name', 'description'];

	protected function grid(DataGrid $grid):void
	{

		// the globals exist before the first hit: the button Configuration shows them
		Tracker::register();

		$hits = fn(string $select):string => "(SELECT {$select} FROM xcore_tracker_hit h WHERE h.xcore_tracker_id = xcore_tracker.id)";
		$grid->qSelect($hits('COUNT(*)'), 'hits');
		$grid->qSelect($hits('COUNT(DISTINCT h.visitor)'), 'uniques');
		$grid->qSelect($hits('MAX(h.`date`)'), 'last_hit');

		$grid->setSearch(['name', 'code', 'description', 'url'], 'Search name, code...');
		$grid->addFilterChips('type', Tracker::TYPES)->chipColors(Tracker::TYPE_COLORS);
		$grid->addFilter('name', 'Name');
		$grid->addFilter('code', 'Code');
		$grid->addFilter('url', 'Target url');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('name', 'link');

		$see_hits = \Model\User::hasRight('tracker-hit', 'list');

		$grid->addCol('id', 'ID', true)->width(64);
		$grid->addCol('name', 'Name', true)->subText('sub');
		$grid->addColBadge('type', 'Type', true, Tracker::TYPE_COLORS)->labels(Tracker::TYPES)->width(100);
		$grid->addCol('link', 'Link')->code()->width(240);
		$grid->addColCount('hits', 'Hits', 'ads_click')->width(110);
		if($see_hits)$grid->link('tracker-hit/?search[]=xcore_tracker_id||{id}');
		$grid->addColNumber('uniques', 'Unique', true)->width(100);
		$grid->addColDatetime('last_hit', 'Last hit', true)->width(150);
		$grid->addColBoolean('active', 'Active', true, true)->width(80);

		if($see_hits)$grid->rowAction('hits', 'Hits', 'ads_click', 'list', 'tracker-hit/?search[]=xcore_tracker_id||{id}');

		$grid->hookData(function(array $row):array{

			$row['link'] = "/go/{$row['code']}/";
			$row['sub'] = ($row['type'] === 'redirect') ? (string)$row['url'] : (string)$row['description'];
			return $row;

		});

	}

	protected function form(Form $form, array $record):void
	{

		$form->addRadio('type', 'Type', Tracker::TYPES, true)->defaultValue('redirect')->help('Redirect: counted then sent to the target url. Action: counted without leaving the page (tel:, mailto:, form sent)')->protect();
		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addText('code', 'Code', true)->maxLength(64)->unique()->help('letters, digits, - and _ (locked after the first hit)')->protect();
		$form->addText('url', 'Target url', true)->maxLength(2000)->placeholder('https://')->help('the visitor lands here after the hit is counted')->showIf('type', ['redirect'])->protect();
		$form->addText('description', 'Description')->maxLength(255);
		$form->addBoolean('active', 'Active')->defaultValue('yes')->help('inactive: a redirect still goes to its target, nothing is counted')->protect();

		// link and snippets to copy, once the tracker exists (virtual fields, never written)
		if($record)
		{

			$form->addText('_link', 'Link')->readonly()->help('newsletter button, qr code, social post, sms. Add ?s=<source> to say where the link was placed')->showIf('type', ['redirect']);
			$form->addText('_html', 'HTML')->readonly()->help('button or link of a page: the hit is sent on the click, the visitor stays on the page')->showIf('type', ['action']);
			$form->addText('_php', 'PHP')->readonly()->help('action validated on the server, ex: form really sent. With an amount: amount, currency and ref (counted once)')->showIf('type', ['action']);

		}

	}

	// link and snippets of the tracker (virtual fields)
	protected function getRecord(array $values, array $record):array
	{

		if($record)
		{

			$code = (string)$record['code'];
			$values['_link'] = Tracker::url($code);
			$values['_html'] = '<a href="tel:+33123456789" data-go="'.$code.'">Call us</a>';
			$values['_php'] = "\\Core\\Tracker::hit('{$code}');";

		}

		return $values;

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$v->input('code')->alphaNumeric('-_', false, true, true, ts('`[FIELD]` must hold letters, digits, - or _ only'));

		// http(s) only: no javascript: nor data: target
		$type = (string)$v->inputGet('type', $record['type'] ?? 'redirect');
		$url = trim((string)$v->inputGet('url', $record['url'] ?? ''));
		if($type === 'redirect' && $url !== '' && !preg_match('#^https?://[^\s/]+#i', $url))$v->input('url')->addError(ts('`[FIELD]` must start with http:// or https://'));

	}

	protected function rulesOnUpdate(\Core\Validator $v, array $record):void
	{

		// links already placed keep working: the code is locked once counted
		$code = (string)$v->inputGet('code', $record['code']);
		if($code !== (string)$record['code'] && $this->hitCount((int)$record['id']) > 0)$v->input('code')->addError(ts('`[FIELD]` is locked: this tracker already has hits'));

	}

	/**
	 * statistics of a tracker on a period: totals with their trend, series, breakdowns, amounts by currency, last hits
	 * query: period = today, 7, 30 (default), 12 or custom with from and to (Y-m-d)
	 *
	 * @param Request $request
	 * @param int $id
	 *
	 * @return JsonResponse
	 */
	#[Route('/{id}/stats/', name: 'stats', methods: ['GET'])]
	public function apiStats(Request $request, int $id):JsonResponse
	{

		if($denied = $this->deny('list'))return $denied;
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');

		[$from, $to] = Tracker::period((string)$request->query->get('period', '30'), (string)$request->query->get('from', ''), (string)$request->query->get('to', ''));

		$tracker = ['id' => (int)$record['id'], 'name' => (string)$record['name'], 'code' => (string)$record['code'], 'type' => (string)$record['type'], 'link' => Tracker::url((string)$record['code'])];

		return new JsonResponse(['tracker' => $tracker, 'retention_months' => Tracker::retentionMonths()] + Tracker::stats($id, $from, $to));

	}

	/**
	 * hits of a tracker, all periods
	 *
	 * @param int $id
	 *
	 * @return int
	 */
	private function hitCount(int $id):int
	{

		return (int)DB()->query("SELECT COUNT(*) FROM xcore_tracker_hit WHERE xcore_tracker_id = :id", ['id' => $id])->fetchOne();

	}

}
