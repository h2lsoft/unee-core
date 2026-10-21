<?php

// cron task "Newsletter sender": sends the due newsletters to their mailing lists, $params: limit (emails per run), max_seconds
// due = status scheduled or sending, programming_date and scheduler_date_start passed (when set), stopped after scheduler_date_end
// each recipient gets a row in xcore_newsletter_data (sent or sent-error): the next run resumes where this one stopped
// variables of the subject and the template: [[email]], [[firstname]], [[lastname]]

$limit = max(1, (int)($params['limit'] ?? 500));
$max_seconds = max(10, (int)($params['max_seconds'] ?? 780));
$started = time();
$now = now();
$total_sent = 0;
$errors = [];

// adds a query string to the http links of the html (not mailto, not anchors), before the fragment
$add_link_parameters = static function(string $html, string $query):string
{

	if($query === '')return $html;

	return preg_replace_callback('/(\bhref\s*=\s*["\'])(https?:\/\/[^"\'#]*)(#[^"\']*)?(["\'])/i', function(array $m) use ($query){

		$sep = str_contains($m[2], '?') ? '&' : '?';
		return $m[1].$m[2].$sep.$query.($m[3] ?? '').$m[4];

	}, $html);

};

// self-check of the link helper
assert($add_link_parameters('<a href="https://x.fr/a?b=1#top">', 'utm_source=nl') === '<a href="https://x.fr/a?b=1&utm_source=nl#top">');

// recipients left: subscribed to one of the lists, not blacklisted, not already processed for this newsletter
$recipients_sql = static function(array $newsletter, array $list_ids, bool $count):string
{

	$lists = implode(',', array_map('intval', $list_ids));
	$select = ($count) ? "COUNT(DISTINCT s.email)" : "s.id, s.xcore_mailinglist_id, s.email, s.firstname, s.lastname";

	return "SELECT {$select}
			FROM xcore_mailinglist_subscriber s
			WHERE s.deleted = 'no' AND s.unsubscribe_date IS NULL AND s.email != '' AND s.xcore_mailinglist_id IN ({$lists})
			AND NOT EXISTS (SELECT 1 FROM xcore_blacklist b WHERE b.deleted = 'no' AND b.email = s.email)
			AND NOT EXISTS (SELECT 1 FROM xcore_newsletter_data d WHERE d.deleted = 'no' AND d.xcore_newsletter_id = ".(int)$newsletter['id']." AND d.email = s.email AND d.action IN ('sent', 'sent-error'))"
			.(($count) ? "" : " ORDER BY s.id");

};

$newsletters = DB()->query("SELECT * FROM xcore_newsletter WHERE deleted = 'no' AND status IN ('scheduled', 'sending') AND (programming_date IS NULL OR programming_date <= :now) AND (scheduler_date_start IS NULL OR scheduler_date_start <= :now2) ORDER BY programming_date, id", ['now' => $now, 'now2' => $now])->fetchAll();

foreach($newsletters as $newsletter)
{

	if($total_sent >= $limit || time() - $started >= $max_seconds)break;

	try
	{

		preg_match_all('/\d+/', (string)$newsletter['mailinglist_ids'], $m);
		$list_ids = $m[0];
		$stopped = ($newsletter['scheduler_date_end'] && $newsletter['scheduler_date_end'] < $now);

		if(!$stopped && $list_ids)
		{

			// variants a/b/c: template url + subject (subject a when empty), a subscriber always gets the same one
			$variants = [];
			foreach(['', '2', '3'] as $n)
			{

				$url = trim((string)$newsletter["template_url{$n}"]);
				if($url === '')continue;

				// shortcut: no retry on a slow template server, the next run fetches it again
				$html = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30]]));
				if($html === false || trim($html) === '')throw new \RuntimeException("template not readable: {$url}");

				$query = trim((string)$newsletter['link_add_parameters']);
				if($newsletter['campaign'] !== '' && !str_contains($query, 'utm_campaign='))
					$query = ltrim($query.'&utm_campaign='.rawurlencode($newsletter['campaign']), '&');

				$subject = (trim((string)$newsletter["subject{$n}"]) !== '') ? $newsletter["subject{$n}"] : $newsletter['subject'];
				$variants[] = ['subject' => $subject, 'body' => $add_link_parameters($html, $query)];

			}

			if(!$variants)throw new \RuntimeException('no template url');

			if($newsletter['status'] === 'scheduled')
				DB()->query("UPDATE xcore_newsletter SET status = 'sending', updated_at = :now, updated_by = 'cron' WHERE id = :id", ['now' => now(), 'id' => $newsletter['id']]);

			$from = ($newsletter['expeditor_email_label'] !== '') ? [$newsletter['expeditor_email_label'], $newsletter['expeditor_email']] : $newsletter['expeditor_email'];
			$options = ($newsletter['reply_to'] !== '') ? ['reply-to' => $newsletter['reply_to']] : [];

			$subscribers = DB()->query($recipients_sql($newsletter, $list_ids, false)." LIMIT ".($limit - $total_sent))->fetchAll();
			$done = [];
			$sent = 0;
			$failed = 0;
			foreach($subscribers as $subscriber)
			{

				if(time() - $started >= $max_seconds)break;

				// same email in several lists of the newsletter: once
				$email = strtolower($subscriber['email']);
				if(isset($done[$email]))continue;
				$done[$email] = true;

				$variant = $variants[$subscriber['id'] % count($variants)];
				$data = ['email' => $subscriber['email'], 'firstname' => $subscriber['firstname'], 'lastname' => $subscriber['lastname']];
				$ok = \Core\Mailer::send($from, $subscriber['email'], $variant['subject'], $variant['body'], [], $options, $data, [], $newsletter['return_path']);

				DB('xcore_newsletter_data')->insert([
					'xcore_newsletter_id' => $newsletter['id'],
					'xcore_mailinglist_id' => $subscriber['xcore_mailinglist_id'],
					'xcore_mailinglist_subscriber_id' => $subscriber['id'],
					'date' => now(),
					'email' => $subscriber['email'],
					'action' => ($ok) ? 'sent' : 'sent-error',
					'conversion_params' => '',
					'error_message' => ($ok) ? '' : mb_substr(\Core\Mailer::getLastError(), 0, 255),
					'url' => '',
				], 'cron');

				if($ok)$sent++;
				else $failed++;
				$total_sent++;

			}

			$left = (int)DB()->query($recipients_sql($newsletter, $list_ids, true))->fetchOne();
			echo "#{$newsletter['id']} {$newsletter['subject']}: {$sent} sent, {$failed} error(s), {$left} left".CR;
			if($left > 0)continue;

		}

		// finished: every recipient processed, end of the sending window or no list
		DB()->query("UPDATE xcore_newsletter SET status = 'sent', updated_at = :now, updated_by = 'cron' WHERE id = :id", ['now' => now(), 'id' => $newsletter['id']]);
		$stats = DB()->query("SELECT action, COUNT(*) FROM xcore_newsletter_data WHERE deleted = 'no' AND xcore_newsletter_id = :id GROUP BY action", ['id' => $newsletter['id']])->fetchAll(\PDO::FETCH_KEY_PAIR);
		$summary = "Newsletter #{$newsletter['id']} {$newsletter['subject']}: ".((int)($stats['sent'] ?? 0))." sent, ".((int)($stats['sent-error'] ?? 0))." error(s)".(($stopped) ? ", stopped at the end of the sending window" : "");
		echo $summary.CR;

		if($newsletter['scheduler_report_email'] === 'yes')
		{

			$recipients = array_filter(array_map('trim', preg_split('/[;,\s]+/', (string)$newsletter['scheduler_report_email_recipients'])), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
			if($recipients)\Core\Mailer::send('', implode(';', $recipients), $summary, '<p>'.htmlspecialchars($summary).'</p>');

		}

	}
	catch(\Throwable $e)
	{

		// one newsletter in error does not block the others, the task ends in error
		$errors[] = "#{$newsletter['id']}: ".$e->getMessage();
		echo "#{$newsletter['id']} {$newsletter['subject']}: ".$e->getMessage().CR;

	}

}

echo "{$total_sent} email(s) processed".CR;
if($errors)throw new \RuntimeException(implode(' | ', $errors));
