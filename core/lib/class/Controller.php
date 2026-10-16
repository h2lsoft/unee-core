<?php

namespace Core;

use h2lsoft\Data\Validator;
use Symfony\Component\HttpFoundation\JsonResponse;

class Controller {

	public Validator $validator;
	public string $object_label = 'record';
	public string $table = '';
	public int $id;
	
	
	public function __construct()
	{
		// load locale
		$this->validator = new Validator(App()->locale);

		// translations: core first, then the instance adds or overrides its own keys
		$core_i18n = CORE_PATH."/core/i18n/".App()->locale.".php";
		if(file_exists($core_i18n))
			$this->validator->addLocaleMessages(App()->locale, require($core_i18n));

		$instance_i18n = APP_PATH."/instances/".APP_DIR."/i18n/".App()->locale.".php";
		if(file_exists($instance_i18n))
			$this->validator->addLocaleMessages(App()->locale, require($instance_i18n));
		
		// init user logon timestamp
		\Core\EventManager::emit('core.controller.init', []);
	}
	
	
	public function getForm(int $id=0){}

	public function add(){
		$data = [];
		$data['content'] = $this->getForm();
		return View('@plugin-content', $data);
	}

	public function edit(int $id){
		$data = [];
		$data['content'] = $this->getForm($id);
		return View('@plugin-content', $data);
	}

	public function delete(int $id)
	{
		$this->id = $id;
		$this->onDeleteBefore();
		
		if($this->validator->success())
		{
			$this->onDeleteDatabaseBefore();
			DB($this->table)->delete($id, 1, \Core\Session::get('auth.login'));
			$this->onDeleteDatabaseAfter();
		}
		
		$this->onDeleteAfter();
		
		// return json_encode();
		return new JsonResponse($this->validator->result());
		
	}


	
	public function loadAssetsJs(array $scripts, bool $inside_body=true):void
	{
		global $config;
		
		foreach($scripts as $js)
		{
			if(basename($js) == $js)
			{
				$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[0]['file'];
				$js = str_erase(APP_PATH, dirname($caller)."/assets/js/{$js}");
			}
			
			$section = ($inside_body) ? 'js_body' : 'js_head';
			// $config['backend']['assets'][$section][] = $js;

			//$current = \Core\Globals::get("backend.{$section}_files", "");
			//$current = trim($current."\n{$js}");
			//\Core\Globals::set("backend.{$section}_files", $current);
			
		}
	}
	public function loadAssetsCss(array $scripts):void
	{
		global $config;
		
		foreach($scripts as $css)
		{
			if(basename($css) == $css)
			{
				$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[0]['file'];
				$css = str_erase(APP_PATH, dirname($caller)."/assets/css/{$css}");
			}
			
			// $config['backend']['assets']['css'][] = $css;

			//$current = \Core\Globals::get("backend.assets_files", "");
			//$current = trim($current."\n{$css}");
			//\Core\Globals::set("backend.assets_files", $current);
		}
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

	// event
	public function onSaveDatabaseBefore(array $f):array{return $f;}
	public function onSaveDatabaseAddBefore(array $f):array{return $f;}
	public function onSaveDatabaseEditBefore(array $f):array{return $f;}
	public function onSaveDatabaseAfter(array $f):array{return $f;}
	public function onSaveDatabaseAddAfter(array $f):array{return $f;}
	public function onSaveDatabaseEditAfter(array $f):array{return $f;}
	public function onSaveAfter(array $f):array{return $f;}
	public function onDeleteBefore():void{}
	public function onDeleteAfter():void{}
	public function onDeleteDatabaseBefore():void{}
	public function onDeleteDatabaseAfter():void{}
	
}