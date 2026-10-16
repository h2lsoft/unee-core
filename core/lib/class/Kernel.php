<?php

namespace Core;

use h2lsoft\Data\Validator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;

class Kernel extends HttpKernel {
	
	protected \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $dispatcher;
	protected $controllerResolver;
	protected \Symfony\Component\HttpFoundation\RequestStack $requestStack;
	protected $argumentResolver;
	public $request;

	public string $locale = 'en';
	public bool $is_backend = false;
	public bool $is_frontend = true;
	
	public $db;
	public $pdo;
	public $twig;
	public $plugin;
	public mixed $debugbar = false;
	
	public $validator;
	public array $globals = [];
	
	public function __construct($dispatcher, $controllerResolver, $requestStack, $argumentResolver, Request $request, $db_link=false, $pdo=false)
	{
		$this->request = $request;
		$this->dispatcher = $dispatcher;
		$this->controllerResolver = $controllerResolver;
		$this->requestStack = $requestStack;
		$this->argumentResolver = $argumentResolver;

		parent::__construct($this->dispatcher, $this->controllerResolver, $this->requestStack, $this->argumentResolver);

		if($db_link)$this->db = $db_link;
		if($pdo)$this->pdo = $pdo;

		$recs = $this->db->query("select name, type, value from xcore_globals where deleted = 'no'")->fetchAll();
		foreach($recs as $rec)
		{
			$value = $rec['value'];

			if($rec['type'] == 'list')
			{
				$value = trim(str_erase("\r", $value));
				if(empty($value))
					$value=[];
				else
					$value = explode("\n", $value);
			}
			elseif($rec['type'] == 'json-array')
			{
				$value = json_decode($value, true);
			}
			elseif($rec['type'] == 'json-object')
			{
				$value = json_decode($value);
			}
			elseif($rec['type'] == 'boolean')
			{
				if(trim(strtolower($value)) == 'true' || (int)trim($value) == 1)
					$value = true;
				else
					$value = false;
			}

			$this->globals[$rec['name']] = $value;
		}

		// app part
		$cur_uri = strtok($request->getRequestUri(), '?');
		$backend_dirname = \Core\Config::get('backend.dirname');
		if(preg_match("#^/{$backend_dirname}/#i", $cur_uri))
		{
			$this->is_backend = true;
			$this->is_frontend = false;
		}


		if($this->is_backend)
		{
			if(!\Model\User::isLogon())
			{
				$browser_lang = @trim(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2));
				if(ctype_alpha($browser_lang) && strlen($browser_lang) == 2 && (file_exists(CORE_PATH."/core/i18n/{$browser_lang}.php") || file_exists(APP_PATH."/instances/".APP_DIR."/i18n/{$browser_lang}.php")))
					$this->locale = $browser_lang;
			}
			else
			{
				$this->locale = \Core\Session::get('auth.language', 'en');
				if(empty(trim($this->locale)))$this->locale = 'en';
			}
		}
		else
		{
			// language
			$langs = $this->globals['frontend.langs'];
			$this->locale = $langs[0][0];
		}

		// validator
		$this->validator = new Validator($this->locale);

		// load twig
		$paths = [];
		$loader = new \Twig\Loader\FilesystemLoader(".");
		$this->twig = new \Twig\Environment($loader, \Core\Config::get('twig/options'));

		include CORE_PATH.'/core/lib/inc/twig_functions.inc.php';

		if($this->is_backend)
			include CORE_PATH.'/core/lib/inc/twig_functions_backend.inc.php';

		if($this->is_frontend)
			include CORE_PATH.'/core/lib/inc/twig_functions_frontend.inc.php';

		$files = (array)\Core\Config::get('twig/functions_file');
		foreach($files as $f)
		{
			// if(!file_exists($f) || empty($f))continue;
			include $f;
		}

		// events
		$files = (array)\Core\Config::get('events');
		foreach($files as $f)
			include $f;

		// debugbar
		if(!APP_CLI_MODE && \Core\Config::get('debug')  && \Core\Config::get('debug_bar') && $request->getRequestFormat() == 'html')
		{
			$this->debugbar = new \DebugBar\DebugBar();

			$cfg = (array)\Core\Config::get();

			// mask data sensitive
			$stack = [&$cfg];

			while (!empty($stack)) {
				$current_array = &$stack[array_key_last($stack)];
				array_pop($stack);

				foreach ($current_array as $key => &$value)
				{
					if(in_array($key, ['password', 'pass', 'pwd', 'api_key']) || str_contains($key, '.password'))
					{
						if (!is_array($value))
							$value = '*******';
						else
							$stack[] = &$value;

					}
					elseif(is_array($value))
					{
						$stack[] = &$value;
					}
				}
				unset($value);
			}



			$cfg['APP_PATH'] = APP_PATH;
			$cfg['CORE_PATH'] = CORE_PATH;
			$cfg['APP_DIR'] = APP_DIR;
			$cfg['APP_ENV'] = APP_ENV;
			ksort($cfg);
			$this->debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($cfg, 'CONFIG'));

			$server = (array)$_SERVER;
			ksort($server);
			$this->debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($server, 'SERVER'));

			$this->debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($this->globals, 'GLOBALS'));

			$cookie = (array)$_COOKIE;
			ksort($cookie);

			$__files = (array)$_FILES;
			ksort($__files);

			$session = (array)\Core\Session::get();
			$session['@cookie-parameters'] = session_get_cookie_params();
			ksort($session, SORT_STRING);

			$this->debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($session, 'SESSION'));
			$this->debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($cookie, 'COOKIE'));
			$this->debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($request->query->all(), 'GET'));
			$this->debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($_POST, 'POST'));
			$this->debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($__files, 'FILES'));

			$connection_collector = new \DebugBar\DataCollector\PDO\TraceablePDO($this->pdo);
			$this->debugbar->addCollector(new \DebugBar\DataCollector\PDO\PDOCollector($connection_collector));

			$this->debugbar->addCollector(new \DebugBar\DataCollector\MemoryCollector());
			$this->debugbar->addCollector(new \DebugBar\DataCollector\PhpInfoCollector());
			// $app->debugbar->addCollector(new \DebugBar\DataCollector\ExceptionsCollector());
			$this->debugbar->addCollector(new \DebugBar\DataCollector\TimeDataCollector());
			$this->debugbar->addCollector(new \DebugBar\DataCollector\MessagesCollector());
		}

		// backend plugin **********************************************************************************************
		if($this->is_backend && \Model\User::isLogon())
		{
			// @todo> user has right plugin_name/plugin_action ?
			$plugin_name = \Model\Plugin::extractName($cur_uri);

			if(!in_array($plugin_name, ['@error', 'logout', 'login', 'password', 'password-reset']))
			{
				$rec = $this->db->table(\Model\Plugin::$table)
					->select("
								*,
								(SELECT name FROM xcore_menu WHERE id = xcore_menu_id) AS category_name
							")
					->where('route_prefix_name = :route_prefix_name')
					->executeSQL([':route_prefix_name' => $plugin_name])
					->limit(1)
					->fetch();


				$bookmarks = $this->db->select('xcore_plugin_id')
					->from(\Model\User_Bookmark::$table)
					->where("xcore_user_id = :xcore_user_id")
					->executeSQL([':xcore_user_id' => \Model\User::getUID()])
					->fetchAllOne();

				$tmp = [];
				if($rec)
				{
					$rec['bookmarked'] = (in_array($rec['id'], $bookmarks));

					// breadcrumbs

					if($rec['name'] != 'Dashboard')
					{
						// category
						$tmp[] = [
							'name' => $rec['category_name'],
							'url' => "/@backend/{$rec['route_prefix_name']}/"
						];

						// @todo> add parent plugin

						// plugin
						$tmp[] = [
							'name' => $rec['name'],
							'url' => "/@backend/{$rec['route_prefix_name']}/"
						];
					}
				}

				@$rec['breadcrumbs'] = $tmp;

				$this->plugin = $rec;
			}


		}
	}
}