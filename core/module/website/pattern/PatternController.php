<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * html patterns (xcore_pattern): pieces of html inserted from the code editor of the pages, templates and blocks ("Insert pattern")
 */
#[Route('/api/pattern', name: 'api.pattern.')]
class PatternController extends \Core\CrudController
{

	public string $table = 'xcore_pattern';
	public string $title = 'Patterns';
	public string $object_label = 'pattern';

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['name', 'category', 'description', 'pattern'], 'Search name...');
		$grid->addFilterChips('category');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->setMobile('name', 'category');
		$grid->batchEdit(['category', 'active']);

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('category', 'Category', true)->width(160);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('pattern', 'Code', true)->width(180);
		$grid->addCol('description', 'Description');
		$grid->addColBoolean('active', 'Active', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_pattern WHERE deleted = 'no' AND category != '' ORDER BY category")->fetchAllOne();

		$form->addSelect('category', 'Category', array_combine($categories, $categories), true)->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addText('pattern', 'Code')->maxLength(255)->help('unique key of the pattern for the code, ex: hero-2-columns');
		$form->addText('description', 'Description')->maxLength(255);
		$form->addCode('content', 'Content', true, 'html');
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	/**
	 * active patterns for the code editor ("Insert pattern"), grouped by category
	 *
	 * @return array [{label, group, content}]
	 */
	public static function snippets():array
	{

		$recs = DB()->query("SELECT category, name, content FROM xcore_pattern WHERE deleted = 'no' AND active = 'yes' ORDER BY category, name LIMIT 500")->fetchAll();
		return array_map(fn($r) => ['label' => $r['name'], 'group' => $r['category'], 'content' => $r['content']], $recs);

	}

}
