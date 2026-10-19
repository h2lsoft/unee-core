<?php
/**
 * debug bar (php-debugbar, dev dependency) added at the end of the html pages when config debug and debug_bar are on
 * its css and js are inlined: the vendor folder is never served
 */

use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

$dispatcher->addListener(KernelEvents::RESPONSE, function(ResponseEvent $event){

	$app = App();
	if(!$event->isMainRequest() || !is_object($app->debugbar ?? null))return;

	$response = $event->getResponse();
	$content = (string)$response->getContent();
	if(!str_contains((string)$response->headers->get('Content-Type', 'text/html'), 'html') || !str_contains($content, '</body>'))return;

	$renderer = $app->debugbar->getJavascriptRenderer();
	$css = $renderer->dumpCssAssets(null, false);
	$js = str_ireplace('</script', '<\/script', $renderer->dumpJsAssets(null, false));
	$bar = "<style>{$css}</style>\n".$renderer->dumpHeadAssets(null, false)."<script>{$js}</script>\n".$renderer->render();

	$pos = strripos($content, '</body>');
	$response->setContent(substr($content, 0, $pos).$bar."\n".substr($content, $pos));

});
