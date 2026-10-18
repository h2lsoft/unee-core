<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * dictionaries of values by category (xcore_list), ex: statuses of a record, each value has a label and a badge color
 */
#[Route('/api/list', name: 'api.list.')]
class ListController extends \Core\CrudController
{

	public string $table = 'xcore_list';
	public string $title = 'Lists';
	public string $object_label = 'value';

	/**
	 * badge colors of the backend (DataGrid::addColBadge)
	 */
	public const array COLORS = ['grey' => 'grey', 'orange' => 'orange', 'red' => 'red', 'green' => 'green', 'blue' => 'blue', 'purple' => 'purple', 'yellow' => 'yellow', 'cyan' => 'cyan'];

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['category', 'name', 'label'], 'Search name...');
		$grid->addFilterChips('category');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('label', 'Label');
		$grid->addFilter('color', 'Color', 'select', self::COLORS);
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('name', 'category');
		$grid->batchEdit(['category', 'color', 'active']);

		$grid->addColPosition();
		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('category', 'Category', true);
		$grid->addCol('name', 'Name', true);
		$grid->addColIcon('icon', 'Icon')->width(70);
		$grid->addColBadge('label', 'Label', false, [], 'color')->width(160);
		$grid->addColBoolean('active', 'Active', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_list WHERE deleted = 'no' AND category != '' ORDER BY category")->fetchAllOne();

		$form->addSelect('category', 'Category', array_combine($categories, $categories), true)->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addText('name', 'Name', true)->maxLength(255)->help('value stored in the records');
		$form->addText('label', 'Label', true)->maxLength(255)->help('text shown, translated');
		$form->addIcon('icon', 'Icon');
		$form->addSelect('color', 'Color', self::COLORS)->defaultValue('grey');
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	// a name once per category
	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$category = trim((string)$v->inputGet('category', $record['category'] ?? ''));
		$name = trim((string)$v->inputGet('name', $record['name'] ?? ''));
		if($category === '' || $name === '')return;

		$found = DB()->query("SELECT id FROM xcore_list WHERE category = :category AND name = :name AND deleted = 'no' AND id != :id LIMIT 1", ['category' => $category, 'name' => $name, 'id' => (int)($record['id'] ?? 0)])->fetchOne();
		if($found)$v->input('name')->addError(ts('`[FIELD]` is already used in this category'));

	}

}
