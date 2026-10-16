<?php
/**
 * auth.api middleware: a logged user is required under /api/, except public routes (config auth/api/public_routes)
 * idle timeout: logout after auth/api/idle_timeout_seconds without request (does not rely on the server session gc)
 * revocation: the user and its group are checked in the database on each request (inactive or deleted = logout)
 */

if(!str_starts_with($request->getPathInfo(), '/api/'))return;
if(in_array($request->attributes->get('_route'), \Core\Config::get('auth/api/public_routes', []), true))return;

if(!\Model\User::isLogon())
{

	$event->setResponse(\Core\JsonResponse::error(401, 'authentication required'));
	return;

}

$idle_timeout = (int)\Core\Config::get('auth/api/idle_timeout_seconds', 0);
$last_activity = (int)\Core\Session::get('auth.last_activity', 0);

if($idle_timeout > 0 && $last_activity > 0 && (time() - $last_activity) > $idle_timeout)
{

	\Model\User::logout();
	$event->setResponse(\Core\JsonResponse::error(401, 'session expired'));
	return;

}

$allowed = DB()->query("SELECT COUNT(*)
						FROM xcore_user u
						INNER JOIN xcore_group g ON g.id = u.xcore_group_id AND g.deleted = 'no' AND g.active = 'yes' AND g.access_backend = 'yes'
						WHERE u.id = :id AND u.deleted = 'no' AND u.active = 'yes'", ['id' => \Model\User::getUID()])->fetchOne();

if(!$allowed)
{

	\Model\User::logout();
	$event->setResponse(\Core\JsonResponse::error(401, 'account disabled'));
	return;

}

\Core\Session::set('auth.last_activity', time());
