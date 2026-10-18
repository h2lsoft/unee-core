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

		$form->addText('name', 'Name', true)->maxLength(255)->unique()->help('the category is the first part, ex: backend.logo');
		$form->addSelect('type', 'Type', array_combine(self::TYPES, self::TYPES), true)->defaultValue('text')->width(6);
		$form->addText('description', 'Description')->maxLength(255);

		if($record && self::isSecret($record['name']))
			$form->addPassword('value', 'Value')->help('leave empty to keep the current value');
		else
			$form->addTextarea('value', 'Value', false, 3);

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$v->input('name')->regex('/^[a-z0-9_\-]+(\.[a-z0-9_\-]+)*$/i', 'letters, digits, dots, dashes and underscores only');

		$v->input('value');
		match((string)$v->inputGet('type'))
		{
			'int' => $v->integer(false, 'invalid number'),
			'float' => $v->float(false, 'invalid number'),
			'boolean' => $v->in(['true', 'false', '1', '0'], 'true, false, 1 or 0'),
			'json-array' => $v->jsonArray('invalid json array'),
			'json-object' => $v->jsonObject('invalid json object'),
			default => null,
		};

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		$row['category'] = explode('.', $row['name'])[0];
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
