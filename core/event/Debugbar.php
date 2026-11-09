<?php
/**
 * debug bar (php-debugbar, dev dependency): see \Core\Debugbar::attach
 */

use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

$dispatcher->addListener(KernelEvents::RESPONSE, function(ResponseEvent $event){

	$app = App();
	if(!$event->isMainRequest() || !is_object($app->debugbar ?? null))return;

	\Core\Debugbar::attach($app->debugbar, $event->getResponse());

});
