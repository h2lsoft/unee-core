<?php

namespace Model;

/**
 * categories of the backend menu, their plugins are in xcore_plugin (xcore_menu_id)
 */
class Menu extends \Core\Entity
{

	public static string $table = 'xcore_menu';

	/**
	 * menu of the logged user: categories with the visible plugins he has a right on, names translated
	 *
	 * @return array [{name, icon, items: [{name, icon, route, url, rights}]}]
	 */
	public static function forCurrentUser():array
	{

		$recs = DB()->query("SELECT p.*, m.id AS menu_id, m.name AS menu_name, m.icon AS menu_icon
							 FROM xcore_plugin p
							 INNER JOIN xcore_menu m ON m.id = p.xcore_menu_id AND m.deleted = 'no'
							 WHERE p.deleted = 'no' AND p.active = 'yes' AND p.visible = 'yes' AND COALESCE(p.xcore_plugin_id, 0) = 0
							 ORDER BY m.position, p.position, p.name")->fetchAll();

		$menu = [];
		foreach($recs as $rec)
		{

			$rights = \Model\User::getRights($rec['route_prefix_name']);
			if(!in_array('list', $rights, true))continue;

			$menu[$rec['menu_id']] ??= ['name' => ts($rec['menu_name']), 'icon' => $rec['menu_icon'], 'items' => []];
			$menu[$rec['menu_id']]['items'][] = [
				'name' => ts($rec['name']),
				'icon' => $rec['icon'],
				'route' => $rec['route_prefix_name'],
				'url' => ($rec['type'] === 'url') ? $rec['url'] : null,
				'rights' => $rights,
			];

		}

		return array_values($menu);

	}

}
