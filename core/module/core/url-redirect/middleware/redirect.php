<?php
/**
 * url.redirect middleware (config middlewares_before, before the router: urls without route are redirected too)
 * the backend is answered before the kernel: run.php applies the same redirects to it while this middleware is declared
 */

if($response = \Plugin\Core\Url_RedirectController::redirect($request))
	$event->setResponse($response);
