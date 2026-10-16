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
	 * too many requests of an action in the window of auth/logon_throttle, counted per author (login typed) and per ip
	 *
	 * @param string $action xcore_log action, ex: logon_failed, password_forgot
	 * @param string $login
	 *
	 * @return bool
	 */
	public static function isThrottled(string $action, string $login):bool
	{

		$throttle = \Core\Config::get('auth/logon_throttle');
		$since = date('Y-m-d H:i:s', time() - $throttle['window_seconds']);
		$count = DB()->query("SELECT COUNT(*) FROM xcore_log WHERE action = :action AND deleted = 'no' AND date >= :since AND (author = :login OR ip = :ip)", ['action' => $action, 'since' => $since, 'login' => $login, 'ip' => getVisitorIp()])->fetchOne();

		return ($count >= $throttle['max_attempts']);

	}

	/**
	 * password recovery: stores the hash of a one-time token and mails the reset link
	 * silent whatever happens (unknown account, mail error): the caller always gives the same answer (no account enumeration)
	 * the link is built from config url: set it in the instance config in production (HTTP_HOST comes from the client)
	 *
	 * @param string $login email or login, depending on auth/backend/key
	 *
	 * @return void
	 */
	public static function passwordForgot(string $login):void
	{

		$key = (\Core\Config::get('auth/backend/key') === 'login') ? 'login' : 'email';
		$user = DB()->query("SELECT u.id, u.login, u.email, u.firstname, u.language
							 FROM xcore_user u
							 INNER JOIN xcore_group g ON g.id = u.xcore_group_id AND g.deleted = 'no' AND g.active = 'yes' AND g.access_backend = 'yes'
							 WHERE u.deleted = 'no' AND u.active = 'yes' AND u.{$key} = :login
							 LIMIT 1", ['login' => $login])->fetch();

		\Core\Log::write('password_forgot', 'password recovery requested', [], (int)($user['id'] ?? 0), 'info', 'auth', mb_substr($login, 0, 255));
		if(!$user)return;

		// only the hash is stored: a database leak gives no usable link
		$token = bin2hex(random_bytes(32));
		$hours = (int)\Core\Config::get('auth/password_reset_token_expiration_hours', 12);

		$row = [];
		$row['password_token'] = hash('sha256', $token);
		$row['password_token_date'] = now();
		$row['password_token_expiration_date'] = date('Y-m-d H:i:s', time() + $hours * 3600);
		DB(self::$table)->update($row, (int)$user['id'], 1, 'system');

		$link = rtrim(\Core\Config::get('url'), '/').'/'.\Core\Config::get('backend.dirname').'/password/reset/?token='.$token;
		$sent = \Core\Mailer::sendTemplate('core.password.forgot', $user['email'], ['firstname' => $user['firstname'], 'link' => $link, 'hours' => $hours], (string)$user['language']);

		if(!$sent)
			\Core\Log::write('password_forgot_mail', \Core\Mailer::getLastError(), [], (int)$user['id'], 'error', 'auth', $user['login']);

	}

	/**
	 * password reset with the token of the recovery link: one-time, expires after auth/password_reset_token_expiration_hours
	 *
	 * @param string $token token of the link (64 hex characters)
	 * @param string $password new password, already validated by the caller
	 *
	 * @return bool false when the token is unknown, used or expired
	 */
	public static function passwordReset(string $token, string $password):bool
	{

		if(!preg_match('/^[a-f0-9]{64}$/', $token))return false;

		$user = DB()->query("SELECT id, login
							 FROM xcore_user
							 WHERE deleted = 'no' AND active = 'yes' AND password_token = :token AND password_token_expiration_date >= :now
							 LIMIT 1", ['token' => hash('sha256', $token), 'now' => now()])->fetch();
		if(!$user)return false;

		$password_cfg = \Core\Config::get('auth/security/password');

		$row = [];
		$row['password'] = password_hash($password, $password_cfg['algo'], $password_cfg['algo_options']);
		$row['password_token'] = null;
		$row['password_token_date'] = null;
		$row['password_token_expiration_date'] = null;
		DB(self::$table)->update($row, (int)$user['id'], 1, $user['login']);

		\Core\Log::write('password_reset', 'password reset', [], (int)$user['id'], 'info', 'auth', $user['login']);

		return true;

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
