<?php
/**
 * api (/api/): csrf check on unsafe methods, json errors
 * csrf: the browser sends the session cookie, a cross-site page cannot add a custom header without a cors preflight
 */

use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

// csrf, before the router (priority 32): unknown routes are refused the same way
$dispatcher->addListener(KernelEvents::REQUEST, function(RequestEvent $event){

	$request = $event->getRequest();
	if(!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/'))return;

	if(!$request->isMethodSafe() && $request->headers->get('X-Requested-With') !== 'XMLHttpRequest')
	{

		$event->setResponse(new \Core\JsonResponse(['error' => ['code' => 403, 'message' => 'missing header X-Requested-With: XMLHttpRequest']], 403));

	}

}, 64);

// json errors, 5xx details only in debug mode
$dispatcher->addListener(KernelEvents::EXCEPTION, function(ExceptionEvent $event){

	if(!str_starts_with($event->getRequest()->getPathInfo(), '/api/'))return;

	$e = $event->getThrowable();
	$is_http = ($e instanceof HttpExceptionInterface);
	$code = ($is_http) ? $e->getStatusCode() : 500;
	$message = ($code < 500 || \Core\Config::isDebug()) ? $e->getMessage() : 'internal server error';

	$event->setResponse(new \Core\JsonResponse(['error' => ['code' => $code, 'message' => $message]], $code, ($is_http) ? $e->getHeaders() : []));

});
