<?php

namespace Core;

use Core\Config;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * emails of the application: mail templates (xcore_mail_template) or raw html, smtp of the globals mailer.smtp.*
 *
 * credits: every email that goes out uses 1 credit of mail.credits.monthly (0 = unlimited), from mail.credits.reset_day
 * - used = rows sent of xcore_mail_sent (written by send()) + the newsletters sent (xcore_newsletter_data, never counted twice)
 * - transactional emails (default) are sent while a credit is left, marketing ones keep mail.credits.reserve percent for them
 * - refused (no credit) and failed (smtp error) emails are written too, they use no credit
 * - options of send() and sendTemplate(): category (transactional, marketing), module, action, record_id, xcore_user_id, application, credit (false = checked, not written)
 * - never put a secret (code, link) in a subject: subjects are kept in xcore_mail_sent
 */
class Mailer
{
	public static string $last_error = "";

	public const array CATEGORIES = ['transactional', 'marketing'];

	public static function getLastError():string {return self::$last_error;}


	/**
	 * Send email by module email template
	 *
	 * @param string|int $template_name
	 * @param string     $to
	 * @param array      $vars
	 * @param string     $locale
	 * @param array      $attachments
	 * @param array      $options see send()
	 *
	 * @return bool
	 */
	public static function sendTemplate(string|int $template_name, string $to, array $vars=[], string $locale='', array $attachments=[], array $options=[]):bool
	{
		if(empty($locale))$locale = App()->locale;
		$locale = strtolower($locale);

		$column = (is_int($template_name)) ? 'id' : 'name';
		$template = \Model\Mail_Template::findOne("{$column} = :value", [':value' => $template_name], '*');
		if(!$template)
		{

			self::$last_error = "mail template not found: {$template_name}";
			return false;

		}

		if(($template['active'] ?? 'yes') === 'no')
		{

			self::$last_error = "mail template inactive: {$template_name}";
			return false;

		}

		// templates hold en and fr columns: other languages fall back to en
		if(!array_key_exists("body_{$locale}", $template))$locale = 'en';

		$from = $template["sender_{$locale}"];

		$subject = $template["subject_{$locale}"];
		$body = $template["body_{$locale}"];
		$body = str_replace(['[[ ', ' ]]'], ['[[', ']]'], $body);

		// vars are escaped in an html body (user data), the subject is plain text on one line
		$html_mode = (isset($options['html'])) ? $options['html'] : true;
		foreach($vars as $var => $val)
		{

			$body = str_replace("[[{$var}]]", ($html_mode) ? htmlspecialchars((string)$val, ENT_QUOTES) : (string)$val, $body);
			$subject = str_replace("[[{$var}]]", str_replace(["\r", "\n"], ' ', (string)$val), $subject);

		}

		// add layout
		if($template['xcore_layout_id'])
		{
			$layout = \Model\Layout::findById($template['xcore_layout_id']);
			if($layout)
			{
				$body = str_replace(['[CONTENT]'], $body, $layout['content']);
			}
		}

		$options += ['template' => (string)$template['name']];

		return \Core\Mailer::send($from, $to, $subject, $body, $attachments, $options);

	}



	/**
	 * transport from globals: smtp when <prefix>.host is set, otherwise php mail()
	 *
	 * @param PHPMailer $mail
	 * @param string $prefix globals prefix, ex: newsletter.smtp for a dedicated server
	 *
	 * @return void
	 */
	public static function transport(PHPMailer $mail, string $prefix='mailer.smtp'):void
	{

		$smtp_host = trim((string)\Core\Globals::get("{$prefix}.host", ($prefix === 'mailer.smtp') ? 'localhost' : '', true, 'text', 'smtp host, empty = php mail()'));
		if($smtp_host === '')return;

		$mail->isSMTP();
		$mail->Host = $smtp_host;
		$mail->Port = (int)\Core\Globals::get("{$prefix}.port", '25', true, 'int', 'smtp port');

		$security = (string)\Core\Globals::get("{$prefix}.security", '', true, 'text', 'smtp encryption: empty, tls or ssl');
		$mail->SMTPSecure = (in_array($security, ['tls', 'ssl'], true)) ? $security : '';

		$username = (string)\Core\Globals::get("{$prefix}.username", '', true, 'text', 'smtp user, empty = no authentication');
		if($username !== '')
		{

			$mail->SMTPAuth = true;
			$mail->Username = $username;
			$mail->Password = (string)\Core\Globals::get("{$prefix}.password", '', true, 'text', 'smtp password');

		}

	}

	/**
	 * @param string|array $from array(author|email) or string with | as separator
	 * @param string|array $to  if string separated by ;
	 * @param string       $subject
	 * @param string       $body
	 * @param array        $attachments array name and path
	 * @param array        $options (like charset : utf-8 by default or html : true by default or reply-to, bcc, cc), credits: category, module, action, record_id, xcore_user_id, application, template, credit
	 * @param array        $data append value for data in body
	 *
	 * @return bool false when not sent (getLastError(): "no email credits" when refused by the credits)
	 */
	public static function send(string|array $from, string $to, string $subject, string $body, array $attachments=[], array $options=[], array $data=[], array $headers=[], $return_path=''):bool
	{
		global $request;

		$mail = new PHPMailer(true);

		self::transport($mail);

		if(!isset($options['charset']))$options['charset'] = 'utf-8';
		$mail->CharSet = $options['charset'];

		if(!isset($options['X-Mailer']) && !isset($options['XMailer']) && !isset($options['x-mailer']))
			$mail->XMailer = null;

		// add headers
		foreach($headers as $header_name => $header_value)
		{
			$mail->addCustomHeader($header_name, $header_value);
		}

		// html
		$html_mode = (isset($options['html'])) ? $options['html'] : true;
		$mail->isHTML($html_mode);

		// from
		if(empty($from))$from = \Core\Config::get('noreply_email');
		$from_email = $from;
		$from_author = '';
		if(is_array($from))
		{
			$from_author = $from[0];
			$from_email = $from[1];
		}
		elseif(count(explode('|', $from)) == 2)
		{
			list($from_author, $from_email) = explode('|', $from);
		}

		$mail->setFrom(trim($from_email), trim($from_author), true);

		// to
		if(!is_array($to))
		{
			$to = str_erase(' ', str_replace(',', ';', trim($to)));
			$to = explode(';', $to);
			$to = array_map('trim', $to);
		}

		foreach($to as $email)
		{
			$mail->addAddress($email);
		}

		// attachments
		foreach($attachments as $file)
		{
			if(empty($file['name']))$file['name'] = basename($file['path']);
			$mail->addAttachment($file['path'], $file['name']);
		}

		// reply-to
		if(isset($options['reply-to']) && !empty($options['reply-to']))
			$mail->addReplyTo($options['reply-to']);

		// cc
		if(isset($options['cc']))
		{
			$to = $options['cc'];
			if(!is_array($to))
			{
				$to = str_erase(' ', str_replace(',', ';', trim($options['cc'])));
				$to = explode(';', $to);
				$to = array_map('trim', $to);
			}

			foreach($to as $email)
				$mail->addCC($email);
		}

		// bcc
		if(isset($options['bcc']))
		{
			$to = $options['bcc'];
			if(!is_array($to))
			{
				$to = str_erase(' ', str_replace(',', ';', trim($options['bcc'])));
				$to = explode(';', $to);
				$to = array_map('trim', $to);
			}

			foreach($to as $email)
				$mail->addBCC($email);
		}

		// parsing subject, body
		foreach($data as $key => $val)
		{
			$subject = str_replace("[[{$key}]]", $val, $subject);
			$body = str_replace("[[{$key}]]", $val, $body);
		}

		$mail->Subject = $subject;

		$uri = \Core\Config::get('url');
		// @backend: folder of the backend (config backend.dirname), ex: <img src="/@backend/logo-mail.png">
		$body = str_replace('/@backend/', '/'.\Core\Backend::dirname().'/', $body);
		$body = str_replace(' src="/',  " src=\"{$uri}/", $body);
		$body = str_replace(' href="/',  " href=\"{$uri}/", $body);

		$mail->Body = $body;

		if(!empty($return_path))
			$mail->Sender = $return_path;

		// credits: one email, whatever the number of recipients (an email of the application has one recipient)
		$category = (($options['category'] ?? '') === 'marketing') ? 'marketing' : 'transactional';
		$credit = (($options['credit'] ?? true) !== false);
		if(!self::canSend($category))
		{

			self::$last_error = 'no email credits';
			if($credit)self::record('refused', $subject, $options);
			if($category === 'transactional')self::alert('refused');
			return false;

		}

		try {
			$mail->send();
			if($credit)self::record('sent', $subject, $options);
			self::alert('low');
			return true;
		} catch (Exception $e) {
			self::$last_error = "Mailer Error: {$mail->ErrorInfo}";
			if($credit)self::record('failed', $subject, $options);
			return false;
		}

	}

	// credits **********************************************************************************************************

	/**
	 * registers the globals of the credits (shown in the module Globals)
	 *
	 * @return void
	 */
	public static function register():void
	{

		self::monthly();
		self::resetDay();
		self::reserve();
		self::alertBelow();
		self::alertKeys();

	}

	/**
	 * emails a month, 0 = unlimited
	 *
	 * @return int
	 */
	public static function monthly():int
	{

		return max(0, (int)Globals::get('mail.credits.monthly', '100000', true, 'int', 'emails sent a month (every email: newsletters, sign-in codes, notifications...), 0 = unlimited'));

	}

	/**
	 * day of the month the credits are reset (1-28)
	 *
	 * @return int
	 */
	public static function resetDay():int
	{

		return max(1, min(28, (int)Globals::get('mail.credits.reset_day', '1', true, 'int', 'day of the month the email credits are reset (1-28)')));

	}

	/**
	 * percent of the monthly credits kept for the transactional emails (0-50)
	 *
	 * @return int
	 */
	public static function reserve():int
	{

		return max(0, min(50, (int)Globals::get('mail.credits.reserve', '2', true, 'int', 'percent of the monthly credits kept for the transactional emails (sign-in codes, passwords...), never used by the newsletters')));

	}

	/**
	 * credits left under which the super admins are notified, 0 = never
	 *
	 * @return int
	 */
	public static function alertBelow():int
	{

		return max(0, (int)Globals::get('mail.alert.below', '1000', true, 'int', 'the super admins are notified once a period when the email credits left go below, 0 = never'));

	}

	/**
	 * start of the current credit period (reset day of this month, or of the previous one)
	 *
	 * @param int|null $time
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
	 * credits of the period
	 * remaining: PHP_INT_MAX when unlimited, reserve: credits kept for the transactional emails, available: what the marketing can use (remaining - reserve)
	 *
	 * @return array{monthly: int, used: int, transactional: int, marketing: int, remaining: int, reserve: int, available: int, percent: int, reset: string}
	 */
	public static function credits():array
	{

		$monthly = self::monthly();
		$start = self::periodStart();

		$used = ['transactional' => 0, 'marketing' => 0];
		foreach(DB()->query("SELECT category, COUNT(*) AS total FROM xcore_mail_sent WHERE deleted = 'no' AND status = 'sent' AND date >= :start GROUP BY category", ['start' => $start])->fetchAll() as $row)
			$used[($row['category'] === 'marketing') ? 'marketing' : 'transactional'] += (int)$row['total'];

		$used['marketing'] += (int)DB()->query("SELECT COUNT(*) FROM xcore_newsletter_data WHERE deleted = 'no' AND action = 'sent' AND date >= :start", ['start' => $start])->fetchOne();

		$total = $used['transactional'] + $used['marketing'];
		$unlimited = ($monthly === 0);
		$remaining = ($unlimited) ? PHP_INT_MAX : max(0, $monthly - $total);
		$reserve = ($unlimited) ? 0 : (int)ceil($monthly * self::reserve() / 100);

		return [
			'monthly' => $monthly,
			'used' => $total,
			'transactional' => $used['transactional'],
			'marketing' => $used['marketing'],
			'remaining' => $remaining,
			'reserve' => $reserve,
			'available' => ($unlimited) ? PHP_INT_MAX : max(0, $remaining - $reserve),
			'percent' => ($unlimited) ? 100 : (int)floor($remaining * 100 / $monthly),
			'reset' => date('Y-m-d', strtotime($start.' +1 month')),
		];

	}

	/**
	 * enough credits for emails of a category: transactional while a credit is left, marketing above the reserve
	 * credits unreadable (migration not run yet): allowed
	 *
	 * @param string $category transactional or marketing
	 * @param int $count emails to send
	 *
	 * @return bool
	 */
	public static function canSend(string $category='transactional', int $count=1):bool
	{

		try
		{

			$c = self::credits();

		}
		catch(\Throwable $e)
		{

			return true;

		}

		if($c['monthly'] === 0)return true;
		return ((($category === 'marketing') ? $c['available'] : $c['remaining']) >= $count);

	}

	/**
	 * writes an email in xcore_mail_sent, sent = 1 credit used
	 *
	 * @param string $status sent, failed or refused
	 * @param string $subject final subject
	 * @param array $options category, module, action, record_id, xcore_user_id, application, template
	 *
	 * @return void
	 */
	public static function record(string $status, string $subject, array $options=[]):void
	{

		try
		{

			DB('xcore_mail_sent')->insert([
				'date' => now(),
				'application' => mb_substr((string)($options['application'] ?? self::application()), 0, 20),
				'module' => mb_substr((string)($options['module'] ?? self::module()), 0, 255),
				'action' => mb_substr((string)($options['action'] ?? ''), 0, 255),
				'record_id' => max(0, (int)($options['record_id'] ?? 0)),
				'category' => (($options['category'] ?? '') === 'marketing') ? 'marketing' : 'transactional',
				'template' => mb_substr((string)($options['template'] ?? ''), 0, 255),
				'subject' => mb_substr(str_replace(["\r", "\n"], ' ', $subject), 0, 255),
				'xcore_user_id' => max(0, (int)($options['xcore_user_id'] ?? 0)),
				'status' => $status,
			], (string)(\Core\Session::get('auth.login') ?: 'mailer'));

		}
		catch(\Throwable $e)
		{

			// table not created yet (migration not run): the email is not blocked

		}

	}

	/**
	 * notifications of the super admins, once a period each: low (credits below mail.alert.below), refused (a transactional email refused)
	 *
	 * @param string $type low or refused
	 *
	 * @return void
	 */
	public static function alert(string $type):void
	{

		try
		{

			if($type === 'low' && self::alertBelow() <= 0)return;

			$c = self::credits();
			if($c['monthly'] === 0 || ($type === 'low' && $c['remaining'] >= self::alertBelow()))return;

			$keys = self::alertKeys();
			if(($keys[$type] ?? '') === $c['reset'])return;

			$keys[$type] = $c['reset'];
			$value = json_encode($keys);
			Globals::set('mail.alert.key', $value, 'text', 'set by the code: period of the last alerts of the email credits');
			App()->globals['mail.alert.key'] = $value;

			$link = 'globals/?search[]='.rawurlencode('q||mail.credits');
			if($type === 'low')
				Notification::sendGroup(\Model\User::GROUP_SUPERADMIN, ts('Email credits are running low'), ts('[COUNT] credits left', ['COUNT' => number_format($c['remaining'], 0, ',', ' ')]), $link, 'warning', 'Email');
			else
				Notification::sendGroup(\Model\User::GROUP_SUPERADMIN, ts('Email credits exhausted'), ts('transactional emails (sign-in codes, passwords...) are refused until the reset on [DATE]', ['DATE' => $c['reset']]), $link, 'error', 'Email');

		}
		catch(\Throwable $e)
		{

			// an alert never blocks the email

		}

	}

	/**
	 * periods of the last alerts: {"low": "Y-m-d", "refused": "Y-m-d"}
	 *
	 * @return array
	 */
	private static function alertKeys():array
	{

		$keys = json_decode((string)Globals::get('mail.alert.key', '', true, 'text', 'set by the code: period of the last alerts of the email credits'), true);
		return (is_array($keys)) ? $keys : [];

	}

	/**
	 * application of the request: cron or cli (console), backend (api called by the react backend), api (other clients), frontend
	 *
	 * @return string
	 */
	private static function application():string
	{

		global $request;

		if(PHP_SAPI === 'cli')return (Cron::current()) ? 'cron' : 'cli';
		if(!$request instanceof \Symfony\Component\HttpFoundation\Request)return 'frontend';

		if(str_starts_with($request->getPathInfo(), '/api/'))
			return ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') ? 'backend' : 'api';

		return (str_starts_with($request->getPathInfo(), '/'.Backend::dirname().'/')) ? 'backend' : 'frontend';

	}

	/**
	 * module of the current route (api.<module>.<action>), '' otherwise
	 *
	 * @return string
	 */
	private static function module():string
	{

		global $request;

		if(!$request instanceof \Symfony\Component\HttpFoundation\Request)return '';

		$parts = explode('.', (string)$request->attributes->get('_route', ''));
		return ($parts[0] === 'api' && isset($parts[1])) ? str_replace('_', '-', $parts[1]) : '';

	}
}
