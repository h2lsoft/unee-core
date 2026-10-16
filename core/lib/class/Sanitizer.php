<?php

namespace Core;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * html cleaning with symfony/html-sanitizer, profiles in config sanitizer: [profile => [tag => [attributes]]]
 * for html fields only: plain text is stored as typed and escaped on output (react)
 */
class Sanitizer
{

	private const array BLOCKED = [
		'div', 'span', 'font', 'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'small', 'sup', 'sub', 'mark', 'center', 'label',
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'a', 'img',
		'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'section', 'article', 'header', 'footer', 'main', 'nav', 'aside',
	];

	private static array $instances = [];

	/**
	 * sanitizer of a profile, unknown profile = no tag allowed (text kept)
	 *
	 * @param string $profile key of config sanitizer
	 *
	 * @return HtmlSanitizer
	 */
	public static function get(string $profile='basic'):HtmlSanitizer
	{

		if(!isset(self::$instances[$profile]))
		{

			$config = (new HtmlSanitizerConfig())->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])->allowRelativeLinks();

			// not allowed layout tags are removed, their text is kept (other tags like script are removed with their content)
			foreach(self::BLOCKED as $tag)
				$config = $config->blockElement($tag);

			foreach((array)\Core\Config::get("sanitizer/{$profile}", []) as $tag => $attributes)
				$config = $config->allowElement($tag, $attributes);

			self::$instances[$profile] = new HtmlSanitizer($config);

		}

		return self::$instances[$profile];

	}

	/**
	 * clean an html string
	 *
	 * @param string $html
	 * @param string $profile key of config sanitizer
	 *
	 * @return string
	 */
	public static function clean(string $html, string $profile='basic'):string
	{

		return self::get($profile)->sanitize($html);

	}

}
