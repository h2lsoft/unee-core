<?php
/**
 * auth.api middleware: a logged user is required under /api/, except public routes (config auth/api/public_routes)
 * idle timeout: logout after auth/api/idle_timeout_seconds without request (does not rely on the server session gc)
 * revocation: the user and its group are checked in the database on each request (inactive or deleted = logout)
 * password change: the session keeps a fingerprint of the password hash, another hash = logout of every open session
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

$account = DB()->query("SELECT u.password
						FROM xcore_user u
						INNER JOIN xcore_group g ON g.id = u.xcore_group_id AND g.deleted = 'no' AND g.active = 'yes' AND g.access_backend = 'yes'
						WHERE u.id = :id AND u.deleted = 'no' AND u.active = 'yes'", ['id' => \Model\User::getUID()])->fetch();

if(!$account)
{

	\Model\User::logout();
	$event->setResponse(\Core\JsonResponse::error(401, 'account disabled'));
	return;

}

if(!hash_equals((string)\Core\Session::get('auth.password_check', ''), \Model\User::passwordCheck((string)$account['password'])))
{

	\Model\User::logout();
	$event->setResponse(\Core\JsonResponse::error(401, 'password changed, sign in again'));
	return;

}

\Core\Session::set('auth.last_activity', time());
