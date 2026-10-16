<?php

// path
$function = new \Twig\TwigFunction('path', function(string $route_name):string{
	global $routes;
	$url = $routes->get($route_name)->getPath();
	return $url;
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// abs_path
$function = new \Twig\TwigFunction('abs_path', function(string $path):string{
	return get_absolute_path($path);
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// url_get_query_string
$function = new \Twig\TwigFunction('url_get_query_string', function():string{

	$qs = urldecode(http_build_query($_GET));
	$qs = preg_replace("#search\[([0-9])\]#", 'search[]', $qs);

	return $qs;
}, ['is_safe' => ['html']]);
$this->twig->addFunction($function);

// absolute_path
$function = new \Twig\TwigFunction('absolute_path', function(string $path):string{
	return get_absolute_path($path);
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// session
$function = new \Twig\TwigFunction('session', function(string $key, bool|string $default=false):string{
	return \Core\Session::get($key, $default);
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// config
$function = new \Twig\TwigFunction('config', function(string $key, bool|string $default=false):mixed{
	return \Core\Config::get($key, $default);
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// globals
$function = new \Twig\TwigFunction('globals', function(string $var, bool|string $default=false):mixed{
	return \Core\Globals::get($var, $default, false);
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// ucfirst
$function = new \Twig\TwigFunction('ucfirst', function(string $str):mixed{
	return ucfirst($str);
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// get
$function = new \Twig\TwigFunction('get', function(string $key, mixed $default='', array $list=[]):mixed{
	return get($key, $default, $list);
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// post
$function = new \Twig\TwigFunction('post', function(string $key, mixed $default='', array $list=[]):mixed{
	return post($key, $default, $list);
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// server
$function = new \Twig\TwigFunction('server', function(string $key, mixed $default=''):mixed{
	return $_SERVER[$key] ?? $default;
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);


// json
$function = new \Twig\TwigFunction('json', function(mixed $value):string{
	$encoded = json_encode($value);
	return $encoded;
	
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// json_encode
$function = new \Twig\TwigFunction('json_encode', function(mixed $value):string{
	$encoded = json_encode($value);
	return $encoded;
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// json_decode
$function = new \Twig\TwigFunction('json_decode', function(mixed $value):mixed{
	$decoded = json_decode($value, true);
	return $decoded;
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// slugify
$function = new \Twig\TwigFunction('slugify', function(string $str):string{
	
	$str = slugify($str);
	return $str;
	
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// time
$function = new \Twig\TwigFunction('time', function():float{
	return time();
}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// csrf
$function = new \Twig\TwigFunction('csrf', function():string {

	$crsf = csrf_generate();

	if(!\Core\Session::get('csrf_token'))
		\Core\Session::set('csrf_token', $crsf);

	return \Core\Session::get('csrf_token');

}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// db2date
$function = new \Twig\TwigFunction('db2date', function(string $date, string $format='', string $locale=''):string {

	return db2date($date, $format, $locale);

}, ['is_safe' => ['text']]);
$this->twig->addFunction($function);

// number_formatX
$function = new \Twig\TwigFilter('number_formatX', function(string|float $number, int $decimals_count=2, string $dec_point=".", string $thousands_sep=" ", bool $remove_decimals_zero=true):string{
	return number_formatX((float)$number, $decimals_count, $dec_point, $thousands_sep, $remove_decimals_zero);
}, ['is_safe' => ['text']]);
$this->twig->addFilter($function);