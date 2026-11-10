<?php

namespace Core;

/**
 * second factor of the sign in: authenticator app (totp rfc 6238, 6 digits, 30 s, sha1), code by sms, code by email, or none
 *
 * - method of the application: config auth/security/<application>/two_factor/method, a user can have his own (xcore_user.two_factor_method, '' = the application)
 *   among the methods allowed by config auth/security/<application>/two_factor/allowed_methods (method => true / false)
 * - code by sms or email: 6 digits, only its hash kept in the pending logon (session), config auth/security/two_factor/code/* (ttl, max_attempts, resend_delay, max_resends)
 *   sms: a mobile and sms credits (\Core\Sms) needed, email: email credits (\Core\Mailer) needed, never one instead of the other
 * - trusted devices: config auth/security/<application>/two_factor/trust_days, cookie unee_trusted_device, its sha256 in xcore_user_trusted_device
 * - secrets encrypted with config auth/security/encryption_key (32 bytes base64, per instance: php vendor/bin/unee key:generate)
 */
class TwoFactor
{

	public const int DIGITS = 6;
	public const int PERIOD = 30;
	public const array METHODS = ['authenticator', 'sms', 'email', 'none'];

	// english labels of the methods (translated by the grids, labels() for the forms)
	public const array LABELS = ['authenticator' => 'Authenticator app', 'sms' => 'SMS', 'email' => 'Email', 'none' => 'None'];

	// code by sms or email, config auth/security/two_factor/code/<name>: [default, min, max], values kept in their bounds
	public const array CODE_SETTINGS = [
		'ttl' => [300, 60, 900],
		'max_attempts' => [5, 3, 10],
		'resend_delay' => [60, 30, 300],
		'max_resends' => [3, 0, 5],
	];

	// key of the core default config before the keys by instance: public, only read to re-encrypt (key:generate --rotate)
	public const string LEGACY_KEY = 'nlZhzqppHPjTKTovQJ/5eFYcxNV5tw3FDYpsFl7uwlw=';

	private const string TRUST_COOKIE = 'unee_trusted_device';
	private const string BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	// settings *********************************************************************************************************

	/**
	 * methods a user can get in the application: config auth/security/<application>/two_factor/allowed_methods (method => true / false)
	 * a map, not a list: the config of an instance overrides one method without replacing the others (array_replace_recursive)
	 *
	 * @param string $application backend or frontend
	 *
	 * @return array methods in the order of METHODS
	 */
	public static function allowedMethods(string $application='backend'):array
	{

		$allowed = Config::get("auth/security/{$application}/two_factor/allowed_methods", []);
		if(!is_array($allowed) || !$allowed)return self::METHODS;

		$methods = [];
		foreach(self::METHODS as $method)
		{

			if(!empty($allowed[$method]))$methods[] = $method;

		}

		return $methods;

	}

	/**
	 * method of the application: config auth/security/<application>/two_factor/method
	 * a value out of the list or not allowed is read as authenticator (never none)
	 * old key before the methods: auth/security/<application>/two_factor/enabled (true = authenticator, false = none)
	 *
	 * @param string $application backend or frontend
	 *
	 * @return string authenticator, sms, email or none
	 */
	public static function globalMethod(string $application='backend'):string
	{

		$method = Config::get("auth/security/{$application}/two_factor/method", '');
		if($method === '' || $method === false || $method === null)
		{

			$enabled = Config::get("auth/security/{$application}/two_factor/enabled", 'missing');
			if($enabled === 'missing')$enabled = ($application === 'backend');
			$method = ($enabled) ? 'authenticator' : 'none';

		}

		$method = trim((string)$method);
		if(!in_array($method, self::METHODS, true) || !in_array($method, self::allowedMethods($application), true))return 'authenticator';

		return $method;

	}

	/**
	 * method of a user: his own when allowed in the application, otherwise the one of the application
	 *
	 * @param array $user row of xcore_user
	 * @param string $application backend or frontend
	 *
	 * @return string authenticator, sms, email or none
	 */
	public static function method(array $user, string $application='backend'):string
	{

		$method = (string)($user['two_factor_method'] ?? '');
		if(in_array($method, self::METHODS, true) && in_array($method, self::allowedMethods($application), true))return $method;

		return self::globalMethod($application);

	}

	/**
	 * a second factor can be asked in the application: method of the application, or a user with his own allowed method
	 *
	 * @param string $application backend or frontend
	 *
	 * @return bool
	 */
	public static function enabled(string $application='backend'):bool
	{

		if(self::globalMethod($application) !== 'none')return true;

		$methods = array_values(array_diff(self::allowedMethods($application), ['none']));
		if(!$methods)return false;

		// values of the fixed list METHODS
		$in = "'".implode("', '", $methods)."'";
		return (bool)DB()->query("SELECT COUNT(*) FROM xcore_user WHERE deleted = 'no' AND two_factor_method IN ({$in})")->fetchOne();

	}

	/**
	 * labels of the methods (selects, badges), translated
	 *
	 * @return array method => label
	 */
	public static function labels():array
	{

		return array_map('ts', self::LABELS);

	}

	/**
	 * days a device stays trusted ("trust this device"), 0 = never: config auth/security/<application>/two_factor/trust_days (0-365)
	 *
	 * @param string $application backend or frontend
	 *
	 * @return int
	 */
	public static function trustDays(string $application='backend'):int
	{

		return max(0, min(365, (int)Config::get("auth/security/{$application}/two_factor/trust_days", 30)));

	}

	/**
	 * setting of the codes: config auth/security/two_factor/code/<name>, kept in its bounds (a typo never weakens the sign in)
	 *
	 * @param string $name ttl, max_attempts, resend_delay or max_resends
	 *
	 * @return int
	 */
	public static function setting(string $name):int
	{

		[$default, $min, $max] = self::CODE_SETTINGS[$name];
		return max($min, min($max, (int)Config::get("auth/security/two_factor/code/{$name}", $default)));

	}

	// authenticator app (totp) *****************************************************************************************

	/**
	 * new secret, 160 bits in base32 (shown to the user and in the qr code)
	 *
	 * @return string
	 */
	public static function generateSecret():string
	{

		return self::base32Encode(random_bytes(20));

	}

	/**
	 * otpauth uri of the qr code
	 *
	 * @param string $secret base32
	 * @param string $account login or email of the user
	 *
	 * @return string
	 */
	public static function uri(string $secret, string $account):string
	{

		$issuer = (string)Config::get('name', 'Unee');
		return 'otpauth://totp/'.rawurlencode("{$issuer}:{$account}").'?'.http_build_query(['secret' => $secret, 'issuer' => $issuer, 'digits' => self::DIGITS, 'period' => self::PERIOD]);

	}

	/**
	 * checks a code at the current time, one step of tolerance on both sides (clock drift)
	 *
	 * @param string $secret base32
	 * @param string $code digits typed by the user
	 * @param int $last_step step of the last accepted code, a code is never accepted twice (replay)
	 *
	 * @return int matched step, 0 = invalid
	 */
	public static function verify(string $secret, string $code, int $last_step=0):int
	{

		$code = preg_replace('/\D/', '', $code);
		if(strlen($code) !== self::DIGITS)return 0;

		$key = self::base32Decode($secret);
		$now = intdiv(time(), self::PERIOD);
		for($step = $now - 1; $step <= $now + 1; $step++)
		{

			if($step <= $last_step)continue;
			if(hash_equals(self::code($key, $step), $code))return $step;

		}

		return 0;

	}

	/**
	 * code of a time step (rfc 4226 dynamic truncation)
	 *
	 * @param string $key raw secret
	 * @param int $step
	 *
	 * @return string
	 */
	public static function code(string $key, int $step):string
	{

		$hash = hash_hmac('sha1', pack('J', $step), $key, true);
		$offset = ord($hash[19]) & 0x0f;
		$value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

		return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);

	}

	// code by sms or email *********************************************************************************************

	/**
	 * new code, 6 random digits
	 *
	 * @return string
	 */
	public static function newCode():string
	{

		return str_pad((string)random_int(0, 10 ** self::DIGITS - 1), self::DIGITS, '0', STR_PAD_LEFT);

	}

	/**
	 * hash of a code kept in the pending logon, the salt is random by pending logon
	 *
	 * @param string $code
	 * @param string $salt
	 *
	 * @return string
	 */
	public static function codeHash(string $code, string $salt):string
	{

		return hash_hmac('sha256', preg_replace('/\D/', '', $code), $salt);

	}

	/**
	 * sends a code by sms or email, in the language of the user
	 * sms: one-off of the module SMS (1 credit or more), the code is masked in its message once sent
	 * email: mail template core.two_factor.code (1 email credit, transactional)
	 *
	 * @param array $user row of xcore_user
	 * @param string $method sms or email
	 * @param string $code
	 * @param string $application backend or frontend
	 *
	 * @return string '' when sent, otherwise no_mobile, no_email, no_sms_credits, no_email_credits or send_failed
	 */
	public static function sendCode(array $user, string $method, string $code, string $application='backend'):string
	{

		$minutes = (int)ceil(self::setting('ttl') / 60);

		if($method === 'sms')
		{

			$mobile = Sms::normalize((string)($user['mobile'] ?? ''));
			if($mobile === '')return 'no_mobile';

			$text = self::smsText($user, $code, $minutes);
			if(Sms::available() < Sms::measure($text)['parts'])return 'no_sms_credits';

			$result = Sms::send($mobile, $text, ['author' => 'unee-2fa']);

			// the code is never readable in the module SMS
			DB()->query("UPDATE xcore_sms SET message = REPLACE(message, :code, '******') WHERE id = :id", ['code' => $code, 'id' => (int)$result['id']]);

			if((int)$result['sent'] < 1)return (Sms::available() < 1) ? 'no_sms_credits' : 'send_failed';
			return '';

		}

		if($method === 'email')
		{

			if(trim((string)($user['email'] ?? '')) === '')return 'no_email';
			if(!Mailer::canSend('transactional'))return 'no_email_credits';

			$vars = [
				'firstname' => (string)$user['firstname'],
				'code' => $code,
				'minutes' => $minutes,
				'ip' => getVisitorIp(),
				'browser' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
				'date' => date('Y-m-d H:i'),
			];
			$options = ['module' => 'auth', 'action' => 'two_factor_code', 'record_id' => (int)$user['id'], 'xcore_user_id' => (int)$user['id'], 'application' => $application];

			if(Mailer::sendTemplate('core.two_factor.code', (string)$user['email'], $vars, (string)($user['language'] ?? ''), [], $options))return '';
			return (Mailer::getLastError() === 'no email credits') ? 'no_email_credits' : 'send_failed';

		}

		return 'send_failed';

	}

	/**
	 * text of the sms: code, validity, WebOTP line (@domain #code: Chrome Android fills the code)
	 *
	 * @param array $user
	 * @param string $code
	 * @param int $minutes
	 *
	 * @return string
	 */
	private static function smsText(array $user, string $code, int $minutes):string
	{

		$language = (string)($user['language'] ?? '');
		$messages = I18n::messages(preg_match('/^[a-z]{2}$/', $language) ? $language : '');
		$t = function(string $text, array $params) use ($messages):string
		{

			$text = $messages[$text] ?? $text;
			foreach($params as $key => $value)
				$text = str_replace("[{$key}]", (string)$value, $text);

			return $text;

		};

		$text = $t('Your [NAME] sign-in code: [CODE]', ['NAME' => (string)Config::get('name', 'Unee'), 'CODE' => $code])."\n".$t('Valid [MINUTES] min. Never share it.', ['MINUTES' => $minutes]);

		$host = (string)parse_url((string)Config::get('url', ''), PHP_URL_HOST);
		if($host === '')$host = (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
		if(preg_match('/^[a-z0-9.-]+$/i', $host))$text .= "\n\n@{$host} #{$code}";

		return $text;

	}

	/**
	 * destination shown at the sign in: +33 6** ** ** 78, j*****@company.com
	 *
	 * @param string $value mobile or email
	 * @param string $method sms or email
	 *
	 * @return string
	 */
	public static function mask(string $value, string $method):string
	{

		if($method === 'email')
		{

			[$local, $domain] = array_pad(explode('@', trim($value), 2), 2, '');
			return mb_substr($local, 0, 1).'*****@'.$domain;

		}

		$phone = Sms::normalize($value) ?: preg_replace('/\s+/', '', $value);
		if(preg_match('/^\+33(\d)\d{6}(\d{2})$/', $phone, $m))return "+33 {$m[1]}** ** ** {$m[2]}";

		$length = strlen($phone);
		return ($length <= 6) ? str_repeat('*', $length) : substr($phone, 0, 4).str_repeat('*', $length - 6).substr($phone, -2);

	}

	// secrets **********************************************************************************************************

	/**
	 * encrypts a secret for the database (sodium secretbox)
	 *
	 * @param string $secret
	 * @param string|null $key base64 key, null = config auth/security/encryption_key
	 *
	 * @return string base64 nonce + cipher
	 */
	public static function encrypt(string $secret, ?string $key=null):string
	{

		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return base64_encode($nonce.sodium_crypto_secretbox($secret, $nonce, self::key($key)));

	}

	/**
	 * decrypts a secret of the database
	 *
	 * @param string $value base64 nonce + cipher
	 * @param string|null $key base64 key, null = config auth/security/encryption_key
	 *
	 * @return string '' when it cannot be decrypted (key changed)
	 */
	public static function decrypt(string $value, ?string $key=null):string
	{

		$raw = base64_decode($value, true);
		if($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)return '';

		$secret = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key($key));
		return ($secret === false) ? '' : $secret;

	}

	/**
	 * state of the key of the config: missing (empty or invalid), legacy (the public key of the old core config) or ok
	 *
	 * @return string
	 */
	public static function keyStatus():string
	{

		$key = (string)Config::get('auth/security/encryption_key', '');
		$raw = base64_decode($key, true);
		if($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES)return 'missing';

		return (hash_equals(self::LEGACY_KEY, $key)) ? 'legacy' : 'ok';

	}

	// trusted devices **************************************************************************************************

	/**
	 * trusted device: token in an httponly cookie, its hash in xcore_user_trusted_device
	 *
	 * @param int $user_id
	 * @param string $application backend or frontend
	 *
	 * @return void
	 */
	public static function trustDevice(int $user_id, string $application='backend'):void
	{

		$days = self::trustDays($application);
		if($days <= 0)return;

		$token = bin2hex(random_bytes(32));
		$expires = time() + $days * 86400;

		DB('xcore_user_trusted_device')->insert([
			'xcore_user_id' => $user_id,
			'application' => $application,
			'token_hash' => hash('sha256', $token),
			'expires_at' => date('Y-m-d H:i:s', $expires),
			'ip' => getVisitorIp(),
			'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
		], 'unee-2fa');

		setcookie(self::TRUST_COOKIE, $token, ['expires' => $expires, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);

	}

	/**
	 * the device of the request is trusted by the user (cookie not expired)
	 *
	 * @param int $user_id
	 * @param string $application backend or frontend
	 *
	 * @return bool
	 */
	public static function isTrustedDevice(int $user_id, string $application='backend'):bool
	{

		$token = (string)($_COOKIE[self::TRUST_COOKIE] ?? '');
		if($token === '' || self::trustDays($application) <= 0)return false;

		return (bool)DB()->query("SELECT COUNT(*) FROM xcore_user_trusted_device WHERE deleted = 'no' AND xcore_user_id = :id AND application = :application AND token_hash = :hash AND expires_at > NOW()", ['id' => $user_id, 'application' => $application, 'hash' => hash('sha256', $token)])->fetchOne();

	}

	/**
	 * reset of a user (lost phone, method changed): secret removed, trusted devices forgotten, the app is set up again at the next logon
	 *
	 * @param int $user_id
	 * @param string $author
	 *
	 * @return void
	 */
	public static function reset(int $user_id, string $author):void
	{

		DB()->query("UPDATE xcore_user SET two_factor_secret = NULL, two_factor_enabled_at = NULL, two_factor_last_step = 0 WHERE id = :id", ['id' => $user_id]);
		DB()->query("UPDATE xcore_user_trusted_device SET deleted = 'yes', deleted_at = NOW(), deleted_by = :author WHERE xcore_user_id = :id AND deleted = 'no'", ['id' => $user_id, 'author' => $author]);

	}

	/**
	 * raw encryption key, 32 bytes
	 *
	 * @param string|null $key base64, null = config auth/security/encryption_key
	 *
	 * @return string
	 */
	private static function key(?string $key=null):string
	{

		$raw = base64_decode((string)($key ?? Config::get('auth/security/encryption_key', '')), true);
		if($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
			throw new \RuntimeException('config auth/security/encryption_key must be 32 bytes in base64: php vendor/bin/unee key:generate --instance=<name>');

		return $raw;

	}

	private static function base32Encode(string $data):string
	{

		$bits = '';
		foreach(str_split($data) as $char)
			$bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);

		$out = '';
		foreach(str_split($bits, 5) as $chunk)
			$out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];

		return $out;

	}

	private static function base32Decode(string $data):string
	{

		$bits = '';
		foreach(str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $data))) as $char)
			$bits .= str_pad(decbin(strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);

		$out = '';
		foreach(str_split($bits, 8) as $byte)
			if(strlen($byte) === 8)$out .= chr(bindec($byte));

		return $out;

	}

}
