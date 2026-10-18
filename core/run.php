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

// log in xcore_log, chained to the symfony handler
\Core\ErrorHandler::register();

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
$DB = \Core\DB::connectFromConfig();
$PDO = $DB->getConnectionLink();

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

// controller routes from the symfony #[Route] attributes (inherited methods included, prefix and name prefix on the class)
$route_loader = new class extends \Symfony\Component\Routing\Loader\AttributeClassLoader
{
	protected function configureRoute(\Symfony\Component\Routing\Route $route, \ReflectionClass $class, \ReflectionMethod $method, object $attr):void
	{
		$route->setDefault('_controller', $class->getName().'::'.$method->getName());
	}
};

foreach(\Core\Controller::getAll() as $c_controller)
{
	$class = new ReflectionClass(\Core\Controller::getInfo($c_controller)['class_path']);
	if($class->isAbstract())continue;

	$controller_routes = $route_loader->load($class->getName());
	foreach($controller_routes->all() as $route_name => $route)
	{
		// rule: a route ends with a slash (files like /api/openapi.json excepted)
		if(!str_ends_with($route->getPath(), '/') && !str_contains(basename($route->getPath()), '.'))
			throw new \LogicException("Route \"{$route->getPath()}\" ({$route_name}) must end with a slash");

		// {id} and {page} are digits unless the route says otherwise
		foreach(['id', 'page'] as $placeholder)
		{
			if(str_contains($route->getPath(), '{'.$placeholder.'}') && !$route->hasRequirement($placeholder))
				$route->setRequirement($placeholder, '\d+');
		}
	}

	$routes->addCollection($controller_routes);
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

foreach(['Middleware', 'Api', 'Debugbar', 'Controller', 'I18n'] as $event_file)
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
