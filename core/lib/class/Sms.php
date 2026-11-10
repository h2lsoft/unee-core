<?php

namespace Core;

/**
 * sms (xcore_sms): settings, credits, recipients and sending, the http calls of the providers are in \Core\SmsProvider
 *
 * - settings: globals sms.* (module SMS, button Settings), the secrets of the providers are encrypted (\Core\TwoFactor::encrypt), a value typed in clear in the module Globals works too
 * - credits: mode prepaid (balance = purchases of xcore_sms_credit - credits used) or monthly (sms.credits.monthly from sms.credits.reset_day, 0 = unlimited)
 *   a sms is never started without enough credits, its rows are queued with their credits (reserved until sent)
 * - one row per recipient in xcore_sms_data: queued, sending (claimed by a run), sent, delivered, failed (credits 0 when refused at the sending)
 * - blacklist (xcore_blacklist.mobile, STOP): left out of the campaigns and of the marketing messages
 * - code api: \Core\Sms::send('+33612345678', 'Your code is 1234') sends now and returns {id, sent, failed, left, error}
 */
class Sms
{

	public const array TYPES = ['one-off' => 'One-off', 'campaign' => 'Campaign'];
	public const array MODES = ['prepaid' => 'Prepaid (purchases)', 'monthly' => 'Monthly allowance'];

	// statuses of a row of xcore_sms_data whose credits are used
	public const array USED = ['sent', 'delivered', 'failed'];

	// gsm 03.38: basic characters (1) and extension (2: escape + character), any other character = unicode
	private const string GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
	private const string GSM_EXTENDED = "^{}\\[~]|€\f";

	/**
	 * settings of the current request (secrets in clear), null = read from the globals; set by the test of the settings form (values not saved yet)
	 */
	public static ?array $settings = null;

	// settings *********************************************************************************************************

	/**
	 * globals of the sms: name => [default, type, description], the fields of the providers included
	 *
	 * @return array
	 */
	public static function definitions():array
	{

		$definitions = [
			'sms.provider' => ['ovh', 'text', 'provider: '.implode(', ', array_keys(SmsProvider::PROVIDERS))],
			'sms.sender' => ['', 'text', 'default sender: 3 to 11 letters or digits, or a number, declared at the provider'],
			'sms.country_code' => ['+33', 'text', 'country code of the numbers typed with a leading 0'],
			'sms.stop_text' => ['', 'text', 'STOP mention added to the marketing messages, ex: STOP au XXXXX (Octopush), empty for OVHcloud and SMSFactor (added by the provider)'],
			'sms.credits.mode' => ['prepaid', 'text', 'prepaid (purchases added in SMS, Credits) or monthly'],
			'sms.credits.monthly' => ['1000', 'int', 'monthly mode: sms a month, 0 = unlimited'],
			'sms.credits.reset_day' => ['1', 'int', 'monthly mode: day of the month the credits are reset (1-28)'],
			'sms.alert.below' => ['100', 'int', 'the super admins are notified once when the credits left go below, 0 = never'],
			'sms.alert.key' => ['', 'text', 'set by the code: period of the last alert of the credits'],
			'sms.report.token' => [bin2hex(random_bytes(12)), 'text', 'secret of the delivery reports url, a change breaks the url declared at the provider'],
		];

		foreach(SmsProvider::PROVIDERS as $provider => $p)
			foreach($p['fields'] as $key => $field)
				$definitions["sms.{$provider}.{$key}"] = [$field['default'] ?? '', 'text', "{$p['label']}: {$field['label']}".((($field['type'] ?? '') === 'secret') ? ' (encrypted)' : '')];

		return $definitions;

	}

	/**
	 * registers the globals (shown in the module Globals)
	 *
	 * @return void
	 */
	public static function register():void
	{

		self::settings();

	}

	/**
	 * every setting, secrets decrypted
	 *
	 * @return array name => value
	 */
	public static function settings():array
	{

		if(self::$settings !== null)return self::$settings;

		$settings = [];
		foreach(self::definitions() as $name => [$default, $type, $description])
		{

			$value = (string)Globals::get($name, $default, true, $type, $description);
			// a secret typed in clear in the module Globals is not decrypted
			if(self::isSecret($name) && $value !== '' && ($plain = TwoFactor::decrypt($value)) !== '')$value = $plain;
			$settings[$name] = $value;

		}

		return self::$settings = $settings;

	}

	public static function isSecret(string $name):bool
	{

		$parts = explode('.', $name);
		return count($parts) === 3 && (SmsProvider::PROVIDERS[$parts[1]]['fields'][$parts[2]]['type'] ?? '') === 'secret';

	}

	/**
	 * saves settings (secrets encrypted), type and description kept
	 *
	 * @param array $values name => value
	 *
	 * @return void
	 */
	public static function put(array $values):void
	{

		$definitions = self::definitions();
		foreach($values as $name => $value)
		{

			if(!isset($definitions[$name]))continue;
			$value = (string)$value;
			if(self::isSecret($name) && $value !== '')$value = TwoFactor::encrypt($value);

			Globals::set($name, $value, $definitions[$name][1], $definitions[$name][2]);
			App()->globals[$name] = $value;

		}

		self::$settings = null;

	}

	public static function reportUrl():string
	{

		return rtrim((string)Config::get('url'), '/').'/api/sms/report/'.self::settings()['sms.report.token'].'/';

	}

	// text *************************************************************************************************************

	/**
	 * length and parts of a message: gsm 160 characters (153 by part), unicode 70 (67 by part, an emoji counts 2)
	 *
	 * @param string $text
	 *
	 * @return array{length: int, unicode: bool, parts: int}
	 */
	public static function measure(string $text):array
	{

		$length = 0;
		$unicode = false;
		foreach(mb_str_split($text) as $char)
		{

			if(mb_strpos(self::GSM, $char) !== false)$length++;
			elseif(mb_strpos(self::GSM_EXTENDED, $char) !== false)$length += 2;
			else
			{

				$unicode = true;
				break;

			}

		}

		if($unicode)$length = intdiv(strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);
		$parts = ($length === 0) ? 0 : (($length <= ($unicode ? 70 : 160)) ? 1 : (int)ceil($length / ($unicode ? 67 : 153)));

		return ['length' => $length, 'unicode' => $unicode, 'parts' => $parts];

	}

	/**
	 * characters added to a marketing message: the STOP mention of the settings (+ line break), or the one of OVHcloud (11 characters)
	 *
	 * @return int
	 */
	public static function stopLength():int
	{

		$s = self::settings();
		$stop = trim($s['sms.stop_text']);
		if($stop !== '')return mb_strlen($stop) + 1;

		return ($s['sms.provider'] === 'ovh') ? 11 : 0;

	}

	/**
	 * message of a recipient: variables [[firstname]] [[lastname]] replaced, STOP mention of the settings added to a marketing message
	 *
	 * @param array $sms row of xcore_sms
	 * @param array $recipient firstname, lastname
	 *
	 * @return string
	 */
	public static function text(array $sms, array $recipient):string
	{

		$text = trim(str_replace(['[[firstname]]', '[[lastname]]'], [(string)($recipient['firstname'] ?? ''), (string)($recipient['lastname'] ?? '')], (string)$sms['message']));
		$stop = trim(self::settings()['sms.stop_text']);
		if(($sms['marketing'] ?? 'no') === 'yes' && $stop !== '')$text .= "\n".$stop;

		return $text;

	}

	/**
	 * credits of a message: its parts, plus the STOP mention of OVHcloud on a marketing message
	 *
	 * @param array $sms
	 * @param string $text
	 *
	 * @return int
	 */
	public static function parts(array $sms, string $text):int
	{

		$extra = (($sms['marketing'] ?? 'no') === 'yes' && trim(self::settings()['sms.stop_text']) === '') ? self::stopLength() : 0;
		return self::measure($text.str_repeat(' ', $extra))['parts'];

	}

	/**
	 * number in international format (+33612345678), '' when invalid; a leading 0 gets the default country code, 00 becomes +
	 *
	 * @param string $number
	 *
	 * @return string
	 */
	public static function normalize(string $number):string
	{

		$number = preg_replace('/[\s().\/-]/', '', trim($number));
		$country = '+'.ltrim(preg_replace('/[^\d]/', '', self::settings()['sms.country_code']), '0');

		if(str_starts_with($number, '00'))$number = '+'.substr($number, 2);
		elseif(str_starts_with($number, '0') && $country !== '+')$number = $country.substr($number, 1);

		return (preg_match('/^\+[1-9]\d{6,14}$/', $number)) ? $number : '';

	}

	// recipients *******************************************************************************************************

	public static function ids(mixed $json):array
	{

		preg_match_all('/\d+/', (string)$json, $m);
		return array_values(array_unique(array_map('intval', $m[0])));

	}

	public static function numbers(mixed $json):array
	{

		$list = json_decode((string)$json, true);
		return (is_array($list)) ? array_values(array_filter(array_map(fn($n) => trim((string)$n), $list), 'strlen')) : [];

	}

	/**
	 * recipients of a sms, each number once: one-off = numbers, users and groups (their mobile), campaign = subscribers of the lists with a mobile
	 * error: invalid number, blacklisted (campaigns and marketing messages only)
	 *
	 * @param array $sms
	 *
	 * @return array [{phone, xcore_user_id, xcore_mailinglist_subscriber_id, firstname, lastname, error}]
	 */
	public static function recipients(array $sms):array
	{

		$candidates = [];
		if(($sms['type'] ?? '') === 'campaign')
		{

			$lists = implode(',', self::ids($sms['mailinglist_ids'] ?? '') ?: [0]);
			foreach(DB()->query("SELECT id, mobile, firstname, lastname FROM xcore_mailinglist_subscriber WHERE deleted = 'no' AND unsubscribe_date IS NULL AND mobile != '' AND xcore_mailinglist_id IN ({$lists}) ORDER BY id")->fetchAll() as $rec)
				$candidates[] = ['number' => $rec['mobile'], 'xcore_user_id' => 0, 'xcore_mailinglist_subscriber_id' => (int)$rec['id'], 'firstname' => $rec['firstname'], 'lastname' => $rec['lastname']];

		}
		else
		{

			foreach(self::numbers($sms['numbers'] ?? '') as $number)
				$candidates[] = ['number' => $number, 'xcore_user_id' => 0, 'xcore_mailinglist_subscriber_id' => 0, 'firstname' => '', 'lastname' => ''];

			$users = implode(',', self::ids($sms['xcore_user_ids'] ?? '') ?: [0]);
			$groups = implode(',', self::ids($sms['xcore_group_ids'] ?? '') ?: [0]);
			foreach(DB()->query("SELECT id, mobile, firstname, lastname FROM xcore_user WHERE deleted = 'no' AND active = 'yes' AND (id IN ({$users}) OR xcore_group_id IN ({$groups})) AND xcore_group_id != :system AND mobile IS NOT NULL AND mobile != '' ORDER BY id", ['system' => \Model\User::GROUP_SYSTEM])->fetchAll() as $rec)
				$candidates[] = ['number' => $rec['mobile'], 'xcore_user_id' => (int)$rec['id'], 'xcore_mailinglist_subscriber_id' => 0, 'firstname' => $rec['firstname'], 'lastname' => $rec['lastname']];

		}

		$filtered = ($sms['type'] ?? '') === 'campaign' || ($sms['marketing'] ?? 'no') === 'yes';
		$blacklist = ($filtered) ? array_flip(DB()->query("SELECT mobile FROM xcore_blacklist WHERE deleted = 'no' AND mobile IS NOT NULL")->fetchAllOne()) : [];

		$recipients = [];
		foreach($candidates as $c)
		{

			$phone = self::normalize($c['number']);
			$key = ($phone !== '') ? $phone : mb_substr($c['number'], 0, 20);
			if(isset($recipients[$key]))continue;

			$error = ($phone === '') ? 'invalid number' : ((isset($blacklist[$phone])) ? 'blacklisted' : '');
			$recipients[$key] = ['phone' => $key, 'xcore_user_id' => $c['xcore_user_id'], 'xcore_mailinglist_subscriber_id' => $c['xcore_mailinglist_subscriber_id'], 'firstname' => (string)$c['firstname'], 'lastname' => (string)$c['lastname'], 'error' => $error];

		}

		return array_values($recipients);

	}

	/**
	 * recipients and credits of a sms not started (values of the form)
	 *
	 * @param array $sms
	 *
	 * @return array{recipients: int, skipped: int, credits: int}
	 */
	public static function estimate(array $sms):array
	{

		$recipients = 0;
		$skipped = 0;
		$credits = 0;
		foreach(self::recipients($sms) as $r)
		{

			if($r['error'] !== '')
			{

				$skipped++;
				continue;

			}

			$recipients++;
			$credits += self::parts($sms, self::text($sms, $r));

		}

		return ['recipients' => $recipients, 'skipped' => $skipped, 'credits' => $credits];

	}

	// credits **********************************************************************************************************

	public static function resetDay():int
	{

		return max(1, min(28, (int)self::settings()['sms.credits.reset_day']));

	}

	/**
	 * start of the monthly period (reset day of this month, or of the previous one)
	 *
	 * @return string Y-m-d H:i:s
	 */
	public static function periodStart(?int $time=null):string
	{

		$time ??= time();
		$day = self::resetDay();
		$start = mktime(0, 0, 0, (int)date('n', $time), $day, (int)date('Y', $time));
		if($start > $time)$start = mktime(0, 0, 0, (int)date('n', $time) - 1, $day, (int)date('Y', $time));

		return date('Y-m-d H:i:s', $start);

	}

	/**
	 * credits: remaining (PHP_INT_MAX when unlimited) = balance (prepaid) or allowance left (monthly), reserved = queued rows of the sms being sent
	 *
	 * @return array{mode: string, unlimited: bool, remaining: int, reserved: int, used: int, monthly: int, percent: int, reset: string, bought: int, used_total: int, last_purchase: ?array}
	 */
	public static function credits():array
	{

		$s = self::settings();
		$mode = ($s['sms.credits.mode'] === 'monthly') ? 'monthly' : 'prepaid';
		$used_sql = "SELECT COALESCE(SUM(credits), 0) FROM xcore_sms_data WHERE deleted = 'no' AND status IN ('".implode("', '", self::USED)."')";
		$reserved = (int)DB()->query("SELECT COALESCE(SUM(credits), 0) FROM xcore_sms_data WHERE deleted = 'no' AND status IN ('queued', 'sending')")->fetchOne();

		$c = ['mode' => $mode, 'unlimited' => false, 'remaining' => 0, 'reserved' => $reserved, 'used' => 0, 'monthly' => 0, 'percent' => 100, 'reset' => '', 'bought' => 0, 'used_total' => 0, 'last_purchase' => null];

		if($mode === 'monthly')
		{

			$start = self::periodStart();
			$c['monthly'] = max(0, (int)$s['sms.credits.monthly']);
			$c['used'] = (int)DB()->query("{$used_sql} AND sent_at >= :start", ['start' => $start])->fetchOne();
			$c['reset'] = date('Y-m-d', strtotime($start.' +1 month'));
			$c['unlimited'] = ($c['monthly'] === 0);
			$c['remaining'] = ($c['unlimited']) ? PHP_INT_MAX : max(0, $c['monthly'] - $c['used']);
			$c['percent'] = ($c['unlimited']) ? 100 : (int)floor($c['remaining'] * 100 / $c['monthly']);

			return $c;

		}

		$c['bought'] = (int)DB()->query("SELECT COALESCE(SUM(credits), 0) FROM xcore_sms_credit WHERE deleted = 'no'")->fetchOne();
		$c['used_total'] = (int)DB()->query($used_sql)->fetchOne();
		$c['used'] = (int)DB()->query("{$used_sql} AND sent_at >= :start", ['start' => date('Y-m-01 00:00:00')])->fetchOne();
		$c['remaining'] = max(0, $c['bought'] - $c['used_total']);
		$c['percent'] = ($c['bought'] > 0) ? (int)floor($c['remaining'] * 100 / $c['bought']) : 0;
		$c['last_purchase'] = DB()->query("SELECT date, credits FROM xcore_sms_credit WHERE deleted = 'no' ORDER BY date DESC, id DESC LIMIT 1")->fetch() ?: null;

		return $c;

	}

	/**
	 * credits a new sms can use: remaining minus the reserved ones
	 *
	 * @return int
	 */
	public static function available():int
	{

		$c = self::credits();
		return ($c['unlimited']) ? PHP_INT_MAX : max(0, $c['remaining'] - $c['reserved']);

	}

	/**
	 * notification to the super admins when the credits left go below sms.alert.below, once by period (monthly) or by purchase (prepaid)
	 *
	 * @return void
	 */
	public static function alert():void
	{

		$below = (int)self::settings()['sms.alert.below'];
		if($below <= 0)return;

		$c = self::credits();
		if($c['unlimited'] || $c['remaining'] >= $below)return;

		$key = ($c['mode'] === 'monthly') ? "m{$c['reset']}" : "p{$c['bought']}";
		if(self::settings()['sms.alert.key'] === $key)return;

		self::put(['sms.alert.key' => $key]);
		Notification::sendGroup(\Model\User::GROUP_SUPERADMIN, ts('SMS credits are running low'), ts('[COUNT] credits left', ['COUNT' => number_format($c['remaining'], 0, ',', ' ')]), 'sms-credit/', 'warning', 'SMS');

	}

	// sending **********************************************************************************************************

	private static function status(int $id, string $status, string $error=''):void
	{

		$sent = ($status === 'sent') ? ', sent_at = :now2' : '';
		$binds = ['status' => $status, 'error' => mb_substr($error, 0, 255), 'now' => now(), 'id' => $id] + (($sent !== '') ? ['now2' => now()] : []);
		DB()->query("UPDATE xcore_sms SET status = :status, error_message = :error{$sent}, updated_at = :now, updated_by = 'sms' WHERE id = :id", $binds);

	}

	/**
	 * counters of a sms from its rows: recipients, credits used, delivered, failed
	 *
	 * @param int $id
	 *
	 * @return void
	 */
	public static function refresh(int $id):void
	{

		$used = "'".implode("', '", self::USED)."'";
		DB()->query("UPDATE xcore_sms SET
				recipients = (SELECT COUNT(*) FROM xcore_sms_data d WHERE d.deleted = 'no' AND d.xcore_sms_id = :id1),
				credits_used = (SELECT COALESCE(SUM(d.credits), 0) FROM xcore_sms_data d WHERE d.deleted = 'no' AND d.xcore_sms_id = :id2 AND d.status IN ({$used})),
				delivered = (SELECT COUNT(*) FROM xcore_sms_data d WHERE d.deleted = 'no' AND d.xcore_sms_id = :id3 AND d.status = 'delivered'),
				failed = (SELECT COUNT(*) FROM xcore_sms_data d WHERE d.deleted = 'no' AND d.xcore_sms_id = :id4 AND d.status = 'failed')
			WHERE id = :id", ['id1' => $id, 'id2' => $id, 'id3' => $id, 'id4' => $id, 'id' => $id]);

	}

	/**
	 * start of a sms (scheduled -> sending): one row per recipient with its credits, never started without enough credits (status error)
	 * resumed after an error: the rows already queued are kept
	 *
	 * @param array $sms
	 *
	 * @return string error, '' when started
	 */
	private static function start(array $sms):string
	{

		$id = (int)$sms['id'];
		if(trim((string)$sms['message']) === '')return 'empty message';

		if(!DB()->query("SELECT 1 FROM xcore_sms_data WHERE xcore_sms_id = :id AND deleted = 'no' LIMIT 1", ['id' => $id])->fetchOne())
		{

			$rows = [];
			$needed = 0;
			foreach(self::recipients($sms) as $r)
			{

				$credits = ($r['error'] === '') ? self::parts($sms, self::text($sms, $r)) : 0;
				$needed += $credits;
				$rows[] = ['xcore_sms_id' => $id, 'phone' => $r['phone'], 'xcore_user_id' => $r['xcore_user_id'], 'xcore_mailinglist_subscriber_id' => $r['xcore_mailinglist_subscriber_id'], 'firstname' => mb_substr($r['firstname'], 0, 255), 'lastname' => mb_substr($r['lastname'], 0, 255), 'status' => ($r['error'] === '') ? 'queued' : 'failed', 'credits' => $credits, 'error' => $r['error']];

			}

			if(!$rows)return 'no recipient';

			$available = self::available();
			if($needed > $available)return ts('insufficient credits: [NEEDED] needed, [AVAILABLE] available', ['NEEDED' => $needed, 'AVAILABLE' => $available]);

			DB()->beginTransaction();
			try
			{

				foreach($rows as $row)
					DB('xcore_sms_data')->insert($row, 'sms');
				DB()->commit();

			}
			catch(\Throwable $e)
			{

				DB()->rollBack();
				throw $e;

			}

		}

		DB()->query("UPDATE xcore_sms SET status = 'sending', error_message = '', parts = :parts, updated_at = :now, updated_by = 'sms' WHERE id = :id", ['parts' => self::measure(self::text($sms, []))['parts'], 'now' => now(), 'id' => $id]);
		self::refresh($id);

		return '';

	}

	/**
	 * sends the queued rows of a sms (started first when scheduled), status sent when every row is processed
	 * a provider error that stops every sending (configuration, authentication, credits of the provider) puts the sms in error, its rows stay queued
	 *
	 * @param int $id
	 * @param int $limit rows at most
	 * @param int $deadline unix time to stop at, 0 = none
	 *
	 * @return array{sent: int, failed: int, left: int, error: string}
	 */
	public static function process(int $id, int $limit=500, int $deadline=0):array
	{

		$result = ['sent' => 0, 'failed' => 0, 'left' => 0, 'error' => ''];
		$sms = DB()->query("SELECT * FROM xcore_sms WHERE id = :id AND deleted = 'no'", ['id' => $id])->fetch();
		if(!$sms || !in_array($sms['status'], ['scheduled', 'sending'], true))return ['error' => 'not due'] + $result;

		if($sms['status'] === 'scheduled' && ($error = self::start($sms)) !== '')
		{

			self::status($id, 'error', $error);
			return ['error' => $error] + $result;

		}

		$settings = self::settings();
		$report_url = self::reportUrl();
		$rows = DB()->query("SELECT * FROM xcore_sms_data WHERE xcore_sms_id = :id AND deleted = 'no' AND status = 'queued' ORDER BY id LIMIT ".max(1, $limit), ['id' => $id])->fetchAll();
		foreach($rows as $i => $row)
		{

			if($deadline > 0 && time() >= $deadline)break;

			// cancelled from the backend meanwhile
			if($i > 0 && $i % 50 === 0 && DB()->query("SELECT status FROM xcore_sms WHERE id = :id", ['id' => $id])->fetchOne() !== 'sending')break;

			// claimed: a run started at the same time never sends it twice
			DB()->query("UPDATE xcore_sms_data SET status = 'sending' WHERE id = :id AND status = 'queued'", ['id' => $row['id']]);
			if(DB()->rowCount() !== 1)continue;

			$text = self::text($sms, $row);
			$sent = SmsProvider::send($settings, $row['phone'], $text, (string)$sms['sender'], $sms['marketing'] === 'yes', $report_url, (int)$row['id']);

			if($sent['fatal'])
			{

				DB()->query("UPDATE xcore_sms_data SET status = 'queued' WHERE id = :id", ['id' => $row['id']]);
				$result['error'] = $sent['error'];
				self::status($id, 'error', $sent['error']);
				break;

			}

			if($sent['error'] === '')
			{

				DB()->query("UPDATE xcore_sms_data SET status = 'sent', provider_id = :pid, sent_at = :now, updated_at = :now2, updated_by = 'sms' WHERE id = :id", ['pid' => mb_substr($sent['id'], 0, 100), 'now' => now(), 'now2' => now(), 'id' => $row['id']]);
				$result['sent']++;

			}
			else
			{

				DB()->query("UPDATE xcore_sms_data SET status = 'failed', credits = 0, error = :error, sent_at = :now, updated_at = :now2, updated_by = 'sms' WHERE id = :id", ['error' => mb_substr($sent['error'], 0, 255), 'now' => now(), 'now2' => now(), 'id' => $row['id']]);
				if($sent['blacklisted'])self::blacklist($row['phone']);
				$result['failed']++;

			}

		}

		self::refresh($id);
		$result['left'] = (int)DB()->query("SELECT COUNT(*) FROM xcore_sms_data WHERE xcore_sms_id = :id AND deleted = 'no' AND status IN ('queued', 'sending')", ['id' => $id])->fetchOne();
		if($result['error'] === '' && $result['left'] === 0 && DB()->query("SELECT status FROM xcore_sms WHERE id = :id", ['id' => $id])->fetchOne() === 'sending')self::status($id, 'sent');
		if($result['sent'] > 0)self::alert();

		return $result;

	}

	/**
	 * cancels a sms: its queued rows are released (failed, credits 0)
	 *
	 * @param int $id
	 *
	 * @return void
	 */
	public static function cancel(int $id):void
	{

		DB()->query("UPDATE xcore_sms_data SET status = 'failed', credits = 0, error = 'cancelled', updated_at = :now, updated_by = 'sms' WHERE xcore_sms_id = :id AND status = 'queued'", ['now' => now(), 'id' => $id]);
		self::status($id, 'cancelled');
		self::refresh($id);

	}

	/**
	 * sends a sms now (code api): a one-off row of xcore_sms, credits checked, provider of the settings
	 *
	 * @param string|array $to numbers, international format or national with a leading 0 (default country of the settings)
	 * @param string $message variables [[firstname]] [[lastname]] left empty
	 * @param array $options sender (default of the settings), marketing (bool), author (created_by)
	 *
	 * @return array{id: int, sent: int, failed: int, left: int, error: string}
	 */
	public static function send(string|array $to, string $message, array $options=[]):array
	{

		$id = (int)DB('xcore_sms')->insert([
			'type' => 'one-off',
			'sender' => (string)($options['sender'] ?? self::settings()['sms.sender']),
			'message' => $message,
			'numbers' => json_encode(array_values(array_unique(array_map(fn($n) => self::normalize((string)$n) ?: (string)$n, (array)$to)))),
			'xcore_user_ids' => '[]',
			'xcore_group_ids' => '[]',
			'mailinglist_ids' => '[]',
			'marketing' => (!empty($options['marketing'])) ? 'yes' : 'no',
			'status' => 'scheduled',
		], (string)($options['author'] ?? 'sms'));

		return ['id' => $id] + self::process($id, 1000);

	}

	/**
	 * delivery reports received on /api/sms/report/<token>/: final status of the rows (delivered, failed), numbers opted out blacklisted
	 *
	 * @param array $data query string and body of the callback
	 *
	 * @return int rows updated
	 */
	public static function report(array $data):int
	{

		$updated = 0;
		$ids = [];
		foreach(SmsProvider::report(self::settings()['sms.provider'], $data) as $r)
		{

			if($r['id'] === '')continue;
			$row = DB()->query("SELECT id, xcore_sms_id, phone, status FROM xcore_sms_data WHERE provider_id = :pid AND deleted = 'no' ORDER BY id DESC LIMIT 1", ['pid' => $r['id']])->fetch();
			if(!$row)continue;

			if($r['blacklisted'])self::blacklist($row['phone']);
			if($r['status'] === '' || !in_array($row['status'], ['sent', 'delivered', 'failed'], true))continue;

			DB()->query("UPDATE xcore_sms_data SET status = :status, error = :error, delivered_at = :at, updated_at = :now, updated_by = 'sms' WHERE id = :id", ['status' => $r['status'], 'error' => mb_substr($r['error'], 0, 255), 'at' => ($r['status'] === 'delivered') ? now() : null, 'now' => now(), 'id' => $row['id']]);
			$ids[(int)$row['xcore_sms_id']] = true;
			$updated++;

		}

		foreach(array_keys($ids) as $id)
			self::refresh($id);

		return $updated;

	}

	/**
	 * number opted out (STOP at the provider): added to the blacklist once
	 *
	 * @param string $phone
	 *
	 * @return void
	 */
	public static function blacklist(string $phone):void
	{

		if(!preg_match('/^\+\d{7,15}$/', $phone))return;
		if(DB()->query("SELECT 1 FROM xcore_blacklist WHERE deleted = 'no' AND mobile = :mobile", ['mobile' => $phone])->fetchOne())return;

		DB('xcore_blacklist')->insert(['date' => now(), 'email' => null, 'mobile' => $phone], 'sms');

	}

	// widgets **********************************************************************************************************

	/**
	 * credits as a counters widget of the dashboard (\Core\Widget)
	 *
	 * @return array
	 */
	public static function widget():array
	{

		$c = self::credits();
		$fmt = fn(int $n) => number_format($n, 0, ',', ' ');
		$alert = (int)self::settings()['sms.alert.below'];

		if($c['unlimited'])return ['type' => 'counters', 'status' => ['label' => ts('Credits'), 'badge' => ['text' => ts('unlimited'), 'color' => 'green']], 'tiles' => [['value' => $c['used'], 'label' => ts('Sent this month'), 'color' => 'blue']]];

		if($c['mode'] === 'monthly')
		{

			$color = ($c['percent'] <= 10) ? 'red' : (($c['percent'] <= 25) ? 'orange' : 'green');
			$tiles = [['value' => $c['remaining'], 'label' => ts('Left'), 'color' => $color], ['value' => $c['used'], 'label' => ts('Used'), 'color' => 'blue'], ['value' => $c['monthly'], 'label' => ts('Monthly'), 'color' => 'grey']];

			return ['type' => 'counters', 'status' => ['label' => ts('Credits left'), 'badge' => ['text' => "{$c['percent']}%", 'color' => $color]], 'note' => ['text' => ts('reset on [DATE]', ['DATE' => $c['reset']])], 'tiles' => $tiles];

		}

		$color = ($alert > 0 && $c['remaining'] < $alert) ? (($c['remaining'] < $alert / 2) ? 'red' : 'orange') : 'green';
		$last = $c['last_purchase'];
		$tiles = [['value' => $c['remaining'], 'label' => ts('Left'), 'color' => $color], ['value' => $c['used'], 'label' => ts('Used this month'), 'color' => 'blue'], ['value' => (int)($last['credits'] ?? 0), 'label' => ts('Last purchase'), 'color' => 'grey']];

		return [
			'type' => 'counters',
			'status' => ['label' => ts('Credits left'), 'badge' => ['text' => $fmt($c['remaining']), 'color' => $color]],
			'note' => ['text' => ($last) ? ts('last purchase on [DATE]', ['DATE' => $last['date']]) : ts('no purchase yet')],
			'tiles' => $tiles,
		];

	}

}
