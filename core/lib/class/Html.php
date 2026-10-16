<?php

namespace Core;

class Html
{
	/**
	 * escape a value for an html attribute
	 * @param mixed $value
	 * @return string
	 */
	private static function _escape(mixed $value):string
	{
		return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * build escaped html attributes
	 * attributes are always escaped, tag content ($text, $label) is raw html: escape user data before passing it
	 * @param array $attributes
	 * @return string
	 */
	private static function _attributes(array $attributes):string
	{
		$attrs = "";
		foreach($attributes as $key => $val)
		{
			$attrs .= " ".self::_escape($key)."=\"".self::_escape($val)."\" ";
		}

		return $attrs;
	}
	
	
	public static function Image(string $src="", array $attributes=[]): string
	{
		$attrs = self::_attributes($attributes);
		
		$src = self::_escape($src);
		$str = "<img src=\"{$src}\" {$attrs}>";
		return $str;
	}
	
	public static function Icon(string $content="", array $attributes=[]): string
	{
		$class = "{$content} ".@$attributes['class'];
		
		unset($attributes['class']);
		$attrs = self::_attributes($attributes);
		
		$class = self::_escape($class);
		$str = "<i class=\"{$class}\" {$attrs}></i>";
		return $str;
	}
	
	
	public static function Button(string $name, string $label="", string $type="button", array $attributes=[]): string
	{
		if(empty($label))$label = str_replace('_', ' ', $name);
		$class = @$attributes['class'];
		
		unset($attributes['class']);
		$attrs = self::_attributes($attributes);
		
		[$name, $class, $type] = [self::_escape($name), self::_escape($class), self::_escape($type)];
		$str = "<button id=\"{$name}\" class=\"btn btn-default {$class}\" type=\"{$type}\" {$attrs}>{$label}</button>";
		return $str;
	}
	
	
	public static function Badge(string $text, string $type='', string $class="", array $attributes=[]):string {
		
		if(empty($type) && in_array($text, ['error', 'fatal', 'critical', 'warning', 'success', 'debug']))
			$type = $text;
		
		if(empty($type))$type = 'text-bg-light';
		elseif($type == 'error' || $type == 'fatal' || $type == 'critical' || $type == 'danger')$type = 'text-bg-danger';
		elseif($type == 'warning')$type = 'text-bg-warning';
		elseif($type == 'success')$type = 'text-bg-success';
		elseif($type == 'debug')$type = 'text-bg-dark';
		elseif($type == 'info')$type = 'text-bg-info';
		
		unset($attributes['class']);
		$attrs = self::_attributes($attributes);
		
		
		
		[$type, $class] = [self::_escape($type), self::_escape($class)];
		$str = "<span class=\"badge {$type} {$class}\" {$attrs}>{$text}</span>";
		return $str;
	}
	
	public static function A(string $text, string $url, array $attributes=[]): string
	{
		$attrs = self::_attributes($attributes);
		
		$url = self::_escape($url);
		$str = "<a href=\"{$url}\" {$attrs}>{$text}</a>";
		return $str;
	}


	public static function getIconExtension(string $file_url, string $class_added="", $only_class=false)
	{
		$ext = file_get_extension($file_url);

		$icon = 'file-earmark text-muted';
		if(in_array($ext, \Core\Config::get('file_manager/filters/pdf', [])))$icon = 'file-earmark-pdf-fill text-danger';
		elseif(in_array($ext, \Core\Config::get('file_manager/filters/image', [])))$icon = 'file-earmark-image-fill';
		elseif(in_array($ext, \Core\Config::get('file_manager/filters/audio', [])))$icon = 'file-earmark-music-fill text-info';
		elseif(in_array($ext, \Core\Config::get('file_manager/filters/video', [])))$icon = 'file-earmark-play-fill text-danger';
		elseif(in_array($ext, \Core\Config::get('file_manager/filters/word', [])))$icon = 'file-earmark-word-fill';
		elseif(in_array($ext, \Core\Config::get('file_manager/filters/excel', [])))$icon = 'file-earmark-excel-fill text-success';
		elseif(in_array($ext, \Core\Config::get('file_manager/filters/archive', [])))$icon = 'file-earmark-zip-fill text-warning';


		$str = trim("{$icon} {$class_added}");

		if(!$only_class)
			$str = "<i class=\"bi bi-{$str}\"></i>";

		return $str;
	}


	
}