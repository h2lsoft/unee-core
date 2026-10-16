<?php

if(php_sapi_name() === 'cli')
{
	$_SERVER['HTTP_HOST'] = getenv('HTTP_HOST') ?: 'localhost';
	$_SERVER['SERVER_PORT'] = 443;
}

return [

	// generics
	'name' => 'UNEE',
	'url' => "https://{$_SERVER['HTTP_HOST']}".(($_SERVER['SERVER_PORT'] != 443) ? ":{$_SERVER['SERVER_PORT']}" : ""),
	'timezone' => "", # empty = default timezone
	'backend.dirname' => 'backend',
	'upload_dir' => APP_PATH."/instances/".APP_DIR."/public/upload",
	'tmp_dir' => (APP_DIR != '' && APP_DIR != '_default') ? APP_PATH."/instances/".APP_DIR."/.tmp" : APP_PATH."/.tmp",
	'noreply_email' => "noreply@{$_SERVER['HTTP_HOST']}",

	// DEBUG ***********************************************************************************************
	'debug' => false,
	'debug_bar' => false,
	'debug_for_ip_auto' => true, # auto-turn debug
	'debug_for_ip' => [			
	],

	// PHP **************************************************************************************************
	'php' => [
		// override php options (empty = default)
		'display_errors' => '',
		'error_reporting' => '',
		'memory_limit' => '',
		'max_execution_time' => '',
		'max_file_uploads' => '',
		'upload_max_filesize' => '',
		'post_max_size' => '',
	],

	// SESSION ***************************************************************************************
	'session' => [
		'name' => 'PHPSESSID', # name of session default PHPSESSID
		'options' => [
			'use_strict_mode' => true,
			'cookie_httponly' => true,
			'cookie_lifetime' => (int)(60*60*24*90), // 90 days
			'gc_maxlifetime' => (int)(60*60*24*90), // 90 days
			'cookie_path' => '/',
			'cookie_domain' => '',
			'cookie_secure' => true, # https only, set false in app_dev.php if local dev runs over http
			'cookie_samesite' => 'Lax',
			'cache_limiter' => 'nocache',
			'cache_expire' => 0,
		],
	],

	// DB - override in instance config ***********************************************************************
	'db' => [
		'driver'    => 'mysql',
		'host'      => 'localhost',
		'port'      => 3306,
		'username'  => 'unee',
		'password'  => '<db_password>',
		'database'  => 'unee',
		'pdo_options' => [

		], # PDO options see php pdo documentation

		// 'init_queries' => ["SET SESSION sql_mode='ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'"],
		'init_queries' => [
			"SET NAMES utf8mb4"
		],
	],

	// CLI *************************************************************************************************************
	'cli' => [
				'db' => [
								'host' => '',
								'port' => '',
								'username' => '',
								'password' => '',
								'pdo_options' => [],
								'init_queries' => [],
				]
	],

	// TWIG ************************************************************************************************************
	'twig' => [
		'options' => [
			'cache' => false, // ex: APP_PATH.'/instances/'.APP_DIR.'/.tmp/twig/' see twig documentation for option (https://twig.symfony.com/doc/3.x/api.html)
			'strict_variables' => true,
			'debug' => true,
		],
		// add your custom extension by include file, example: /core/inc/twig_functions.inc.php
		'functions_file' => []
	],

	// FILE-MANAGER  ***************************************************************************************************
	'file_manager' => [

		'dir' => [
			'root' => APP_PATH."/instances/".APP_DIR."/public/www",
			'cache' => APP_PATH."/instances/".APP_DIR."/public/www/.thumbs",
		]

	],
	
	// ROUTES **********************************************************************************************************
	'routes' => [
		# add your custom routes file path here, ex: APP_PATH.'/route/my_routes.php';
	],

	// FRONTEND dynamic ************************************************************************************************
	'frontend' => [
		'theme' => 'simple',
		'page' => [
			'url_pattern' => "/{locale}/{slug}-{id}.html", // {locale} will be replaced by language, {id} by id and {slug} by slugify page name
			'plugins' => [

				[
					'name' => 'block',
					'pattern' => '/(<x-block[^>]*data-name="([^"]*)"[^>]*>)(<\/x-block>)/',
					'controller' =>  \Plugin\Core_Frontend\BlockController::class,
					'method' => 'render',
					'reload_pattern' => true,
				],

				[
					'name' => 'slider',
					'pattern' => '/(<x-slider[^>]*data-name="([^"]*)"[^>]*>)(<\/x-slider>)/',
					'controller' =>  \Plugin\Core_Frontend\SliderController::class,
					'method' => 'render',
					'reload_pattern' => false,
				],

				[
					'name' => 'gallery',
					'pattern' => '/(<x-gallery[^>]*data-name="([^"]*)"[^>]*>)(<\/x-gallery>)/',
					'controller' =>  \Plugin\Core_Frontend\GalleryController::class,
					'method' => 'render',
					'reload_pattern' => false,
				],

				[
					'name' => 'thumbpage',
					'pattern' => '/(<x-thumbpage[^>]*data-parent-page="([^"]*)"[^>]*>)(<\/x-thumbpage>)/',
					'controller' =>  \Plugin\Core_Frontend\PageController::class,
					'method' => 'thumbnailsRender',
					'reload_pattern' => false,
				],
			]
		],

	],



	// AUTH ************************************************************************************************************
	'auth' => [
		'key_name' => 'UID',	 # key to store session auth data
		'logon_session_columns_added' => "",
		// brute-force protection: failed attempts counted per login and per ip (no sleep, it blocks a php worker)
		'logon_throttle' => [
			'max_attempts' => 5, # failed attempts allowed in the window
			'window_seconds' => 900, # counting window, also the lock duration once max_attempts is reached
		],
		'password_reset_token_expiration_hours' => 12,

		'security' => [
			'email' => [
				'max_length' => 255,
			],

			'login' => [
				'min_length' => 6,
				'max_length' => 30,
				'regex' => "/^[a-z\d\-_]+$/i",
				'regex_error_message' => "`login`: please fill only alphanumeric and [OTHER_CHARS]",
				'regex_error_message_added' => ['OTHER_CHARS' => " '-', '_'"],
			],

			'password' => [
				'algo' => PASSWORD_DEFAULT, # choose php password encrypt view php manual => http://php.net/manual/en/function.password-hash.php
				'algo_options' => [],
				'min_length' => 8,
				'max_length' => 72,
				// all characters allowed; no /u flag so the length is counted in bytes (bcrypt ignores bytes after 72)
				'regex' => "/^.{8,72}$/s",
				'regex_error_message' => "`password`: 8 to 72 characters (accented characters count double)",
				'regex_error_message_added' => [],
			]
		],

		'backend' => [
			'key' => 'email', # choose between email or login
		],

		'frontend' => [
			'key' => 'email', # choose between email or login
		]
	],

	// EVENTS **********************************************************************************************************
	'events' => [
			# add your event file here
	],

	// MIDDLEWARES *****************************************************************************************************
	// keyed absolute paths: an instance can override a key, add a new one, or set it to null to disable it
	'middlewares' => [
				'auth.backend' => CORE_PATH.'/core/module/core/auth/middleware/backend.php',
				// 'auth.frontend' => CORE_PATH.'/core/module/core/auth/middleware/frontend.php', # enable in instance config
	],

];