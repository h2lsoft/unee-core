<?php
/**
 * runs config `middlewares` on the main request, after the router listener (priority 32): the route is known
 * a middleware is a php file using $request and $event, it stops the request with $event->setResponse()
 */

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

$dispatcher->addListener(KernelEvents::REQUEST, function(RequestEvent $event){

	if(!$event->isMainRequest())return;

	$request = $event->getRequest();
	foreach(\Core\Config::get('middlewares', []) as $middleware_file)
	{

		include $middleware_file;
		if($event->hasResponse())return;

	}

}, 16);
