<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * mailing lists (xcore_mailinglist), their subscribers are in xcore_mailinglist_subscriber (module mailinglist-subscriber)
 */
#[Route('/api/mailinglist', name: 'api.mailinglist.')]
class MailinglistController extends \Core\CrudController
{

	public string $table = 'xcore_mailinglist';
	public string $title = 'Mailing lists';
	public string $object_label = 'list';

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT COUNT(*) FROM xcore_mailinglist_subscriber s WHERE s.xcore_mailinglist_id = xcore_mailinglist.id AND s.deleted = 'no' AND s.unsubscribe_date IS NULL)", 'subscribers');
		$grid->setSearch(['name', 'description'], 'Search name...');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('name', 'description');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('description', 'Description');
		$grid->addColCount('subscribers', 'Subscribers', 'group')->width(120);
		if(\Model\User::hasRight('mailinglist-subscriber', 'list'))$grid->link('mailinglist-subscriber/?search[]=xcore_mailinglist_id||{id}');

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(255)->unique();
		$form->addText('description', 'Description')->maxLength(255);

	}

	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		$count = (int)DB()->query("SELECT COUNT(*) FROM xcore_mailinglist_subscriber WHERE xcore_mailinglist_id = :id AND deleted = 'no'", ['id' => (int)$record['id']])->fetchOne();
		if($count)$v->addError(ts('this list holds [COUNT] subscribers, delete them first', ['COUNT' => $count]));

	}

}
