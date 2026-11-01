<?php

namespace Core;

class Log
{

	/**
	 * Log all actions
	 *
	 * @param string $action
	 * @param string $message
	 * @param array $values
	 * @param int $record_id
	 * @param string $level (debug, info, fatal, error)
	 * @param string $plugin
	 * @param string $author
	 * @param string $application
	 * @return void
	 */
	public static function write(string $action, string $message="", array $values=[], int $record_id=0, string $level='info', string $plugin='', string $author="", string $application='backend'):void
	{
		if(empty($author))$author = \Core\Session::get('auth.login');
		
		$f = [];
		$f['application'] = $application;
		$f['date'] = now();
		$f['level'] = $level;
		$f['plugin'] = $plugin;
		$f['action'] = $action;
		$f['message'] = $message;
		$f['values'] = json_encode($values, JSON_PRETTY_PRINT);
		$f['record_id'] = $record_id;
		$f['ip'] = getVisitorIp();
		$f['user_agent'] = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
		$f['author'] = $author;
		
		
		DB('xcore_log')->insert($f, \Core\Session::get('auth.login'));
	}
	
	
	
}