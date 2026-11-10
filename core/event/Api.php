<?php
/**
 * api (/api/): csrf check on unsafe methods, json body, json errors
 * csrf: the browser sends the session cookie, a cross-site page cannot add a custom header without a cors preflight
 */

use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

// csrf, after the router (priority 32) and before the auth middlewares (16): a route called by a third party with a secret token in its url declares defaults: ['_csrf' => false] (ex: delivery reports of a sms provider, one-click unsubscribe of the mail clients)
$dispatcher->addListener(KernelEvents::REQUEST, function(RequestEvent $event){

	$request = $event->getRequest();
	if(!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/') || $request->attributes->get('_csrf') === false)return;

	if(!$request->isMethodSafe() && $request->headers->get('X-Requested-With') !== 'XMLHttpRequest')$event->setResponse(\Core\JsonResponse::error(403, 'missing header X-Requested-With: XMLHttpRequest'));

}, 24);

// json body, before the router
$dispatcher->addListener(KernelEvents::REQUEST, function(RequestEvent $event){

	$request = $event->getRequest();
	if(!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/'))return;

	// json body: available as $request->request and $_POST (read by the h2lsoft validator)
	if(str_contains((string)$request->headers->get('Content-Type'), 'json') && $request->getContent() !== '')
	{

		try
		{

			$data = $request->toArray();

		}
		catch(\Symfony\Component\HttpFoundation\Exception\JsonException $e)
		{

			$event->setResponse(\Core\JsonResponse::error(400, 'invalid json body'));
			return;

		}

		$request->request->replace($data);
		$_POST = $data;

	}

}, 64);

// json errors, 5xx details only in debug mode, 5xx logged (xcore_log) for every url
$dispatcher->addListener(KernelEvents::EXCEPTION, function(ExceptionEvent $event){

	$e = $event->getThrowable();
	$is_http = ($e instanceof HttpExceptionInterface);
	$code = ($is_http) ? $e->getStatusCode() : 500;

	if($code >= 500)\Core\ErrorHandler::logThrowable($e);
	if(!str_starts_with($event->getRequest()->getPathInfo(), '/api/'))return;
	$message = ($code < 500 || \Core\Config::isDebug()) ? $e->getMessage() : 'internal server error';

	$event->setResponse(\Core\JsonResponse::error($code, $message, [], ($is_http) ? $e->getHeaders() : []));

});
