<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * subscribers of the mailing lists (xcore_mailinglist_subscriber): one email once by list, unsubscribed = unsubscribe date set
 */
#[Route('/api/mailinglist-subscriber', name: 'api.mailinglist_subscriber.')]
class Mailinglist_SubscriberController extends \Core\CrudController
{

	public string $table = 'xcore_mailinglist_subscriber';
	public string $title = 'Subscribers';
	public string $object_label = 'subscriber';
	public array $ucfirst = ['firstname', 'lastname'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT l.name FROM xcore_mailinglist l WHERE l.id = xcore_mailinglist_subscriber.xcore_mailinglist_id)", 'list');
		$grid->qSelect("IF(unsubscribe_date IS NULL, 'yes', 'no')", 'subscribed');
		$grid->setSearch(['email', 'firstname', 'lastname'], 'Search email, name...');
		$grid->addFilter('xcore_mailinglist_id', 'List', 'select', DB()->query("SELECT id, name FROM xcore_mailinglist WHERE deleted = 'no' ORDER BY name")->fetchAll(\PDO::FETCH_KEY_PAIR));
		$grid->addFilter('email', 'Email');
		$grid->addFilter('language', 'Language', 'select', \Plugin\Website\PageController::languages());
		$grid->addFilter('date', 'Date', 'date');
		$grid->addFilter('unsubscribe_date', 'Unsubscribed on', 'date');
		$grid->setMobile('email', 'list');
		$grid->batchEdit(['xcore_mailinglist_id', 'language']);
		$grid->setOrderByFirst('id', 'desc');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('list', 'List')->width(160);
		$grid->addCol('email', 'Email', true);
		$grid->addCol('firstname', 'Firstname', true)->width(140);
		$grid->addCol('lastname', 'Lastname', true)->width(140);
		$grid->addCol('language', 'Lang.', true)->width(70);
		$grid->addColDatetime('date', 'Date', true)->width(140);
		$grid->addColBoolean('subscribed', 'Subscribed')->width(110);

	}

	protected function form(Form $form, array $record):void
	{

		$languages = \Plugin\Website\PageController::languages();

		$form->addSelectSql('xcore_mailinglist_id', 'List', 'xcore_mailinglist', 'name', true);
		$form->addEmail('email', 'Email', true)->maxLength(255);
		$form->addText('firstname', 'Firstname')->maxLength(255);
		$form->addText('lastname', 'Lastname')->maxLength(255);
		$form->addSelect('language', 'Language', $languages, true)->defaultValue((string)array_key_first($languages))->set('translate', false);
		$form->addDatetime('date', 'Subscription date', true)->defaultValue(date('Y-m-d H:i:s'));
		$form->addDatetime('unsubscribe_date', 'Unsubscribed on')->help('set = no more mail from this list');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$email = mb_strtolower(trim((string)$v->inputGet('email', $record['email'] ?? '')));
		$list = (int)$v->inputGet('xcore_mailinglist_id', $record['xcore_mailinglist_id'] ?? 0);
		$v->input('email');
		if($email !== '' && DB()->query("SELECT id FROM xcore_mailinglist_subscriber WHERE deleted = 'no' AND xcore_mailinglist_id = :list AND email = :email AND id != :id LIMIT 1", ['list' => $list, 'email' => $email, 'id' => (int)($record['id'] ?? 0)])->fetchOne())
			$v->addError(ts('`[FIELD]` is already subscribed to this list'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(isset($row['email']))$row['email'] = mb_strtolower($row['email']);
		return $row;

	}

}
