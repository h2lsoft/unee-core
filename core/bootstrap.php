<?php

// APP_PATH (project root) is defined by the project front controller before this file is required
if(!defined('APP_PATH'))
{
	throw new RuntimeException("APP_PATH must be defined before requiring the unee core bootstrap");
}

define('CORE_PATH', dirname(__DIR__));
define('APP_CLI_MODE', (strtolower(php_sapi_name()) == 'cli'));
define('APP_ENV', !getenv('APP_ENV') ? 'production' : getenv('APP_ENV'));
define('APP_DIR', !getenv('APP_DIR') ? '' : getenv('APP_DIR'));


// utils
const CR = "\n";
const TAB = "\t";
const CHARLIST_LATIN = 'âàäéèêëôöüùÿçïî';
const CHARLIST_DASHES = '-_';
const CHARLIST_SPACE = ' ';
const CHARLIST_LATIN_DASHES = CHARLIST_LATIN.CHARLIST_DASHES;
const CHARLIST_LATIN_DASHES_SPACE = CHARLIST_LATIN.CHARLIST_DASHES.CHARLIST_SPACE;
const CHARLIST_LATIN_DASHES_SPACE_QUOTE = CHARLIST_LATIN.CHARLIST_DASHES.CHARLIST_SPACE."'";

// core functions, listed explicitly (loaded before the config and the class autoloader, which use them)
require_once CORE_PATH.'/core/lib/func/array.php';
require_once CORE_PATH.'/core/lib/func/core.php';
require_once CORE_PATH.'/core/lib/func/date.php';
require_once CORE_PATH.'/core/lib/func/http.php';
require_once CORE_PATH.'/core/lib/func/string.php';

// load config: core defaults < project < project env < instance *****************************************************

$config = require CORE_PATH."/config/default/app.php";

// project config
if(file_exists(APP_PATH."/config/app.php"))
{
	$tmp = require APP_PATH."/config/app.php";
	$config = array_replace_recursive($config, $tmp);
}

// project env config, ex: config/app_dev.php (not versioned, may hold credentials)
if(file_exists(APP_PATH."/config/app_".APP_ENV.".php"))
{
	$tmp = require APP_PATH."/config/app_".APP_ENV.".php";
	$config = array_replace_recursive($config, $tmp);
}

// load instance config: overrides existing keys and adds new ones
// lists (middlewares, routes, events, commands, jobs) are keyed: same key overrides, new key adds, null disables
if(APP_DIR != '' && file_exists(APP_PATH."/instances/".APP_DIR."/config/app.php"))
{
	$tmp = require APP_PATH."/instances/".APP_DIR."/config/app.php";
	$config = array_replace_recursive($config, $tmp);
}

// remove disabled entries
foreach(['middlewares', 'routes', 'events', 'commands', 'jobs'] as $key)
{
	$config[$key] = array_filter($config[$key] ?? []);
}



// load php ini
foreach($config['php'] as $php_directive => $php_value)
{
	if($php_value != '')
		ini_set($php_directive, $php_value);
}

// core autoloader *****************************************************************************************************
spl_autoload_register(function($class){

	$path = explode('\\', $class);

	// Core
	if($path[0] == 'Core')
	{
		$class2 = str_replace('\\', '/', $class);
		$class2 = str_erase('Core/', $class2);
		$class_path = CORE_PATH."/core/lib/class/{$class2}.php";

		if(file_exists($class_path))
		{

			include_once($class_path);
			return true;
		}
	}

	// Model
	if($path[0] == 'Model')
	{
		$package = strtolower($path[1]);
		$class_path = APP_PATH."/instances/".APP_DIR."/model/{$path[1]}.php";
		$core_class_path = CORE_PATH."/core/model/{$path[1]}.php";

		if(file_exists($class_path))
		{
			include_once($class_path);
			return true;
		}

		if(file_exists($core_class_path))
		{
			include_once($core_class_path);
			return true;
		}
	}

	// Plugin
	if($path[0] == 'Plugin')
	{
		$package = strtolower($path[1]);
		$package = str_replace('_', '-', $package);
		$controller_dir = strtolower(str_erase('Controller', $path[2]));
		$controller_dir = str_replace('_', '-', $controller_dir);
		$controller = $path[2];
		$class_path = APP_PATH."/instances/".APP_DIR."/module/{$package}/{$controller_dir}/{$controller}.php";
		$core_class_path = CORE_PATH."/core/module/{$package}/{$controller_dir}/{$controller}.php";

		if(file_exists($class_path))
		{
			include_once($class_path);
			return true;
		}

		if(file_exists($core_class_path))
		{
			include_once($core_class_path);
			return true;
		}
	}

	// Component
	if($path[0] == 'Component')
	{
		$package = strtolower($path[1]);
		$package = str_replace('_', '-', $package);
		$file = $path[1];

		$core_class_path = CORE_PATH."/core/component/{$package}/{$file}.php";
		if(file_exists($core_class_path))
		{
			include_once($core_class_path);
			return true;
		}

	}

	// Job handler (config jobs): Job\Core\Send_Email => core/job/Core/Send_Email.php, an instance can override
	if($path[0] == 'Job' && count($path) === 3)
	{

		$class_path = APP_PATH."/instances/".APP_DIR."/job/{$path[1]}/{$path[2]}.php";
		$core_class_path = CORE_PATH."/core/job/{$path[1]}/{$path[2]}.php";

		foreach([$class_path, $core_class_path] as $file)
		{

			if(file_exists($file))
			{
				include_once($file);
				return true;
			}

		}

	}

	// Command
	if($path[0] == 'Command')
	{
		$package = strtolower($path[1]);
		$class_path = APP_PATH."/instances/".APP_DIR."/command/{$path[1]}/{$path[2]}.php";
		$core_class_path = CORE_PATH."/core/command/{$path[1]}/{$path[2]}.php";

		if(file_exists($class_path))
		{
			include_once($class_path);
			return true;
		}

		if(file_exists($core_class_path))
		{
			include_once($core_class_path);
			return true;
		}
	}

});