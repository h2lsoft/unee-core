<?php

namespace Plugin\Core_Backend;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * saved filters of the grids (xcore_user_filter): owner, plugin, shared with a group or private, conditions in json
 * the grids save and read them with /api/backend/filters/
 */
#[Route('/api/user-filter', name: 'api.user_filter.')]
class User_FilterController extends \Core\CrudController
{

	public string $table = 'xcore_user_filter';
	public string $title = 'Saved filters';
	public string $object_label = 'filter';

	// plugins with a grid: route => category > name
	private static function plugins():array
	{

		return DB()->query("SELECT p.route_prefix_name, CONCAT(COALESCE(m.name, ''), ' > ', p.name) FROM xcore_plugin p LEFT JOIN xcore_menu m ON m.id = p.xcore_menu_id WHERE p.deleted = 'no' AND p.type != 'url' AND p.route_prefix_name != '' ORDER BY m.position, p.position")->fetchAll(\PDO::FETCH_KEY_PAIR);

	}

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT TRIM(CONCAT(u.firstname, ' ', u.lastname)) FROM xcore_user u WHERE u.id = xcore_user_filter.xcore_user_id)", 'owner');
		$grid->qSelect("(SELECT g.name FROM xcore_group g WHERE g.id = xcore_user_filter.xcore_group_id)", 'shared_with');
		$grid->qSelect("(SELECT p.name FROM xcore_plugin p WHERE p.route_prefix_name = xcore_user_filter.plugin AND p.deleted = 'no' LIMIT 1)", 'plugin_name');
		$grid->setSearch(['name', 'plugin'], 'Search name...');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('plugin', 'Plugin', 'select', self::plugins());
		$grid->addFilter('xcore_user_id', 'Owner', 'select', DB()->query("SELECT id, TRIM(CONCAT(firstname, ' ', lastname)) FROM xcore_user WHERE deleted = 'no' ORDER BY firstname, lastname")->fetchAll(\PDO::FETCH_KEY_PAIR));
		$grid->addFilter('xcore_group_id', 'Shared with', 'select', DB()->query("SELECT id, name FROM xcore_group WHERE deleted = 'no' ORDER BY name")->fetchAll(\PDO::FETCH_KEY_PAIR));
		$grid->setMobile('name', 'plugin_name');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('plugin_name', 'Plugin')->width(180);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('owner', 'Owner')->width(170);
		$grid->addCol('shared_with', 'Shared with')->width(170);

		$grid->hookData(function(array $row):array{

			if(($row['shared_with'] ?? null) === null)$row['shared_with'] = '-';
			return $row;

		});

	}

	protected function form(Form $form, array $record):void
	{

		$form->addSelectSql('xcore_user_id', 'Owner', 'xcore_user', 'login', true, 'id > 1');
		$form->addSelect('plugin', 'Plugin', self::plugins(), true)->autocomplete()->set('translate', false);
		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addSelectSql('xcore_group_id', 'Shared with', 'xcore_group', 'name')->help('empty = private to its owner');
		$form->addCode('conditions', 'Conditions', true, 'json')->help('{"match": "all|any", "conditions": [{"f": "field", "o": "operator", "v": "value"}]}');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$data = json_decode((string)$v->inputGet('conditions', $record['conditions'] ?? ''), true);
		$v->input('conditions');
		$valid = is_array($data) && is_array($data['conditions'] ?? null) && $data['conditions'];
		foreach(($valid) ? $data['conditions'] : [] as $c)
			if(!is_array($c) || !isset($c['f'], $c['o']) || !array_key_exists('v', $c))$valid = false;
		if(!$valid)$v->addError(ts('`[FIELD]` must be a json object with a list of conditions {"f", "o", "v"}'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		// no group = private (null in the table)
		if(array_key_exists('xcore_group_id', $row) && !(int)$row['xcore_group_id'])$row['xcore_group_id'] = null;

		$data = json_decode((string)($row['conditions'] ?? ''), true);
		if(is_array($data))$row['conditions'] = json_encode(['match' => (($data['match'] ?? '') === 'any') ? 'any' : 'all', 'conditions' => array_values($data['conditions'])]);

		return $row;

	}

	// pretty json in the editor
	protected function getRecord(array $values, array $record):array
	{

		$data = json_decode((string)($values['conditions'] ?? ''), true);
		if(is_array($data))$values['conditions'] = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return $values;

	}

}
