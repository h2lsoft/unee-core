<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * groups of users (xcore_group), their rights are set in the module right (xcore_group_right)
 * fixed groups: 1 super admin (every right), 2 system (cron and console)
 */
#[Route('/api/group', name: 'api.group.')]
class GroupController extends \Core\CrudController
{

	public string $table = 'xcore_group';
	public string $title = 'Groups';
	public string $object_label = 'group';

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['name', 'description'], 'Search name...');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('priority', 'Priority', 'number');
		$grid->addFilter('access_backend', 'Backend access', 'boolean');
		$grid->addFilter('access_frontend', 'Frontend access', 'boolean');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setOrderByFirst('priority', 'asc');
		$grid->setMobile('name', 'description');
		$grid->batchEdit(['access_backend', 'access_frontend', 'active']);
		$grid->qSelect("(SELECT COUNT(*) FROM xcore_user u WHERE u.xcore_group_id = xcore_group.id AND u.deleted = 'no')", 'users');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('name', 'Name', true)->subText('description');
		$grid->addColNumber('priority', 'Priority', true)->width(100);
		$grid->addColCount('users', 'Users', 'person')->width(100);
		if(\Model\User::hasRight('user', 'list'))$grid->link('user/?search[]=xcore_group_id||{id}');
		$grid->addColBoolean('access_backend', 'Backend access', true, false, true)->width(140);
		$grid->addColBoolean('access_frontend', 'Frontend access', true, false, true)->width(140);
		$grid->addColBoolean('active', 'Active', true, true)->width(90);

		if(\Model\User::hasRight('right', 'list'))
			$grid->rowAction('rights', 'Rights', 'admin_panel_settings', 'list', 'right/?group={id}');

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(255)->unique();
		$form->addText('description', 'Description')->maxLength(255);
		$form->addNumber('priority', 'Priority', true, true)->defaultValue(10)->help('lowest priorities are the most important');
		$form->addBoolean('access_backend', 'Backend access');
		$form->addBoolean('access_frontend', 'Frontend access');
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	protected function rulesOnUpdate(\Core\Validator $v, array $record):void
	{

		// the fixed groups keep their access: super admin always enters the backend, system never logs in
		if((int)$record['id'] === \Model\User::GROUP_SUPERADMIN && ($v->inputGet('access_backend', $record['access_backend']) !== 'yes' || $v->inputGet('active', $record['active']) !== 'yes'))
			$v->addError(ts('the super admin group keeps its backend access'));

		if((int)$record['id'] === (int)\Core\Session::get('auth.group_id') && ($v->inputGet('access_backend', $record['access_backend']) !== 'yes' || $v->inputGet('active', $record['active']) !== 'yes'))
			$v->addError(ts('you cannot remove the backend access of your own group'));

	}

	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		$id = (int)$record['id'];

		if(in_array($id, [\Model\User::GROUP_SUPERADMIN, \Model\User::GROUP_SYSTEM], true))
		{

			$v->addError(ts('this group is used by the application, it cannot be deleted'));
			return;

		}

		if($id === (int)\Core\Session::get('auth.group_id'))
		{

			$v->addError(ts('you cannot delete your own group'));
			return;

		}

		$count = (int)DB()->query("SELECT COUNT(*) FROM xcore_user WHERE xcore_group_id = :id AND deleted = 'no'", ['id' => $id])->fetchOne();
		if($count)$v->addError(ts('this group holds [COUNT] users, move or delete them first', ['COUNT' => $count]));

	}

	// rights of the group are removed with it
	protected function onDeleteAfter(array $record):void
	{

		DB()->query("UPDATE xcore_group_right SET deleted = 'yes', deleted_at = NOW(), deleted_by = :author WHERE xcore_group_id = :id AND deleted = 'no'", ['id' => (int)$record['id'], 'author' => $this->author()]);

	}

}
