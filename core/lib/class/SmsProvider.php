<?php

namespace Core;

/**
 * http drivers of the sms providers (\Core\Sms), one request by recipient, numbers in international format (+33612345678)
 *
 * - ovh: POST /1.0/sms/<account>/jobs signed ($1$ sha1), OVHcloud adds its STOP mention to a marketing message (noStopClause otherwise)
 * - twilio: POST /2010-04-01/Accounts/<sid>/Messages.json (basic auth), StatusCallback = delivery reports url
 * - brevo: POST /v3/transactionalSMS/sms (header api-key), type transactional or marketing, webUrl = delivery reports url
 * - smsfactor: POST /send (bearer), pushtype alert or marketing, gsmsmsid = id of xcore_sms_data (back as message_id in the reports)
 * - octopush: POST /v1/public/sms-campaign/send (headers api-login, api-key), purpose alert or wholesale (marketing: STOP mention required in the text)
 * - generic: url, method, headers and body with [[to]], [[sender]], [[message]], sent on a 2xx answer, no delivery report
 * delivery reports: OVHcloud, SMSFactor and Octopush get the url in the settings of the account, Twilio and Brevo with each message (https url only)
 */
class SmsProvider
{

	/**
	 * providers: label, help, fields (type text, secret, select, radio, textarea; required)
	 */
	public const array PROVIDERS = [
		'ovh' => ['label' => 'OVHcloud SMS', 'help' => 'Keys of an OVHcloud API application with the rights GET and POST on /sms/*, and the name of the SMS account.', 'fields' => [
			'endpoint' => ['label' => 'Endpoint', 'type' => 'select', 'options' => ['ovh-eu' => 'ovh-eu', 'ovh-ca' => 'ovh-ca', 'ovh-us' => 'ovh-us'], 'default' => 'ovh-eu', 'required' => true],
			'account' => ['label' => 'SMS account', 'placeholder' => 'sms-ab12345-1', 'required' => true],
			'application_key' => ['label' => 'Application key', 'required' => true],
			'application_secret' => ['label' => 'Application secret', 'type' => 'secret', 'required' => true],
			'consumer_key' => ['label' => 'Consumer key', 'type' => 'secret', 'required' => true],
		]],
		'twilio' => ['label' => 'Twilio', 'help' => 'Account SID and auth token of the Twilio console.', 'fields' => [
			'account_sid' => ['label' => 'Account SID', 'placeholder' => 'AC...', 'required' => true],
			'auth_token' => ['label' => 'Auth token', 'type' => 'secret', 'required' => true],
			'from' => ['label' => 'From', 'placeholder' => '+33755123456 or MG...', 'help' => 'Twilio number or Messaging Service SID (MG...), empty = sender of the SMS'],
		]],
		'brevo' => ['label' => 'Brevo', 'help' => 'API key of the Brevo account (transactional SMS), credits bought at Brevo.', 'fields' => [
			'api_key' => ['label' => 'API key', 'type' => 'secret', 'required' => true],
		]],
		'smsfactor' => ['label' => 'SMSFactor', 'help' => 'API token of the SMSFactor account.', 'fields' => [
			'api_token' => ['label' => 'API token', 'type' => 'secret', 'required' => true],
		]],
		'octopush' => ['label' => 'Octopush', 'help' => 'Login and API key of the Octopush account, premium SMS.', 'fields' => [
			'login' => ['label' => 'Login (email)', 'required' => true],
			'api_key' => ['label' => 'API key', 'type' => 'secret', 'required' => true],
		]],
		'generic' => ['label' => 'Generic HTTP', 'help' => 'Any other provider with an HTTP API: url, headers and body built from variables.', 'fields' => [
			'url' => ['label' => 'Url', 'placeholder' => 'https://', 'required' => true],
			'method' => ['label' => 'Method', 'type' => 'radio', 'options' => ['POST' => 'POST', 'GET' => 'GET'], 'default' => 'POST'],
			'headers' => ['label' => 'Headers', 'type' => 'textarea', 'help' => 'one per line, ex: Authorization: Bearer xxx', 'placeholder' => "Authorization: Bearer xxx\nContent-Type: application/json"],
			'body' => ['label' => 'Body', 'type' => 'textarea', 'default' => '{"to": "[[to]]", "from": "[[sender]]", "text": "[[message]]"}', 'help' => 'variables [[to]] (international format), [[sender]], [[message]]: escaped for json when the body starts with { or [, url encoded otherwise; sent on an HTTP 2xx answer'],
		]],
	];

	// endpoints of the ovh api (official php client ovh/ovh)
	private const array OVH_ENDPOINTS = ['ovh-eu' => 'https://eu.api.ovh.com/1.0', 'ovh-ca' => 'https://ca.api.ovh.com/1.0', 'ovh-us' => 'https://api.us.ovhcloud.com/1.0'];

	/**
	 * sends one message
	 *
	 * @param array $settings \Core\Sms::settings()
	 * @param string $to +33612345678
	 * @param string $text
	 * @param string $sender
	 * @param bool $marketing
	 * @param string $report_url delivery reports url (twilio, brevo)
	 * @param int $data_id id of xcore_sms_data (smsfactor)
	 *
	 * @return array{id: string, error: string, fatal: bool, blacklisted: bool} fatal = every sending would fail (configuration, authentication, credits of the provider)
	 */
	public static function send(array $settings, string $to, string $text, string $sender, bool $marketing, string $report_url, int $data_id):array
	{

		$provider = (string)$settings['sms.provider'];
		if(!isset(self::PROVIDERS[$provider]))return self::result('', "unknown provider `{$provider}`", true);

		$s = fn(string $key):string => trim((string)($settings["sms.{$provider}.{$key}"] ?? ''));
		foreach(self::PROVIDERS[$provider]['fields'] as $key => $field)
			if(!empty($field['required']) && $s($key) === '')return self::result('', ts('provider not configured: [FIELD]', ['FIELD' => $field['label']]), true);

		$https = (str_starts_with($report_url, 'https://')) ? $report_url : '';
		$unicode = Sms::measure($text)['unicode'];

		return match($provider)
		{
			'ovh' => self::ovh($s, $to, $text, $sender, $marketing),
			'twilio' => self::twilio($s, $to, $text, $sender, $https),
			'brevo' => self::brevo($s, $to, $text, $sender, $marketing, $unicode, $https),
			'smsfactor' => self::smsfactor($s, $to, $text, $sender, $marketing, $unicode, $data_id),
			'octopush' => self::octopush($s, $to, $text, $sender, $marketing),
			'generic' => self::generic($s, $to, $text, $sender),
		};

	}

	private static function ovh(\Closure $s, string $to, string $text, string $sender, bool $marketing):array
	{

		$base = self::OVH_ENDPOINTS[$s('endpoint')] ?? self::OVH_ENDPOINTS['ovh-eu'];
		$url = "{$base}/sms/".rawurlencode($s('account')).'/jobs';
		$payload = ['receivers' => [$to], 'message' => $text, 'noStopClause' => !$marketing] + (($sender !== '') ? ['sender' => $sender] : ['senderForResponse' => true]);
		$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		// clock of the ovh servers: a local clock out of time is refused
		static $delta = null;
		if($delta === null)
		{

			$time = self::http('GET', "{$base}/auth/time");
			$delta = ($time['code'] === 200 && is_numeric(trim($time['body']))) ? (int)trim($time['body']) - time() : 0;

		}
		$timestamp = (string)(time() + $delta);

		$r = self::http('POST', $url, [
			'Content-Type: application/json',
			'X-Ovh-Application: '.$s('application_key'),
			'X-Ovh-Consumer: '.$s('consumer_key'),
			'X-Ovh-Timestamp: '.$timestamp,
			'X-Ovh-Signature: $1$'.sha1($s('application_secret').'+'.$s('consumer_key').'+POST+'.$url.'+'.$body.'+'.$timestamp),
		], $body);

		if($r['code'] >= 200 && $r['code'] < 300)
		{

			$id = (string)($r['json']['ids'][0] ?? '');
			return ($id !== '') ? self::result($id) : self::result('', (!empty($r['json']['invalidReceivers'])) ? 'invalid number' : 'not sent');

		}

		return self::result('', self::message($r, 'message'), in_array($r['code'], [0, 401, 403], true));

	}

	private static function twilio(\Closure $s, string $to, string $text, string $sender, string $report_url):array
	{

		$from = $s('from');
		$fields = ['To' => $to, 'Body' => $text] + ((str_starts_with($from, 'MG')) ? ['MessagingServiceSid' => $from] : ['From' => ($from !== '') ? $from : $sender]);
		if($report_url !== '')$fields['StatusCallback'] = $report_url;

		$r = self::http('POST', 'https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($s('account_sid')).'/Messages.json', ['Content-Type: application/x-www-form-urlencoded'], http_build_query($fields), $s('account_sid').':'.$s('auth_token'));
		if($r['code'] === 201 && !empty($r['json']['sid']))return self::result((string)$r['json']['sid']);

		return self::result('', self::message($r, 'message'), in_array($r['code'], [0, 401, 403], true));

	}

	private static function brevo(\Closure $s, string $to, string $text, string $sender, bool $marketing, bool $unicode, string $report_url):array
	{

		$payload = ['sender' => $sender, 'recipient' => ltrim($to, '+'), 'content' => $text, 'type' => ($marketing) ? 'marketing' : 'transactional', 'unicodeEnabled' => $unicode];
		if($report_url !== '')$payload['webUrl'] = $report_url;

		$r = self::http('POST', 'https://api.brevo.com/v3/transactionalSMS/sms', ['Content-Type: application/json', 'Accept: application/json', 'api-key: '.$s('api_key')], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		if($r['code'] >= 200 && $r['code'] < 300 && isset($r['json']['messageId']))return self::result((string)$r['json']['messageId']);

		// 402 and not_enough_credits: credits of the brevo account
		$fatal = in_array($r['code'], [0, 401, 402, 403], true) || ($r['json']['code'] ?? '') === 'not_enough_credits';
		return self::result('', self::message($r, 'message'), $fatal);

	}

	private static function smsfactor(\Closure $s, string $to, string $text, string $sender, bool $marketing, bool $unicode, int $data_id):array
	{

		$message = ['text' => $text, 'pushtype' => ($marketing) ? 'marketing' : 'alert', 'unicode' => ($unicode) ? 1 : 0] + (($sender !== '') ? ['sender' => $sender] : []);
		$payload = ['sms' => ['message' => $message, 'recipients' => ['gsm' => [['gsmsmsid' => (string)$data_id, 'value' => ltrim($to, '+')]]]]];

		$r = self::http('POST', 'https://api.smsfactor.com/send', ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer '.$s('api_token')], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		$status = (int)($r['json']['status'] ?? 0);

		// -8: campaign in moderation, sent after it (never sent again)
		if($status === -8)return self::result((string)$data_id);
		if($status === 1)
		{

			if((int)($r['json']['sent'] ?? 1) > 0)return self::result((string)$data_id);
			if((int)($r['json']['blacklisted'] ?? 0) > 0)return self::result('', 'blacklisted', false, true);
			return self::result('', ((int)($r['json']['invalid'] ?? 0) > 0) ? 'invalid number' : 'not sent');

		}

		// -1 authentication, -3 credits of the smsfactor account
		return self::result('', self::message($r, 'message'), $r['code'] === 0 || in_array($status, [-1, -3], true));

	}

	private static function octopush(\Closure $s, string $to, string $text, string $sender, bool $marketing):array
	{

		$payload = ['recipients' => [['phone_number' => $to]], 'text' => $text, 'type' => 'sms_premium', 'purpose' => ($marketing) ? 'wholesale' : 'alert'] + (($sender !== '') ? ['sender' => $sender] : []);

		$r = self::http('POST', 'https://api.octopush.com/v1/public/sms-campaign/send', ['Content-Type: application/json', 'Accept: application/json', 'api-login: '.$s('login'), 'api-key: '.$s('api_key')], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		if($r['code'] === 200 && !empty($r['json']['sms_ticket']))return self::result((string)$r['json']['sms_ticket']);

		// 104: credits of the octopush account
		return self::result('', self::message($r, 'message'), in_array($r['code'], [0, 401, 403], true) || (int)($r['json']['code'] ?? 0) === 104);

	}

	private static function generic(\Closure $s, string $to, string $text, string $sender):array
	{

		$vars = ['[[to]]' => $to, '[[sender]]' => $sender, '[[message]]' => $text];
		$method = ($s('method') === 'GET') ? 'GET' : 'POST';
		$url = strtr($s('url'), array_map('rawurlencode', $vars));

		$body = null;
		if($method === 'POST')
		{

			$template = $s('body');
			$json = in_array(substr($template, 0, 1), ['{', '['], true);
			$body = strtr($template, array_map(fn($v) => ($json) ? substr(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1) : rawurlencode($v), $vars));

		}

		$headers = array_values(array_filter(array_map('trim', preg_split('/\R/', $s('headers')))));
		$r = self::http($method, $url, $headers, $body);
		if($r['code'] >= 200 && $r['code'] < 300)return self::result('');

		return self::result('', self::message($r, 'message'), in_array($r['code'], [0, 401, 403], true));

	}

	/**
	 * final statuses of a delivery report: delivered, failed, '' = pending (ignored); blacklisted = the number opted out (STOP)
	 *
	 * @param string $provider
	 * @param array $data query string and body (form or json) of the callback
	 *
	 * @return array [{id, status, error, blacklisted}]
	 */
	public static function report(string $provider, array $data):array
	{

		$get = fn(string $key):string => (is_scalar($data[$key] ?? null)) ? trim((string)$data[$key]) : '';

		return match($provider)
		{
			// description: 1 success, 2 failed, 16 error not billed, 0 4 8 pending; ptt = detailed code
			'ovh' => [['id' => $get('id'), 'status' => match($get('description')) { '1' => 'delivered', '2', '16' => 'failed', default => '' }, 'error' => ($get('ptt') !== '') ? "ptt {$get('ptt')}" : '', 'blacklisted' => false]],
			// 21610: the number replied STOP
			'twilio' => [['id' => $get('MessageSid'), 'status' => match($get('MessageStatus')) { 'delivered' => 'delivered', 'failed', 'undelivered' => 'failed', default => '' }, 'error' => ($get('ErrorCode') !== '') ? "error {$get('ErrorCode')}" : '', 'blacklisted' => $get('ErrorCode') === '21610']],
			'brevo' => [['id' => $get('messageId'), 'status' => match($get('msg_status')) { 'delivered' => 'delivered', 'hard_bounce', 'soft_bounce', 'rej', 'bl', 'skip' => 'failed', default => '' }, 'error' => ($get('msg_status') === 'delivered') ? '' : ($get('description') ?: $get('msg_status')), 'blacklisted' => in_array($get('msg_status'), ['bl', 'unsubscribed'], true)]],
			'smsfactor' => [['id' => $get('message_id'), 'status' => match($get('status')) { '3' => 'delivered', '2', '4', '5', '6', '7', '9', '11', '12' => 'failed', default => '' }, 'error' => [2 => 'not delivered', 4 => 'not allowed', 5 => 'invalid destination', 6 => 'invalid sender', 7 => 'route not available', 9 => 'rejected', 11 => 'network error', 12 => 'expired'][(int)$get('status')] ?? '', 'blacklisted' => false]],
			// message_id = sms_ticket (one recipient by request)
			'octopush' => [['id' => $get('message_id'), 'status' => match($get('status')) { 'DELIVERED' => 'delivered', 'NOT_DELIVERED', 'NOT_ALLOWED', 'BAD_DESTINATION', 'BLACKLISTED_NUMBER', 'EXPIRED', 'ANTI_FLOODING', 'UNFOUND_DESTINATION', 'TIMEOUT' => 'failed', default => '' }, 'error' => ($get('status') === 'DELIVERED') ? '' : strtolower(str_replace('_', ' ', $get('status'))), 'blacklisted' => $get('status') === 'BLACKLISTED_NUMBER']],
			default => [],
		};

	}

	private static function result(string $id, string $error='', bool $fatal=false, bool $blacklisted=false):array
	{

		return ['id' => $id, 'error' => $error, 'fatal' => $fatal, 'blacklisted' => $blacklisted];

	}

	// error of an answer: json field, else the http code and the start of the body
	private static function message(array $r, string $key):string
	{

		if($r['code'] === 0)return ts('provider unreachable: [ERROR]', ['ERROR' => $r['error']]);
		$message = (is_array($r['json']) && is_scalar($r['json'][$key] ?? null)) ? (string)$r['json'][$key] : mb_substr(trim(strip_tags($r['body'])), 0, 200);

		return "HTTP {$r['code']}: {$message}";

	}

	/**
	 * http request (curl)
	 *
	 * @return array{code: int, body: string, json: mixed, error: string} code 0 = no answer
	 */
	private static function http(string $method, string $url, array $headers=[], ?string $body=null, string $userpwd=''):array
	{

		$ch = curl_init($url);
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'Unee']);
		if($body !== null)curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		if($userpwd !== '')curl_setopt($ch, CURLOPT_USERPWD, $userpwd);

		$response = curl_exec($ch);
		$code = ($response === false) ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

		return ['code' => $code, 'body' => (string)$response, 'json' => json_decode((string)$response, true), 'error' => ($response === false) ? curl_error($ch) : ''];

	}

}
