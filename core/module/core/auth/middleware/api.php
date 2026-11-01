<?php
/**
 * auth.api middleware: a logged user is required under /api/, except public routes (config auth/api/public_routes, or defaults: ['_public' => true] on the route)
 * idle timeout: logout after auth/api/idle_timeout_seconds without request (does not rely on the server session gc)
 * revocation: the user and its group are checked in the database on each request (inactive or deleted = logout, group refreshed)
 * password change: the session keeps a fingerprint of the password hash, another hash = logout of every open session
 */

if(!str_starts_with($request->getPathInfo(), '/api/'))return;
if(in_array($request->attributes->get('_route'), \Core\Config::get('auth/api/public_routes', []), true))return;
if($request->attributes->get('_public') === true)return;

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

$account = DB()->query("SELECT u.password, u.xcore_group_id
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

// group changed by an admin: rights follow without a new logon
\Core\Session::set('auth.group_id', (int)$account['xcore_group_id']);
\Core\Session::set('auth.last_activity', time());

// plugin the user is in, saved on change for the dashboard (last active users), routes outside a plugin (auth, i18n...) ignored
$controller = $request->attributes->get('_controller');
$class = is_string($controller) ? explode('::', $controller)[0] : '';
if($class && class_exists($class))
{

	$module = basename(dirname((new \ReflectionClass($class))->getFileName()));
	if($module !== \Core\Session::get('auth.plugin'))
	{

		\Core\Session::set('auth.plugin', $module);
		if($module === 'dashboard' || DB()->query("SELECT id FROM xcore_plugin WHERE route_prefix_name = :module AND deleted = 'no' LIMIT 1", ['module' => $module])->fetchOne())
			DB()->query("UPDATE xcore_user SET last_connection_plugin = :module WHERE id = :id", ['module' => $module, 'id' => \Model\User::getUID()]);

	}

}
