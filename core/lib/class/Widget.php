<?php

namespace Core;

/**
 * widgets of the dashboard (xcore_widget): a public static method of a module, ex: Plugin\Core\CronController::widgetCron, returns json data drawn by the react dashboard
 * shown to the users with the widget right on the plugin of the widget (no plugin or access_all: shown to every user), its title links to the plugin with the list right, size on a dashboard of 4 columns: quarter (1), half (2), three_quarters (3) or full (4)
 * the method NOTES is the personal notes of the user, drawn by the react dashboard itself (type notes, no data call)
 * the method CLOCK is the date and time of the browser, drawn by the react dashboard itself (type clock, no data call)
 * a widget of type url is a https page shown in an iframe (type url, url, height), no data call
 *
 * data types:
 * - rows: {"type": "rows", "rows": [[cell, ...]], "empty": "text", "filter" (optional): {"column", "options": [text, ...]} toggleable chips on the text of a column, "scroll" (optional): height in px of the rows, the others scrolled, "max_rows" (optional): rows shown, the others scrolled (scroll wins)}
 * - progress: {"type": "progress", "items": [{"name", "link" (page of the backend, optional), "color", "percent", "badge": {"text", "color"}}]}
 * - counters: {"type": "counters", "status": {"label", "badge": {"text", "color"}}, "note": {"text"} or {"prefix", "date": datetime shown relative} or {"prefix", "day": Y-m-d shown as a date}, "tiles": [{"value", "label", "color"}]}
 * a cell: a string or {"text", "strong", "badge" (color), "muted", "mono", "grow" (the column takes the free space, table at 100%), "align", "color" (error), "date" (Y-m-d H:i:s, shown relative), "day" (Y-m-d, shown relative), "avatar": {"initials", "color", "url"}, "title", "width", "link" (page of the backend), "small"}
 * colors: grey, blue, green, orange, red, purple, yellow
 */
class Widget
{

	public const string METHOD_PATTERN = '/^\\\\?Plugin\\\\[A-Za-z_]+\\\\[A-Za-z_]+Controller::widget[A-Za-z0-9_]*$/';
	public const string NOTES = 'Plugin\\Core_Backend\\DashboardController::widgetNotes';
	public const string CLOCK = 'Plugin\\Core_Backend\\DashboardController::widgetClock';

	// dashboard of 4 columns: columns taken by each size
	public const int COLUMNS = 4;
	public const array SPANS = ['quarter' => 1, 'half' => 2, 'three_quarters' => 3, 'full' => 4];

	// settings of a configurable widget (config_fields), set by each user on each widget he placed
	public const array FIELD_TYPES = ['text', 'number', 'select', 'boolean'];
	public const int FIELDS_MAX = 20;

	/**
	 * widgets of the logged user, without their data
	 *
	 * @return array [{"id", "type" (notes, clock, data or url), "name", "icon", "route", "size", "refresh", "multiple", "fields" (settings of a configurable widget), "url" and "height" for url}]
	 */
	public static function forCurrentUser():array
	{

		$recs = DB()->query("SELECT w.*, p.route_prefix_name, p.icon AS plugin_icon FROM xcore_widget w LEFT JOIN xcore_plugin p ON p.id = w.xcore_plugin_id AND p.deleted = 'no' AND p.active = 'yes' WHERE w.deleted = 'no' AND w.active = 'yes' ORDER BY w.position, w.id")->fetchAll();

		$widgets = [];
		foreach($recs as $rec)
		{

			if(!self::allowed($rec))continue;
			$method = ltrim(trim((string)$rec['method']), '\\');
			$type = ($rec['type'] === 'url') ? 'url' : match($method){self::NOTES => 'notes', self::CLOCK => 'clock', default => 'data'};
			$widget = ['id' => (int)$rec['id'], 'type' => $type, 'name' => ts($rec['name']), 'icon' => ((string)$rec['icon'] !== '') ? (string)$rec['icon'] : (string)$rec['plugin_icon'], 'route' => (!empty($rec['route_prefix_name']) && \Model\User::hasRight($rec['route_prefix_name'], 'list')) ? (string)$rec['route_prefix_name'] : '', 'size' => in_array($rec['size'], ['quarter', 'half', 'three_quarters'], true) ? $rec['size'] : 'full', 'position' => (int)$rec['position'], 'refresh' => ($rec['autorefresh'] === 'yes') ? max(5, (int)$rec['autorefresh_seconds']) : 0, 'multiple' => ($rec['multiple'] === 'yes'), 'fields' => ($rec['configurable'] === 'yes') ? self::fields((string)$rec['config_fields']) : []];

			// url widget: https page and height of its iframe
			if($type === 'url')
			{

				if(!str_starts_with(strtolower((string)$rec['url']), 'https://'))continue;
				$widget['url'] = (string)$rec['url'];
				$widget['height'] = max(100, (int)$rec['url_height']);
				$widget['refresh'] = 0;

			}

			$widgets[] = $widget;

		}

		return $widgets;

	}

	/**
	 * dashboard of the logged user (xcore_user_widget) by rows: the widgets he placed and still allowed, in his order
	 * a row wider than 4 columns (size changed in the module Widgets) is split, rows renumbered from 0, a widget no longer multiple kept once
	 *
	 * @param array|null $catalog widgets allowed (forCurrentUser()), read when null
	 *
	 * @return array same items as forCurrentUser() with "key" (id of the placed widget), "row", "config" (its settings, defaults included), "title" (set by the user, '' = name)
	 */
	public static function layoutForCurrentUser(?array $catalog=null):array
	{

		$allowed = array_column($catalog ?? self::forCurrentUser(), null, 'id');
		$recs = DB()->query("SELECT id, xcore_widget_id, `row`, config, title FROM xcore_user_widget WHERE deleted = 'no' AND xcore_user_id = :me ORDER BY `row`, position, id", ['me' => \Model\User::getUID()])->fetchAll() ?: [];

		$widgets = [];
		$seen = [];
		$row = -1;
		$stored = null;
		$width = 0;
		foreach($recs as $rec)
		{

			$id = (int)$rec['xcore_widget_id'];
			if(!isset($allowed[$id]) || (isset($seen[$id]) && !$allowed[$id]['multiple']))continue;
			$seen[$id] = true;

			// new row: another stored row, or no room left in this one
			$span = self::SPANS[$allowed[$id]['size']] ?? self::COLUMNS;
			if($stored !== (int)$rec['row'] || $width + $span > self::COLUMNS)
			{

				$row++;
				$width = 0;

			}

			$stored = (int)$rec['row'];
			$width += $span;
			$widgets[] = $allowed[$id] + ['key' => (int)$rec['id'], 'row' => $row, 'config' => self::config($allowed[$id]['fields'], json_decode((string)$rec['config'], true)), 'title' => (string)$rec['title']];

		}

		return $widgets;

	}

	/**
	 * saves the dashboard of the logged user: his placed widgets updated (key), added (key 0) or removed (not given)
	 *
	 * @param array $rows [[{"key", "widget", "config", "title"}, ...], ...] checked by the caller (keys of the user, widgets allowed, config and title valid)
	 *
	 * @return void
	 */
	public static function saveLayout(array $rows):void
	{

		$me = \Model\User::getUID();
		$author = (string)\Core\Session::get('auth.login');
		$kept = [];

		foreach(array_values($rows) as $r => $items)
		{

			foreach(array_values($items) as $i => $item)
			{

				$data = ['row' => $r, 'position' => $i + 1, 'config' => $item['config'] ? json_encode($item['config'], JSON_UNESCAPED_UNICODE) : null, 'title' => (string)($item['title'] ?? '')];
				if($item['key'])
				{

					DB('xcore_user_widget')->update($data, (int)$item['key'], -1, $author);
					$kept[] = (int)$item['key'];

				}
				else
				{

					$kept[] = (int)DB('xcore_user_widget')->insert($data + ['xcore_user_id' => $me, 'xcore_widget_id' => (int)$item['widget']], $author);

				}

			}

		}

		// widgets removed from the dashboard
		$binds = ['me' => $me];
		$not_in = '';
		if($kept)
		{

			$names = [];
			foreach($kept as $n => $key)
			{

				$names[] = ":k{$n}";
				$binds["k{$n}"] = $key;

			}

			$not_in = ' AND id NOT IN ('.implode(', ', $names).')';

		}

		DB()->query("DELETE FROM xcore_user_widget WHERE xcore_user_id = :me{$not_in}", $binds);

	}

	/**
	 * settings of a configurable widget (column config_fields), invalid fields left out
	 *
	 * @param string $json [{"name", "label", "type", "default", "options"}], options of a select: ["value", ...] or {"value": "label", ...}
	 *
	 * @return array [{"name", "label" (translated), "type", "default", "options": [{"value", "label" (translated)}]}]
	 */
	public static function fields(string $json):array
	{

		$list = json_decode($json, true);
		if(!is_array($list) || !array_is_list($list))return [];

		$fields = [];
		foreach(array_slice($list, 0, self::FIELDS_MAX) as $f)
		{

			if(!is_array($f) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string)($f['name'] ?? '')) || !in_array($f['type'] ?? 'text', self::FIELD_TYPES, true))continue;

			$options = self::options($f['options'] ?? []);
			if(($f['type'] ?? 'text') === 'select' && !$options)continue;

			$fields[] = ['name' => $f['name'], 'label' => ts(trim((string)($f['label'] ?? '')) ?: $f['name']), 'type' => $f['type'] ?? 'text', 'default' => is_scalar($f['default'] ?? '') ? (string)($f['default'] ?? '') : '', 'options' => $options];

		}

		return $fields;

	}

	/**
	 * error of a json of settings (module Widgets), null when valid
	 *
	 * @param string $json
	 *
	 * @return string|null
	 */
	public static function fieldsError(string $json):?string
	{

		$list = json_decode($json, true);
		if(!is_array($list) || !array_is_list($list) || !$list)return ts('`[FIELD]` must be a json array of fields: [{"name", "label", "type", "default"}]');
		if(count($list) > self::FIELDS_MAX)return ts('`[FIELD]` holds [MAX] fields at most', ['MAX' => self::FIELDS_MAX]);

		$names = [];
		foreach($list as $f)
		{

			$name = is_array($f) ? (string)($f['name'] ?? '') : '';
			if(!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name))return ts('`[FIELD]`: name `[NAME]` invalid (a-z, 0-9, _)', ['NAME' => $name]);
			if(isset($names[$name]))return ts('`[FIELD]`: name `[NAME]` used twice', ['NAME' => $name]);
			$names[$name] = true;

			$type = $f['type'] ?? 'text';
			if(!in_array($type, self::FIELD_TYPES, true))return ts('`[FIELD]`: type of `[NAME]` must be text, number, select or boolean', ['NAME' => $name]);
			if($type === 'select' && !self::options($f['options'] ?? null))return ts('`[FIELD]`: `[NAME]` needs a list of options', ['NAME' => $name]);

		}

		return null;

	}

	/**
	 * settings of a placed widget: defaults of its fields, replaced by the valid values of the user
	 *
	 * @param array $fields fields()
	 * @param mixed $values values of the user
	 *
	 * @return array {name: value} values as text (boolean: yes / no)
	 */
	public static function config(array $fields, mixed $values):array
	{

		$values = is_array($values) ? $values : [];
		$config = [];
		foreach($fields as $f)
		{

			$value = $values[$f['name']] ?? null;
			$config[$f['name']] = ($value !== null && self::configError($f, $value) === null) ? self::configValue($f, $value) : $f['default'];

		}

		return $config;

	}

	/**
	 * error of a value of a setting, null when valid
	 *
	 * @param array $field
	 * @param mixed $value
	 *
	 * @return string|null
	 */
	public static function configError(array $field, mixed $value):?string
	{

		if(!is_scalar($value))return ts('`[FIELD]` is invalid', ['FIELD' => $field['label']]);
		if(is_bool($value))return ($field['type'] === 'boolean') ? null : ts('`[FIELD]` is invalid', ['FIELD' => $field['label']]);

		$value = trim((string)$value);
		return match($field['type'])
		{
			'number' => ($value === '' || is_numeric($value)) ? null : ts('`[FIELD]` must be a number', ['FIELD' => $field['label']]),
			'select' => in_array($value, array_column($field['options'], 'value'), true) ? null : ts('`[FIELD]` is invalid', ['FIELD' => $field['label']]),
			'boolean' => in_array($value, ['yes', 'no', '1', '0', 'true', 'false'], true) ? null : ts('`[FIELD]` is invalid', ['FIELD' => $field['label']]),
			default => (mb_strlen($value) <= 255) ? null : ts('`[FIELD]` is longer than 255 characters', ['FIELD' => $field['label']]),
		};

	}

	/**
	 * options of a select setting: ["value", ...] (label = value) or {"value": "label", ...}, labels translated
	 *
	 * @param mixed $options
	 *
	 * @return array [{"value", "label"}]
	 */
	private static function options(mixed $options):array
	{

		if(!is_array($options))return [];

		$list = [];
		foreach($options as $value => $label)
		{

			if(!is_scalar($label))continue;
			$value = array_is_list($options) ? (string)$label : (string)$value;
			$list[] = ['value' => $value, 'label' => ts((string)$label)];

		}

		return $list;

	}

	// value of a setting stored as text, boolean as yes / no
	private static function configValue(array $field, mixed $value):string
	{

		if(is_bool($value))return $value ? 'yes' : 'no';

		$value = trim((string)$value);
		if($field['type'] === 'boolean')return in_array($value, ['yes', '1', 'true'], true) ? 'yes' : 'no';

		return $value;

	}

	/**
	 * data of a widget placed by the logged user, null when not his, not allowed or not found
	 * the method gets the settings of the placed widget: ex public static function widgetWeather(array $config=[]):array
	 *
	 * @param int $key id of the placed widget (xcore_user_widget)
	 *
	 * @return array|null
	 */
	public static function data(int $key):?array
	{

		$rec = DB()->query("SELECT w.type, w.method, w.xcore_plugin_id, w.access_all, w.configurable, w.config_fields, u.config, p.route_prefix_name FROM xcore_user_widget u JOIN xcore_widget w ON w.id = u.xcore_widget_id AND w.deleted = 'no' AND w.active = 'yes' LEFT JOIN xcore_plugin p ON p.id = w.xcore_plugin_id AND p.deleted = 'no' AND p.active = 'yes' WHERE u.id = :key AND u.deleted = 'no' AND u.xcore_user_id = :me", ['key' => $key, 'me' => \Model\User::getUID()])->fetch();
		if(!$rec || !self::allowed($rec))return null;

		// url widget: shown in an iframe, no data
		if($rec['type'] === 'url')return ['type' => 'error', 'message' => 'url widget: no data'];

		$callable = self::callable((string)$rec['method']);
		if(!$callable)return ['type' => 'error', 'message' => "invalid method: {$rec['method']}"];

		$config = ($rec['configurable'] === 'yes') ? self::config(self::fields((string)$rec['config_fields']), json_decode((string)$rec['config'], true)) : [];

		return self::isoDates((array)$callable($config));

	}

	/**
	 * widget shown to the logged user: no plugin or access_all = everybody, else the plugin is active and the user has its widget right
	 *
	 * @param array $rec xcore_plugin_id, access_all, route_prefix_name (null when the plugin is missing or inactive)
	 *
	 * @return bool
	 */
	private static function allowed(array $rec):bool
	{

		if((int)$rec['xcore_plugin_id'] === 0)return true;

		// access all: every user, while its plugin is active
		if(($rec['access_all'] ?? 'no') === 'yes')return !empty($rec['route_prefix_name']);

		return !empty($rec['route_prefix_name']) && \Model\User::hasRight($rec['route_prefix_name'], 'widget');

	}

	/**
	 * dates (Y-m-d H:i:s, server time) written iso 8601 with the offset of the server: the browser shows them relative to its own clock
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public static function isoDates(array $data):array
	{

		foreach($data as $key => $value)
		{

			if(is_array($value))$data[$key] = self::isoDates($value);
			elseif($key === 'date' && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value))$data[$key] = date(DATE_ATOM, strtotime($value));

		}

		return $data;

	}

	/**
	 * callable of a method written Plugin\Package\NameController::widgetName, null when it is not one
	 *
	 * @param string $method
	 *
	 * @return callable|null
	 */
	public static function callable(string $method):?callable
	{

		$method = trim($method);
		if(!preg_match(self::METHOD_PATTERN, $method))return null;

		[$class, $function] = explode('::', ltrim($method, '\\'));
		if(!class_exists($class) || !method_exists($class, $function))return null;

		$reflection = new \ReflectionMethod($class, $function);
		return ($reflection->isPublic() && $reflection->isStatic()) ? [$class, $function] : null;

	}

	/**
	 * avatar cell of a user: picture (avatar column) or initials on a color of his id
	 *
	 * @param array $user firstname, lastname, login, avatar, id
	 *
	 * @return array
	 */
	public static function avatar(array $user):array
	{

		$colors = ['#4169e1', '#1d4f91', '#0f8a6a', '#c77700', '#b0164e', '#5b6178', '#6b7f2a', '#0e7c86'];
		$name = trim(($user['firstname'] ?? '').' '.($user['lastname'] ?? ''));
		$initials = mb_strtoupper(mb_substr((string)($user['firstname'] ?? ''), 0, 1).mb_substr((string)($user['lastname'] ?? ''), 0, 1));
		if($initials === '')$initials = mb_strtoupper(mb_substr((string)($user['login'] ?? '?'), 0, 2));

		// picture: a file of upload_dir (avatar field), or an url / absolute path (shown in an img src)
		$url = trim((string)($user['avatar'] ?? ''));
		if($url !== '' && preg_match('#^[a-z0-9_-]+(/[a-z0-9_.-]+)+$#i', $url) && !str_contains($url, '..'))$url = \Core\File::url($url);
		if(!preg_match('#^(https?://|/)[^\s"\'<>]+$#i', $url))$url = '';

		return ['avatar' => ['initials' => $initials, 'color' => $colors[(int)($user['id'] ?? 0) % count($colors)], 'url' => $url], 'title' => $name];

	}

}
