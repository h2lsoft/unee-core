<?php

namespace Core;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Debugbar
{

	// datasets of the ajax requests (fetch / xhr), answered before the kernel in core/run.php
	const OPEN_HANDLER_URL = '/_debugbar/';

	/**
	 * debugbar wanted for this run: debug mode, config debug_bar, web request, php-debugbar installed (dev dependency)
	 * @return bool
	 */
	public static function enabled():bool
	{
		return !APP_CLI_MODE && Config::get('debug') && Config::get('debug_bar') && class_exists(\DebugBar\DebugBar::class);
	}

	/**
	 * datasets stored in tmp_dir/debugbar (pruned by php-debugbar)
	 * @return \DebugBar\Storage\FileStorage
	 */
	public static function storage():\DebugBar\Storage\FileStorage
	{
		return new \DebugBar\Storage\FileStorage(Config::get('tmp_dir').'/debugbar');
	}

	/**
	 * debugbar of the request, false when disabled or for a file url (ex: /invoice.pdf)
	 * the sql of $db is traced
	 *
	 * @param Request $request
	 * @param DB $db
	 * @param array $globals
	 *
	 * @return mixed \DebugBar\DebugBar or false
	 */
	public static function create(Request $request, DB $db, array $globals=[]):mixed
	{

		if(!self::enabled() || pathinfo($request->getPathInfo(), PATHINFO_EXTENSION) !== '')return false;

		$debugbar = new \DebugBar\DebugBar();
		$debugbar->setStorage(self::storage());

		$cfg = (array)Config::get();

		// mask data sensitive
		$stack = [&$cfg];

		while(!empty($stack))
		{

			$current_array = &$stack[array_key_last($stack)];
			array_pop($stack);

			foreach($current_array as $key => &$value)
			{

				if(in_array($key, ['password', 'pass', 'pwd', 'api_key']) || str_contains($key, '.password'))
				{

					if(!is_array($value))
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
		$debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($cfg, 'CONFIG'));

		$server = (array)$_SERVER;
		ksort($server);
		$debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($server, 'SERVER'));

		$debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($globals, 'GLOBALS'));

		$cookie = (array)$_COOKIE;
		ksort($cookie);

		$__files = (array)$_FILES;
		ksort($__files);

		$session = (array)Session::get();
		$session['@cookie-parameters'] = session_get_cookie_params();
		ksort($session, SORT_STRING);

		$debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($session, 'SESSION'));
		$debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($cookie, 'COOKIE'));
		$debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($request->query->all(), 'GET'));
		$debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($_POST, 'POST'));
		$debugbar->addCollector(new \DebugBar\DataCollector\ConfigCollector($__files, 'FILES'));

		// the db queries go through the traceable connection (the global $PDO stays untraced)
		if(!$db->connection instanceof \DebugBar\DataCollector\PDO\TraceablePDO)
			$db->connection = new \DebugBar\DataCollector\PDO\TraceablePDO($db->connection);
		$debugbar->addCollector(new \DebugBar\DataCollector\PDO\PDOCollector($db->connection));

		$debugbar->addCollector(new \DebugBar\DataCollector\MemoryCollector());
		$debugbar->addCollector(new \DebugBar\DataCollector\PhpInfoCollector());
		$debugbar->addCollector(new \DebugBar\DataCollector\TimeDataCollector());
		$debugbar->addCollector(new \DebugBar\DataCollector\MessagesCollector());

		return $debugbar;

	}

	/**
	 * full html page: bar injected before </body>, its css and js inlined (the vendor folder is never served)
	 * other responses (api json, html fragment): dataset stored, its id sent in the header read by the bar of the page
	 *
	 * @param \DebugBar\DebugBar $debugbar
	 * @param Response $response
	 *
	 * @return void
	 */
	public static function attach(\DebugBar\DebugBar $debugbar, Response $response):void
	{

		$content = (string)$response->getContent();
		$is_html = str_contains((string)$response->headers->get('Content-Type', 'text/html'), 'html');

		if(!$is_html || !str_contains($content, '</body>'))
		{

			$debugbar->collect();
			$response->headers->set('phpdebugbar-id', $debugbar->getCurrentRequestId());
			return;

		}

		// dark by default, the theme chosen in its settings (browser storage) wins
		$renderer = $debugbar->getJavascriptRenderer()->setTheme('dark')->setOpenHandlerUrl(self::OPEN_HANDLER_URL);
		$css = $renderer->dumpCssAssets(null, false);
		$js = str_ireplace('</script', '<\/script', $renderer->dumpJsAssets(null, false));
		$bar = "<style>{$css}</style>\n".$renderer->dumpHeadAssets(null, false)."<script>{$js}</script>\n".$renderer->render();

		$pos = strripos($content, '</body>');
		$response->setContent(substr($content, 0, $pos).$bar."\n".substr($content, $pos));

	}

	
	/**
	 * add message to debugbar
	 * @param mixed $message
	 * @param string $type (info, warning, error)
	 *
	 * @return void
	 */
	public static function addMessage(mixed $message, string $type='info'):void
	{
		global $app;
		
		// debugbar disabled (production, api, cli): nothing to do
		if(!is_object($app->debugbar))return;
		$app->debugbar["messages"]->addMessage($message, $type);
		
	}
	
	/**
	 * add message info in debugbar
	 * @param string $message
	 *
	 * @return void
	 */
	public static function info(mixed $message):void{self::addMessage($message);}
	
	/**
	 * add message warning in debugbar
	 * @param mixed $message
	 *
	 * @return void
	 */
	public static function warning(mixed $message):void{self::addMessage($message, 'warning');}
	
	/**
	 * add message error in debugbar
	 * @param mixed $message
	 *
	 * @return void
	 */
	public static function error(mixed $message):void{self::addMessage($message, 'error');}
	
	
	/**
	 * start measure operation
	 * @param string $operation_name
	 * @param string $message
	 *
	 * @return void
	 */
	public static function startMeasure(string $operation_name, string $message):void
	{
		if(!is_object(App()->debugbar))return;
		App()->debugbar['time']->startMeasure($operation_name, $message);
	}
	
	/**
	 * stop measure operation
	 * @param string $operation_name
	 * @param string $message
	 *
	 * @return void
	 */
	public static function stopMeasure(string $operation_name):void
	{
		if(!is_object(App()->debugbar))return;
		App()->debugbar['time']->stopMeasure($operation_name);
	}
	
	/**
	 * measure operation
	 * @param string $message
	 * @param callable $function
	 *
	 * @return void
	 */
	public static function measure(string $message, callable $callable):void
	{
		if(!is_object(App()->debugbar))return;
		App()->debugbar['time']->measure($message, $callable);
	}
	
	/**
	 * enable debug
	 * @return void
	 */
	/*public static function enable():void
	{
		// if(!is_object(App()->debugbar))return;
		// App()->debugbar->enable();
	}*/
	
	/**
	 * disable debug
	 * @return void
	 */
	public static function disable():void
	{
		// if(!is_object(App()->debugbar))return;
		// App()->debugbar->disable();
		App()->debugbar = false;
	}
	
	
}