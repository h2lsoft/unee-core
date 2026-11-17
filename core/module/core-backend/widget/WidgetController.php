<?php

namespace Plugin\Core_Backend;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * widgets of the dashboard (xcore_widget, \Core\Widget): order, size, refresh, the plugin gives the right (list) and the link
 * type method (data of a module method) or url (page shown in an iframe, https forced)
 */
#[Route('/api/widget', name: 'api.widget.')]
class WidgetController extends \Core\CrudController
{

	public string $table = 'xcore_widget';
	public string $title = 'Widgets';
	public string $object_label = 'widget';

	// dashboard of 4 columns
	public const array SIZES = ['quarter' => '1 column', 'half' => '2 columns', 'three_quarters' => '3 columns', 'full' => '4 columns'];
	private const array TYPES = ['method' => 'Method', 'url' => 'URL'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT p.name FROM xcore_plugin p WHERE p.id = xcore_widget.xcore_plugin_id AND p.deleted = 'no')", 'plugin');
		$grid->qSelect("(SELECT p.icon FROM xcore_plugin p WHERE p.id = xcore_widget.xcore_plugin_id AND p.deleted = 'no')", 'plugin_icon');
		$grid->setSearch(['name', 'method', 'url'], 'Search name, method, url...');
		$grid->addFilter('type', 'Type', 'select', self::TYPES);
		$grid->addFilter('name', 'Name');
		$grid->addFilter('size', 'Size', 'select', self::SIZES);
		$grid->addFilter('access_all', 'Access all', 'boolean');
		$grid->addFilter('multiple', 'Allow multiple', 'boolean');
		$grid->addFilter('configurable', 'Configurable', 'boolean');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->setMobile('name', 'plugin');
		$grid->batchEdit(['size', 'access_all', 'multiple', 'active']);

		$grid->addColPosition();
		$grid->addCol('id', 'ID', true)->width(64);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('plugin', 'Plugin', false, 'text', ['icon_column' => 'plugin_icon'])->width(180);
		$grid->addColBadge('type', 'Type', true, ['method' => 'grey', 'url' => 'blue'])->labels(self::TYPES)->width(90);
		$grid->addCol('method', 'Method / URL')->code();
		$grid->addColBadge('size', 'Size', true, ['quarter' => 'grey', 'half' => 'green', 'three_quarters' => 'orange', 'full' => 'blue'])->labels(self::SIZES)->width(110);
		$grid->addCol('refresh', 'Refresh', false, 'chip', ['icon_column' => 'refresh_icon', 'style_column' => 'refresh_style', 'align' => 'center'])->width(80);
		$grid->addColBoolean('access_all', 'Access all', true, false)->width(90);
		$grid->addColBoolean('multiple', 'Multiple', true, false)->width(80);
		$grid->addColBoolean('configurable', 'Configurable', true, false)->width(100);
		$grid->addColBoolean('active', 'Active', true, true)->width(80);

		$grid->hookData(function(array $row):array{

			// timer icon and seconds without badge background, - when no auto refresh
			$row['refresh'] = ($row['autorefresh'] === 'yes') ? "{$row['autorefresh_seconds']} s" : '';
			$row['refresh_icon'] = 'timer';
			$row['refresh_style'] = 'background-color: transparent';

			// url widget: its url in the method column
			if($row['type'] === 'url')$row['method'] = $row['url'];

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
		$form->addIcon('icon', 'Icon')->help('empty: icon of the plugin');
		$form->addSelectSql('xcore_plugin_id', 'Plugin', 'xcore_plugin', 'name', false, "type != 'url'")->help('the widget right on this plugin shows the widget, its title links to the plugin, empty: shown to every user')->protect();
		$form->addBoolean('access_all', 'Access all')->defaultValue('no')->help('shown to every user, even without the widget right on its plugin (ex: weather)');
		$form->addBoolean('multiple', 'Allow multiple')->defaultValue('no')->help('a user can place it several times on his dashboard (ex: weather of two cities)');
		$form->addBoolean('configurable', 'Configurable')->defaultValue('no')->help('each user sets the fields below on each widget he placed, the method gets them: widgetName(array $config=[])');
		$form->addCode('config_fields', 'Fields', true, 'json')->showIf('configurable', ['yes'])->help('[{"name": "city", "label": "City", "type": "text", "default": "Paris"}], types: text, number, select (+ "options": [...]), boolean (default yes or no)');
		$form->addRadio('type', 'Type', self::TYPES, true)->defaultValue('method');
		$form->addText('method', 'Method', true)->maxLength(255)->placeholder('Plugin\Core\CronController::widgetCron')->help('public static method of a module returning the data of the widget')->showIf('type', ['method'])->protect();
		$form->addText('url', 'URL', true)->maxLength(2048)->placeholder('https://')->help('page shown in an iframe, https only (http:// is turned into https://), the site must allow to be framed')->showIf('type', ['url']);
		$form->addNumber('url_height', 'Height (px)', true, true)->defaultValue('300')->showIf('type', ['url']);
		$form->addRadio('size', 'Size', self::SIZES, true)->defaultValue('full');
		$form->addBoolean('autorefresh', 'Auto refresh')->defaultValue('no')->protect();
		$form->addNumber('autorefresh_seconds', 'Every (seconds)', true, true)->defaultValue('60')->showIf('autorefresh', ['yes'])->protect();
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$type = (string)$v->inputGet('type', $record['type'] ?? 'method');

		if($type === 'url')
		{

			$url = self::httpsUrl((string)$v->inputGet('url', $record['url'] ?? ''));
			if($url !== '' && !self::validUrl($url))
				$v->input('url')->addError(ts('`[FIELD]` must be a valid https url'));

			$height = (int)$v->inputGet('url_height', $record['url_height'] ?? 300);
			if($height < 100 || $height > 2000)
				$v->input('url_height')->addError(ts('`[FIELD]` must be between 100 and 2000'));

		}
		else
		{

			$method = (string)$v->inputGet('method', $record['method'] ?? '');
			if($method !== '' && !\Core\Widget::callable($method))
				$v->input('method')->addError(ts('`[FIELD]` must be a public static method widget... of a module controller'));

		}

		if($v->inputGet('configurable', $record['configurable'] ?? 'no') === 'yes' && ($error = \Core\Widget::fieldsError((string)$v->inputGet('config_fields', $record['config_fields'] ?? ''))))
			$v->input('config_fields')->addError($error);

		if($v->inputGet('autorefresh', $record['autorefresh'] ?? 'no') === 'yes' && (int)$v->inputGet('autorefresh_seconds', $record['autorefresh_seconds'] ?? 0) < 5)
			$v->input('autorefresh_seconds')->addError(ts('`[FIELD]` must be at least 5'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		$type = $row['type'] ?? $record['type'] ?? 'method';

		// url widget: https forced, no method; method widget: no url
		if($type === 'url')
		{

			if(isset($row['url']))$row['url'] = self::httpsUrl((string)$row['url']);
			$row['method'] = '';

		}
		elseif(isset($row['type']))
		{

			$row['url'] = '';

		}

		return $row;

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
	 * url with https forced: trimmed, https:// added when no scheme, http:// turned into https://
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public static function httpsUrl(string $url):string
	{

		$url = trim($url);
		if($url === '')return '';

		if(preg_match('#^http://#i', $url))return 'https://'.substr($url, 7);
		if(!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url))return 'https://'.ltrim($url, '/');

		return $url;

	}

	/**
	 * https url with a host, without credentials
	 *
	 * @param string $url
	 *
	 * @return bool
	 */
	private static function validUrl(string $url):bool
	{

		if(filter_var($url, FILTER_VALIDATE_URL) === false)return false;

		$parts = parse_url($url);

		return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);

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
