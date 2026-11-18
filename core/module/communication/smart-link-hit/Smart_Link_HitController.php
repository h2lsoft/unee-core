<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Core\Stats;
use Symfony\Component\Routing\Attribute\Route;

/**
 * hits of the smart links (xcore_smart_link_hit, written by \Core\SmartLink), hidden plugin, read only, csv export (right export)
 * opened from the hits of a smart link: smart-link-hit/?search[]=xcore_smart_link_id||<id>
 * a hit holds no ip and no user agent: country, device, browser and os are computed when it is counted
 */
#[Route('/api/smart-link-hit', name: 'api.smart_link_hit.')]
class Smart_Link_HitController extends \Core\CrudController
{

	public string $table = 'xcore_smart_link_hit';
	public string $title = 'Smart link hits';
	public string $object_label = 'hit';

	protected function grid(DataGrid $grid):void
	{

		$smart_links = DB()->query("SELECT id, name FROM xcore_smart_link WHERE deleted = 'no' ORDER BY name LIMIT 500")->fetchAll(\PDO::FETCH_KEY_PAIR);

		$grid->qSelect("(SELECT t.name FROM xcore_smart_link t WHERE t.id = xcore_smart_link_hit.xcore_smart_link_id)", 'smart_link');

		$grid->setSearch(['source', 'referrer', 'ref'], 'Search source, referrer, ref...');
		$grid->addFilter('xcore_smart_link_id', 'Smart link', 'select', $smart_links);
		$grid->addFilter('date', 'Date', 'date', [], 'DATE(`date`)');
		$grid->addFilter('source', 'Source');
		$grid->addFilter('referrer', 'Referrer');
		$grid->addFilter('country', 'Country');
		$grid->addFilter('device', 'Device', 'select', Stats::DEVICES);
		$grid->addFilter('browser', 'Browser');
		$grid->addFilter('os', 'OS');
		$grid->addFilter('amount', 'Amount', 'number');
		$grid->addFilter('currency', 'Currency');
		$grid->addFilter('ref', 'Ref');
		$grid->setOrderByFirst('date', 'desc');
		$grid->setMobile('smart_link', 'date');
		$grid->setBatchMode(false);
		$grid->duplicate(false);
		$grid->allowExport();

		$grid->addColDatetime('date', 'Date', true)->width(150);
		$grid->addCol('smart_link', 'Smart link')->filterOnClick('xcore_smart_link_id');
		$grid->addCol('country', 'Country', true)->filterOnClick('country')->width(90);
		$grid->addColBadge('device', 'Device', true, ['mobile' => 'blue', 'desktop' => 'grey', 'tablet' => 'orange'])->labels(Stats::DEVICES)->width(100);
		$grid->addCol('browser', 'Browser', true)->filterOnClick('browser')->width(140);
		$grid->addCol('os', 'OS', true)->filterOnClick('os')->width(110);
		$grid->addCol('source', 'Source', true)->filterOnClick('source')->width(140);
		$grid->addCol('referrer', 'Referrer', true)->filterOnClick('referrer');
		$grid->addCol('amount_text', 'Amount', false, 'text', ['align' => 'right'])->width(130);

		$grid->hookData(function(array $row):array{

			// amount with its iso code: currencies are never added together nor converted
			$row['amount_text'] = ($row['amount'] === null) ? '' : number_format((float)$row['amount'], 2, '.', ' ').' '.$row['currency'];
			return $row;

		});

	}

	// read only: no form (no add or edit right declared on the plugin)
	protected function form(Form $form, array $record):void
	{
	}

}
