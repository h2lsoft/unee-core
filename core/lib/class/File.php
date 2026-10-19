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

	// uploads (form fields of type file) ******************************************************************************

	// never accepted, whatever the field allows: executed or rendered by a server or a browser
	public const array FORBIDDEN_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'exe', 'bat', 'cmd', 'com', 'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'htaccess', 'asp', 'aspx', 'jsp'];

	// a token of upload lives one day
	private const int UPLOAD_TTL = 86400;

	/**
	 * stores an uploaded file in private_dir/tmp/upload until the form is saved (\Core\CrudController)
	 *
	 * @param \Symfony\Component\HttpFoundation\File\UploadedFile|null $file
	 * @param array $extensions allowed extensions, lower case
	 * @param int $max_size bytes, 0 = no limit
	 *
	 * @return array|string ['token', 'name', 'size'] or the error message (translated)
	 */
	public static function uploadStore(?\Symfony\Component\HttpFoundation\File\UploadedFile $file, array $extensions, int $max_size):array|string
	{

		if(!$file || !$file->isValid())return ts('the upload failed');
		if($max_size > 0 && $file->getSize() > $max_size)return ts('the file is larger than [SIZE] MB', ['SIZE' => round($max_size / 1048576, 1)]);

		$extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
		$allowed = array_values(array_diff($extensions, self::FORBIDDEN_EXTENSIONS));
		if(!in_array($extension, $allowed, true))return ts('file type not allowed: [TYPES]', ['TYPES' => implode(', ', $allowed)]);

		$dir = self::privateDir('tmp/upload');

		// old tokens never saved
		foreach(glob("{$dir}/*.json") ?: [] as $meta)
		{

			if(filemtime($meta) < time() - self::UPLOAD_TTL)
			{

				@unlink($meta);
				@unlink(substr($meta, 0, -5));

			}

		}

		$token = bin2hex(random_bytes(16));
		$name = mb_substr(preg_replace('/[\x00-\x1f"\\\\\/]/u', '_', $file->getClientOriginalName()), 0, 200);
		$file->move($dir, $token);
		$size = (int)filesize("{$dir}/{$token}");
		file_put_contents("{$dir}/{$token}.json", json_encode(['name' => $name, 'size' => $size, 'uid' => \Model\User::getUID()]));

		return ['token' => "upload:{$token}", 'name' => $name, 'size' => $size];

	}

	/**
	 * file of an upload token of the logged user
	 *
	 * @param string $value upload:<token>
	 *
	 * @return array|null ['path', 'name', 'size']
	 */
	public static function uploadGet(string $value):?array
	{

		if(!preg_match('/^upload:([a-f0-9]{32})$/', $value, $m))return null;

		$path = self::privateDir('tmp/upload')."/{$m[1]}";
		$meta = json_decode((string)@file_get_contents("{$path}.json"), true);
		if(!is_array($meta) || !is_file($path) || (int)$meta['uid'] !== \Model\User::getUID())return null;

		return ['path' => $path, 'name' => (string)$meta['name'], 'size' => (int)$meta['size']];

	}

	/**
	 * moves an upload to private_dir/<dir> under the slug of its name (-2, -3... when taken)
	 *
	 * @param string $value upload:<token>
	 * @param string $dir ex: files
	 *
	 * @return string path relative to private_dir, '' when the token is not valid
	 */
	public static function uploadMove(string $value, string $dir):string
	{

		if(!$upload = self::uploadGet($value))return '';

		$dir = trim(str_replace('..', '', $dir), '/');
		$target = self::privateDir($dir);
		$extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
		$base = slugify(pathinfo($upload['name'], PATHINFO_FILENAME), 'file');
		$name = "{$base}.{$extension}";
		for($i = 2; file_exists("{$target}/{$name}"); $i++)
			$name = "{$base}-{$i}.{$extension}";

		rename($upload['path'], "{$target}/{$name}");
		@unlink("{$upload['path']}.json");

		return "{$dir}/{$name}";

	}

	/**
	 * private file as a response: images and pdf shown inline, the others downloaded
	 *
	 * @param string $relative path relative to private_dir
	 * @param bool $download always as an attachment
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public static function serve(string $relative, bool $download=false):\Symfony\Component\HttpFoundation\Response
	{

		$path = self::privateDir().'/'.ltrim(str_replace('..', '', $relative), '/');
		if($relative === '' || !is_file($path))return \Core\JsonResponse::error(404, 'file not found');

		$mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($path);
		$inline = !$download && ((str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') || $mime === 'application/pdf');
		$response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($path);
		$response->headers->set('Content-Type', $mime ?: 'application/octet-stream');
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		$response->setContentDisposition(($inline) ? 'inline' : 'attachment', basename($path), preg_replace('/[^\x20-\x7e]|[%\/\\\\]/', '_', basename($path)));

		return $response;

	}

	/**
	 * size of a private file in bytes, 0 when missing
	 *
	 * @param string $relative path relative to private_dir
	 *
	 * @return int
	 */
	public static function privateSize(string $relative):int
	{

		$path = self::privateDir().'/'.ltrim(str_replace('..', '', $relative), '/');
		return ($relative !== '' && is_file($path)) ? (int)filesize($path) : 0;

	}

}