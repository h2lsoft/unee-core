<?php

namespace Model;

/**
 * user, authentication by session
 * session keys: auth.id, auth.login, auth.email, auth.firstname, auth.lastname, auth.language, auth.group_id, auth.last_activity
 * failed attempts are logged in xcore_log (action logon_failed), counted per login and per ip for the throttle
 */
class User extends \Core\Entity
{

	public static string $table = 'xcore_user';

	/**
	 * fixed group ids (migration core_groups)
	 */
	public const int GROUP_SUPERADMIN = 1;
	public const int GROUP_SYSTEM = 2;

	/**
	 * is a user logged in the current session
	 *
	 * @return bool
	 */
	public static function isLogon():bool
	{

		return (self::getUID() > 0);

	}

	/**
	 * id of the logged user
	 *
	 * @return int 0 when nobody is logged
	 */
	public static function getUID():int
	{

		return (int)\Core\Session::get('auth.id', 0);

	}

	/**
	 * is the logged user a superadmin (rights are not checked)
	 *
	 * @return bool
	 */
	public static function isSuperAdmin():bool
	{

		return ((int)\Core\Session::get('auth.group_id', 0) === self::GROUP_SUPERADMIN);

	}

	/**
	 * logon with config auth/backend/key (email or login)
	 * unknown user, wrong password, inactive user or group without backend access give the same error (no account enumeration)
	 *
	 * @param string $login email or login, depending on auth/backend/key
	 * @param string $password
	 *
	 * @return string '' on success, 'throttled' or 'invalid'
	 */
	public static function logon(string $login, string $password):string
	{

		$throttle = \Core\Config::get('auth/logon_throttle');
		$ip = getVisitorIp();
		$since = date('Y-m-d H:i:s', time() - $throttle['window_seconds']);

		// throttled attempts are not logged: the lock ends window_seconds after the last real failure
		$failed = DB()->query("SELECT COUNT(*) FROM xcore_log WHERE action = 'logon_failed' AND deleted = 'no' AND date >= :since AND (author = :login OR ip = :ip)", ['since' => $since, 'login' => $login, 'ip' => $ip])->fetchOne();
		if($failed >= $throttle['max_attempts'])return 'throttled';

		$key = (\Core\Config::get('auth/backend/key') === 'login') ? 'login' : 'email';
		$user = DB()->query("SELECT u.*, g.access_backend
							 FROM xcore_user u
							 INNER JOIN xcore_group g ON g.id = u.xcore_group_id AND g.deleted = 'no' AND g.active = 'yes'
							 WHERE u.deleted = 'no' AND u.{$key} = :login
							 LIMIT 1", ['login' => $login])->fetch();

		$password_cfg = \Core\Config::get('auth/security/password');

		if($user)
		{

			$password_ok = password_verify($password, (string)$user['password']);

		}
		else
		{

			// unknown user: hashing costs the same as verifying with the config algo, same response time as a wrong password
			password_hash($password, $password_cfg['algo'], $password_cfg['algo_options']);
			$password_ok = false;

		}

		if(!$user || !$password_ok || $user['active'] !== 'yes' || $user['access_backend'] !== 'yes')
		{

			\Core\Log::write('logon_failed', 'logon failed', [], 0, 'warning', 'auth', mb_substr($login, 0, 255));
			return 'invalid';

		}

		// session fixation
		session_regenerate_id(true);
		foreach(['id', 'login', 'email', 'firstname', 'lastname', 'language'] as $field)
		{

			\Core\Session::set("auth.{$field}", $user[$field]);

		}
		\Core\Session::set('auth.group_id', (int)$user['xcore_group_id']);
		\Core\Session::set('auth.last_activity', time());

		$row = ['last_connection_date' => now(), 'last_connection_application' => 'backend', 'last_connection_ip' => $ip];

		// algo or cost changed in config
		if(password_needs_rehash($user['password'], $password_cfg['algo'], $password_cfg['algo_options']))
			$row['password'] = password_hash($password, $password_cfg['algo'], $password_cfg['algo_options']);

		DB(self::$table)->update($row, (int)$user['id'], 1, $user['login']);
		\Core\Log::write('logon', 'logon', [], (int)$user['id'], 'info', 'auth', $user['login']);

		return '';

	}

	/**
	 * logout: clears the session and gives a new session id
	 *
	 * @return void
	 */
	public static function logout():void
	{

		if(self::isLogon())\Core\Log::write('logout', 'logout', [], self::getUID(), 'info', 'auth');

		\Core\Session::destroy();
		session_regenerate_id(true);

	}

	/**
	 * logged user, read from the session
	 *
	 * @return array id, login, email, firstname, lastname, language, group_id (null values when nobody is logged)
	 */
	public static function getCurrent():array
	{

		$user = [];
		foreach(['id', 'login', 'email', 'firstname', 'lastname', 'language', 'group_id'] as $field)
		{

			$user[$field] = \Core\Session::get("auth.{$field}");

		}

		return $user;

	}

}
