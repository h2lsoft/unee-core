<?php

namespace Plugin\Core_Backend;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * dictionaries of values by category (xcore_list), ex: statuses of a record, each value is shown as a mui chip: class = color and variant, style = css added
 */
#[Route('/api/list', name: 'api.list.')]
class ListController extends \Core\CrudController
{

	public string $table = 'xcore_list';
	public string $title = 'Lists';
	public string $object_label = 'value';
	public array $ucfirst = [];

	/**
	 * mui chip: color and variant, ex: success outlined
	 */
	public const array COLORS = ['default', 'primary', 'secondary', 'error', 'warning', 'info', 'success'];
	public const array VARIANTS = ['filled', 'outlined'];

	/**
	 * inline css: property: value; pairs, no braces, tags or url()
	 */
	public const string STYLE_PATTERN = '/^\s*[a-z-]+\s*:\s*[^;{}<>()]+(;\s*[a-z-]+\s*:\s*[^;{}<>()]+)*;?\s*$/i';

	/**
	 * classes of the select, value => label
	 *
	 * @return array
	 */
	public static function classes():array
	{

		$classes = [];
		foreach(self::VARIANTS as $variant)
			foreach(self::COLORS as $color)
				$classes["{$color} {$variant}"] = "{$color} {$variant}";

		return $classes;

	}

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['category', 'name', 'label'], 'Search name...');
		$grid->addFilterChips('category');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('label', 'Label');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('name', 'category');
		$grid->batchEdit(['category', 'class', 'active']);

		$grid->addColPosition();
		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('category', 'Category', true)->width(1); // 1px: the column shrinks to its content
		$grid->addCol('name', 'Name', true);
		$grid->addColChip('label', 'Label', 'class', 'style', 'icon')->width(180);
		$grid->addColBoolean('active', 'Active', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_list WHERE deleted = 'no' AND category != '' ORDER BY category")->fetchAllOne();

		$form->addSelect('category', 'Category', array_combine($categories, $categories), true)->autocomplete(true)->maxLength(255)->set('translate', false)->protect();
		$form->addText('name', 'Name', true)->maxLength(255)->help('value stored in the records')->protect();
		$form->addText('label', 'Label', true)->maxLength(255)->help('text shown, translated');
		$form->addIcon('icon', 'Icon');
		$form->addSelect('class', 'Class', self::classes(), true)->defaultValue('default filled')->set('translate', false)->help('color and variant of the mui chip');
		$form->addText('style', 'Style')->maxLength(255)->placeholder('background:#1f2335;color:#ffffff')->help('css added to the chip')->protect();
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	// a name once per category
	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$style = trim((string)$v->inputGet('style', $record['style'] ?? ''));
		if($style !== '' && !preg_match(self::STYLE_PATTERN, $style))$v->input('style')->addError(ts('`[FIELD]` must hold css like color: #ffffff; font-weight: bold'));

		$category = trim((string)$v->inputGet('category', $record['category'] ?? ''));
		$name = trim((string)$v->inputGet('name', $record['name'] ?? ''));
		if($category === '' || $name === '')return;

		$found = DB()->query("SELECT id FROM xcore_list WHERE category = :category AND name = :name AND deleted = 'no' AND id != :id LIMIT 1", ['category' => $category, 'name' => $name, 'id' => (int)($record['id'] ?? 0)])->fetchOne();
		if($found)$v->input('name')->addError(ts('`[FIELD]` is already used in this category'));

	}

}
