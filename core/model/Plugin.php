<?php

namespace Model;

/**
 * backend modules: route_prefix_name = folder of the module, actions = json array of the rights (ex: ["list","add","edit","delete"])
 * type: normal (rights by group), core (every backend user), url (external link of the menu)
 * custom: coded by a developer (own page, meta module), never opened in the Studio (studio type: url, custom or crud)
 */
class Plugin extends \Core\Entity
{

	public static string $table = 'xcore_plugin';

	private static array $cache = [];

	/**
	 * active plugin by route_prefix_name, cached for the request
	 *
	 * @param string $route_prefix_name
	 *
	 * @return array|false
	 */
	public static function findActive(string $route_prefix_name):array|false
	{

		if(!array_key_exists($route_prefix_name, self::$cache))
			self::$cache[$route_prefix_name] = self::findOne("route_prefix_name = :route AND active = 'yes' AND deleted = 'no'", ['route' => $route_prefix_name]);

		return self::$cache[$route_prefix_name];

	}

	/**
	 * actions declared by a plugin, a url plugin has one implicit action: list
	 *
	 * @param array $plugin row of xcore_plugin
	 *
	 * @return array
	 */
	public static function actions(array $plugin):array
	{

		if($plugin['type'] === 'url')return ['list'];

		$actions = json_decode((string)$plugin['actions'], true);
		return (is_array($actions)) ? array_values(array_filter($actions, 'is_string')) : [];

	}

}
