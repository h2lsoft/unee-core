<?php

namespace Core;

/**
 * php errors and uncaught exceptions written in xcore_log (action php_error), level filter in config error_log/level
 * registered after the symfony handler (core/run.php) and chained to it: display and responses are unchanged
 * fallback file <tmp_dir>/log/php-errors.log when the database is not reachable
 */
class ErrorHandler
{

	// a failure while logging must not log again
	private static bool $in_handler = false;

	private static $previous_error_handler = null;
	private static $previous_exception_handler = null;

	/**
	 * register the error, exception and shutdown handlers
	 *
	 * @return void
	 */
	public static function register():void
	{

		self::$previous_error_handler = set_error_handler([self::class, 'handleError']);
		self::$previous_exception_handler = set_exception_handler([self::class, 'handleException']);
		register_shutdown_function([self::class, 'handleShutdown']);

	}

	/**
	 * log a throwable caught by the kernel (500 responses), called by the kernel.exception listener
	 *
	 * @param \Throwable $e
	 *
	 * @return void
	 */
	public static function logThrowable(\Throwable $e):void
	{

		// php errors turned into exceptions by the symfony debug handler: already logged by handleError
		if($e instanceof \ErrorException)return;

		self::write('error', $e->getMessage(), [
			'type' => $e::class,
			'file' => $e->getFile(),
			'line' => $e->getLine(),
			'trace' => $e->getTraceAsString(),
		]);

	}

	/**
	 * php error handler, @-suppressed errors and error_reporting() are respected
	 *
	 * @param int $type
	 * @param string $message
	 * @param string $file
	 * @param int $line
	 *
	 * @return bool
	 */
	public static function handleError(int $type, string $message, string $file='', int $line=0):bool
	{

		if(!(error_reporting() & $type))return false;

		[$level, $label] = self::mapErrorType($type);
		self::write($level, $message, ['type' => $label, 'file' => $file, 'line' => $line]);

		if(is_callable(self::$previous_error_handler))
			return (bool)call_user_func(self::$previous_error_handler, $type, $message, $file, $line);

		return false;

	}

	/**
	 * uncaught exception (outside the kernel: cli, bootstrap)
	 *
	 * @param \Throwable $e
	 *
	 * @return void
	 */
	public static function handleException(\Throwable $e):void
	{

		self::logThrowable($e);

		if(is_callable(self::$previous_exception_handler))
			call_user_func(self::$previous_exception_handler, $e);

	}

	/**
	 * fatal errors, not catchable by the error handler
	 *
	 * @return void
	 */
	public static function handleShutdown():void
	{

		$error = error_get_last();
		if($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true))return;

		self::write('critical', (string)$error['message'], ['type' => self::mapErrorType($error['type'])[1], 'file' => $error['file'], 'line' => $error['line']]);

	}

	/**
	 * php error constant to [level, label]
	 *
	 * @param int $type
	 *
	 * @return array
	 */
	private static function mapErrorType(int $type):array
	{

		return match($type)
		{
			E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => ['fatal', 'PHP Fatal'],
			E_PARSE => ['fatal', 'PHP Parse'],
			E_RECOVERABLE_ERROR => ['error', 'PHP Recoverable Error'],
			E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => ['warning', 'PHP Warning'],
			E_NOTICE, E_USER_NOTICE => ['info', 'PHP Notice'],
			E_DEPRECATED, E_USER_DEPRECATED => ['info', 'PHP Deprecated'],
			default => ['error', 'PHP Error'],
		};

	}

	/**
	 * level kept by config error_log/level
	 *
	 * @param string $level
	 *
	 * @return bool
	 */
	private static function accepts(string $level):bool
	{

		return match((string)\Core\Config::get('error_log/level', 'warning'))
		{
			'off' => false,
			'error' => in_array($level, ['error', 'fatal', 'critical'], true),
			'warning' => in_array($level, ['warning', 'error', 'fatal', 'critical'], true),
			default => true,
		};

	}

	/**
	 * write in xcore_log, file fallback
	 *
	 * @param string $level
	 * @param string $message
	 * @param array $values
	 *
	 * @return void
	 */
	private static function write(string $level, string $message, array $values):void
	{

		if(self::$in_handler || !self::accepts($level))return;
		self::$in_handler = true;

		$values['uri'] = $_SERVER['REQUEST_URI'] ?? '';
		$values['method'] = $_SERVER['REQUEST_METHOD'] ?? (APP_CLI_MODE ? 'CLI' : '');

		try
		{

			if(!App())throw new \RuntimeException('kernel not built');
			\Core\Log::write('php_error', mb_substr($message, 0, 2000), $values, 0, $level, 'core');

		}
		catch(\Throwable $e)
		{

			$dir = \Core\Config::get('tmp_dir').'/log';
			if(!is_dir($dir))@mkdir($dir, 0770, true);
			@file_put_contents("{$dir}/php-errors.log", date('Y-m-d H:i:s')." [{$level}] {$message} ".json_encode($values).CR, FILE_APPEND);

		}
		finally
		{

			self::$in_handler = false;

		}

	}

}
