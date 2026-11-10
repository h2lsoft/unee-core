<?php

// cron task "SMS sender": sends the due sms (\Core\Sms), $params: limit (sms per run), max_seconds
// due = status scheduled or sending, programming_date passed (empty = now), oldest first
// start (scheduled -> sending): one row per recipient in xcore_sms_data, never started without enough credits (status error)
// a provider error that stops every sending (configuration, authentication, credits of the provider) puts the sms in error, its rows stay queued

$limit = max(1, (int)($params['limit'] ?? 500));
$max_seconds = max(10, (int)($params['max_seconds'] ?? 240));
$deadline = time() + $max_seconds;
$total = 0;
$errors = [];

\Core\Sms::register();

$ids = DB()->query("SELECT id FROM xcore_sms WHERE deleted = 'no' AND status IN ('scheduled', 'sending') AND (programming_date IS NULL OR programming_date <= :now) ORDER BY COALESCE(programming_date, created_at), id", ['now' => now()])->fetchAllOne();
foreach($ids as $id)
{

	if($total >= $limit || time() >= $deadline)break;

	try
	{

		$r = \Core\Sms::process((int)$id, $limit - $total, $deadline);
		$total += $r['sent'] + $r['failed'];
		// an sms in error (credits, provider) shows it in the module, the task stays in success
		echo "#{$id}: {$r['sent']} sent, {$r['failed']} failed, {$r['left']} left".(($r['error'] !== '') ? ", {$r['error']}" : '').CR;

	}
	catch(\Throwable $e)
	{

		// one sms in error does not block the others, the task ends in error
		$errors[] = "#{$id}: ".$e->getMessage();
		echo "#{$id}: ".$e->getMessage().CR;

	}

}

echo "{$total} SMS processed".CR;
if($errors)throw new \RuntimeException(implode(' | ', $errors));
