<?php

namespace Plugin\Marketing;

use Component\DataGrid;
use Component\Form;
use Model\Campaign;
use Symfony\Component\Routing\Attribute\Route;

/**
 * expenses and revenues of the campaigns (xcore_campaign_entry, amounts incl. tax), hidden plugin
 * opened from the totals of a campaign: campaign-entry/?search[]=xcore_campaign_id||<id>&search[]=type||expense
 * record_type + record_id = record of origin (ex: donation 125), once by type, \Model\Campaign::addEntry() for the other modules
 */
#[Route('/api/campaign-entry', name: 'api.campaign_entry.')]
class Campaign_EntryController extends \Core\CrudController
{

	public string $table = 'xcore_campaign_entry';
	public string $title = 'Campaign entries';
	public string $object_label = 'entry';

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT c.name FROM xcore_campaign c WHERE c.id = xcore_campaign_entry.xcore_campaign_id)", 'campaign');
		$grid->qSelect("(SELECT c.currency FROM xcore_campaign c WHERE c.id = xcore_campaign_entry.xcore_campaign_id)", 'currency');
		$grid->setSearch(['label', 'record_type', 'record_id', 'note'], 'Search label, origin...');
		$grid->addFilterChips('type', Campaign::TYPES)->chipColors(Campaign::TYPE_COLORS);
		$grid->addFilter('xcore_campaign_id', 'Campaign', 'select', DB()->query("SELECT id, name FROM xcore_campaign WHERE deleted = 'no' ORDER BY name")->fetchAll(\PDO::FETCH_KEY_PAIR));
		$grid->addFilter('date', 'Date', 'date');
		$grid->addFilter('label', 'Label');
		$grid->addFilter('amount', 'Amount', 'number');
		$grid->addFilter('record_type', 'Origin');
		$grid->addFilter('record_id', 'Record ID');
		$grid->setMobile('label', 'campaign');
		$grid->setOrderByFirst('date', 'desc');
		$grid->batchEdit(['xcore_campaign_id']);

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addColDate('date', 'Date', true)->width(110);
		$grid->addCol('campaign', 'Campaign')->filterOnClick('xcore_campaign_id')->width(160);
		$grid->addColBadge('type', 'Type', true, Campaign::TYPE_COLORS)->labels(Campaign::TYPES)->width(100);
		$grid->addCol('label', 'Label', true);
		$grid->addCol('record_type', 'Origin', true)->filterOnClick('record_type')->width(130);
		$grid->addCol('record_id', 'Record ID', true)->width(110);
		$grid->addCol('amount', 'Amount incl. tax', true, 'text', ['align' => 'right'])->width(140);

		$grid->hookData(function(array $row):array{

			$row['amount'] = Campaign::money((float)$row['amount'], (string)$row['currency']);
			return $row;

		});

	}

	protected function form(Form $form, array $record):void
	{

		$origins = DB()->query("SELECT DISTINCT record_type FROM xcore_campaign_entry WHERE deleted = 'no' AND record_type != '' ORDER BY record_type")->fetchAll(\PDO::FETCH_COLUMN);

		$form->addSelectSql('xcore_campaign_id', 'Campaign', 'xcore_campaign', 'name', true)->autocomplete();
		$form->addRadio('type', 'Type', Campaign::TYPES, true)->defaultValue('expense');
		$form->addDate('date', 'Date', true)->defaultValue(now('', false));
		$form->addText('label', 'Label', true)->maxLength(255);
		$form->addNumber('amount', 'Amount incl. tax', true)->set('size', 150)->set('align', 'center')->set('mask', 'thousands');
		$form->addSelect('record_type', 'Origin', array_combine($origins, $origins))->autocomplete(true)->maxLength(64)->set('translate', false)->help('ex: donation, empty when the entry is typed by hand');
		$form->addText('record_id', 'Record ID')->maxLength(64)->set('inline', true);
		$form->addTextarea('note', 'Note')->maxLength(5000);

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		// a record of origin is given whole, and once by type
		$type = (string)$v->inputGet('type', $record['type'] ?? '');
		$record_type = trim((string)$v->inputGet('record_type', $record['record_type'] ?? ''));
		$record_id = trim((string)$v->inputGet('record_id', $record['record_id'] ?? ''));

		if($record_type !== '' && $record_id === '')$v->input('record_id')->addError(ts('`[FIELD]` is required with an origin'));
		if($record_type === '' && $record_id !== '')$v->input('record_type')->addError(ts('`[FIELD]` is required with a record id'));
		if($record_type !== '' && $record_id !== '' && Campaign::entryOf($type, $record_type, $record_id, (int)($record['id'] ?? 0)))
			$v->input('record_id')->addError(ts('this record is already counted in an entry of this type'));

	}

}
