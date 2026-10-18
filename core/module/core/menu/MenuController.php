<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * categories of the backend menu (xcore_menu), ordered by drag and drop, their plugins are in xcore_plugin
 */
#[Route('/api/menu', name: 'api.menu.')]
class MenuController extends \Core\CrudController
{

	public string $table = 'xcore_menu';
	public string $title = 'Menu';
	public string $object_label = 'category';

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['name'], 'Search name...');
		$grid->addFilter('name', 'Name');
		$grid->setMobile('name');
		$grid->setBatchMode(false);
		$grid->qSelect("(SELECT COUNT(*) FROM xcore_plugin p WHERE p.xcore_menu_id = xcore_menu.id AND p.deleted = 'no')", 'plugins');

		$grid->addColPosition();
		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addColIcon('icon', 'Icon', 'color')->width(70);
		$grid->addColColor('color', 'Color')->width(110);
		$grid->addCol('name', 'Name', true);
		$grid->addColCount('plugins', 'Plugins', 'extension')->width(110);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addIcon('icon', 'Icon', true)->help('name of a Material Symbols icon, ex: settings');
		$form->addColor('color', 'Color', true);

	}

	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		$count = (int)DB()->query("SELECT COUNT(*) FROM xcore_plugin WHERE xcore_menu_id = :id AND deleted = 'no'", ['id' => (int)$record['id']])->fetchOne();
		if($count)$v->addError(ts('this category holds [COUNT] plugins, move or delete them first', ['COUNT' => $count]));

	}

}
