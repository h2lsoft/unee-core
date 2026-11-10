<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * blacklist (xcore_blacklist): emails never sent a newsletter, mobiles never sent a sms campaign or a marketing sms
 * an email (exact) or a pattern with * anywhere (*@gmail.com, test*, contact@*, *@*.ru), the patterns are checked at the sending (\Core\Newsletter::isBlacklisted)
 * a mobile in international format (+33612345678), added by the delivery reports of the sms provider on a STOP
 * a row holds an email, a mobile or both (null when empty: unique keys)
 */
#[Route('/api/blacklist', name: 'api.blacklist.')]
class BlacklistController extends \Core\CrudController
{

	public string $table = 'xcore_blacklist';
	public string $title = 'Blacklist';
	public string $object_label = 'entry';

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['email', 'mobile'], 'Search email, mobile...');
		$grid->addFilter('email', 'Email');
		$grid->addFilter('mobile', 'Mobile');
		$grid->addFilter('date', 'Date', 'date');
		$grid->setMobile('email', 'date');
		$grid->setOrderByFirst('id', 'desc');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('email', 'Email', true);
		$grid->addCol('mobile', 'Mobile', true)->width(180);
		$grid->addColDatetime('date', 'Date', true)->width(160);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('email', 'Email')->maxLength(255)->unique()->set('lowercase', true)->placeholder('name@example.com, *@gmail.com, test*')->help('an email, or a pattern with * (any characters): *@gmail.com = the whole domain, test* = every email starting with test')->protect();
		$form->addText('mobile', 'Mobile')->maxLength(24)->placeholder('+33 6 12 34 56 78')->help('never sent a sms campaign or a marketing sms (STOP)')->protect();
		$form->addDatetime('date', 'Date', true)->defaultValue(date('Y-m-d H:i:s'));

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$email = mb_strtolower(trim((string)$v->inputGet('email', $record['email'] ?? '')));
		$mobile = trim((string)$v->inputGet('mobile', $record['mobile'] ?? ''));

		$v->input('email');
		if($email === '' && $mobile === '')$v->addError(ts('type an email or a mobile'));
		if($email !== '')
		{

			// pattern: one @ at most, 2 letters or digits at least (never *, *@* or *.* that would block every email)
			$valid = (str_contains($email, '*')) ? (bool)preg_match('/^[a-z0-9._%+*-]+(@[a-z0-9.*-]*)?$/', $email) && preg_match_all('/[a-z0-9]/', $email) >= 2 : (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
			if(!$valid)$v->addError(ts('`[FIELD]` must be an email or a pattern with *, ex: *@gmail.com, test*'));

		}

		$v->input('mobile');
		if($mobile === '')return;

		$phone = \Core\Sms::normalize($mobile);
		if($phone === '')$v->addError(ts('`[FIELD]` must be a mobile number, ex: +33612345678'));
		elseif(DB()->query("SELECT id FROM xcore_blacklist WHERE deleted = 'no' AND mobile = :mobile AND id != :id LIMIT 1", ['mobile' => $phone, 'id' => (int)($record['id'] ?? 0)])->fetchOne())$v->addError(ts('`[FIELD]` is already in the blacklist'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		// empty = null: unique keys
		if(isset($row['email']))$row['email'] = ($row['email'] !== '') ? mb_strtolower($row['email']) : null;
		if(isset($row['mobile']))$row['mobile'] = ($row['mobile'] !== '') ? \Core\Sms::normalize($row['mobile']) : null;
		return $row;

	}

}
