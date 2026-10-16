<?php

namespace Core;

class Validator extends \h2lsoft\Data\Validator
{

	public function __construct($locale='en', $data='POST')
	{
		global $app;

		if($app->locale != $locale)
			$locale = $app->locale;

		parent::__construct($locale, $data);
	}

	/**
	 * value not used by another non deleted record of the table
	 *
	 * @param string $table
	 * @param string $column empty = name of the input
	 * @param int $exclude_id id of the record being updated
	 * @param string $message
	 *
	 * @return self
	 */
	public function unique(string $table, string $column='', int $exclude_id=0, string $message='already used'):self
	{

		if($this->escapeChecking())return $this;

		if($column === '')$column = $this->last_input;

		$found = DB()->query("SELECT id FROM `{$table}` WHERE `{$column}` = :value AND deleted = 'no' AND id != :id LIMIT 1", ['value' => trim((string)$this->values[$this->last_input]), 'id' => $exclude_id])->fetchOne();
		if($found)$this->addError($message);

		return $this;

	}

}
