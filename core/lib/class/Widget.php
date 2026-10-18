<?php

namespace Core;

/**
 * widgets of the dashboard (xcore_widget): a public static method of a module, ex: Plugin\Core\CronController::widgetCron, returns json data drawn by the react dashboard
 * shown to the users with the list right on the plugin of the widget, size full or half, position < 100 above the notes, from 100 below
 *
 * data types:
 * - rows: {"type": "rows", "rows": [[cell, ...]], "empty": "text"}
 * - progress: {"type": "progress", "items": [{"name", "color", "percent", "badge": {"text", "color"}}]}
 * - counters: {"type": "counters", "status": {"label", "badge": {"text", "color"}}, "note", "tiles": [{"value", "label", "color"}]}
 * - tabs: {"type": "tabs", "tabs": [{"label", "rows": [[cell, ...]], "empty"}]}
 * a cell: a string or {"text", "strong", "badge" (color), "muted", "mono", "grow", "align", "color" (error), "date" (Y-m-d H:i:s, shown relative), "day" (Y-m-d, shown relative), "avatar": {"initials", "color", "url"}, "title", "width"}
 * colors: grey, blue, green, orange, red, purple, yellow
 */
class Widget
{

	public const string METHOD_PATTERN = '/^\\\\?Plugin\\\\[A-Za-z_]+\\\\[A-Za-z_]+Controller::widget[A-Za-z0-9_]*$/';

	/**
	 * widgets of the logged user, without their data
	 *
	 * @return array [{"id", "name", "icon", "route", "size", "refresh"}]
	 */
	public static function forCurrentUser():array
	{

		$recs = DB()->query("SELECT w.*, p.route_prefix_name, p.icon FROM xcore_widget w INNER JOIN xcore_plugin p ON p.id = w.xcore_plugin_id AND p.deleted = 'no' AND p.active = 'yes' WHERE w.deleted = 'no' AND w.active = 'yes' ORDER BY w.position, w.id")->fetchAll();

		$widgets = [];
		foreach($recs as $rec)
		{

			if(!\Model\User::hasRight($rec['route_prefix_name'], 'list'))continue;
			$widgets[] = ['id' => (int)$rec['id'], 'name' => ts($rec['name']), 'icon' => $rec['icon'], 'route' => $rec['route_prefix_name'], 'size' => ($rec['size'] === 'half') ? 'half' : 'full', 'position' => (int)$rec['position'], 'refresh' => ($rec['autorefresh'] === 'yes') ? max(5, (int)$rec['autorefresh_seconds']) : 0];

		}

		return $widgets;

	}

	/**
	 * data of a widget of the logged user, null when not allowed or not found
	 *
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function data(int $id):?array
	{

		$rec = DB()->query("SELECT w.method, p.route_prefix_name FROM xcore_widget w INNER JOIN xcore_plugin p ON p.id = w.xcore_plugin_id AND p.deleted = 'no' AND p.active = 'yes' WHERE w.id = :id AND w.deleted = 'no' AND w.active = 'yes'", ['id' => $id])->fetch();
		if(!$rec || !\Model\User::hasRight($rec['route_prefix_name'], 'list'))return null;

		$callable = self::callable((string)$rec['method']);
		if(!$callable)return ['type' => 'error', 'message' => "invalid method: {$rec['method']}"];

		return self::isoDates((array)$callable());

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

		$colors = ['#6d4aff', '#2f6fd0', '#0f8a6a', '#c77700', '#b0164e', '#5b6178', '#7b3fbf', '#0e7c86'];
		$name = trim(($user['firstname'] ?? '').' '.($user['lastname'] ?? ''));
		$initials = mb_strtoupper(mb_substr((string)($user['firstname'] ?? ''), 0, 1).mb_substr((string)($user['lastname'] ?? ''), 0, 1));
		if($initials === '')$initials = mb_strtoupper(mb_substr((string)($user['login'] ?? '?'), 0, 2));

		// picture: an url or a path only (shown in an img src)
		$url = trim((string)($user['avatar'] ?? ''));
		if(!preg_match('#^(https?://|/)[^\s"\'<>]+$#i', $url))$url = '';

		return ['avatar' => ['initials' => $initials, 'color' => $colors[(int)($user['id'] ?? 0) % count($colors)], 'url' => $url], 'title' => $name];

	}

}
