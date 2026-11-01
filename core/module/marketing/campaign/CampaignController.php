<?php

namespace Plugin\Marketing;

use Component\DataGrid;
use Component\Form;
use Model\Campaign;
use Symfony\Component\Routing\Attribute\Route;

/**
 * marketing campaigns (xcore_campaign): name = alphanumeric code, budget, tags
 * expenses and revenues in xcore_campaign_entry (module campaign-entry), opened from their totals: campaign-entry/?search[]=xcore_campaign_id||<id>&search[]=type||expense
 */
#[Route('/api/campaign', name: 'api.campaign.')]
class CampaignController extends \Core\CrudController
{

	public string $table = 'xcore_campaign';
	public string $title = 'Campaigns';
	public string $object_label = 'campaign';

	// the name is a code: no uppercase initial
	public array $ucfirst = ['description'];

	protected function grid(DataGrid $grid):void
	{

		$sum = fn(string $type) => "(SELECT COALESCE(SUM(e.amount), 0) FROM xcore_campaign_entry e WHERE e.xcore_campaign_id = xcore_campaign.id AND e.type = '{$type}' AND e.deleted = 'no')";
		$grid->qSelect($sum('expense'), 'expenses');
		$grid->qSelect($sum('revenue'), 'revenues');
		$grid->qSelect("{$sum('revenue')} - {$sum('expense')}", 'balance');
		$grid->qSelect('LEFT(description, 120)', 'excerpt');
		$grid->setSearch(['name', 'description'], 'Search name...');
		$grid->addFilter('name', 'Name');
		$grid->addFilterTags();
		$grid->addFilter('date_start', 'Start date', 'date');
		$grid->addFilter('date_end', 'End date', 'date');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('name', 'excerpt');

		$entries = \Model\User::hasRight('campaign-entry', 'list');

		$grid->addCol('id', 'ID', true)->width(64);
		$grid->addCol('name', 'Name', true)->subText('excerpt');
		$grid->addColTags()->width(180);
		$grid->addColDate('date_start', 'Start', true)->width(110);
		$grid->addColDate('date_end', 'End', true)->width(110);
		$grid->addCol('budget', 'Budget', true, 'text', ['align' => 'right'])->width(120);
		$grid->addCol('expenses', 'Expenses', true, 'count', ['align' => 'right', 'icon' => 'trending_down'])->width(140);
		if($entries)$grid->link('campaign-entry/?search[]=xcore_campaign_id||{id}&search[]=type||expense');
		$grid->addCol('revenues', 'Revenues', true, 'count', ['align' => 'right', 'icon' => 'trending_up'])->width(140);
		if($entries)$grid->link('campaign-entry/?search[]=xcore_campaign_id||{id}&search[]=type||revenue');
		$grid->addColBadge('balance', 'Balance', true, [], 'balance_color')->width(130);

		$grid->hookData(function(array $row):array{

			$row['balance_color'] = ((float)$row['balance'] < 0) ? 'red' : (((float)$row['balance'] > 0) ? 'green' : 'grey');
			foreach(['budget', 'expenses', 'revenues', 'balance'] as $col)
				$row[$col] = Campaign::money((float)$row[$col], (string)$row['currency']);

			return $row;

		});

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(64)->unique()->help('letters, digits, - and _, ex: spring-2026');
		$form->addText('description', 'Description')->maxLength(255);
		$form->addDate('date_start', 'Start date', true);
		$form->addDate('date_end', 'End date', true)->set('inline', true);
		$form->addNumber('budget', 'Budget', true)->defaultValue('0')->set('size', 150)->set('align', 'center')->set('mask', 'thousands');
		$currency = (string)\Core\Globals::get('backend.currency.default', 'EUR', true, 'text', 'currency selected by default for the amounts (iso 4217 code: EUR, USD, GBP, CHF, CAD, JPY)');
		$currencies = \Plugin\Core_Backend\ProjectController::CURRENCIES;
		$form->addSelect('currency', 'Currency', $currencies, true)->defaultValue(isset($currencies[$currency]) ? $currency : 'EUR')->set('translate', false)->set('inline', true)->set('size', 80)->set('hide_label', true);
		$form->addTagManager($this->table);

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$v->input('name')->alphaNumeric('-_', false, true, true, ts('`[FIELD]` must hold letters, digits, - or _ only'));

		$start = (string)$v->inputGet('date_start', $record['date_start'] ?? '');
		$end = (string)$v->inputGet('date_end', $record['date_end'] ?? '');
		$v->input('date_end');
		if($start !== '' && $end !== '' && $end < $start)$v->addError(ts('`[FIELD]` must be after the start'));

	}

	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		$count = (int)DB()->query("SELECT COUNT(*) FROM xcore_campaign_entry WHERE xcore_campaign_id = :id AND deleted = 'no'", ['id' => (int)$record['id']])->fetchOne();
		if($count)$v->addError(ts('this campaign holds [COUNT] expenses or revenues, delete them first', ['COUNT' => $count]));

	}

}
