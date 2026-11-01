<?php

namespace Core;

/**
 * scheduled tasks (xcore_crontask) run by `php vendor/bin/unee cron` every minute (one crontab entry)
 *
 * a task is due when its 4 rules match the current month, day, hour and minute: `*` (every), a list `0,15,30`, a range `8-18` or a step `*\/5`
 * the script (path relative to the project, or a core script) is included with $params (script_parameters parsed as a query string) and $cron (the task row)
 * what it prints is the report, an exception is an error, a locked task is skipped (unlock it in the module)
 */
class Cron
{

	// rule => [min, max]
	public const array RULES = ['repetition_months' => [1, 12], 'repetition_days' => [1, 31], 'repetition_hours' => [0, 23], 'repetition_minutes' => [0, 59]];
	public const string PATTERN = '/^(\*(\/\d{1,2})?|\d{1,2}(-\d{1,2})?(,\d{1,2}(-\d{1,2})?)*)$/';

	// task being run, unlocked by the shutdown handler when the script stops the process (exit, fatal error)
	private static array $current = [];

	/**
	 * a rule matches a value, ex: match('*\/15', 30) = true, match('8-18', 7) = false
	 *
	 * @param string $rule
	 * @param int $value
	 *
	 * @return bool
	 */
	public static function match(string $rule, int $value):bool
	{

		$rule = str_replace([' ', ';'], ['', ','], trim($rule));
		if($rule === '' || $rule === '*')return true;

		if(preg_match('/^\*\/(\d+)$/', $rule, $m))
			return ((int)$m[1] > 0 && $value % (int)$m[1] === 0);

		foreach(explode(',', $rule) as $part)
		{

			if(str_contains($part, '-'))
			{

				[$from, $to] = array_map('intval', explode('-', $part, 2));
				if($value >= $from && $value <= $to)return true;

			}
			elseif($part !== '' && (int)$part === $value)
			{
				return true;
			}

		}

		return false;

	}

	/**
	 * the 4 rules of a task match a time
	 *
	 * @param array $cron
	 * @param int $time
	 *
	 * @return bool
	 */
	public static function isDue(array $cron, int $time):bool
	{

		return self::match((string)$cron['repetition_months'], (int)date('n', $time))
			&& self::match((string)$cron['repetition_days'], (int)date('j', $time))
			&& self::match((string)$cron['repetition_hours'], (int)date('G', $time))
			&& self::match((string)$cron['repetition_minutes'], (int)date('i', $time));

	}

	/**
	 * error of a rule (translated), '' when valid
	 *
	 * @param string $name key of RULES
	 * @param string $rule
	 *
	 * @return string
	 */
	public static function ruleError(string $name, string $rule):string
	{

		$rule = str_replace([' ', ';'], ['', ','], trim($rule));
		if(!preg_match(self::PATTERN, $rule))return ts('use *, a list (1,15), a range (8-18) or a step (*/5)');

		[$min, $max] = self::RULES[$name];
		preg_match_all('/\d+/', str_starts_with($rule, '*/') ? '' : $rule, $m);
		foreach($m[0] as $number)
			if((int)$number < $min || (int)$number > $max)return ts('values from [MIN] to [MAX]', ['MIN' => $min, 'MAX' => $max]);

		return '';

	}

	/**
	 * runs the due tasks (or one task, due or not, active or not)
	 *
	 * @param int $id
	 * @param callable|null $output receives a line per task
	 *
	 * @return array{executed: int, errors: int}
	 */
	public static function run(int $id = 0, ?callable $output = null):array
	{

		$time = time();
		$sql = "SELECT * FROM xcore_crontask WHERE deleted = 'no' AND locked = 'no'".(($id) ? " AND id = :id" : " AND active = 'yes'")." ORDER BY priority, id";
		$crons = DB()->query($sql, ($id) ? ['id' => $id] : [])->fetchAll();

		register_shutdown_function([self::class, 'shutdown']);

		$result = ['executed' => 0, 'errors' => 0];
		foreach($crons as $cron)
		{

			if(!$id && !self::isDue($cron, $time))continue;

			// lock first: a second process started meanwhile skips the task
			DB()->query("UPDATE xcore_crontask SET locked = 'yes', locked_date = :now, status = 'running', last_execution_date_start = :now2, last_execution_date_end = NULL, last_error_message = '' WHERE id = :id AND locked = 'no'", ['now' => now(), 'now2' => now(), 'id' => $cron['id']]);
			if(!DB()->rowCount())continue;

			$run = self::execute($cron);
			$result['executed']++;
			if($run['status'] === 'error')$result['errors']++;
			if($output)$output("#{$cron['id']} {$cron['name']}: {$run['status']} ({$run['duration']}s)".(($run['error'] !== '') ? " {$run['error']}" : ''));

		}

		return $result;

	}

	/**
	 * runs one locked task and unlocks it
	 *
	 * @param array $cron
	 *
	 * @return array{status: string, error: string, output: string, duration: float}
	 */
	private static function execute(array $cron):array
	{

		self::$current = $cron;
		$start = microtime(true);
		$started_at = now();
		$status = 'success';
		$error = '';

		ob_start();
		try
		{

			$path = self::scriptPath((string)$cron['script_path']);
			if($path === '')throw new \RuntimeException("script not found: {$cron['script_path']}");

			parse_str((string)$cron['script_parameters'], $params);
			(static function(string $__file, array $params, array $cron){ include $__file; })($path, $params, $cron);

		}
		catch(\Throwable $e)
		{

			$status = 'error';
			$error = $e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')';

		}
		$output = (string)ob_get_clean();
		$duration = round(microtime(true) - $start, 2);

		self::finish($cron, $status, $error);
		self::$current = [];

		if($status === 'error')\Core\Log::write('error', "cron #{$cron['id']} {$cron['name']}: {$error}", [], (int)$cron['id'], 'error', 'cron', 'cron');
		self::report($cron, $status, $error, $output, $started_at, $duration);

		return ['status' => $status, 'error' => $error, 'output' => $output, 'duration' => $duration];

	}

	/**
	 * absolute path of a script inside the project or the core, '' otherwise
	 *
	 * @param string $script_path
	 *
	 * @return string
	 */
	public static function scriptPath(string $script_path):string
	{

		$script_path = trim($script_path);
		if($script_path === '' || str_contains($script_path, '..') || !str_ends_with(strtolower($script_path), '.php'))return '';

		$path = realpath(APP_PATH.'/'.ltrim($script_path, '/'));
		if($path === false || !is_file($path))return '';

		foreach([APP_PATH, CORE_PATH] as $root)
		{

			$root = realpath($root);
			if($root !== false && str_starts_with($path, $root.DIRECTORY_SEPARATOR))return $path;

		}

		return '';

	}

	/**
	 * unlocks a task with its result
	 *
	 * @param array $cron
	 * @param string $status
	 * @param string $error
	 *
	 * @return void
	 */
	private static function finish(array $cron, string $status, string $error):void
	{

		DB()->query("UPDATE xcore_crontask SET locked = 'no', locked_date = NULL, status = :status, last_error_message = :error, last_execution_date_end = :now WHERE id = :id", ['status' => $status, 'error' => $error, 'now' => now(), 'id' => $cron['id']]);
		if($status === 'error')\Core\Notification::sendGroup(\Model\User::GROUP_SUPERADMIN, ts('Scheduled task in error'), "{$cron['name']}: {$error}", 'cron/', 'error');

	}

	/**
	 * mail template core.cron.report to the recipients (; or , separated), every run or on failure only
	 *
	 * @return void
	 */
	private static function report(array $cron, string $status, string $error, string $output, string $started_at, float $duration):void
	{

		if($cron['email_report'] !== 'yes' || ($cron['email_report_failure'] === 'yes' && $status !== 'error'))return;

		$recipients = array_filter(array_map('trim', preg_split('/[;,\s]+/', (string)$cron['email_report_recipients'])), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
		$subject = (trim((string)$cron['email_report_subject']) !== '') ? trim((string)$cron['email_report_subject']) : "Cron #{$cron['id']} {$cron['name']}: {$status}";
		$vars = ['subject' => $subject, 'name' => $cron['name'], 'status' => $status, 'start' => $started_at, 'duration' => $duration, 'error' => $error, 'output' => mb_substr($output, 0, 20000)];

		foreach($recipients as $email)
			\Core\Mailer::sendTemplate('core.cron.report', $email, $vars);

	}

	/**
	 * the script stopped the process (exit, fatal error): the task is unlocked in error
	 *
	 * @return void
	 */
	public static function shutdown():void
	{

		if(!self::$current)return;

		$output = (string)ob_get_clean();
		$last = error_get_last();
		$error = ($last) ? "{$last['message']} (".basename($last['file']).":{$last['line']})" : 'the script stopped the process';
		self::finish(self::$current, 'error', $error);
		\Core\Log::write('error', "cron #".self::$current['id']." ".self::$current['name'].": {$error}", ['output' => mb_substr($output, 0, 2000)], (int)self::$current['id'], 'error', 'cron', 'cron');
		self::$current = [];

	}

}
