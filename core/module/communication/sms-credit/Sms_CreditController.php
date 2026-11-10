<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * purchases of sms credits (xcore_sms_credit), prepaid mode of \Core\Sms: balance = purchases - credits used
 * hidden plugin opened from the SMS module (button Credits)
 * a purchase is never edited: deleted and added again, both logged
 */
#[Route('/api/sms-credit', name: 'api.sms_credit.')]
class Sms_CreditController extends \Core\CrudController
{

	public string $table = 'xcore_sms_credit';
	public string $title = 'SMS credits';
	public string $object_label = 'purchase';
	public array $ucfirst = ['comment'];

	protected function grid(DataGrid $grid):void
	{

		if(\Model\User::hasRight('sms', 'list'))$grid->gridAction('sms', 'SMS', 'arrow_back', 'list', url: 'sms/');
		$grid->setSearch(['reference', 'comment'], 'Search reference, comment...');
		$grid->addFilter('date', 'Date', 'date');
		$grid->setMobile('reference', 'date');
		$grid->setOrderByFirst('date', 'desc');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addColDate('date', 'Date', true)->width(120);
		$grid->addColNumber('credits', 'Credits', true)->width(110);
		$grid->addColNumber('amount', 'Amount excl. tax', true)->width(140);
		$grid->addCol('currency', 'Currency')->width(90);
		$grid->addCol('reference', 'Reference', true);
		$grid->addCol('comment', 'Comment');
		$grid->addCol('created_by', 'Added by', true)->width(160);

	}

	protected function form(Form $form, array $record):void
	{

		$c = \Core\Sms::credits();
		$help = ($c['mode'] === 'prepaid') ? ts('current balance: [BALANCE] credits', ['BALANCE' => number_format($c['remaining'], 0, ',', ' ')]) : ts('monthly mode: the purchases are not used (SMS settings)');

		$form->addDate('date', 'Date', true)->defaultValue(date('Y-m-d'));
		$form->addNumber('credits', 'Credits', true, true)->help($help)->protect();
		$form->addNumber('amount', 'Amount excl. tax');
		$form->addText('currency', 'Currency', true)->maxLength(3)->defaultValue('EUR');
		$form->addText('reference', 'Reference')->maxLength(255)->placeholder('invoice of the provider');
		$form->addText('comment', 'Comment')->maxLength(255);

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$v->input('credits');
		if((int)$v->inputGet('credits', 0) <= 0)$v->addError(ts('`[FIELD]` must be greater than 0'));

		$v->input('currency');
		if(!preg_match('/^[A-Za-z]{3}$/', trim((string)$v->inputGet('currency', ''))))$v->addError(ts('`[FIELD]` must be a currency code, ex: EUR'));

	}

	protected function rulesOnUpdate(\Core\Validator $v, array $record):void
	{

		$v->addError(ts('a purchase is never edited: delete it and add it again'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(isset($row['currency']))$row['currency'] = strtoupper($row['currency']);
		return $row;

	}

}
