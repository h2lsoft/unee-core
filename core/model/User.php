<?php

namespace Model;

/**
 * user, authentication by session
 * session keys: auth.id, auth.login, auth.email, auth.firstname, auth.lastname, auth.language, auth.group_id, auth.last_activity, auth.password_check
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

	// pending logon waiting for its second factor: lifetime, wrong codes, delay between codes and new codes come from \Core\TwoFactor::setting()

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
	 * actions of a plugin allowed to the logged user, fail closed: unknown or inactive plugin = no right
	 * superadmin and core plugins: every action declared by the plugin, normal plugins: rights of the group (xcore_group_right)
	 *
	 * @param string $plugin route_prefix_name of xcore_plugin (folder of the module)
	 *
	 * @return array ex: ['list', 'edit']
	 */
	public static function getRights(string $plugin):array
	{

		static $cache = [];
		if(!self::isLogon())return [];
		if(isset($cache[$plugin]))return $cache[$plugin];

		$rec = \Model\Plugin::findActive($plugin);
		if(!$rec)return $cache[$plugin] = [];

		$actions = \Model\Plugin::actions($rec);
		if(self::isSuperAdmin() || $rec['type'] === 'core')return $cache[$plugin] = $actions;

		$granted = DB()->query("SELECT action FROM xcore_group_right WHERE deleted = 'no' AND xcore_group_id = :group_id AND xcore_plugin_id = :plugin_id", ['group_id' => (int)\Core\Session::get('auth.group_id', 0), 'plugin_id' => (int)$rec['id']])->fetchAllOne();

		return $cache[$plugin] = array_values(array_intersect($actions, (array)$granted));

	}

	/**
	 * the logged user has the right of an action on a plugin
	 *
	 * @param string $plugin route_prefix_name of xcore_plugin
	 * @param string $action ex: list, add, edit, delete
	 *
	 * @return bool
	 */
	public static function hasRight(string $plugin, string $action='list'):bool
	{

		return in_array($action, self::getRights($plugin), true);

	}

	/**
	 * logon with config auth/backend/key (email or login)
	 * unknown user, wrong password, inactive user or group without backend access give the same error (no account enumeration)
	 *
	 * @param string $login email or login, depending on auth/backend/key
	 * @param string $password
	 *
	 * @return string '' on success, 'throttled', 'invalid', 'two_factor' (code to type), 'two_factor_setup' (app to set up) or 'two_factor_unavailable' (code not sent: no mobile, no credits), see twoFactorCheck()
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

			\Core\Log::write('logon_failed', 'logon failed', [], (int)($user['id'] ?? 0), 'warning', 'auth', mb_substr($login, 0, 255));
			return 'invalid';

		}

		// algo or cost changed in config
		if(password_needs_rehash($user['password'], $password_cfg['algo'], $password_cfg['algo_options']))
		{

			$user['password'] = password_hash($password, $password_cfg['algo'], $password_cfg['algo_options']);
			DB(self::$table)->update(['password' => $user['password']], (int)$user['id'], 1, $user['login']);

		}

		// second factor (\Core\TwoFactor::method): the session opens once the code is checked, except on a trusted device
		$method = \Core\TwoFactor::method($user, 'backend');
		if($method !== 'none' && !\Core\TwoFactor::isTrustedDevice((int)$user['id']))
		{

			session_regenerate_id(true);
			$pending = ['user_id' => (int)$user['id'], 'method' => $method, 'expires' => time() + \Core\TwoFactor::setting('ttl'), 'attempts' => 0, 'secret' => '', 'code_hash' => '', 'code_salt' => '', 'sent_at' => 0, 'resends' => 0];

			if($method === 'authenticator')
			{

				$setup = empty($user['two_factor_secret']);
				if($setup)$pending['secret'] = \Core\TwoFactor::generateSecret();
				\Core\Session::set('two_factor', $pending);

				return $setup ? 'two_factor_setup' : 'two_factor';

			}

			// code by sms or email: sent now, the logon is refused when it cannot be sent (no mobile, no credits)
			$error = self::twoFactorSend($user, $pending);
			if($error === 'throttled')return 'throttled';
			if($error !== '')return 'two_factor_unavailable';

			return 'two_factor';

		}

		self::completeLogon($user);
		return '';

	}

	/**
	 * pending logon waiting for its second factor (password checked less than auth.two_factor.code.ttl seconds ago)
	 *
	 * @return array {"user": row of xcore_user, "method", "secret": base32 secret to set up ('' when the user has one), "sent_at", "resends"}, [] when none
	 */
	public static function twoFactorPending():array
	{

		$pending = (array)\Core\Session::get('two_factor', []);
		if(empty($pending['user_id']) || $pending['expires'] < time())return [];

		$user = DB()->query("SELECT u.*, g.access_backend
							 FROM xcore_user u
							 INNER JOIN xcore_group g ON g.id = u.xcore_group_id AND g.deleted = 'no' AND g.active = 'yes'
							 WHERE u.deleted = 'no' AND u.id = :id
							 LIMIT 1", ['id' => (int)$pending['user_id']])->fetch();

		if(!$user || $user['active'] !== 'yes' || $user['access_backend'] !== 'yes')return [];

		return ['user' => $user, 'method' => (string)($pending['method'] ?? 'authenticator'), 'secret' => (string)$pending['secret'], 'sent_at' => (int)($pending['sent_at'] ?? 0), 'resends' => (int)($pending['resends'] ?? 0)];

	}

	/**
	 * checks the code of a pending logon (twoFactorPending) and opens the session, the secret is saved on setup
	 * each wrong code counts as a failed logon (auth/logon_throttle), after auth.two_factor.code.max_attempts the password is asked again
	 *
	 * @param string $code 6 digits of the app, of the sms or of the email
	 * @param bool $trust_device remember the device (auth.backend.two_factor.trust_days)
	 *
	 * @return string '' on success, 'expired' (password to type again) or 'invalid'
	 */
	public static function twoFactorCheck(string $code, bool $trust_device):string
	{

		$pending = self::twoFactorPending();
		if(!$pending)
		{

			\Core\Session::destroy('two_factor');
			return 'expired';

		}

		$user = $pending['user'];
		$session = (array)\Core\Session::get('two_factor', []);
		$step = 0;

		if($pending['method'] === 'authenticator')
		{

			$setup = ($pending['secret'] !== '');
			$secret = $setup ? $pending['secret'] : \Core\TwoFactor::decrypt((string)$user['two_factor_secret']);
			$step = ($secret === '') ? 0 : \Core\TwoFactor::verify($secret, $code, $setup ? 0 : (int)$user['two_factor_last_step']);
			$valid = ($step > 0);

		}
		else
		{

			$hash = (string)($session['code_hash'] ?? '');
			$valid = ($hash !== '' && hash_equals($hash, \Core\TwoFactor::codeHash($code, (string)($session['code_salt'] ?? ''))));

		}

		if(!$valid)
		{

			\Core\Log::write('logon_failed', 'two-factor code invalid', ['method' => $pending['method']], (int)$user['id'], 'warning', 'auth', (string)$user['login']);

			$attempts = (int)($session['attempts'] ?? 0) + 1;
			if($attempts >= \Core\TwoFactor::setting('max_attempts'))
			{

				\Core\Session::destroy('two_factor');
				return 'expired';

			}

			\Core\Session::set('two_factor', ['attempts' => $attempts] + $session);
			return 'invalid';

		}

		if($pending['method'] === 'authenticator')
		{

			$row = ['two_factor_last_step' => $step];
			if($setup)$row += ['two_factor_secret' => \Core\TwoFactor::encrypt($secret), 'two_factor_enabled_at' => now()];
			DB(self::$table)->update($row, (int)$user['id'], 1, $user['login']);

		}

		\Core\Session::destroy('two_factor');
		self::completeLogon($user);
		if($trust_device)\Core\TwoFactor::trustDevice((int)$user['id']);

		return '';

	}

	/**
	 * new code by sms or email for the pending logon, after auth.two_factor.code.resend_delay, at most auth.two_factor.code.max_resends times
	 * the code sent before is no longer valid, the pending logon lasts auth.two_factor.code.ttl again
	 *
	 * @return string '' when sent, 'expired' (password to type again), 'not_allowed' (authenticator app), 'too_early', 'max_resends', 'throttled' or the error of \Core\TwoFactor::sendCode()
	 */
	public static function twoFactorResend():string
	{

		$pending = self::twoFactorPending();
		if(!$pending)
		{

			\Core\Session::destroy('two_factor');
			return 'expired';

		}

		if(!in_array($pending['method'], ['sms', 'email'], true))return 'not_allowed';
		if($pending['resends'] >= \Core\TwoFactor::setting('max_resends'))return 'max_resends';
		if(time() - $pending['sent_at'] < \Core\TwoFactor::setting('resend_delay'))return 'too_early';

		$session = (array)\Core\Session::get('two_factor', []);
		$session['resends'] = $pending['resends'] + 1;
		$session['expires'] = time() + \Core\TwoFactor::setting('ttl');

		$error = self::twoFactorSend($pending['user'], $session);
		if($error === 'throttled' || $error === '')return $error;

		// not sent: the pending logon ends, the password is asked again
		\Core\Session::destroy('two_factor');
		return $error;

	}

	/**
	 * timing of the pending logon for the sign in page: method, masked destination, seconds before a new code, new codes left
	 *
	 * @return array {"method", "destination", "resend_in", "resends_left"}, [] when none
	 */
	public static function twoFactorState():array
	{

		$pending = self::twoFactorPending();
		if(!$pending)return [];

		$method = $pending['method'];
		$state = ['method' => $method, 'destination' => '', 'resend_in' => 0, 'resends_left' => 0];
		if($method === 'sms' || $method === 'email')
		{

			$state['destination'] = \Core\TwoFactor::mask((string)$pending['user'][($method === 'sms') ? 'mobile' : 'email'], $method);
			$state['resend_in'] = max(0, \Core\TwoFactor::setting('resend_delay') - (time() - $pending['sent_at']));
			$state['resends_left'] = max(0, \Core\TwoFactor::setting('max_resends') - $pending['resends']);

		}

		return $state;

	}

	/**
	 * sends a new code of the pending logon (sms or email) and keeps its hash in the session
	 * sending throttled by account: (1 + max_resends) * 2 codes in the window of auth/logon_throttle (a shared office ip is never blocked)
	 *
	 * @param array $user row of xcore_user
	 * @param array $session pending logon to save
	 *
	 * @return string '' when sent, 'throttled' or the error of \Core\TwoFactor::sendCode() (logged two_factor_unavailable)
	 */
	private static function twoFactorSend(array $user, array $session):string
	{

		$throttle = \Core\Config::get('auth/logon_throttle');
		$since = date('Y-m-d H:i:s', time() - (int)$throttle['window_seconds']);
		$sent = (int)DB()->query("SELECT COUNT(*) FROM xcore_log WHERE action = 'two_factor_code_sent' AND deleted = 'no' AND date >= :since AND record_id = :id", ['since' => $since, 'id' => (int)$user['id']])->fetchOne();
		if($sent >= (1 + \Core\TwoFactor::setting('max_resends')) * 2)
		{

			\Core\Session::destroy('two_factor');
			return 'throttled';

		}

		$code = \Core\TwoFactor::newCode();
		$error = \Core\TwoFactor::sendCode($user, $session['method'], $code);
		if($error !== '')
		{

			\Core\Session::destroy('two_factor');
			\Core\Log::write('two_factor_unavailable', "sign-in code not sent: {$error}", ['method' => $session['method'], 'reason' => $error], (int)$user['id'], 'error', 'auth', (string)$user['login']);
			return $error;

		}

		$session['code_salt'] = bin2hex(random_bytes(16));
		$session['code_hash'] = \Core\TwoFactor::codeHash($code, $session['code_salt']);
		$session['sent_at'] = time();
		\Core\Session::set('two_factor', $session);
		\Core\Log::write('two_factor_code_sent', "sign-in code sent by {$session['method']}", ['method' => $session['method'], 'resend' => (int)$session['resends']], (int)$user['id'], 'info', 'auth', (string)$user['login']);

		return '';

	}

	/**
	 * last step of a logon: connection columns, session, log
	 *
	 * @param array $user row of xcore_user
	 *
	 * @return void
	 */
	private static function completeLogon(array $user):void
	{

		$ip = getVisitorIp();
		$row = ['last_connection_date' => now(), 'last_connection_application' => 'backend', 'last_connection_ip' => $ip, 'last_connection_plugin' => null];
		DB(self::$table)->update($row, (int)$user['id'], 1, $user['login']);

		// ip never seen for this user over 90 days: mail to the user (not on his first logon)
		$history = DB()->query("SELECT COUNT(*) AS total, COALESCE(SUM(ip = :ip), 0) AS known FROM xcore_log WHERE deleted = 'no' AND action = 'logon' AND record_id = :id AND date >= DATE_SUB(NOW(), INTERVAL 90 DAY)", ['id' => (int)$user['id'], 'ip' => $ip])->fetch();
		if((int)$history['total'] > 0 && !(int)$history['known'])
		{

			$sent = \Core\Mailer::sendTemplate('core.logon.new_ip', $user['email'], ['firstname' => $user['firstname'], 'date' => date('Y-m-d H:i'), 'ip' => $ip, 'browser' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)], (string)$user['language'], [], ['module' => 'auth', 'action' => 'logon_new_ip', 'record_id' => (int)$user['id'], 'xcore_user_id' => (int)$user['id']]);
			if(!$sent)\Core\Log::write('logon_new_ip', 'new ip mail not sent: '.\Core\Mailer::getLastError(), [], (int)$user['id'], 'error', 'auth', $user['login']);

		}

		self::openSession($user);
		\Core\Log::write('logon', 'logon', [], (int)$user['id'], 'info', 'auth', $user['login']);

	}

	/**
	 * session of a user (logon, connect as), new session id against session fixation
	 *
	 * @param array $user row of xcore_user
	 *
	 * @return void
	 */
	public static function openSession(array $user):void
	{

		session_regenerate_id(true);
		foreach(['id', 'login', 'email', 'firstname', 'lastname', 'language'] as $field)
		{

			\Core\Session::set("auth.{$field}", $user[$field]);

		}
		\Core\Session::set('auth.group_id', (int)$user['xcore_group_id']);
		\Core\Session::set('auth.last_activity', time());
		\Core\Session::set('auth.password_check', self::passwordCheck((string)$user['password']));
		\Core\Session::set('auth.plugin', '');

	}

	/**
	 * fingerprint of the stored password hash, kept in the session: a password change invalidates every open session (auth.api middleware)
	 *
	 * @param string $password_hash value of xcore_user.password
	 *
	 * @return string
	 */
	public static function passwordCheck(string $password_hash):string
	{

		return hash('sha256', $password_hash);

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
		$sent = \Core\Mailer::sendTemplate('core.password.forgot', $user['email'], ['firstname' => $user['firstname'], 'link' => $link, 'hours' => $hours], (string)$user['language'], [], ['module' => 'auth', 'action' => 'password_forgot', 'record_id' => (int)$user['id'], 'xcore_user_id' => (int)$user['id']]);

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

		$user = DB()->query("SELECT id, login, email, firstname, language
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

		// confirmation to the owner of the account: an unexpected change is noticed at once
		$sent = \Core\Mailer::sendTemplate('core.password.changed', $user['email'], ['firstname' => $user['firstname'], 'date' => date('Y-m-d H:i'), 'ip' => getVisitorIp()], (string)$user['language'], [], ['module' => 'auth', 'action' => 'password_changed', 'record_id' => (int)$user['id'], 'xcore_user_id' => (int)$user['id']]);
		if(!$sent)
			\Core\Log::write('password_changed_mail', \Core\Mailer::getLastError(), [], (int)$user['id'], 'error', 'auth', $user['login']);

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
	 * @return array id, login, email, firstname, lastname, language, group_id, group_name (null values when nobody is logged)
	 */
	public static function getCurrent():array
	{

		$user = [];
		foreach(['id', 'login', 'email', 'firstname', 'lastname', 'language', 'group_id'] as $field)
		{

			$user[$field] = \Core\Session::get("auth.{$field}");

		}

		$user['group_name'] = (string)DB()->query("SELECT name FROM xcore_group WHERE id = :id", ['id' => (int)$user['group_id']])->fetchOne();

		// connect as: login of the account to go back to, null otherwise
		$user['impersonator'] = \Core\Session::get('auth.impersonator')['login'] ?? null;

		// picture of the header, read from the table: a change in the users module shows at the next load
		$avatar = (string)DB()->query("SELECT avatar FROM xcore_user WHERE id = :id", ['id' => (int)$user['id']])->fetchOne();
		$user['avatar'] = \Core\Widget::avatar(['id' => $user['id'], 'avatar' => $avatar])['avatar']['url'];

		return $user;

	}

}
