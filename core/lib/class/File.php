<?php

namespace Core;


class File
{

	/**
	 * Create tmp file in tmp directory
	 * @param string $dir_name (in current directory)
	 * @param string $file_prefix
	 * @param string $file_suffix
	 * @param string $contents
	 * @return string
	 */
	public static function tmpCreate(string $dir_name, string $file_prefix='', string $file_suffix='', string $contents=""):string
	{
		$path = \Core\Config::get('tmp_dir')."/{$dir_name}";
		if(!is_dir($path))@mkdir($path, 0770);
		
		$filename = $path."/".uniqid($file_prefix).$file_suffix;
		file_put_contents($filename, $contents);
		
		return $filename;
		
	}


	/**
	 * folder of private files (config private_dir), created with a deny all .htaccess: the files are served by the api after the rights
	 *
	 * @param string $sub ex: project/12
	 *
	 * @return string absolute path, without final slash
	 */
	public static function privateDir(string $sub=''):string
	{

		$root = rtrim((string)\Core\Config::get('private_dir'), '/');
		if(!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root))throw new \RuntimeException("private_dir `{$root}` cannot be created: check the rights of its parent folder");
		if(!file_exists("{$root}/.htaccess"))file_put_contents("{$root}/.htaccess", "Require all denied\n");

		$sub = trim(str_replace('..', '', $sub), '/');
		$path = ($sub === '') ? $root : "{$root}/{$sub}";
		if(!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path))throw new \RuntimeException("`{$path}` cannot be created: check the rights of private_dir");

		return $path;

	}

	public static function get($file_path, $content_type='')
	{
		if(!file_exists($file_path))
			return new \Core\Response("Error : file not found", 404);

		$response = new Response(file_get_contents($file_path));

		if(empty($content_type))
			$content_type = mime_content_type($file_path);

		if(!empty($content_type))
			$response->headers->set('Content-Type', $content_type);

		return $response;
	}


}