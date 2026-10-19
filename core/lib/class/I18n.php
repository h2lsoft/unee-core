<?php

namespace Core;

/**
 * server translations, key = english text: core/i18n/<locale>.php then instances/<dir>/i18n/<locale>.php (adds or overrides keys)
 * used for api messages, labels of grids and forms, and the validator messages
 * the react backend translates its own interface (core/theme/core-admin/src/i18n)
 */
class I18n
{

	private static array $messages = [];

	/**
	 * translations of a locale
	 *
	 * @param string $locale empty = locale of the request (en before the kernel is built)
	 *
	 * @return array
	 */
	public static function messages(string $locale=''):array
	{

		if($locale === '')$locale = App()?->locale ?? 'en';

		if(!isset(self::$messages[$locale]))
		{

			self::$messages[$locale] = [];

			// locale is a key of config backend.languages or a frontend language: no path traversal
			if(!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale))return [];

			foreach([CORE_PATH."/core/i18n/{$locale}.php", APP_PATH."/instances/".APP_DIR."/i18n/{$locale}.php"] as $file)
			{

				if(file_exists($file))
					self::$messages[$locale] = array_replace(self::$messages[$locale], (array)require($file));

			}

		}

		return self::$messages[$locale];

	}

	/**
	 * translate a text, [KEY] are replaced by params
	 *
	 * @param string $text english text
	 * @param array $params ex: ['COUNT' => 3]
	 *
	 * @return string
	 */
	public static function t(string $text, array $params=[]):string
	{

		$messages = self::messages();
		$text = $messages[$text] ?? $text;

		foreach($params as $key => $value)
			$text = str_replace("[{$key}]", (string)$value, $text);

		return $text;

	}

}
