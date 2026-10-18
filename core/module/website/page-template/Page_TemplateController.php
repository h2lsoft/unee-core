<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * templates of the website pages (xcore_page_template): html of a layout, a page picks one by its name
 */
#[Route('/api/page-template', name: 'api.page_template.')]
class Page_TemplateController extends \Core\CrudController
{

	public string $table = 'xcore_page_template';
	public string $title = 'Page templates';
	public string $object_label = 'template';
	public array $ucfirst = ['collection', 'description'];

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['name', 'collection', 'description'], 'Search name...');
		$grid->addFilterChips('collection');
		$grid->addFilter('collection', 'Collection');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->setMobile('name', 'collection');
		$grid->batchEdit(['collection', 'active']);

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('collection', 'Collection', true)->width(160);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('description', 'Description');
		$grid->addColBoolean('active', 'Active', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$collections = DB()->query("SELECT DISTINCT collection FROM xcore_page_template WHERE deleted = 'no' AND collection != '' ORDER BY collection")->fetchAllOne();

		$form->addSelect('collection', 'Collection', array_combine($collections, $collections))->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addText('name', 'Name', true)->maxLength(255)->unique()->help('used by the pages and the code, ex: home');
		$form->addText('description', 'Description')->maxLength(255);
		$form->addText('image_preview', 'Preview image')->maxLength(255)->placeholder('/upload/templates/home.png');
		$form->addCode('content', 'Content', false, 'html')->set('snippets', PatternController::snippets());
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		$count = (int)DB()->query("SELECT COUNT(*) FROM xcore_page WHERE template = :name AND deleted = 'no'", ['name' => (string)$record['name']])->fetchOne();
		if($count)$v->addError(ts('[COUNT] pages use this template', ['COUNT' => $count]));

	}

}
