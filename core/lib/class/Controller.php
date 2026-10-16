<?php

namespace Core;

class Controller
{

	public \Core\Validator $validator;

	/**
	 * folder of the module (ex: globals), also the route_prefix_name of its xcore_plugin row (rights)
	 */
	public string $module = '';

	public function __construct()
	{

		$this->module = basename(dirname((new \ReflectionClass($this))->getFileName()));

		// validator messages: core i18n then instance i18n (\Core\I18n)
		$this->validator = new \Core\Validator(App()->locale);
		$this->validator->addLocaleMessages(App()->locale, \Core\I18n::messages());

		\Core\EventManager::emit('core.controller.init', []);

	}

	/**
	 * get all files controller files
	 * @return void
	 */
	public static function getAll():array
	{

		$all_controllers = [];

		$controllers = glob(CORE_PATH."/core/module/*/*/*Controller.php");
		foreach($controllers as $c_controller)
		{
			// skip core controllers overridden by the instance (same rule as the class autoloader)
			if(file_exists(str_replace(CORE_PATH."/core/module/", APP_PATH."/instances/".APP_DIR."/module/", $c_controller)))continue;
			$all_controllers[] = $c_controller;
		}

		// user custom plugin
		if(APP_DIR != '_default')
		{
			$controllers = glob(APP_PATH."/instances/".APP_DIR."/module/*/*/*Controller.php");

			foreach($controllers as $c_controller)
			{
				$all_controllers[] = $c_controller;
			}
		}

		return $all_controllers;
	}


	/**
	 * get info from controller
	 *
	 * @param string $file_controller_path
	 * @return array
	 */
	public static function getInfo(string $file_controller_path):array
	{
		$info = [];
		$info['core'] = str_starts_with($file_controller_path, CORE_PATH."/core/module/");

		$paths = str_erase([CORE_PATH."/core/module/", APP_PATH."/instances/".APP_DIR."/module/"], $file_controller_path);
		$paths = explode('/', $paths);

		$package = str_replace(' ', '_', ucwords(str_replace('-', ' ', $paths[0])));
		$module = str_replace(' ', '_', ucwords(str_replace('-', ' ', $paths[1])));
		$controller = ucfirst(str_erase('.php', $paths[2]));
		$class_path = "\Plugin\\{$package}\\{$controller}";

		$info['paths'] = $paths;
		$info['package'] = $package;
		$info['module'] = $module;
		$info['controller'] = $controller;
		$info['class_path'] = $class_path;



		return $info;
	}

}