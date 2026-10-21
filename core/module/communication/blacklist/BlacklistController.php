<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * blacklisted emails (xcore_blacklist): never sent a newsletter
 * an email (exact) or a pattern with * (*@hotmail.fr, contact@*, *@*.ru), the patterns are checked at the sending
 */
#[Route('/api/blacklist', name: 'api.blacklist.')]
class BlacklistController extends \Core\CrudController
{

	public string $table = 'xcore_blacklist';
	public string $title = 'Blacklist';
	public string $object_label = 'email';

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['email'], 'Search email...');
		$grid->addFilter('email', 'Email');
		$grid->addFilter('date', 'Date', 'date');
		$grid->setMobile('email', 'date');
		$grid->setOrderByFirst('id', 'desc');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('email', 'Email', true);
		$grid->addColDatetime('date', 'Date', true)->width(160);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('email', 'Email', true)->maxLength(255)->unique()->set('lowercase', true)->placeholder('name@example.com, *@example.com')->help('an email, or a pattern with *: *@hotmail.fr, contact@*');
		$form->addDatetime('date', 'Date', true)->defaultValue(date('Y-m-d H:i:s'));

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$email = mb_strtolower(trim((string)$v->inputGet('email', $record['email'] ?? '')));
		$v->input('email');
		if($email === '')return;

		$valid = (str_contains($email, '*')) ? (bool)preg_match('/^[a-z0-9._%+*-]+@[a-z0-9.*-]+$/', $email) && $email !== '*@*' : (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
		if(!$valid)$v->addError(ts('`[FIELD]` must be an email or a pattern like *@example.com'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(isset($row['email']))$row['email'] = mb_strtolower($row['email']);
		return $row;

	}

}
