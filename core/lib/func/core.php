<?php


/**
 * debug utils
 * @param      $var
 * @param bool $exit
 *
 * @return void
 */
function x(mixed $var, bool $exit=true):void
{
	\Symfony\Component\VarDumper\VarDumper::dump($var);
	if($exit)die();
}

/**
 * debug utils
 * @param      $var
 *
 * @return void
 */
function _x(mixed $var):void
{
	\Symfony\Component\VarDumper\VarDumper::dump($var);
}


/**
 * return global app
 * @param string $key
 *
 * @return void
 */
function App(string $key=''):mixed
{
	global $app;
	
	$v = (empty($key)) ? $app : $app[$key];
	return $v;
}

/**
 * return global db manager
 * @param string $table (optionnal init table)
 *
 * @return \Core\DB
 */
function DB(string $table="")
{
	global $app, $DB;

	if(!empty($table))
		$app->db->table($table);
	return $app->db;



}

/**
 * return plugin view
 *
 * @param string $template
 * @param array  $data
 * @param int    $status (if 0 direct render)
 * @param array  $headers
 *
 * @return \Symfony\Component\HttpFoundation\Response or string (if status == 0)
 */
function View(string $template='', array $data=[], int $status=200, array $headers=[]):\Core\Response|string
{
	global $app;
	
	$caller = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1)[0]['file'];
	$path = dirname($caller);
	$plugin_folder_name = basename($path);
	
	if(empty($template))$template = '@plugin-content';
	if(!strpos($template, '.')) $template .= '.twig';
	
	
	// backend
	if($template[0] == '@' && file_exists(CORE_PATH.'/core/theme/core-admin/'.$template))
	{
		$template = '/core/theme/core-admin/'.$template;
	}
	

	if($template[0] == '/')
	{
		$tpl_path = $template;
	}
	else
	{
		$tpl_path = "{$path}/view/{$template}";
	}
	
	// add variables
	$data['core_version'] = \Model\Core::$version;
	$data['APP_TITLE'] = \Core\Globals::get('name', 'Unee app');
	$data['app_name'] = \Core\Globals::get('name', 'Unee app');
	$data['locale'] = $app->locale;

	// add custom css files
	$files = \Core\Globals::get('backend.css_files', [], true, 'list');
	$data['config_css'] = $files;

	// add custom js files
	$files = \Core\Globals::get('backend.js_head_files', [], true, 'list');
	$data['config_js_head'] = $files;

	// add custom js end files
	$files = \Core\Globals::get('backend.js_body_files', [], true, 'list');
	$data['config_js_body'] = $files;


	// twig loader roots are APP_PATH and CORE_PATH: template name is relative to one of them
	$tpl_path = str_erase([APP_PATH, CORE_PATH], $tpl_path);
	if($app->is_backend)
	{
		$data['plugin'] = $app->plugin;
	}

	$content = $app->twig->render($tpl_path, $data);
	
	// add @globals
	$cur_dir = get_absolute_path($path);
	
	
	$content = str_replace('@backend', \Core\Config::get('backend.dirname'), $content);
	$content = str_replace('@locale', App()->locale, $content);
	
	$content = str_replace('@assets_img', $cur_dir."/assets/img", $content);
	$content = str_replace('@assets_css', $cur_dir."/assets/css", $content);
	$content = str_replace('@assets_js', $cur_dir."/assets/js", $content);
	$content = str_replace('@assets', $cur_dir."/assets", $content);
	
	if(!$status)
		return $content;

	return new \Core\Response($content, $status, $headers);
}


/*
 * shortcut for \Core\Globals
 * @param $name
 * @param $default
 * @return bool|string
 */
function globals($name, $default=false, $register_value=true, $register_type='text', $register_description=''):bool|string
{
	return \Core\Globals::get($name, $default, $register_value, $register_type, $register_description);
}

/*
 * shortcut for \Core\Config
 */
function config(string $path='', mixed $default=false, bool $cache=true):mixed
{
	return \Core\Config::get($path, $default);
}




