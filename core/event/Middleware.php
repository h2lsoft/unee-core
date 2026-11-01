<?php
/**
 * runs config `middlewares_before` on the main request before the router listener (priority 32), then `middlewares` after it: the route is known
 * a middleware is a php file using $request and $event, it stops the request with $event->setResponse()
 */

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

foreach(['middlewares_before' => 64, 'middlewares' => 16] as $list => $priority)
{

	$dispatcher->addListener(KernelEvents::REQUEST, function(RequestEvent $event) use ($list){

		if(!$event->isMainRequest())return;

		$request = $event->getRequest();
		foreach(\Core\Config::get($list, []) as $middleware_file)
		{

			include $middleware_file;
			if($event->hasResponse())return;

		}

	}, $priority);

}
