<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * zones of the website pages (xcore_page_zone), ex: main menu, footer: a page belongs to one zone
 */
#[Route('/api/page-zone', name: 'api.page_zone.')]
class Page_ZoneController extends \Core\CrudController
{

	public string $table = 'xcore_page_zone';
	public string $title = 'Page zones';
	public string $object_label = 'zone';

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT COUNT(*) FROM xcore_page p WHERE p.xcore_page_zone_id = xcore_page_zone.id AND p.deleted = 'no')", 'pages');
		$grid->setSearch(['name', 'website'], 'Search name...');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('website', 'Website');
		$grid->setMobile('name', 'website');

		$grid->addColPosition();
		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('website', 'Website', true);
		$grid->addColCount('pages', 'Pages', 'description')->width(100);
		if(\Model\User::hasRight('page', 'list'))$grid->link('page/?search[]=xcore_page_zone_id||{id}');

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addText('website', 'Website')->maxLength(255)->help('domain or code of the website when the instance holds several, ex: www.example.com');

	}

	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		$count = (int)DB()->query("SELECT COUNT(*) FROM xcore_page WHERE xcore_page_zone_id = :id AND deleted = 'no'", ['id' => (int)$record['id']])->fetchOne();
		if($count)$v->addError(ts('this zone holds [COUNT] pages, move or delete them first', ['COUNT' => $count]));

	}

}
