<?php

namespace Core;

/**
 * job queue (xcore_job_queue): \Core\Jobber::push('send_email', [...]) queues a job, the worker (cron task "Jobber", or `php vendor/bin/unee jobber`) runs it
 * a type is a handler declared in config `jobs` (keyed, ex: 'send_email' => \Job\Core\Send_Email::class), extending \Core\JobHandler
 * a failed job goes back to pending (retried 1, 4, 9... minutes later) until max_attempts, then dead
 */
class Jobber
{

	public const array STATUSES = ['pending', 'processing', 'completed', 'failed', 'dead'];

	// a job processing for longer is released (worker killed)
	public const int STALE_MINUTES = 30;

	/**
	 * queues a job
	 *
	 * @param string $type key of config `jobs`
	 * @param array $payload
	 * @param string $comment
	 * @param int $priority higher first (0-255)
	 * @param string|null $scheduled_at Y-m-d H:i:s, null = now
	 * @param int $max_attempts
	 *
	 * @return int job id
	 */
	public static function push(string $type, array $payload = [], string $comment = '', int $priority = 0, ?string $scheduled_at = null, int $max_attempts = 3, int $notify_user_id = 0):int
	{

		if(!isset(\Core\Config::get('jobs', [])[$type]))throw new \InvalidArgumentException("unknown job type: {$type}");

		return (int)DB('xcore_job_queue')->insert([
			'date' => now(),
			'type' => $type,
			'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			'comment' => mb_substr($comment, 0, 255),
			'priority' => max(0, min(255, $priority)),
			'status' => 'pending',
			'attempts' => 0,
			'max_attempts' => max(1, $max_attempts),
			'scheduled_at' => $scheduled_at,
			'notify_user_id' => max(0, $notify_user_id),
		], 'jobber');

	}

	/**
	 * runs pending jobs until there is none, or a limit is reached
	 *
	 * @param int $max_jobs
	 * @param int $max_seconds
	 * @param callable|null $output receives a line per job
	 *
	 * @return array{completed: int, failed: int}
	 */
	public static function run(int $max_jobs = 100, int $max_seconds = 50, ?callable $output = null):array
	{

		$worker = gethostname().':'.getmypid().':'.bin2hex(random_bytes(3));
		$start = time();
		$result = ['completed' => 0, 'failed' => 0];

		self::releaseStale();

		while($result['completed'] + $result['failed'] < $max_jobs && time() - $start < $max_seconds)
		{

			$job = self::fetchNext($worker);
			if(!$job)break;

			$line = self::execute($job);
			($line['status'] === 'completed') ? $result['completed']++ : $result['failed']++;
			if($output)$output("#{$job['id']} {$job['type']}: {$line['status']}".(($line['error'] !== '') ? " {$line['error']}" : ''));

		}

		return $result;

	}

	/**
	 * claims the next due job for this worker
	 *
	 * @param string $worker
	 *
	 * @return array|null
	 */
	private static function fetchNext(string $worker):?array
	{

		DB()->query("UPDATE xcore_job_queue SET status = 'processing', locked_at = :now, locked_by = :worker, attempts = attempts + 1 WHERE status = 'pending' AND deleted = 'no' AND (scheduled_at IS NULL OR scheduled_at <= :now2) ORDER BY priority DESC, id ASC LIMIT 1", ['now' => now(), 'worker' => $worker, 'now2' => now()]);
		if(!DB()->rowCount())return null;

		$job = DB()->query("SELECT * FROM xcore_job_queue WHERE locked_by = :worker AND status = 'processing' AND deleted = 'no' ORDER BY id DESC LIMIT 1", ['worker' => $worker])->fetch();
		return ($job) ?: null;

	}

	/**
	 * name of a job in a notification: its comment, else its type and id
	 *
	 * @param array $job
	 *
	 * @return string
	 */
	private static function label(array $job):string
	{

		return ((string)($job['comment'] ?? '') !== '') ? (string)$job['comment'] : "{$job['type']} #{$job['id']}";

	}

	/**
	 * runs a claimed job
	 *
	 * @param array $job
	 *
	 * @return array{status: string, error: string}
	 */
	private static function execute(array $job):array
	{

		try
		{

			$class = \Core\Config::get('jobs', [])[$job['type']] ?? '';
			if($class === '' || !class_exists($class) || !is_subclass_of($class, JobHandler::class))throw new \RuntimeException("no handler for the type {$job['type']}");

			$payload = json_decode((string)$job['payload'], true);
			$data = (new $class())->handle(is_array($payload) ? $payload : []);

			DB()->query("UPDATE xcore_job_queue SET status = 'completed', completed_at = :now, locked_by = '', error_message = '', result = :result WHERE id = :id", ['now' => now(), 'result' => ($data === null) ? null : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'id' => $job['id']]);
			\Core\Notification::send((int)($job['notify_user_id'] ?? 0), ts('Job completed'), self::label($job), 'jobber/', 'success', 'Jobber');
			return ['status' => 'completed', 'error' => ''];

		}
		catch(\Throwable $e)
		{

			$error = $e->getMessage();
			$status = ((int)$job['attempts'] >= (int)$job['max_attempts']) ? 'dead' : 'pending';

			// next attempt delayed: 1, 4, 9... minutes
			$retry_at = date('Y-m-d H:i:s', time() + 60 * (int)$job['attempts'] ** 2);
			DB()->query("UPDATE xcore_job_queue SET status = :status, locked_by = '', error_message = :error, scheduled_at = IF(:dead = 1, scheduled_at, :retry_at) WHERE id = :id", ['status' => $status, 'error' => $error, 'dead' => (int)($status === 'dead'), 'retry_at' => $retry_at, 'id' => $job['id']]);
			if($status === 'dead')
			{

				\Core\Log::write('error', "job #{$job['id']} {$job['type']}: {$error}", [], (int)$job['id'], 'error', 'jobber', 'jobber');

				// abandoned: the user who queued it, else the super admins
				if((int)($job['notify_user_id'] ?? 0) > 0)\Core\Notification::send((int)$job['notify_user_id'], ts('Job abandoned'), self::label($job).": {$error}", 'jobber/', 'warning', 'Jobber');
				else \Core\Notification::sendGroup(\Model\User::GROUP_SUPERADMIN, ts('Job abandoned'), self::label($job).": {$error}", 'jobber/', 'warning', 'Jobber');

			}

			return ['status' => ($status === 'dead') ? 'dead' : 'failed', 'error' => $error];

		}

	}

	/**
	 * jobs left in processing by a killed worker go back to pending
	 *
	 * @return int
	 */
	public static function releaseStale():int
	{

		DB()->query("UPDATE xcore_job_queue SET status = 'pending', locked_by = '' WHERE status = 'processing' AND deleted = 'no' AND locked_at <= :threshold", ['threshold' => date('Y-m-d H:i:s', time() - self::STALE_MINUTES * 60)]);
		return (int)DB()->rowCount();

	}

}
