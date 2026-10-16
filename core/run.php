<?php
/**
 * core request handling, required by the project index.php after core/bootstrap.php
 * variables declared here are globals ($config, $request, $DB, $routes, $app): keep it included from the top level
 */

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

// for POST, PUT, PATCH, DELETE
Request::enableHttpMethodParameterOverride();

// debug ***************************************************************************************************************
if(!$config['debug'] && $config['debug_for_ip_auto'] && in_array(getVisitorIp(), $config['debug_for_ip']))
	$config['debug'] = true;

if($config['debug'] && isset($_GET['debug']) && $_GET['debug'] == 0)
	$config['debug'] = false;

if(!$config['debug'] && isset($_GET['debug']) && $_GET['debug'] == 1 && in_array(getVisitorIp(), $config['debug_for_ip']))
	$config['debug'] = true;

// errors (web and cli) ************************************************************************************************
if($config['debug'])
{
	\Symfony\Component\ErrorHandler\Debug::enable();
}
else
{
	\Symfony\Component\ErrorHandler\ErrorHandler::register();

	$error_template = CORE_PATH."/core/theme/@error.html.php";
	if(file_exists($error_template))
		\Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer::setTemplate($error_template);
}

// request *************************************************************************************************************
if(!APP_CLI_MODE)
{
	$request = Request::createFromGlobals();
	\Core\Session::start(\Core\Config::get('session/name'), \Core\Config::get('session/options'));
}
else
{
	// cli: php index.php [method] path
	if(count($argv) == 2)
	{
		$method = 'GET';
		list($_, $path) = $argv;
	}
	else
	{
		list($_, $method, $path) = $argv;
	}

	$request = Request::create($path, $method);
}

// db ******************************************************************************************************************
$DB = new \Core\DB();
$db_cfg = \Core\Config::get('db');

// cli overrides, ex: a cron reaching the database through another host
if(APP_CLI_MODE)
{
	foreach(['host', 'port', 'username', 'password', 'database', 'pdo_options', 'init_queries'] as $key)
	{
		$value = \Core\Config::get("cli/db/{$key}");
		if(!empty($value))$db_cfg[$key] = $value;
	}
}

$PDO = $DB->connect(
	$db_cfg['driver'],
	$db_cfg['host'],
	$db_cfg['username'],
	$db_cfg['password'],
	$db_cfg['database'],
	$db_cfg['port'],
	$db_cfg['pdo_options']
);

foreach($db_cfg['init_queries'] as $sql)
	$DB->query($sql);

// url redirect firewall ***********************************************************************************************
if(!APP_CLI_MODE)
{
	$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
	$port = $_SERVER['SERVER_PORT'];
	$is_default_port = ($scheme === 'https' && $port == 443) || ($scheme === 'http' && $port == 80);

	$url_complete = $scheme.'://'.$_SERVER['HTTP_HOST'];
	if(!$is_default_port)
		$url_complete .= ':'.$port;
	$url_complete .= $_SERVER['REQUEST_URI'];

	$recs = $DB->query("select * from xcore_url_redirect where deleted = 'no' and active = 'yes' order by position")->fetchAll();
	foreach($recs as $rec)
	{
		if(preg_match($rec['rule'], $url_complete))
		{
			http_response_code($rec['code']);
			header('Location: '.$rec['url']);
			die();
		}
	}
}

// format **************************************************************************************************************
if($request->getRequestFormat() != $request->request->get('_format', 'html'))
	$request->setRequestFormat($request->request->get('_format', 'html'));

// forced format from query string
if(isset($_GET['_format']) && in_array($_GET['_format'], ['json', 'text', 'html']) && $_GET['_format'] != $request->request->get('_format', 'html'))
	$request->setRequestFormat($_GET['_format']);

// routes **************************************************************************************************************
$routes = new RouteCollection();

// custom route files (config routes)
foreach(\Core\Config::get('routes', []) as $route_file)
	include $route_file;

// controller routes from @route annotations
foreach(\Core\Controller::getAll() as $c_controller)
{
	$info = \Core\Controller::getInfo($c_controller);
	$paths = $info['paths'];

	$class = new ReflectionClass($info['class_path']);
	foreach($class->getMethods() as $method)
	{
		$doclet = $method->getDocComment();
		if(!$doclet)continue;

		preg_match_all("/@route(.*)$/m", $doclet, $matches);
		foreach($matches[1] as $route)
		{
			$route = trim(str_replace("\t", "    ", $route));

			$tmp = explode(' {', $route, 2);
			$verbs = ['GET'];
			$route_name = "";
			$uri = str_replace('/@module/', "/{$paths[1]}/", trim($tmp[0]));
			$method_name = $method->getName();

			// parameters: {name: "x", method: "GET|POST", controller: "method"}
			if(count($tmp) == 2)
			{
				$params = explode(", ", rtrim($tmp[1], "}"));
				foreach($params as $param)
				{
					$line = explode(':', $param);
					if($line[0] == 'name')
					{
						$route_name = trim(str_erase(['"', "'"], $line[1]));
					}
					elseif($line[0] == 'method')
					{
						$verbs = explode("|", trim(str_erase(['"', "'"], $line[1])));
					}
					elseif($line[0] == 'controller')
					{
						$method_name = trim(str_erase(['"', "'"], $line[1]));
					}
					else
					{
						throw new Exception("`{$line[0]}` not correct for route `{$uri}` in `{$c_controller}`");
					}
				}
			}

			\Core\Router::match($verbs, $uri, $class->getName()."::{$method_name}", $route_name);
		}
	}
}

// frontend routes (not for backend urls)
$cur_uri = strtok($request->getRequestUri(), '?');
$backend_dirname = \Core\Config::get('backend.dirname');
$frontend_routes = CORE_PATH.'/core/route/frontend.php';
if(!preg_match("#^/{$backend_dirname}/#i", $cur_uri) && file_exists($frontend_routes))
	include $frontend_routes;

$all_routes = \Core\Router::compile();
$matcher = new UrlMatcher($all_routes, new RequestContext());

// dispatcher **********************************************************************************************************
// one request stack shared by the router listener and the kernel (sub-requests need the parent request)
$request_stack = new RequestStack();
$dispatcher = new EventDispatcher();

foreach(['Middleware', 'Debugbar', 'Controller', 'I18n'] as $event_file)
{
	$event_path = CORE_PATH."/core/event/{$event_file}.php";
	if(file_exists($event_path))
		include $event_path;
}

$dispatcher->addSubscriber(new RouterListener($matcher, $request_stack));

// kernel **************************************************************************************************************
$app = new \Core\Kernel($dispatcher, new ControllerResolver(), $request_stack, new ArgumentResolver(), $request, $DB, $PDO);
$response = $app->handle($request);
$response->send();

$app->terminate($request, $response);
