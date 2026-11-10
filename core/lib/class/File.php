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

	// .htaccess of upload_dir: files served as they are, never executed nor rendered as a page
	private const string PUBLIC_HTACCESS = <<<'HTACCESS'
	# uploaded files are never executed
	<IfModule mod_php.c>
		php_flag engine off
	</IfModule>
	<FilesMatch "\.(?i:php\d?|phtml|phar|pht|phps|cgi|pl|py|sh|html?|xhtml|shtml|svgz?|js|mjs)$">
		Require all denied
	</FilesMatch>
	<IfModule mod_headers.c>
		Header set X-Content-Type-Options "nosniff"
	</IfModule>

	HTACCESS;

	/**
	 * folder of public files (config upload_dir, default instances/<instance>/public/upload), served by the web server without any right check
	 * created with an .htaccess that never executes a file
	 *
	 * @param string $sub ex: avatar
	 *
	 * @return string absolute path, without final slash
	 */
	public static function publicDir(string $sub=''):string
	{

		$root = rtrim((string)\Core\Config::get('upload_dir'), '/');
		if(!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root))throw new \RuntimeException("upload_dir `{$root}` cannot be created: check the rights of its parent folder");
		if(!file_exists("{$root}/.htaccess"))file_put_contents("{$root}/.htaccess", self::PUBLIC_HTACCESS);

		$sub = trim(str_replace('..', '', $sub), '/');
		$path = ($sub === '') ? $root : "{$root}/{$sub}";
		if(!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path))throw new \RuntimeException("`{$path}` cannot be created: check the rights of upload_dir");

		return $path;

	}

	/**
	 * absolute path of a stored file
	 *
	 * @param string $relative path relative to private_dir or upload_dir, as kept by the column
	 * @param bool $public upload_dir instead of private_dir
	 *
	 * @return string '' when the path is empty
	 */
	public static function path(string $relative, bool $public=false):string
	{

		$relative = ltrim(str_replace('..', '', $relative), '/');
		if($relative === '')return '';

		return (($public) ? self::publicDir() : self::privateDir())."/{$relative}";

	}

	/**
	 * url of a public file, from the folder of upload_dir under the project root
	 *
	 * @param string $relative path relative to upload_dir, '' = url of upload_dir with a final slash
	 *
	 * @return string ex: /instances/_default/public/upload/avatar/<uuid>.webp, '' when upload_dir is out of the project
	 */
	public static function url(string $relative=''):string
	{

		$relative = ltrim(str_replace('..', '', $relative), '/');

		$root = str_replace('\\', '/', rtrim(APP_PATH, '/\\'));
		$dir = str_replace('\\', '/', rtrim((string)\Core\Config::get('upload_dir'), '/\\'));
		if(!str_starts_with($dir, "{$root}/"))return '';

		return substr($dir, strlen($root))."/{$relative}";

	}

	/**
	 * deletes a stored file (replaced or emptied field)
	 *
	 * @param string $relative path relative to private_dir or upload_dir
	 * @param bool $public upload_dir instead of private_dir
	 *
	 * @return void
	 */
	public static function remove(string $relative, bool $public=false):void
	{

		$path = self::path($relative, $public);
		if($path !== '' && is_file($path))@unlink($path);

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

	// real types (fileinfo) accepted for an extension, an extension missing here is checked on its name only
	public const array MIMES = [
		'pdf' => ['application/pdf'],
		'doc' => ['application/msword', 'application/cdfv2', 'application/x-ole-storage'],
		'xls' => ['application/vnd.ms-excel', 'application/cdfv2', 'application/x-ole-storage'],
		'ppt' => ['application/vnd.ms-powerpoint', 'application/cdfv2', 'application/x-ole-storage'],
		'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
		'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
		'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
		'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
		'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
		'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
		'txt' => ['text/plain'],
		'csv' => ['text/csv', 'text/plain', 'application/csv'],
		'zip' => ['application/zip', 'application/x-zip-compressed'],
		'jpg' => ['image/jpeg'],
		'jpeg' => ['image/jpeg'],
		'png' => ['image/png'],
		'gif' => ['image/gif'],
		'webp' => ['image/webp'],
		'avif' => ['image/avif'],
	];

	// images written by the conversion of an upload
	private const array IMAGE_TYPES = ['webp' => IMAGETYPE_WEBP, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF];

	/**
	 * stores an uploaded file in private_dir/tmp/upload until the form is saved (\Core\CrudController)
	 *
	 * @param \Symfony\Component\HttpFoundation\File\UploadedFile|null $file
	 * @param array $extensions allowed extensions, lower case
	 * @param int $max_size bytes, 0 = no limit
	 * @param array $convert image cropped from its center to ['width', 'height'] and written as ['extension'] (webp, jpg, png, gif), [] = kept as sent
	 *
	 * @return array|string ['token', 'name', 'size'] or the error message (translated)
	 */
	public static function uploadStore(?\Symfony\Component\HttpFoundation\File\UploadedFile $file, array $extensions, int $max_size, array $convert=[]):array|string
	{

		if(!$file || !$file->isValid())return ts('the upload failed');

		$extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
		$allowed = array_values(array_diff($extensions, self::FORBIDDEN_EXTENSIONS));

		// validator on the multipart input `file` ($_FILES), its values hold the name: the file rules skip empty values
		$locale = App()?->locale ?? 'en';
		$v = new \Core\Validator($locale, ['file' => $file->getClientOriginalName()]);
		$v->addLocaleMessages($locale, \Core\I18n::messages());
		$v->input('file', ts('File'))->fileUploaded('the upload failed');
		if($max_size > 0 && $file->getSize() > $max_size)$v->addError('the file is larger than [SIZE] MB', ['SIZE' => round($max_size / 1048576, 1)]);
		$v->fileExtension($allowed, 'file type not allowed: [EXTENSIONS]');
		if(!$v->fails() && isset(self::MIMES[$extension]))$v->fileMime(self::MIMES[$extension], 'the content of the file does not match its extension');
		if($v->fails())return (string)$v->result()['error_stack'][0];

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

		if($convert)
		{

			$extension = self::imageConvert("{$dir}/{$token}", $convert);
			if($extension === '')
			{

				@unlink("{$dir}/{$token}");
				return ts('the file is not a valid image');

			}

			$name = pathinfo($name, PATHINFO_FILENAME).".{$extension}";

		}

		clearstatcache(true, "{$dir}/{$token}");
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
	 * or to upload_dir/<dir> under a random uuid (a public url is never guessed)
	 *
	 * @param string $value upload:<token>
	 * @param string $dir ex: files
	 * @param bool $public upload_dir instead of private_dir
	 *
	 * @return string path relative to private_dir or upload_dir, '' when the token is not valid
	 */
	public static function uploadMove(string $value, string $dir, bool $public=false):string
	{

		if(!$upload = self::uploadGet($value))return '';

		$dir = trim(str_replace('..', '', $dir), '/');
		$target = ($public) ? self::publicDir($dir) : self::privateDir($dir);
		$extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));

		if($public)
		{

			do
			{

				$name = uuid().".{$extension}";

			}
			while(file_exists("{$target}/{$name}"));

		}
		else
		{

			$base = slugify(pathinfo($upload['name'], PATHINFO_FILENAME), 'file');
			$name = "{$base}.{$extension}";
			for($i = 2; file_exists("{$target}/{$name}"); $i++)
				$name = "{$base}-{$i}.{$extension}";

		}

		rename($upload['path'], "{$target}/{$name}");
		@unlink("{$upload['path']}.json");

		return "{$dir}/{$name}";

	}

	/**
	 * crops an image from its center to the size asked and writes it in place in the format asked (gd, exif orientation applied)
	 *
	 * @param string $path image, replaced
	 * @param array $convert ['width', 'height', 'extension', 'background'], extension webp, jpg, png or gif (default webp), background #rrggbb drawn under the transparent pixels (empty = transparency kept)
	 *
	 * @return string extension written, '' when the file is not a readable image
	 */
	public static function imageConvert(string $path, array $convert):string
	{

		$extension = strtolower((string)($convert['extension'] ?? 'webp'));
		if(!isset(self::IMAGE_TYPES[$extension]))$extension = 'webp';
		if($extension === 'jpeg')$extension = 'jpg';

		$width = max(1, (int)($convert['width'] ?? 150));
		$height = max(1, (int)($convert['height'] ?? 150));

		// a server problem, not a bad file: logged as an error
		$writer = ['webp' => 'imagewebp', 'jpg' => 'imagejpeg', 'png' => 'imagepng', 'gif' => 'imagegif'][$extension];
		if(!function_exists($writer))throw new \RuntimeException("php gd cannot write {$extension} images (function {$writer} missing)");

		try
		{

			$image = new \Gumlet\ImageResize($path);
			$image->crop($width, $height, true);

			// transparent png / gif / webp: flattened on the background color
			if(preg_match('/^#([0-9a-f]{6})$/i', (string)($convert['background'] ?? ''), $m))
			{

				$rgb = array_map('hexdec', str_split($m[1], 2));
				$image->addFilter(function(\GdImage $dest) use ($rgb):void
				{

					$w = imagesx($dest);
					$h = imagesy($dest);
					$canvas = imagecreatetruecolor($w, $h);
					imagefill($canvas, 0, 0, imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]));
					imagecopy($canvas, $dest, 0, 0, 0, 0, $w, $h);

					imagealphablending($dest, false);
					imagecopy($dest, $canvas, 0, 0, 0, 0, $w, $h);
					imagecolortransparent($dest, -1);

				});

			}

			$image->save($path, self::IMAGE_TYPES[$extension]);

		}
		catch(\Throwable)
		{

			return '';

		}

		return $extension;

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