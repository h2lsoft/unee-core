<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * announcements (xcore_announce, \Core\Announce): html message shown once in a lightbox to the logged users of the backend, the frontend or both
 * state live / scheduled / ended computed from the dates, groups empty = every logged user
 * reads (I understood) in the hidden plugin announce-read, kept when the logs are purged; a read announcement is never shown again
 */
#[Route('/api/announce', name: 'api.announce.')]
class AnnounceController extends \Core\CrudController
{

	public string $table = 'xcore_announce';
	public string $title = 'Announce';
	public string $object_label = 'announcement';

	private const array STATES = ['live' => 'Live', 'scheduled' => 'Scheduled', 'ended' => 'Ended'];
	private const array STATE_COLORS = ['live' => 'green', 'scheduled' => 'blue', 'ended' => 'grey'];
	private const array TARGETS = ['backend' => 'Backend', 'frontend' => 'Frontend', 'both' => 'Both'];

	protected function grid(DataGrid $grid):void
	{

		$groups = $this->groups();

		$grid->qSelect(\Core\Announce::STATE_SQL, 'state');
		$grid->qSelect("(SELECT COUNT(*) FROM xcore_announce_read r WHERE r.xcore_announce_id = xcore_announce.id AND r.deleted = 'no')", 'reads');
		$grid->setSearch(['title', 'content'], 'Search title, content...');
		$grid->addFilterChips('state', self::STATES, true, true, \Core\Announce::STATE_SQL)->chipColors(self::STATE_COLORS);
		$grid->addFilter('title', 'Title');
		$grid->addFilter('target', 'Target', 'select', self::TARGETS);
		$grid->addFilter('xcore_group_ids', 'Groups', 'multiselect', $groups);
		$grid->addFilter('start_at', 'Start', 'date', [], 'DATE(start_at)');
		$grid->addFilter('end_at', 'End', 'date', [], 'DATE(end_at)');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setOrderByFirst('start_at', 'desc');
		$grid->setMobile('title', 'start_at');

		$grid->hookData(function(array $row) use ($groups):array{

			$ids = json_decode((string)($row['xcore_group_ids'] ?? ''), true);
			$row['_groups'] = (is_array($ids) && $ids) ? implode(', ', array_filter(array_map(fn($id) => $groups[$id] ?? '', $ids))) : ts('Everyone');
			$row['_excerpt'] = mb_strimwidth(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', (string)$row['content'])), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 120, '...');
			return $row;

		});

		$grid->addCol('id', 'ID', true)->width(60);
		$grid->addCol('title', 'Title', true)->subText('_excerpt');
		$grid->addColBadge('state', 'State', false, self::STATE_COLORS)->labels(self::STATES)->width(100);
		$grid->addColBadge('target', 'Target', true)->labels(self::TARGETS)->width(100);
		$grid->addCol('_groups', 'Groups')->width(160);
		$grid->addColDatetime('start_at', 'Start', true)->width(150);
		$grid->addColDatetime('end_at', 'End', true)->width(150);
		$grid->addColCount('reads', 'Reads', 'visibility')->width(90);
		if(\Model\User::hasRight('announce-read', 'list'))$grid->link('announce-read/?search[]=xcore_announce_id||{id}');
		$grid->addColBoolean('active', 'Active', true, true)->width(80);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('title', 'Title', true)->maxLength(255);
		$form->addHtml('content', 'Content', true);
		$form->addRadio('target', 'Target', self::TARGETS, true)->defaultValue('backend');
		$form->addSelectMultiple('xcore_group_ids', 'Groups', $this->groups())->set('translate', false)->help('Empty: every logged user');
		$form->addDatetime('start_at', 'Start', true)->defaultValue(date('Y-m-d H:i:s'))->protect();
		$form->addDatetime('end_at', 'End')->set('inline', true)->help('Empty: no end')->protect();
		$form->addText('link_label', 'Learn more: label')->maxLength(100)->placeholder(ts('Learn more'));
		$form->addText('link_url', 'Learn more: url')->maxLength(255)->placeholder('https://... or /path/')->help('Empty: no Learn more button')->protect();
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		// a link of the button only: no javascript: or data: url
		$url = (string)$v->inputGet('link_url', $record['link_url'] ?? '');
		$v->input('link_url');
		if($url !== '' && !preg_match('#^(https?://\S+|/\S*)$#i', $url))$v->addError(ts('`[FIELD]` must be an url (https://...) or a path (/...)'));

		$start = (string)$v->inputGet('start_at', $record['start_at'] ?? '');
		$end = (string)$v->inputGet('end_at', $record['end_at'] ?? '');
		$v->input('end_at');
		if($start !== '' && $end !== '' && strtotime($end) <= strtotime($start))$v->addError(ts('`[FIELD]` must be after the start'));

	}

	/**
	 * active groups that can be targeted, system group excluded
	 *
	 * @return array id => name
	 */
	private function groups():array
	{

		return DB()->query("SELECT id, name FROM xcore_group WHERE deleted = 'no' AND active = 'yes' AND id <> :system ORDER BY priority, name", ['system' => \Model\User::GROUP_SYSTEM])->fetchAll(\PDO::FETCH_KEY_PAIR);

	}

}
