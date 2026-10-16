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

}