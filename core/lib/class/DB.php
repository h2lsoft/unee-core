<?php

namespace Core;

class DB extends \h2lsoft\DBManager\DBManager
{

	/**
	 * connect with config `db`, in cli mode `cli/db` values override it (ex: a cron reaching the database through another host)
	 */
	public static function connectFromConfig():static
	{

		$db_cfg = Config::get('db');

		if(APP_CLI_MODE)
		{

			foreach(['host', 'port', 'username', 'password', 'database', 'pdo_options', 'init_queries'] as $key)
			{

				$value = Config::get("cli/db/{$key}");
				if(!empty($value))$db_cfg[$key] = $value;

			}

		}

		$db = new static();
		$db->connect($db_cfg['driver'], $db_cfg['host'], $db_cfg['username'], $db_cfg['password'], $db_cfg['database'], $db_cfg['port'], $db_cfg['pdo_options']);

		foreach($db_cfg['init_queries'] as $sql)
		{

			$db->query($sql);

		}

		return $db;

	}

}
