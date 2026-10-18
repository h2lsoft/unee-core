<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * modules of the backend menu (xcore_plugin), one tab per menu category, ordered by drag and drop inside a category
 * type normal: route_prefix_name = folder of the module and actions = its rights, type url: link of the menu, type core: not listed (every backend user)
 */
#[Route('/api/plugin', name: 'api.plugin.')]
class PluginController extends \Core\CrudController
{

	public string $table = 'xcore_plugin';
	public string $title = 'Plugins';
	public string $object_label = 'plugin';

	private const array TYPES = ['normal' => 'normal', 'url' => 'url'];

	protected function grid(DataGrid $grid):void
	{

		$menus = DB()->query("SELECT id, name FROM xcore_menu WHERE deleted = 'no' ORDER BY position, name")->fetchAll();
		$options = [];
		foreach($menus as $menu)
			$options[$menu['id']] = $menu['name'];

		$grid->qWhere("type != 'core'");
		$grid->setSearch(['name', 'route_prefix_name', 'url'], 'Search name...');
		$grid->addFilterChips('xcore_menu_id', $options, false);
		$grid->addFilter('xcore_menu_id', 'Menu', 'select', $options);
		$grid->addFilter('name', 'Name');
		$grid->addFilter('type', 'Type', 'select', self::TYPES);
		$grid->addFilter('route_prefix_name', 'Route');
		$grid->addFilter('visible', 'Visible', 'boolean');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('name', 'target');
		$grid->batchEdit(['xcore_menu_id', 'versioning', 'visible', 'active']);

		$grid->addColPosition();
		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addColIcon('icon', 'Icon')->width(70);
		$grid->addCol('name', 'Name', true);
		$grid->addColBadge('type', 'Type', true, ['normal' => 'grey', 'url' => 'blue'])->width(90);
		$grid->addCol('target', 'Route / url');
		$grid->addColBoolean('versioning', 'Versioning', false, true)->width(110);
		$grid->addColBoolean('visible', 'Visible', false, true)->width(90);
		$grid->addColBoolean('active', 'Active', false, true)->width(90);

		$grid->hookData(function(array $row):array{

			$row['target'] = ($row['type'] === 'url') ? (string)$row['url'] : $row['route_prefix_name'];
			return $row;

		});

	}

	protected function form(Form $form, array $record):void
	{

		$form->addSelectSql('xcore_menu_id', 'Menu', 'xcore_menu', 'name', true);
		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addIcon('icon', 'Icon', true)->help('name of a Material Symbols icon, ex: settings');
		$form->addSelect('type', 'Type', self::TYPES, true)->defaultValue('normal');
		$form->addText('route_prefix_name', 'Route', true)->maxLength(255)->showIf('type', 'normal')->help('folder of the module, ex: mail-template');
		$form->addTags('actions', 'Actions', true, ['list', 'add', 'edit', 'delete'])->showIf('type', 'normal')->defaultValue(['list', 'add', 'edit', 'delete'])->help('rights of the module, given to the groups in the module right');
		$form->addText('url', 'Url', true)->maxLength(255)->showIf('type', 'url')->placeholder('https://');
		$form->addBoolean('versioning', 'Versioning')->help('keeps a copy of each version of the records');
		$form->addBoolean('visible', 'Visible')->defaultValue('yes')->help('shown in the menu');
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		if($v->inputGet('type', $record['type'] ?? '') === 'normal')
		{

			$route = (string)$v->inputGet('route_prefix_name', $record['route_prefix_name'] ?? '');
			$v->input('route_prefix_name');
			if($route !== '' && !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $route))
				$v->addError(ts('`[FIELD]` must hold lowercase letters, digits and dashes, ex: mail-template'));
			elseif($route !== '' && DB()->query("SELECT id FROM xcore_plugin WHERE route_prefix_name = :route AND deleted = 'no' AND id != :id LIMIT 1", ['route' => $route, 'id' => (int)($record['id'] ?? 0)])->fetchOne())
				$v->addError(ts('`[FIELD]` is already used'));

		}

		if($v->inputGet('type', $record['type'] ?? '') === 'url')
		{

			$url = (string)$v->inputGet('url', $record['url'] ?? '');
			$v->input('url');
			if($url !== '' && !preg_match('#^(https?://[^\s]+|/[^\s]*)$#i', $url))
				$v->addError(ts('`[FIELD]` must be an url (https://...) or a path (/...)'));

		}

		// the module plugin cannot be switched off: nobody could reach it again
		if(($record['route_prefix_name'] ?? '') === 'plugin' && ($v->inputGet('active', $record['active']) !== 'yes' || $v->inputGet('type', $record['type']) !== 'normal' || $v->inputGet('route_prefix_name', 'plugin') !== 'plugin'))
			$v->addError(ts('the plugin module stays active, it manages the others'));

	}

	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		if($record['route_prefix_name'] === 'plugin')$v->addError(ts('the plugin module stays active, it manages the others'));

	}

	// rights given on the plugin are removed with it
	protected function onDeleteAfter(array $record):void
	{

		DB()->query("UPDATE xcore_group_right SET deleted = 'yes', deleted_at = NOW(), deleted_by = :author WHERE xcore_plugin_id = :id AND deleted = 'no'", ['id' => (int)$record['id'], 'author' => $this->author()]);

	}

}
