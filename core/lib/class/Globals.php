<?php

namespace Core;

class Globals
{
	static public array $cached = [];

	/**
	 * get global value from variable name
	 *
	 * @param string $name
	 * @param mixed $default value if not found
	 * @param bool $register_value (default=true) register default value
	 * @param string $register_type (text, url, boolean, list, json-array, json-object)
	 * @param string $register_description
	 * @return string|bool|array
	 */
	public static function get(string $name, mixed $default=false, bool $register_value=true, string $register_type='text', string $register_description=''):bool|string|array
	{
		global $app;

		// register
		if($register_value && !isset($app->globals[$name]))
		{
			$app->globals[$name] = $default;

			if($register_type == 'text' && is_array($default))
				$register_type = 'list';

			\Core\Globals::set($name, $default, $register_type, $register_description);
		}

		$value = (!isset($app->globals[$name])) ? $default : $app->globals[$name];
		return $value;
	}

	/**
	 * create or update a globals
	 *
	 * @param string $name
	 * @param string $value
	 *
	 * @return void
	 */
	public static function set(string $name, mixed $value, string $type='', string $description=''):void
	{
		\Core\Entity::$table = 'xcore_globals';

		if($type == 'list' && is_array($value))
			$value = join("\n", $value);
		elseif($type == 'json-array' && is_array($value))
			$value = json_encode($value, JSON_PRETTY_PRINT);
		elseif($type == 'json-object' && is_array($value))
			$value = json_encode($value, JSON_PRETTY_PRINT);

		$f = [];
		$f['category'] = explode('.', $name)[0];
		$f['name'] = $name;
		$f['type'] = $type;
		$f['value'] = $value;
		$f['description'] = $description;

		$where = [];
		$where[] = "name = :name";
		$where[] = [':name' => $name];

		\Core\Entity::replace($f, $where, ['category']);

	}

	/**
	 * @param string $name
	 *
	 * @return void
	 */
	public static function destroy(string $name):void
	{
		global $app;

		\Core\Entity::$table = 'xcore_globals';

		$where = [];
		$where[] = "name = :name";
		$where[] = [':name' => $name];

		\Core\Entity::delete($where);

		if(isset($app->globals[$name]))
			unset($app->globals[$name]);
	}



}