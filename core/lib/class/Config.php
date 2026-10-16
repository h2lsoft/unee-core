<?php

namespace Core;

class Config
{
	static public array $cached = [];

	/**
	 * get config value from path
	 *
	 * @param $path
	 * @param $default value if not found
	 * @param $cache retrieve value from cache
	 *
	 * @return false|mixed
	 */
	public static function get(string $path='', mixed $default=false, bool $cache=true):mixed
	{
		global $config;

		if(empty($path))return $config;
		if($cache && array_key_exists($path, self::$cached))return self::$cached[$path];

		$value = $config;
		foreach(explode('/', trim($path, '/')) as $key)
		{
			// missing key: return the default without caching it (another call may pass another default)
			if(!is_array($value) || !array_key_exists($key, $value))return $default;
			$value = $value[$key];
		}

		self::$cached[$path] = $value;
		return $value;
	}


	public static function isDebug():bool
	{
		return \Core\Config::get('debug');
	}


}