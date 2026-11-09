<?php

namespace Plugin\Core_Backend;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * widgets of the dashboard (xcore_widget, \Core\Widget): order, size, refresh, the plugin gives the right (list) and the link
 */
#[Route('/api/widget', name: 'api.widget.')]
class WidgetController extends \Core\CrudController
{

	public string $table = 'xcore_widget';
	public string $title = 'Widgets';
	public string $object_label = 'widget';

	private const array SIZES = ['full' => 'full width', 'half' => 'half width'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT p.name FROM xcore_plugin p WHERE p.id = xcore_widget.xcore_plugin_id AND p.deleted = 'no')", 'plugin');
		$grid->qSelect("(SELECT p.icon FROM xcore_plugin p WHERE p.id = xcore_widget.xcore_plugin_id AND p.deleted = 'no')", 'plugin_icon');
		$grid->setSearch(['name', 'method'], 'Search name, method...');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('size', 'Size', 'select', self::SIZES);
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->setMobile('name', 'plugin');
		$grid->batchEdit(['size', 'active']);

		$grid->addColPosition();
		$grid->addCol('id', 'ID', true)->width(64);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('plugin', 'Plugin', false, 'text', ['icon_column' => 'plugin_icon'])->width(180);
		$grid->addCol('method', 'Method')->code();
		$grid->addColBadge('size', 'Size', true, ['full' => 'blue', 'half' => 'purple'])->labels(self::SIZES)->width(110);
		$grid->addCol('refresh', 'Refresh', false, 'chip', ['icon_column' => 'refresh_icon', 'style_column' => 'refresh_style', 'align' => 'center'])->width(80);
		$grid->addColBoolean('active', 'Active', true, true)->width(80);

		$grid->hookData(function(array $row):array{

			// timer icon and seconds without badge background, - when no auto refresh
			$row['refresh'] = ($row['autorefresh'] === 'yes') ? "{$row['autorefresh_seconds']} s" : '';
			$row['refresh_icon'] = 'timer';
			$row['refresh_style'] = 'background-color: transparent';

			// widget without plugin: shown to every user
			if((int)$row['xcore_plugin_id'] === 0)
			{

				$row['plugin'] = ts('All users');
				$row['plugin_icon'] = 'groups';

			}

			return $row;

		});

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addSelectSql('xcore_plugin_id', 'Plugin', 'xcore_plugin', 'name', false, "type != 'url'")->help('the widget right on this plugin shows the widget, its title links to the plugin, empty: shown to every user');
		$form->addText('method', 'Method', true)->maxLength(255)->placeholder('Plugin\Core\CronController::widgetCron')->help('public static method of a module returning the data of the widget');
		$form->addRadio('size', 'Size', self::SIZES, true)->defaultValue('full');
		$form->addBoolean('autorefresh', 'Auto refresh')->defaultValue('no');
		$form->addNumber('autorefresh_seconds', 'Every (seconds)', true, true)->defaultValue('60')->showIf('autorefresh', ['yes']);
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$method = (string)$v->inputGet('method', $record['method'] ?? '');
		if($method !== '' && !\Core\Widget::callable($method))
			$v->input('method')->addError(ts('`[FIELD]` must be a public static method widget... of a module controller'));

		if($v->inputGet('autorefresh', $record['autorefresh'] ?? 'no') === 'yes' && (int)$v->inputGet('autorefresh_seconds', $record['autorefresh_seconds'] ?? 0) < 5)
			$v->input('autorefresh_seconds')->addError(ts('`[FIELD]` must be at least 5'));

	}

	protected function onSaveAfterCreate(int $id, array $row):void
	{

		$this->offerWidgetRight((int)($row['xcore_plugin_id'] ?? 0));

	}

	protected function onSaveAfterEdit(int $id, array $row, array $changed):void
	{

		$this->offerWidgetRight((int)($row['xcore_plugin_id'] ?? 0));

	}

	/**
	 * adds the action widget to the plugin of the widget, so the module right can give it to the groups
	 *
	 * @param int $plugin_id 0 = widget shown to every user
	 *
	 * @return void
	 */
	private function offerWidgetRight(int $plugin_id):void
	{

		if(!$plugin_id)return;

		$actions = DB()->query("SELECT actions FROM xcore_plugin WHERE id = :id AND deleted = 'no' AND type != 'url'", ['id' => $plugin_id])->fetchOne();
		if($actions === false || $actions === null)return;

		$list = json_decode((string)$actions, true);
		if(!is_array($list) || in_array('widget', $list, true))return;

		$list[] = 'widget';
		DB()->query("UPDATE xcore_plugin SET actions = :actions, updated_at = NOW(), updated_by = :author WHERE id = :id", ['actions' => json_encode($list), 'author' => $this->author(), 'id' => $plugin_id]);

	}

}
