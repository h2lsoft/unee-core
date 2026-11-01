<?php

namespace Core;

/**
 * two-factor authentication: totp (rfc 6238, 6 digits, 30 s, sha1) and trusted devices
 * config auth/security/<application>/two_factor: enabled, trust_days ; auth/security/encryption_key encrypts the secrets
 */
class TwoFactor
{

	public const int DIGITS = 6;
	public const int PERIOD = 30;
	private const string TRUST_COOKIE = 'unee_trusted_device';
	private const string BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * 2fa required for an application (backend or frontend)
	 *
	 * @param string $application backend or frontend
	 *
	 * @return bool
	 */
	public static function enabled(string $application='backend'):bool
	{

		return (bool)Config::get("auth/security/{$application}/two_factor/enabled", false);

	}

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

	/**
	 * encrypts a secret for the database (sodium secretbox, key auth/security/encryption_key)
	 *
	 * @param string $secret
	 *
	 * @return string base64 nonce + cipher
	 */
	public static function encrypt(string $secret):string
	{

		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return base64_encode($nonce.sodium_crypto_secretbox($secret, $nonce, self::key()));

	}

	/**
	 * decrypts a secret of the database
	 *
	 * @param string $value base64 nonce + cipher
	 *
	 * @return string '' when it cannot be decrypted (key changed)
	 */
	public static function decrypt(string $value):string
	{

		$raw = base64_decode($value, true);
		if($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)return '';

		$secret = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
		return ($secret === false) ? '' : $secret;

	}

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

		$days = (int)Config::get("auth/security/{$application}/two_factor/trust_days", 0);
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
		if($token === '' || (int)Config::get("auth/security/{$application}/two_factor/trust_days", 0) <= 0)return false;

		return (bool)DB()->query("SELECT COUNT(*) FROM xcore_user_trusted_device WHERE deleted = 'no' AND xcore_user_id = :id AND application = :application AND token_hash = :hash AND expires_at > NOW()", ['id' => $user_id, 'application' => $application, 'hash' => hash('sha256', $token)])->fetchOne();

	}

	/**
	 * reset of a user (lost phone): secret removed, trusted devices forgotten, activation again at the next logon
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
	 * encryption key, 32 bytes in base64
	 *
	 * @return string
	 */
	private static function key():string
	{

		$key = base64_decode((string)Config::get('auth/security/encryption_key', ''), true);
		if($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
			throw new \RuntimeException('config auth/security/encryption_key must be 32 bytes in base64: php -r "echo base64_encode(random_bytes(32));"');

		return $key;

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
