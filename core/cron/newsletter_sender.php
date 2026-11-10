<?php

// cron task "Newsletter sender": sends the due newsletters, $params: limit (emails per run), max_seconds
// due = status scheduled or sending, programming_date and scheduler_date_start passed (when set), sent when every recipient is processed or after scheduler_date_end
// start (scheduled -> sending): checks, credits (never started without enough credits: status error), html frozen in snapshot_json
// each recipient gets a row in xcore_newsletter_data: the next run resumes where this one stopped
// globals: newsletter.smtp.* (dedicated smtp), newsletter.pause.every / newsletter.pause.seconds, email credits mail.credits.* (\Core\Mailer, reserve of the transactional emails never used)

$limit = max(1, (int)($params['limit'] ?? 500));
$max_seconds = max(10, (int)($params['max_seconds'] ?? 780));
$started = time();
$now = now();
$total_sent = 0;
$errors = [];

\Core\Newsletter::register();
$pause_every = max(0, (int)\Core\Globals::get('newsletter.pause.every', '0'));
$pause_seconds = max(0, (int)\Core\Globals::get('newsletter.pause.seconds', '10'));

// status and error of a newsletter
$status = static function(int $id, string $status, string $error=''):void
{

	DB()->query("UPDATE xcore_newsletter SET status = :status, error_message = :error, updated_at = :now, updated_by = 'newsletter' WHERE id = :id", ['status' => $status, 'error' => mb_substr($error, 0, 255), 'now' => now(), 'id' => $id]);

};

$newsletters = DB()->query("SELECT * FROM xcore_newsletter WHERE deleted = 'no' AND status IN ('scheduled', 'sending') AND (programming_date IS NULL OR programming_date <= :now) AND (scheduler_date_start IS NULL OR scheduler_date_start <= :now2) ORDER BY programming_date, id", ['now' => $now, 'now2' => $now])->fetchAll();

$mail = null;
$patterns = null;
foreach($newsletters as $newsletter)
{

	if($total_sent >= $limit || time() - $started >= $max_seconds)break;

	$id = (int)$newsletter['id'];
	try
	{

		$list_ids = \Core\Newsletter::listIds($newsletter);
		$stopped = ($newsletter['scheduler_date_end'] && $newsletter['scheduler_date_end'] < $now);

		if(!$stopped)
		{

			if($newsletter['status'] === 'scheduled')
			{

				$variants = \Core\Newsletter::variants($newsletter);
				$checks = \Core\Newsletter::checks($newsletter, $variants);
				if($checks)
				{

					$status($id, 'error', implode(' / ', $checks));
					echo "#{$id} {$newsletter['subject']}: ".implode(' / ', $checks).CR;
					continue;

				}

				// never started without enough credits for every recipient
				$needed = \Core\Newsletter::remainingRecipients($id, $list_ids);
				$available = \Core\Newsletter::availableCredits($id);
				if($needed > $available)
				{

					$error = ts('insufficient credits: [NEEDED] needed, [AVAILABLE] available', ['NEEDED' => $needed, 'AVAILABLE' => $available]);
					$status($id, 'error', $error);
					echo "#{$id} {$newsletter['subject']}: {$error}".CR;
					continue;

				}

				// html frozen: the template url may change during the sending
				DB()->query("UPDATE xcore_newsletter SET status = 'sending', error_message = '', snapshot_json = :snapshot, updated_at = :now, updated_by = 'newsletter' WHERE id = :id", ['snapshot' => json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'now' => now(), 'id' => $id]);
				$newsletter['snapshot_json'] = json_encode($variants);

			}

			$variants = \Core\Newsletter::variants($newsletter);
			$mail ??= \Core\Newsletter::mailer();
			$patterns ??= \Core\Newsletter::blacklistPatterns();
			$credits = \Core\Mailer::credits()['available'];

			$subscribers = DB()->query(\Core\Newsletter::recipientsSql($id, $list_ids, false)." LIMIT ".($limit - $total_sent))->fetchAll();
			$done = [];
			$sent = 0;
			$failed = 0;
			$skipped = 0;
			$interrupted = '';
			foreach($subscribers as $subscriber)
			{

				if(time() - $started >= $max_seconds)break;

				// same email in several lists of the newsletter: once
				$email = mb_strtolower(trim($subscriber['email']));
				if(isset($done[$email]))continue;
				$done[$email] = true;

				if(!filter_var($email, FILTER_VALIDATE_EMAIL))
				{

					\Core\Newsletter::log($id, $subscriber, 'refused', 'invalid email');
					$skipped++;
					continue;

				}

				if(\Core\Newsletter::isBlacklisted($email, $patterns))
				{

					\Core\Newsletter::log($id, $subscriber, 'blacklisted', 'blacklist pattern');
					$skipped++;
					continue;

				}

				// new subscribers since the start may exceed the credits
				if($credits <= 0)
				{

					$interrupted = ts('insufficient credits: [NEEDED] needed, [AVAILABLE] available', ['NEEDED' => \Core\Newsletter::remainingRecipients($id, $list_ids), 'AVAILABLE' => 0]);
					break;

				}

				$variant = $variants[(int)$subscriber['id'] % count($variants)];
				$rendered = \Core\Newsletter::render($variant, $id, $subscriber);
				$error = \Core\Newsletter::send($mail, $newsletter, $email, $rendered);
				\Core\Newsletter::log($id, $subscriber, ($error === '') ? 'sent' : 'sent-error', $error);

				if($error === '')
				{

					$sent++;
					$credits--;

				}
				else
				{
					$failed++;
				}
				$total_sent++;

				if($pause_every > 0 && $total_sent % $pause_every === 0 && $pause_seconds > 0)sleep($pause_seconds);

				// paused or cancelled from the backend meanwhile
				if($total_sent % 50 === 0 && DB()->query("SELECT status FROM xcore_newsletter WHERE id = :id", ['id' => $id])->fetchOne() !== 'sending')
				{

					$interrupted = 'stopped from the backend';
					break;

				}

			}

			if($interrupted === 'stopped from the backend')
			{

				echo "#{$id} {$newsletter['subject']}: {$sent} sent, {$failed} error(s), {$skipped} skipped, {$interrupted}".CR;
				continue;

			}

			if($interrupted !== '')
			{

				$status($id, 'error', $interrupted);
				echo "#{$id} {$newsletter['subject']}: {$interrupted}".CR;
				continue;

			}

			$left = \Core\Newsletter::remainingRecipients($id, $list_ids);
			echo "#{$id} {$newsletter['subject']}: {$sent} sent, {$failed} error(s), {$skipped} skipped, {$left} left".CR;
			if($left > 0)continue;

		}

		// finished: every recipient processed or end of the sending window
		$status($id, 'sent');
		$stats = \Core\Newsletter::stats($id);
		$summary = "Newsletter #{$id} {$newsletter['subject']}: {$stats['sent']} sent, {$stats['sent-error']} error(s), ".($stats['blacklisted'] + $stats['refused'])." skipped".(($stopped) ? ", stopped at the end of the sending window" : "");
		echo $summary.CR;

		if($newsletter['scheduler_report_email'] === 'yes')
		{

			$recipients = array_filter(array_map('trim', preg_split('/[;,\s]+/', (string)$newsletter['scheduler_report_email_recipients'])), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
			if($recipients)\Core\Mailer::send('', implode(';', $recipients), $summary, '<p>'.htmlspecialchars($summary).'</p>', [], ['module' => 'newsletter', 'action' => 'report', 'record_id' => $id]);

		}

	}
	catch(\Throwable $e)
	{

		// one newsletter in error does not block the others, the task ends in error
		$errors[] = "#{$id}: ".$e->getMessage();
		echo "#{$id} {$newsletter['subject']}: ".$e->getMessage().CR;

	}

}

if($mail && $mail->Mailer === 'smtp')$mail->smtpClose();

echo "{$total_sent} email(s) processed".CR;
if($errors)throw new \RuntimeException(implode(' | ', $errors));
