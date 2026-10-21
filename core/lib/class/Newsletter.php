<?php

namespace Core;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * newsletters (xcore_newsletter): content, personalization, links, credits, recipients and sending
 *
 * - variables of the subject and the message: [[email]], [[firstname]], [[lastname]], [[online_url]], [[unsubscribe_url]] (required)
 * - links signed with the globals newsletter.secret (hmac of newsletter id and subscriber id): nothing stored by recipient
 * - public routes of NewsletterController: /api/newsletter/v|u|o|c/<token>/ = online view, unsubscribe, open pixel, click
 * - credits: newsletter.credits.monthly emails "sent" a month from newsletter.credits.reset_day, a newsletter is never started without enough credits
 * - every recipient processed gets a row in xcore_newsletter_data: sent, sent-error, blacklisted (pattern), refused (invalid email)
 */
class Newsletter
{

	public const array VARIABLES = ['email', 'firstname', 'lastname', 'online_url', 'unsubscribe_url'];

	// statuses where the newsletter is being sent: locked for edit and delete
	public const array LOCKED = ['sending', 'paused'];

	// actions of xcore_newsletter_data meaning the recipient was processed
	public const array PROCESSED = ['sent', 'sent-error', 'blacklisted', 'refused'];

	/**
	 * registers the globals of the newsletters (shown in the module Globals)
	 *
	 * @return void
	 */
	public static function register():void
	{

		Globals::get('newsletter.smtp.host', '', true, 'text', 'dedicated smtp host of the newsletters, empty = mailer.smtp.*');
		Globals::get('newsletter.smtp.port', '587', true, 'int', 'smtp port of the newsletters');
		Globals::get('newsletter.smtp.security', 'tls', true, 'text', 'smtp encryption of the newsletters: empty, tls or ssl');
		Globals::get('newsletter.smtp.username', '', true, 'text', 'smtp user of the newsletters, empty = no authentication');
		Globals::get('newsletter.smtp.password', '', true, 'text', 'smtp password of the newsletters');
		Globals::get('newsletter.pause.every', '0', true, 'int', 'pause after this number of emails, 0 = no pause');
		Globals::get('newsletter.pause.seconds', '10', true, 'int', 'duration of the pause in seconds');
		self::monthly();
		self::resetDay();
		self::unsubscribeScope();
		self::secret();

	}

	// settings *********************************************************************************************************

	public static function monthly():int
	{

		return max(0, (int)Globals::get('newsletter.credits.monthly', '100000', true, 'int', 'emails sent a month, 0 = unlimited'));

	}

	public static function resetDay():int
	{

		return max(1, min(28, (int)Globals::get('newsletter.credits.reset_day', '1', true, 'int', 'day of the month the credits are reset (1-28)')));

	}

	/**
	 * unsubscribe scope: lists (the lists of the newsletter), all (every list of the email), blacklist (every list + blacklist)
	 *
	 * @return string
	 */
	public static function unsubscribeScope():string
	{

		$scope = (string)Globals::get('newsletter.unsubscribe.scope', 'lists', true, 'text', 'unsubscribe link: lists (lists of the newsletter), all (every list) or blacklist (every list + blacklist)');
		return (in_array($scope, ['lists', 'all', 'blacklist'], true)) ? $scope : 'lists';

	}

	private static function secret():string
	{

		return (string)Globals::get('newsletter.secret', bin2hex(random_bytes(32)), true, 'text', 'secret of the newsletter links (online view, unsubscribe, tracking), a change breaks the links already sent');

	}

	// links ************************************************************************************************************

	public static function token(int $newsletter_id, int $subscriber_id):string
	{

		return "{$newsletter_id}.{$subscriber_id}.".substr(hash_hmac('sha256', "n{$newsletter_id}.{$subscriber_id}", self::secret()), 0, 16);

	}

	/**
	 * newsletter id and subscriber id of a valid token, null otherwise
	 *
	 * @param string $token
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public static function parseToken(string $token):?array
	{

		if(!preg_match('/^(\d{1,19})\.(\d{1,19})\.([a-f0-9]{16})$/', $token, $m))return null;
		if(!hash_equals(self::token((int)$m[1], (int)$m[2]), $token))return null;

		return [(int)$m[1], (int)$m[2]];

	}

	/**
	 * public url: v (online view), u (unsubscribe), o (open pixel), c (click)
	 *
	 * @param string $type
	 * @param string $token
	 *
	 * @return string
	 */
	public static function url(string $type, string $token):string
	{

		return rtrim((string)Config::get('url'), '/')."/api/newsletter/{$type}/{$token}/";

	}

	public static function clickSignature(string $token, string $url):string
	{

		return substr(hash_hmac('sha256', "c{$token}|{$url}", self::secret()), 0, 16);

	}

	// content **********************************************************************************************************

	/**
	 * variants a/b/c [{"subject", "html"}]: the snapshot taken at the start of the sending, else built from the content
	 *
	 * @param array $newsletter
	 *
	 * @return array
	 */
	public static function variants(array $newsletter):array
	{

		$snapshot = json_decode((string)($newsletter['snapshot_json'] ?? ''), true);
		if(is_array($snapshot) && $snapshot)return $snapshot;

		$variants = [];
		foreach(['', '2', '3'] as $n)
		{

			$subject = trim((string)($newsletter["subject{$n}"] ?? ''));
			if(($newsletter['content_type'] ?? 'url') === 'url')
			{

				$url = trim((string)($newsletter["template_url{$n}"] ?? ''));
				if($url === '')continue;
				$html = self::fetch($url);
				$base = (string)preg_replace('#^(https?://[^/]+).*$#i', '$1', $url);

			}
			else
			{

				// html content: a/b/c on the subject only
				if($n !== '' && $subject === '')continue;
				$html = (string)($newsletter['content_html'] ?? '');
				$base = rtrim((string)Config::get('url'), '/');

			}

			if($subject === '')$subject = trim((string)$newsletter['subject']);
			$variants[] = ['subject' => $subject, 'html' => self::prepare($html, $newsletter, $base)];

		}

		return $variants;

	}

	/**
	 * html of a template url
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public static function fetch(string $url):string
	{

		if(!preg_match('#^https?://#i', $url))throw new \RuntimeException("invalid template url: {$url}");

		$html = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 1], 'ssl' => ['verify_peer' => true]]));
		if($html === false || trim($html) === '')throw new \RuntimeException("template not readable: {$url}");

		return $html;

	}

	/**
	 * same html for every recipient: absolute urls, link parameters, preheader
	 *
	 * @param string $html
	 * @param array $newsletter
	 * @param string $base scheme and host of the relative urls
	 *
	 * @return string
	 */
	public static function prepare(string $html, array $newsletter, string $base):string
	{

		$html = preg_replace('/(\s(?:src|href)\s*=\s*["\'])\/(?!\/)/i', '$1'.$base.'/', $html);

		$query = trim((string)($newsletter['link_add_parameters'] ?? ''));
		if(($newsletter['campaign'] ?? '') !== '' && !str_contains($query, 'utm_campaign='))
			$query = ltrim($query.'&utm_campaign='.rawurlencode($newsletter['campaign']), '&');
		$html = self::addLinkParameters($html, $query);

		// preheader: first text shown by the mail clients, hidden in the message
		$preheader = trim((string)($newsletter['preheader'] ?? ''));
		if($preheader !== '')
		{

			$div = '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">'.htmlspecialchars($preheader).'</div>';
			$html = (preg_match('/<body[^>]*>/i', $html)) ? preg_replace('/(<body[^>]*>)/i', '$1'.$div, $html, 1) : $div.$html;

		}

		return $html;

	}

	/**
	 * adds a query string to the http links of the html (not mailto, not anchors), before the fragment
	 *
	 * @param string $html
	 * @param string $query
	 *
	 * @return string
	 */
	public static function addLinkParameters(string $html, string $query):string
	{

		if($query === '')return $html;

		return preg_replace_callback('/(\bhref\s*=\s*["\'])(https?:\/\/[^"\'#]*)(#[^"\']*)?(["\'])/i', function(array $m) use ($query){

			$sep = str_contains($m[2], '?') ? '&' : '?';
			return $m[1].$m[2].$sep.$query.($m[3] ?? '').$m[4];

		}, $html);

	}

	/**
	 * subject and html of a recipient: variables, click tracking, open pixel
	 *
	 * @param array $variant {"subject", "html"}
	 * @param int $newsletter_id
	 * @param array $subscriber {"id", "email", "firstname", "lastname"}, id 0 for a test
	 * @param bool $tracking
	 *
	 * @return array{subject: string, html: string, token: string}
	 */
	public static function render(array $variant, int $newsletter_id, array $subscriber, bool $tracking=true):array
	{

		$token = self::token($newsletter_id, (int)$subscriber['id']);
		$values = ['email' => (string)$subscriber['email'], 'firstname' => (string)$subscriber['firstname'], 'lastname' => (string)$subscriber['lastname'], 'online_url' => self::url('v', $token), 'unsubscribe_url' => self::url('u', $token)];

		$subject = $variant['subject'];
		$html = $variant['html'];
		foreach($values as $key => $value)
		{

			$subject = str_replace("[[{$key}]]", $value, $subject);
			$html = str_replace("[[{$key}]]", htmlspecialchars($value), $html);

		}

		if($tracking)
		{

			$own = rtrim((string)Config::get('url'), '/').'/api/newsletter/';
			$html = preg_replace_callback('/(\bhref\s*=\s*["\'])(https?:\/\/[^"\']+)(["\'])/i', function(array $m) use ($token, $own){

				$url = html_entity_decode($m[2]);
				if(str_starts_with($url, $own))return $m[0];

				$click = self::url('c', $token).'?u='.rawurlencode($url).'&s='.self::clickSignature($token, $url);
				return $m[1].htmlspecialchars($click).$m[3];

			}, $html);

			$pixel = '<img src="'.self::url('o', $token).'" width="1" height="1" alt="" style="display:block;border:0;">';
			$html = (stripos($html, '</body>') !== false) ? str_ireplace('</body>', $pixel.'</body>', $html) : $html.$pixel;

		}

		return ['subject' => $subject, 'html' => $html, 'token' => $token];

	}

	/**
	 * errors preventing the sending, translated
	 *
	 * @param array $newsletter
	 * @param array $variants
	 *
	 * @return array
	 */
	public static function checks(array $newsletter, array $variants):array
	{

		$errors = [];
		if(!self::listIds($newsletter))$errors[] = ts('choose at least one mailing list');
		if(!filter_var((string)$newsletter['expeditor_email'], FILTER_VALIDATE_EMAIL))$errors[] = ts('the sender email is invalid');
		if(!$variants)$errors[] = ts('the message is empty');

		foreach($variants as $variant)
		{

			if(trim($variant['subject']) === '')$errors[] = ts('the subject is empty');
			if(!str_contains($variant['html'], '[[unsubscribe_url]]'))$errors[] = ts('the message must contain the unsubscribe link [[unsubscribe_url]]');

		}

		return array_values(array_unique($errors));

	}

	// recipients *******************************************************************************************************

	public static function listIds(array $newsletter):array
	{

		preg_match_all('/\d+/', (string)($newsletter['mailinglist_ids'] ?? ''), $m);
		return array_map('intval', $m[0]);

	}

	/**
	 * subscribers left: in one of the lists, not unsubscribed, not blacklisted (exact email), not processed yet
	 *
	 * @param int $newsletter_id 0 = estimate
	 * @param array $list_ids
	 * @param bool $count number of distinct emails
	 *
	 * @return string
	 */
	public static function recipientsSql(int $newsletter_id, array $list_ids, bool $count):string
	{

		$lists = implode(',', array_map('intval', $list_ids ?: [0]));
		$processed = "'".implode("', '", self::PROCESSED)."'";
		$select = ($count) ? "COUNT(DISTINCT s.email)" : "s.id, s.xcore_mailinglist_id, s.email, s.firstname, s.lastname";

		return "SELECT {$select}
				FROM xcore_mailinglist_subscriber s
				WHERE s.deleted = 'no' AND s.unsubscribe_date IS NULL AND s.email != '' AND s.xcore_mailinglist_id IN ({$lists})
				AND NOT EXISTS (SELECT 1 FROM xcore_blacklist b WHERE b.deleted = 'no' AND b.email = s.email)
				AND NOT EXISTS (SELECT 1 FROM xcore_newsletter_data d WHERE d.deleted = 'no' AND d.xcore_newsletter_id = ".(int)$newsletter_id." AND d.email = s.email AND d.action IN ({$processed}))"
				.(($count) ? "" : " ORDER BY s.id");

	}

	/**
	 * emails left to send (estimate of a new newsletter with id 0): duplicates and exact blacklist removed, the patterns of the blacklist may reduce it
	 *
	 * @param int $newsletter_id
	 * @param array $list_ids
	 *
	 * @return int
	 */
	public static function remainingRecipients(int $newsletter_id, array $list_ids):int
	{

		if(!$list_ids)return 0;
		return (int)DB()->query(self::recipientsSql($newsletter_id, $list_ids, true))->fetchOne();

	}

	/**
	 * patterns of the blacklist (*@hotmail.fr, contact@*) as regexes
	 *
	 * @return array
	 */
	public static function blacklistPatterns():array
	{

		$patterns = [];
		foreach(DB()->query("SELECT email FROM xcore_blacklist WHERE deleted = 'no' AND email LIKE '%*%'")->fetchAllOne() as $pattern)
			$patterns[] = '/^'.str_replace('\*', '.*', preg_quote(mb_strtolower(trim($pattern)), '/')).'$/u';

		return $patterns;

	}

	public static function isBlacklisted(string $email, array $patterns):bool
	{

		$email = mb_strtolower($email);
		foreach($patterns as $pattern)
			if(preg_match($pattern, $email))return true;

		return false;

	}

	// credits **********************************************************************************************************

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
	 * credits of the period: monthly (0 = unlimited), used, remaining, percent left, next reset
	 *
	 * @return array{monthly: int, used: int, remaining: int, percent: int, reset: string}
	 */
	public static function credits():array
	{

		$monthly = self::monthly();
		$start = self::periodStart();
		$used = (int)DB()->query("SELECT COUNT(*) FROM xcore_newsletter_data WHERE deleted = 'no' AND action = 'sent' AND date >= :start", ['start' => $start])->fetchOne();
		$remaining = ($monthly === 0) ? PHP_INT_MAX : max(0, $monthly - $used);
		$percent = ($monthly === 0) ? 100 : (int)floor($remaining * 100 / $monthly);
		$reset = date('Y-m-d', strtotime($start.' +1 month'));

		return ['monthly' => $monthly, 'used' => $used, 'remaining' => $remaining, 'percent' => $percent, 'reset' => $reset];

	}

	/**
	 * credits left for a newsletter: remaining credits minus the emails left of the other newsletters being sent
	 *
	 * @param int $newsletter_id
	 *
	 * @return int
	 */
	public static function availableCredits(int $newsletter_id):int
	{

		$credits = self::credits();
		if($credits['monthly'] === 0)return PHP_INT_MAX;

		$reserved = 0;
		foreach(DB()->query("SELECT id, mailinglist_ids FROM xcore_newsletter WHERE deleted = 'no' AND status IN ('sending', 'paused') AND id != :id", ['id' => $newsletter_id])->fetchAll() as $other)
			$reserved += self::remainingRecipients((int)$other['id'], self::listIds($other));

		return max(0, $credits['remaining'] - $reserved);

	}

	// sending **********************************************************************************************************

	/**
	 * mailer of the newsletters: dedicated smtp (newsletter.smtp.host) or the one of the application, connection kept between emails
	 *
	 * @return PHPMailer
	 */
	public static function mailer():PHPMailer
	{

		self::register();

		$mail = new PHPMailer(true);
		Mailer::transport($mail, (trim((string)Globals::get('newsletter.smtp.host', '')) !== '') ? 'newsletter.smtp' : 'mailer.smtp');
		$mail->SMTPKeepAlive = true;
		$mail->CharSet = 'utf-8';
		$mail->XMailer = null;
		$mail->isHTML(true);

		return $mail;

	}

	/**
	 * sends one email, '' when sent, the error otherwise
	 *
	 * @param PHPMailer $mail
	 * @param array $newsletter
	 * @param string $to
	 * @param array $rendered see render()
	 *
	 * @return string
	 */
	public static function send(PHPMailer $mail, array $newsletter, string $to, array $rendered):string
	{

		try
		{

			$mail->clearAllRecipients();
			$mail->clearReplyTos();
			$mail->clearCustomHeaders();

			$mail->setFrom((string)$newsletter['expeditor_email'], (string)$newsletter['expeditor_email_label'], false);
			$mail->Sender = (string)(($newsletter['return_path'] ?? '') ?: $newsletter['expeditor_email']);
			if(($newsletter['reply_to'] ?? '') !== '')$mail->addReplyTo((string)$newsletter['reply_to']);
			$mail->addAddress($to);

			// one-click unsubscribe (rfc 8058), token found back in the bounces
			$unsubscribe = self::url('u', $rendered['token']);
			$mail->addCustomHeader('List-Unsubscribe', "<{$unsubscribe}>");
			$mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
			$mail->addCustomHeader('X-Unee-Newsletter', $rendered['token']);

			$mail->Subject = $rendered['subject'];
			$mail->Body = $rendered['html'];
			$mail->AltBody = $mail->html2text($rendered['html'], true);

			$mail->send();
			return '';

		}
		catch(\Throwable $e)
		{

			// smtp kept alive: a failed transaction must not break the next one
			if($mail->Mailer === 'smtp')$mail->getSMTPInstance()->reset();
			return mb_substr(($mail->ErrorInfo !== '') ? $mail->ErrorInfo : $e->getMessage(), 0, 255);

		}

	}

	/**
	 * row of xcore_newsletter_data
	 *
	 * @param int $newsletter_id
	 * @param array $subscriber {"id", "xcore_mailinglist_id", "email"}
	 * @param string $action
	 * @param string $error
	 * @param string $url
	 * @param string $ip
	 * @param string $user_agent
	 *
	 * @return void
	 */
	public static function log(int $newsletter_id, array $subscriber, string $action, string $error='', string $url='', string $ip='', string $user_agent=''):void
	{

		DB('xcore_newsletter_data')->insert([
			'xcore_newsletter_id' => $newsletter_id,
			'xcore_mailinglist_id' => (int)($subscriber['xcore_mailinglist_id'] ?? 0),
			'xcore_mailinglist_subscriber_id' => (int)($subscriber['id'] ?? 0),
			'date' => now(),
			'email' => (string)$subscriber['email'],
			'action' => $action,
			'user_agent' => mb_substr($user_agent, 0, 255),
			'ip' => mb_substr($ip, 0, 255),
			'conversion_params' => '',
			'error_message' => mb_substr($error, 0, 255),
			'url' => $url,
		], 'newsletter');

	}

	/**
	 * figures of a newsletter: processed (sent, errors, blacklisted, refused), unique opens and clicks, clicks, unsubscribes, bounces
	 *
	 * @param int $newsletter_id
	 *
	 * @return array
	 */
	public static function stats(int $newsletter_id):array
	{

		$stats = DB()->query("SELECT action, COUNT(*) AS total, COUNT(DISTINCT email) AS uniq FROM xcore_newsletter_data WHERE deleted = 'no' AND xcore_newsletter_id = :id GROUP BY action", ['id' => $newsletter_id])->fetchAll();

		$result = ['sent' => 0, 'sent-error' => 0, 'blacklisted' => 0, 'refused' => 0, 'view' => 0, 'click' => 0, 'clicks' => 0, 'unsubscribe' => 0, 'bounce' => 0];
		foreach($stats as $row)
		{

			if(!array_key_exists($row['action'], $result))continue;
			$result[$row['action']] = (in_array($row['action'], ['view', 'click', 'unsubscribe', 'bounce'], true)) ? (int)$row['uniq'] : (int)$row['total'];
			if($row['action'] === 'click')$result['clicks'] = (int)$row['total'];

		}

		return $result;

	}

	/**
	 * unsubscribes the email of a subscriber, scope newsletter.unsubscribe.scope
	 *
	 * @param array $newsletter
	 * @param array $subscriber
	 *
	 * @return void
	 */
	public static function unsubscribe(array $newsletter, array $subscriber):void
	{

		$scope = self::unsubscribeScope();
		$where = "deleted = 'no' AND unsubscribe_date IS NULL AND email = :email";
		if($scope === 'lists')$where .= " AND xcore_mailinglist_id IN (".implode(',', self::listIds($newsletter) ?: [0]).")";

		DB()->query("UPDATE xcore_mailinglist_subscriber SET unsubscribe_date = :now, unsubscribe_newsletter_id = :nid, updated_at = :now2, updated_by = 'newsletter' WHERE {$where}", ['now' => now(), 'nid' => $newsletter['id'], 'now2' => now(), 'email' => $subscriber['email']]);

		if($scope === 'blacklist' && !DB()->query("SELECT 1 FROM xcore_blacklist WHERE deleted = 'no' AND email = :email", ['email' => mb_strtolower($subscriber['email'])])->fetchOne())
			DB('xcore_blacklist')->insert(['date' => now(), 'email' => mb_strtolower($subscriber['email'])], 'newsletter');

	}

}
