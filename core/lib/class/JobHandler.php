<?php

namespace Core;

/**
 * handler of a job type (config `jobs`), see \Core\Jobber
 */
abstract class JobHandler
{

	/**
	 * runs the job, an exception fails it (retried until max_attempts)
	 *
	 * @param array $payload
	 *
	 * @return array|null result stored with the job
	 */
	abstract public function handle(array $payload):?array;

}
