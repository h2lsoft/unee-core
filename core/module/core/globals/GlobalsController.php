<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;

/**
 * globals (xcore_globals): settings of the instance, read by App()->globals, category = first part of the name
 * values of globals named like a password are never sent to the browser
 */
class GlobalsController extends \Core\CrudController
{

	public string $table = 'xcore_globals';
	public string $title = 'Globals';
	public string $object_label = 'global';

	public const array TYPES = ['text', 'int', 'float', 'boolean', 'json-array', 'json-object', 'list', 'url'];

	protected function grid(DataGrid $grid):void
	{

		$grid->setOrderByFirst('name');
		$grid->setSearch(['name', 'description', 'value'], 'Search name...');
		$grid->addFilterChips('category');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('type', 'Type', 'select', array_combine(self::TYPES, self::TYPES));
		$grid->addFilter('value', 'Value');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('name', 'value');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('category', 'Category', true)->width(130);
		$grid->addCol('name', 'Name', true);
		$grid->addColBadge('type', 'Type', true, ['text' => 'orange', 'int' => 'red', 'float' => 'red', 'boolean' => 'yellow', 'url' => 'green', 'json-array' => 'purple', 'json-object' => 'purple', 'list' => 'cyan'])->width(120);
		$grid->addCol('value', 'Value');

		$grid->hookData(function(array $row):array {

			if(self::isSecret($row['name']) && $row['value'] !== '')$row['value'] = '********';
			$row['value'] = mb_strimwidth((string)$row['value'], 0, 120, '...');

			return $row;

		});

	}

	protected function form(Form $form, array $record):void
	{

		$categories = (array)DB()->query("SELECT DISTINCT category FROM xcore_globals WHERE deleted = 'no' AND category != '' ORDER BY category")->fetchAllOne();

		$form->addSelect('category', 'Category', array_combine($categories, $categories), true)->autocomplete(true)->maxLength(255)->width(6)->help('existing category or a new one');
		$form->addText('name', 'Name', true)->maxLength(255)->unique()->help('ex: backend.logo');
		$form->addText('description', 'Description')->maxLength(255);
		$form->addSelect('type', 'Type', array_combine(self::TYPES, self::TYPES), true)->autocomplete()->defaultValue('text')->width(6);

		if($record && self::isSecret($record['name']))
			$form->addPassword('value', 'Value')->help('leave empty to keep the current value');
		else
			$form->addTextarea('value', 'Value', false, 3);

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$v->input('name')->regex('/^[a-z0-9_\-]+(\.[a-z0-9_\-]+)*$/i', '`[FIELD]` must contain letters, digits, dots, dashes and underscores only');
		$v->input('category')->regex('/^[a-z0-9_\-]+$/i', '`[FIELD]` must contain letters, digits, dashes and underscores only');

		$v->input('value');
		match((string)$v->inputGet('type'))
		{
			'int' => $v->integer(false),
			'float' => $v->float(false),
			'boolean' => $v->in(['true', 'false', '1', '0']),
			'json-array' => $v->jsonArray(),
			'json-object' => $v->jsonObject(),
			// a path of the site (/upload/logo.png) or an absolute url
			'url' => (str_starts_with((string)$v->inputGet('value'), '/')) ? $v->regex('#^/[^\s]*$#', '`[FIELD]` must be a valid path') : $v->url(0),
			default => null,
		};

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if($row['category'] === '')$row['category'] = explode('.', $row['name'])[0];
		return $row;

	}

	/**
	 * value hidden in the backend
	 *
	 * @param string $name
	 *
	 * @return bool
	 */
	public static function isSecret(string $name):bool
	{

		return (str_contains($name, 'password') || str_contains($name, 'secret') || str_contains($name, 'api_key'));

	}

}
