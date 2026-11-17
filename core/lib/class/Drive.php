<?php

namespace Core;

/**
 * Drive (module drive): folders (xcore_drive_folder) and files (xcore_drive_file) stored in private_dir/drive under random names
 *
 * rights: the plugin gives the ceiling (list = read, write, delete = empty the trash, manage = settings), each folder can only narrow it
 * with its groups (settings read / write / manage), checked on the whole path; the root (id 0) has no row and no setting
 * settings of a folder, inherited by its subfolders: extensions and max_size (narrowed along the path, config drive/* is the ceiling),
 * links (no anywhere on the path = no), public and versions (the nearest folder that sets them)
 * trash: a trashed folder or file has deleted = yes, the content of a trashed folder stays as it is and comes back with it
 */
class Drive
{

	public const string PLUGIN = 'drive';
	public const array LEVELS = ['read', 'write', 'manage'];

	// files and folders copied at once at most
	public const int COPY_MAX = 1000;

	// signature of the tags of the files (\Core\Tag)
	public const string TAGS = 'xcore_drive_file';

	// files edited in the browser: text editor (mime stored by extension) and image editor (formats written by the browsers)
	public const array TEXT_MIMES = ['txt' => 'text/plain', 'md' => 'text/plain', 'log' => 'text/plain', 'ini' => 'text/plain', 'yml' => 'text/plain', 'yaml' => 'text/plain', 'csv' => 'text/csv', 'json' => 'application/json', 'xml' => 'application/xml'];
	public const array IMAGE_EDITABLE = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'bmp'];
	public const array IMAGE_OUTPUTS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
	public const int TEXT_MAX = 5242880;

	// defaults of the globals drive.* (module Globals)
	public const array DEFAULT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'md', 'csv', 'json', 'xml', 'log', 'ini', 'yml', 'yaml', 'eml', 'msg',
											 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'tif', 'tiff', 'bmp', 'psd', 'ai', 'eps',
											 'mp3', 'wav', 'ogg', 'm4a', 'flac', 'mp4', 'webm', 'mov', 'avi', 'mkv',
											 'zip', 'rar', '7z', 'tar', 'gz', 'dwg', 'dxf'];
	public const array DEFAULT_FILTERS = [
		'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'tif', 'tiff', 'bmp', 'psd', 'ai', 'eps'],
		'pdf' => ['pdf'],
		'office' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'],
		'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
		'video' => ['mp4', 'webm', 'mov', 'avi', 'mkv'],
		'audio' => ['mp3', 'wav', 'ogg', 'm4a', 'flac'],
		'text' => ['txt', 'md', 'csv', 'json', 'xml', 'log', 'ini', 'yml', 'yaml'],
	];

	// characters refused in a name (windows and unix), control characters too
	private const string NAME_FORBIDDEN = '/[\x00-\x1f\x7f\/\\\\:*?"<>|]/u';

	/** @var array cache of the request: folder id => rights */
	private static array $rights = [];

	/** @var array cache of the request: folder id => chain */
	private static array $chains = [];

	/**
	 * clears the caches of the request (after a move, a change of settings)
	 *
	 * @return void
	 */
	public static function reset():void
	{

		self::$rights = [];
		self::$chains = [];

	}

	// folders ********************************************************************************************************

	/**
	 * folders from the root to the folder (itself last), every one of them alive
	 *
	 * @param int $id 0 = root
	 *
	 * @return array|null [] for the root, null when the folder or one of its parents is missing or trashed
	 */
	public static function chain(int $id):?array
	{

		if($id <= 0)return [];
		if(array_key_exists($id, self::$chains))return self::$chains[$id];

		$folder = DB()->query("SELECT id, path FROM xcore_drive_folder WHERE id = :id AND deleted = 'no'", ['id' => $id])->fetch();
		if(!$folder)return self::$chains[$id] = null;

		$ids = self::pathIds((string)$folder['path']);
		if(!$ids)return self::$chains[$id] = null;

		$rows = DB()->query("SELECT id, xcore_drive_folder_id, name, path, settings FROM xcore_drive_folder WHERE id IN (".implode(',', $ids).") AND deleted = 'no'")->fetchAll();
		if(count($rows) !== count($ids))return self::$chains[$id] = null;

		$by_id = array_column($rows, null, 'id');
		$chain = [];
		foreach($ids as $fid)
		{

			$row = $by_id[$fid];
			$row['id'] = (int)$row['id'];
			$row['xcore_drive_folder_id'] = (int)$row['xcore_drive_folder_id'];
			$row['settings'] = self::decodeSettings($row['settings']);
			$chain[] = $row;

		}

		return self::$chains[$id] = $chain;

	}

	/**
	 * ids of a materialized path
	 *
	 * @param string $path /3/7/
	 *
	 * @return int[]
	 */
	public static function pathIds(string $path):array
	{

		return array_values(array_filter(array_map('intval', explode('/', trim($path, '/')))));

	}

	/**
	 * alive folder
	 *
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function folder(int $id):?array
	{

		$chain = self::chain($id);
		return ($chain) ? end($chain) : null;

	}

	/**
	 * folder of a path of names, ex: /Clients/Dupont SARL (case insensitive, alive folders only)
	 *
	 * @param string $path
	 *
	 * @return int|null folder id, 0 = root, null when not found
	 */
	public static function folderByPath(string $path):?int
	{

		$id = 0;
		foreach(array_filter(explode('/', str_replace('\\', '/', $path)), fn($s) => trim($s) !== '') as $segment)
		{

			$next = DB()->query("SELECT id FROM xcore_drive_folder WHERE xcore_drive_folder_id = :parent AND name = :name AND deleted = 'no' ORDER BY id LIMIT 1", ['parent' => $id, 'name' => trim($segment)])->fetchOne();
			if(!$next)return null;
			$id = (int)$next;

		}

		return $id;

	}

	/**
	 * path of names of a folder, ex: /Clients/Dupont SARL
	 *
	 * @param int $id
	 *
	 * @return string '/' for the root, '' when the folder is not alive
	 */
	public static function folderPath(int $id):string
	{

		$chain = self::chain($id);
		if($chain === null)return '';

		return '/'.implode('/', array_column($chain, 'name'));

	}

	// rights *********************************************************************************************************

	/**
	 * rights of the logged user in a folder
	 *
	 * @param int $id folder, 0 = root
	 *
	 * @return array ['read', 'write', 'delete', 'manage'] booleans, all false when the folder is not alive
	 */
	public static function rights(int $id):array
	{

		if(isset(self::$rights[$id]))return self::$rights[$id];

		$none = ['read' => false, 'write' => false, 'delete' => false, 'manage' => false];
		$chain = self::chain($id);
		if($chain === null || !\Model\User::isLogon())return self::$rights[$id] = $none;

		$plugin = \Model\User::getRights(self::PLUGIN);
		$rights = [
			'read' => in_array('list', $plugin, true),
			'write' => in_array('write', $plugin, true),
			'delete' => in_array('delete', $plugin, true),
			'manage' => in_array('manage', $plugin, true),
		];

		if(!\Model\User::isSuperAdmin())
		{

			// groups of each folder of the path narrow the rights
			$group = (int)\Core\Session::get('auth.group_id', 0);
			foreach($chain as $folder)
			{

				foreach(self::LEVELS as $level)
				{

					$groups = $folder['settings'][$level] ?? [];
					if(is_array($groups) && $groups && !in_array($group, array_map('intval', $groups), true))$rights[$level] = false;

				}

			}

		}

		// each level needs the one below it
		$rights['write'] = $rights['write'] && $rights['read'];
		$rights['manage'] = $rights['manage'] && $rights['write'];
		$rights['delete'] = $rights['delete'] && $rights['write'];

		return self::$rights[$id] = $rights;

	}

	/**
	 * the logged user has a right in a folder
	 *
	 * @param int $id
	 * @param string $right read, write, delete, manage
	 *
	 * @return bool
	 */
	public static function can(int $id, string $right):bool
	{

		return (bool)(self::rights($id)[$right] ?? false);

	}

	/**
	 * groups that keep each level in a folder: the groups of every folder of the path intersected (null = not narrowed)
	 * compared before a move: a folder or a file moved elsewhere can change who sees it
	 *
	 * @param int $id
	 *
	 * @return array ['read' => int[]|null, 'write' => ..., 'manage' => ...]
	 */
	public static function access(int $id):array
	{

		$access = ['read' => null, 'write' => null, 'manage' => null];
		foreach(self::chain($id) ?? [] as $folder)
		{

			foreach(self::LEVELS as $level)
			{

				$groups = $folder['settings'][$level] ?? [];
				if(!is_array($groups) || !$groups)continue;

				$groups = array_map('intval', $groups);
				$access[$level] = ($access[$level] === null) ? $groups : array_values(array_intersect($access[$level], $groups));

			}

		}

		foreach($access as &$groups)
		{

			if($groups !== null)
			{

				$groups = array_values(array_unique($groups));
				sort($groups);

			}

		}

		return $access;

	}

	// globals ********************************************************************************************************

	/**
	 * extensions allowed in the drive (global drive.extensions, ceiling of every folder), \Core\File::FORBIDDEN_EXTENSIONS left out
	 *
	 * @return string[]
	 */
	public static function allowedExtensions():array
	{

		$list = \Core\Globals::get('drive.extensions', self::DEFAULT_EXTENSIONS, true, 'list', 'extensions allowed in the drive, one by line (ceiling of every folder, php, html, svg, js... always refused)');
		$list = array_map(fn($e) => strtolower(trim((string)$e, " .\r\n")), (array)$list);

		return array_values(array_unique(array_diff(array_filter($list), \Core\File::FORBIDDEN_EXTENSIONS)));

	}

	/**
	 * max size of a file (global drive.max_size in MB, ceiling of every folder)
	 *
	 * @return int bytes
	 */
	public static function maxSize():int
	{

		return max(1, (int)\Core\Globals::get('drive.max_size', '2048', true, 'int', 'max size of a file of the drive in MB (ceiling of every folder)')) * 1048576;

	}

	/**
	 * filters by type of the File Manager (global drive.filters): key => extensions
	 * keys image, pdf, office, archive, video, audio, text are translated by the interface, another key is shown as it is
	 *
	 * @return array
	 */
	public static function filters():array
	{

		$raw = \Core\Globals::get('drive.filters', self::DEFAULT_FILTERS, true, 'json-object', 'filters by type of the drive: {"name": ["ext", ...]}, the keys image, pdf, office, archive, video, audio, text are translated');
		$raw = json_decode((string)json_encode($raw), true);
		if(!is_array($raw))return self::DEFAULT_FILTERS;

		$filters = [];
		foreach($raw as $key => $extensions)
		{

			$key = (string)$key;
			$extensions = array_values(array_filter(array_map(fn($e) => strtolower(trim((string)$e, ' .')), (array)$extensions)));
			if(preg_match('/^[a-z0-9_-]{1,40}$/i', $key) && $extensions)$filters[$key] = $extensions;

		}

		return $filters;

	}

	// search *********************************************************************************************************

	/**
	 * text of a search: words for the name (* and ? as wildcards: *.pdf, devis-202?) and #tags (#"two words" for a tag with spaces)
	 *
	 * @param string $q
	 *
	 * @return array ['name' => like pattern|null, 'tags' => string[]]
	 */
	public static function parseQuery(string $q):array
	{

		// #tag or #"tag with spaces", the other words make the name
		$tags = [];
		$words = [];
		preg_match_all('/#"([^"]+)"|#(\S+)|(\S+)/u', trim($q), $matches, PREG_SET_ORDER);
		foreach($matches as $m)
		{

			if(($m[1] ?? '') !== '')$tags[] = trim($m[1]);
			elseif(($m[2] ?? '') !== '')$tags[] = $m[2];
			elseif(($m[3] ?? '') !== '')$words[] = $m[3];

		}

		$name = null;
		$text = implode(' ', $words);
		if($text !== '')
		{

			$like = addcslashes($text, '%_\\');
			$name = (strpbrk($text, '*?') !== false) ? strtr($like, ['*' => '%', '?' => '_']) : "%{$like}%";

		}

		return ['name' => $name, 'tags' => array_values(array_unique($tags))];

	}

	/**
	 * sql of the files that have every tag (case insensitive)
	 *
	 * @param array $tags
	 * @param array $binds filled
	 * @param string $alias alias of xcore_drive_file
	 *
	 * @return string '' without tag
	 */
	public static function tagsSql(array $tags, array &$binds, string $alias='f'):string
	{

		$sql = '';
		foreach(array_values($tags) as $i => $tag)
		{

			$binds["tag{$i}"] = $tag;
			$sql .= " AND EXISTS (SELECT 1 FROM xcore_tag t WHERE t.deleted = 'no' AND t.signature = '".self::TAGS."' AND t.record_id = {$alias}.id AND t.tag = :tag{$i})";

		}

		return $sql;

	}

	// settings *******************************************************************************************************

	/**
	 * settings of a folder as stored, unknown keys and wrong values left out
	 *
	 * @param mixed $json
	 *
	 * @return array read, write, manage (int[]), extensions (string[]), max_size (int), links, public, versions (yes|no), keep (int)
	 */
	public static function decodeSettings(mixed $json):array
	{

		$raw = (is_array($json)) ? $json : json_decode((string)$json, true);
		if(!is_array($raw))return [];

		$settings = [];
		foreach(self::LEVELS as $level)
		{

			if(isset($raw[$level]) && is_array($raw[$level]) && $raw[$level])$settings[$level] = array_values(array_unique(array_map('intval', $raw[$level])));

		}

		if(isset($raw['extensions']) && is_array($raw['extensions']) && $raw['extensions'])$settings['extensions'] = array_values(array_unique(array_map(fn($e) => strtolower(trim((string)$e, " .")), $raw['extensions'])));
		if(isset($raw['max_size']) && (int)$raw['max_size'] > 0)$settings['max_size'] = (int)$raw['max_size'];
		foreach(['links', 'public', 'versions'] as $key)
		{

			if(isset($raw[$key]) && in_array($raw[$key], ['yes', 'no'], true))$settings[$key] = $raw[$key];

		}
		if(isset($raw['keep']) && (int)$raw['keep'] > 0)$settings['keep'] = (int)$raw['keep'];

		return $settings;

	}

	/**
	 * settings in force in a folder, inherited along the path
	 *
	 * @param int $id 0 = root (config drive/*)
	 * @param bool $parent_only settings inherited from the parents, the folder left out (form of the settings)
	 *
	 * @return array ['extensions' => string[], 'max_size' => int, 'links' => bool, 'public' => bool, 'versions' => bool]
	 */
	public static function settings(int $id, bool $parent_only=false):array
	{

		$extensions = self::allowedExtensions();
		$max_size = self::maxSize();
		$links = true;
		$public = false;
		$versions = false;

		$chain = self::chain($id) ?? [];
		if($parent_only)array_pop($chain);

		foreach($chain as $folder)
		{

			$s = $folder['settings'];
			if(isset($s['extensions']))$extensions = array_values(array_intersect($extensions, $s['extensions']));
			if(isset($s['max_size']))$max_size = min($max_size, $s['max_size']);
			if(($s['links'] ?? '') === 'no')$links = false;
			if(isset($s['public']))$public = ($s['public'] === 'yes');
			if(isset($s['versions']))$versions = ($s['versions'] === 'yes');

		}

		return ['extensions' => $extensions, 'max_size' => $max_size, 'links' => $links, 'public' => $public, 'versions' => $versions];

	}

	/**
	 * checks the settings sent for a folder: groups that exist, extensions and max size inside what the parents allow
	 *
	 * @param array $input
	 * @param int $id folder
	 * @param array $errors field => message
	 *
	 * @return array settings to store
	 */
	public static function checkSettings(array $input, int $id, array &$errors):array
	{

		$errors = [];
		$parent = self::settings($id, true);
		$groups = array_map('intval', DB()->query("SELECT id FROM xcore_group WHERE deleted = 'no'")->fetchAllOne());

		$settings = [];
		foreach(self::LEVELS as $level)
		{

			$values = array_values(array_unique(array_map('intval', (array)($input[$level] ?? []))));
			if(array_diff($values, $groups))$errors[$level] = ts('unknown group');
			elseif($values)$settings[$level] = $values;

		}

		$extensions = array_values(array_unique(array_filter(array_map(fn($e) => strtolower(trim((string)$e, " .")), (array)($input['extensions'] ?? [])))));
		if($extensions)
		{

			$refused = array_diff($extensions, $parent['extensions']);
			if($refused)$errors['extensions'] = ts('not allowed by the parent folders: [EXTENSIONS]', ['EXTENSIONS' => implode(', ', $refused)]);
			else $settings['extensions'] = $extensions;

		}

		$max_size = (int)($input['max_size'] ?? 0);
		if($max_size < 0)$errors['max_size'] = ts('must be a positive number');
		elseif($max_size > $parent['max_size'])$errors['max_size'] = ts('larger than the size allowed by the parent folders ([SIZE] MB)', ['SIZE' => round($parent['max_size'] / 1048576, 1)]);
		elseif($max_size > 0)$settings['max_size'] = $max_size;

		foreach(['links', 'public', 'versions'] as $key)
		{

			$value = (string)($input[$key] ?? '');
			if($value === '')continue;
			if(!in_array($value, ['yes', 'no'], true))$errors[$key] = ts('invalid value');
			else $settings[$key] = $value;

		}

		// links allowed below a parent that refuses them: refused
		if(($settings['links'] ?? '') === 'yes' && !$parent['links'])$errors['links'] = ts('the parent folders refuse the links');

		$keep = (int)($input['keep'] ?? 0);
		if($keep < 0 || $keep > 1000)$errors['keep'] = ts('between 0 and 1000');
		elseif($keep > 0)$settings['keep'] = $keep;

		return $settings;

	}

	// names **********************************************************************************************************

	/**
	 * name of a file or a folder as stored: forbidden characters replaced, trimmed, 255 characters
	 *
	 * @param string $name
	 *
	 * @return string '' when nothing is left
	 */
	public static function cleanName(string $name):string
	{

		$name = trim(preg_replace('/\s+/u', ' ', preg_replace(self::NAME_FORBIDDEN, '_', $name) ?? ''));
		$name = rtrim($name, ' .');
		if($name === '.' || $name === '..')return '';

		return mb_substr($name, 0, 255);

	}

	/**
	 * a name is already used in a folder by a folder or a file (case insensitive, alive ones)
	 *
	 * @param int $folder_id
	 * @param string $name
	 * @param string $skip_type folder|file left out (the record renamed)
	 * @param int $skip_id
	 *
	 * @return array|null ['type', 'id'] of the record that uses it
	 */
	public static function nameTaken(int $folder_id, string $name, string $skip_type='', int $skip_id=0):?array
	{

		$folder = DB()->query("SELECT id FROM xcore_drive_folder WHERE xcore_drive_folder_id = :parent AND name = :name AND deleted = 'no' AND id <> :skip LIMIT 1", ['parent' => $folder_id, 'name' => $name, 'skip' => ($skip_type === 'folder') ? $skip_id : 0])->fetchOne();
		if($folder)return ['type' => 'folder', 'id' => (int)$folder];

		$file = DB()->query("SELECT id FROM xcore_drive_file WHERE xcore_drive_folder_id = :parent AND name = :name AND deleted = 'no' AND id <> :skip LIMIT 1", ['parent' => $folder_id, 'name' => $name, 'skip' => ($skip_type === 'file') ? $skip_id : 0])->fetchOne();
		if($file)return ['type' => 'file', 'id' => (int)$file];

		return null;

	}

	/**
	 * free name in a folder: name (2).ext, name (3).ext...
	 *
	 * @param int $folder_id
	 * @param string $name
	 * @param bool $is_folder the extension is part of the name
	 *
	 * @return string
	 */
	public static function uniqueName(int $folder_id, string $name, bool $is_folder=false):string
	{

		if(!self::nameTaken($folder_id, $name))return $name;

		$extension = ($is_folder) ? '' : pathinfo($name, PATHINFO_EXTENSION);
		$base = ($extension === '') ? $name : mb_substr($name, 0, -(mb_strlen($extension) + 1));
		$base = preg_replace('/ \(\d+\)$/u', '', $base);

		for($i = 2; $i < 10000; $i++)
		{

			$candidate = mb_substr($base, 0, 240)." ({$i})".(($extension === '') ? '' : ".{$extension}");
			if(!self::nameTaken($folder_id, $candidate))return $candidate;

		}

		return mb_substr($base, 0, 200).' ('.bin2hex(random_bytes(4)).')'.(($extension === '') ? '' : ".{$extension}");

	}

	// files **********************************************************************************************************

	/**
	 * alive file whose folder is alive
	 *
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function file(int $id):?array
	{

		$file = DB()->query("SELECT * FROM xcore_drive_file WHERE id = :id AND deleted = 'no'", ['id' => $id])->fetch();
		if(!$file || self::chain((int)$file['xcore_drive_folder_id']) === null)return null;

		return $file;

	}

	/**
	 * checks a file before it is stored in a folder: extension, real type, size
	 *
	 * @param string $path file on the disk
	 * @param string $name name given by the user
	 * @param int $folder_id
	 *
	 * @return string error message, '' when accepted
	 */
	public static function checkFile(string $path, string $name, int $folder_id):string
	{

		$settings = self::settings($folder_id);
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

		if($extension === '' || in_array($extension, \Core\File::FORBIDDEN_EXTENSIONS, true) || !in_array($extension, $settings['extensions'], true))
			return ts('file type not allowed: [EXTENSIONS]', ['EXTENSIONS' => implode(', ', $settings['extensions'])]);

		$size = (int)@filesize($path);
		if($size > $settings['max_size'])return ts('the file is larger than [SIZE] MB', ['SIZE' => round($settings['max_size'] / 1048576, 1)]);

		$mime = self::detectMime($path);
		if(isset(\Core\File::MIMES[$extension]) && !in_array($mime, \Core\File::MIMES[$extension], true))return ts('the content of the file does not match its extension');

		// a renamed script or page is refused whatever its extension
		if(preg_match('#^(text/html|application/x-httpd-php|text/x-php|application/x-php|application/javascript|text/javascript|image/svg\+xml|application/x-executable|application/x-dosexec)#', $mime))return ts('the content of the file does not match its extension');

		return '';

	}

	/**
	 * real type of a file: finfo, then getimagesize for the images an old libmagic does not know (avif, webp of some php builds on windows)
	 *
	 * @param string $path
	 *
	 * @return string
	 */
	public static function detectMime(string $path):string
	{

		$mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($path);
		if($mime === '' || $mime === 'application/octet-stream')
		{

			$image = @getimagesize($path);
			if(is_array($image) && !empty($image['mime']))$mime = (string)$image['mime'];

		}

		return $mime;

	}

	/**
	 * avif written by the server (gd with libavif): the browsers cannot write it from a canvas
	 *
	 * @return bool
	 */
	public static function avifWrite():bool
	{

		// gd can be built with an avif decoder only: a pixel is written once to know it
		static $ok = null;
		if($ok === null)
		{

			$ok = false;
			if(function_exists('imageavif') && !empty(gd_info()['AVIF Support']))
			{

				ob_start();
				$written = @imageavif(imagecreatetruecolor(1, 1), null, 50, 10);
				$ok = $written && ob_get_clean() !== '';
				if(!$written)ob_end_clean();

			}

		}

		return $ok;

	}

	/**
	 * moves a checked file to the storage and records it (new file or new content of an existing one)
	 *
	 * @param string $path file on the disk, moved
	 * @param string $name
	 * @param int $folder_id
	 * @param int $replace_id file whose content is replaced, 0 = new file
	 * @param string $mime type stored instead of the detected one (text files of the editor)
	 *
	 * @return array the row of the file
	 */
	public static function storeFile(string $path, string $name, int $folder_id, int $replace_id=0, string $mime=''):array
	{

		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if($mime === '')$mime = self::detectMime($path);
		$size = (int)filesize($path);
		$sha256 = (string)hash_file('sha256', $path);

		$disk = self::diskPath();
		if(!@rename($path, \Core\File::privateDir().'/'.$disk))
		{

			if(!@copy($path, \Core\File::privateDir().'/'.$disk))throw new \RuntimeException('the file cannot be written in private_dir/drive');
			@unlink($path);

		}

		$author = (string)\Core\Session::get('auth.login');
		$row = ['name' => $name, 'extension' => $extension, 'mime' => mb_substr($mime, 0, 100), 'size' => $size, 'sha256' => $sha256, 'disk' => $disk, 'status' => 'ready'];

		if($replace_id > 0 && ($old = DB()->query("SELECT * FROM xcore_drive_file WHERE id = :id", ['id' => $replace_id])->fetch()))
		{

			// same name, the extension changes only with the format (image editor): name given by the caller
			if(strtolower((string)$old['extension']) === $extension)unset($row['name']);
			DB('xcore_drive_file')->update($row, $replace_id, -1, $author);
			\Core\File::remove((string)$old['disk']);
			\Core\Storage::add($size - (int)$old['size']);
			$id = $replace_id;

		}
		else
		{

			$row['xcore_drive_folder_id'] = $folder_id;
			$id = (int)DB('xcore_drive_file')->insert($row, $author);
			\Core\Storage::add($size);

		}

		return DB()->query("SELECT * FROM xcore_drive_file WHERE id = :id", ['id' => $id])->fetch();

	}

	/**
	 * new random place of a file in private_dir: drive/<2 hex>/<32 hex>
	 *
	 * @return string relative to private_dir
	 */
	public static function diskPath():string
	{

		do
		{

			$hex = bin2hex(random_bytes(16));
			$relative = 'drive/'.substr($hex, 0, 2)."/{$hex}";

		}
		while(file_exists(\Core\File::privateDir().'/'.$relative));

		\Core\File::privateDir('drive/'.substr($hex, 0, 2));
		return $relative;

	}

	/**
	 * copy of a file in a folder (new content on the disk, counted in the quota)
	 *
	 * @param array $file row
	 * @param int $folder_id
	 * @param string $name
	 *
	 * @return int id of the copy
	 */
	public static function copyFile(array $file, int $folder_id, string $name):int
	{

		$disk = self::diskPath();
		if(!@copy(\Core\File::privateDir().'/'.$file['disk'], \Core\File::privateDir().'/'.$disk))throw new \RuntimeException('the file cannot be copied in private_dir/drive');

		$id = (int)DB('xcore_drive_file')->insert([
			'xcore_drive_folder_id' => $folder_id,
			'name' => $name,
			'extension' => $file['extension'],
			'mime' => $file['mime'],
			'size' => (int)$file['size'],
			'sha256' => $file['sha256'],
			'disk' => $disk,
			'status' => $file['status'],
		], (string)\Core\Session::get('auth.login'));

		\Core\Storage::add((int)$file['size']);
		return $id;

	}

	/**
	 * copy of a folder and of its content (alive ones) in a folder
	 *
	 * @param int $folder_id folder copied
	 * @param int $target_id
	 * @param string $name
	 *
	 * @return int id of the copy
	 */
	public static function copyFolder(int $folder_id, int $target_id, string $name):int
	{

		$id = self::createFolder($target_id, $name);

		foreach(DB()->query("SELECT * FROM xcore_drive_file WHERE xcore_drive_folder_id = :id AND deleted = 'no' ORDER BY id", ['id' => $folder_id])->fetchAll() as $file)
			self::copyFile($file, $id, (string)$file['name']);

		foreach(DB()->query("SELECT id, name FROM xcore_drive_folder WHERE xcore_drive_folder_id = :id AND deleted = 'no' ORDER BY id", ['id' => $folder_id])->fetchAll() as $child)
			self::copyFolder((int)$child['id'], $id, (string)$child['name']);

		return $id;

	}

	/**
	 * files and folders under a folder (alive ones), to check a copy
	 *
	 * @param int $folder_id
	 *
	 * @return array ['files', 'bytes', 'folders']
	 */
	public static function treeCount(int $folder_id):array
	{

		$path = (string)DB()->query("SELECT path FROM xcore_drive_folder WHERE id = :id", ['id' => $folder_id])->fetchOne();
		$ids = array_map('intval', DB()->query("SELECT id FROM xcore_drive_folder WHERE (path LIKE :path) AND deleted = 'no'", ['path' => "{$path}%"])->fetchAllOne());
		if(!$ids)return ['files' => 0, 'bytes' => 0, 'folders' => 0];

		$files = DB()->query("SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS bytes FROM xcore_drive_file WHERE xcore_drive_folder_id IN (".implode(',', $ids).") AND deleted = 'no'")->fetch();
		return ['files' => (int)$files['n'], 'bytes' => (int)$files['bytes'], 'folders' => count($ids)];

	}

	// folders: create, move ******************************************************************************************

	/**
	 * creates a folder (name checked and free)
	 *
	 * @param int $parent_id
	 * @param string $name
	 *
	 * @return int
	 */
	public static function createFolder(int $parent_id, string $name):int
	{

		$parent_path = ($parent_id > 0) ? (string)DB()->query("SELECT path FROM xcore_drive_folder WHERE id = :id", ['id' => $parent_id])->fetchOne() : '/';
		$id = (int)DB('xcore_drive_folder')->insert(['xcore_drive_folder_id' => $parent_id, 'name' => $name, 'path' => ''], (string)\Core\Session::get('auth.login'));
		DB()->query("UPDATE xcore_drive_folder SET path = :path WHERE id = :id", ['path' => $parent_path.$id.'/', 'id' => $id]);

		return $id;

	}

	/**
	 * moves a folder under another one, the paths of its subfolders follow
	 *
	 * @param int $id
	 * @param int $target_id
	 * @param string $name
	 *
	 * @return void
	 */
	public static function moveFolder(int $id, int $target_id, string $name):void
	{

		$old_path = (string)DB()->query("SELECT path FROM xcore_drive_folder WHERE id = :id", ['id' => $id])->fetchOne();
		$target_path = ($target_id > 0) ? (string)DB()->query("SELECT path FROM xcore_drive_folder WHERE id = :id", ['id' => $target_id])->fetchOne() : '/';
		$new_path = $target_path.$id.'/';

		DB('xcore_drive_folder')->update(['xcore_drive_folder_id' => $target_id, 'name' => $name], $id, -1, (string)\Core\Session::get('auth.login'));
		DB()->query("UPDATE xcore_drive_folder SET path = CONCAT(:new_path, SUBSTRING(path, :len)) WHERE path LIKE :old_path", ['new_path' => $new_path, 'len' => strlen($old_path) + 1, 'old_path' => "{$old_path}%"]);

		self::reset();

	}

	/**
	 * the folder is the target or one of its parents (a folder never goes inside itself)
	 *
	 * @param int $id folder moved
	 * @param int $target_id
	 *
	 * @return bool
	 */
	public static function isInside(int $id, int $target_id):bool
	{

		if($target_id <= 0)return false;
		$target_path = (string)DB()->query("SELECT path FROM xcore_drive_folder WHERE id = :id", ['id' => $target_id])->fetchOne();

		return in_array($id, self::pathIds($target_path), true);

	}

	// trash **********************************************************************************************************

	/**
	 * deletes for good a trashed folder or file: rows and files of its whole content
	 *
	 * @param string $type folder|file
	 * @param int $id
	 *
	 * @return int bytes freed
	 */
	public static function purge(string $type, int $id):int
	{

		if($type === 'file')
		{

			$file = DB()->query("SELECT id, disk, size FROM xcore_drive_file WHERE id = :id", ['id' => $id])->fetch();
			if(!$file)return 0;

			return self::purgeFiles([$file]);

		}

		$path = (string)DB()->query("SELECT path FROM xcore_drive_folder WHERE id = :id", ['id' => $id])->fetchOne();
		if($path === '')return 0;

		$ids = array_map('intval', DB()->query("SELECT id FROM xcore_drive_folder WHERE path LIKE :path", ['path' => "{$path}%"])->fetchAllOne());
		$freed = 0;

		foreach(array_chunk($ids, 500) as $chunk)
		{

			$files = DB()->query("SELECT id, disk, size FROM xcore_drive_file WHERE xcore_drive_folder_id IN (".implode(',', $chunk).")")->fetchAll();
			$freed += self::purgeFiles($files);

			DB()->query("DELETE FROM xcore_drive_pin WHERE xcore_drive_folder_id IN (".implode(',', $chunk).")");
			DB()->query("DELETE FROM xcore_drive_folder WHERE id IN (".implode(',', $chunk).")");

		}

		return $freed;

	}

	/**
	 * removes files from the disk and the database, the quota is freed
	 *
	 * @param array $files rows with id, disk, size
	 *
	 * @return int bytes freed
	 */
	private static function purgeFiles(array $files):int
	{

		if(!$files)return 0;

		$freed = 0;
		foreach($files as $file)
		{

			\Core\File::remove((string)$file['disk']);
			$freed += (int)$file['size'];

		}

		$ids = implode(',', array_map(fn($f) => (int)$f['id'], $files));
		DB()->query("DELETE FROM xcore_drive_favorite WHERE xcore_drive_file_id IN ({$ids})");
		DB()->query("DELETE FROM xcore_drive_link WHERE xcore_drive_file_id IN ({$ids})");
		DB()->query("DELETE FROM xcore_drive_file WHERE id IN ({$ids})");
		\Core\Storage::add(-$freed);

		return $freed;

	}

	// urls ***********************************************************************************************************

	/**
	 * url of a file served by the api (rights checked, public folders without logon)
	 *
	 * @param int $id
	 * @param bool $full with the url of the instance (config url)
	 *
	 * @return string
	 */
	public static function url(int $id, bool $full=false):string
	{

		$url = "/api/drive/files/{$id}/download/";
		return ($full) ? rtrim((string)\Core\Config::get('url'), '/').$url : $url;

	}

	// share links ****************************************************************************************************

	// wrong passwords in a row before the link is locked, minutes of the lock
	public const int LINK_FAILURES = 5;
	public const int LINK_LOCK_MINUTES = 15;

	// a download ticket is valid this time (s): the ranges of a video or a resumed download do not count again
	public const int LINK_TICKET_TTL = 600;

	/**
	 * link of a token with its file, null when it is unknown or deleted
	 *
	 * @param string $token
	 *
	 * @return array|null link row + 'file' (row of the file, null when the file is gone or in the trash)
	 */
	public static function link(string $token):?array
	{

		if(!preg_match('/^[a-f0-9]{32}$/', $token))return null;

		$link = DB()->query("SELECT * FROM xcore_drive_link WHERE token = :token AND deleted = 'no'", ['token' => $token])->fetch();
		if(!$link)return null;

		$link['file'] = self::file((int)$link['xcore_drive_file_id']);
		return $link;

	}

	/**
	 * state of a link: ok, expired, revoked, used (max downloads reached), gone (file deleted or in the trash, links refused by its folder), locked (wrong passwords)
	 *
	 * @param array $link row of self::link() (with 'file')
	 *
	 * @return string
	 */
	public static function linkState(array $link):string
	{

		if($link['revoked_at'])return 'revoked';
		if(empty($link['file']) || !self::settings((int)$link['file']['xcore_drive_folder_id'])['links'])return 'gone';
		if($link['expires_at'] && strtotime((string)$link['expires_at']) <= time())return 'expired';
		if((int)$link['max_downloads'] > 0 && (int)$link['downloads'] >= (int)$link['max_downloads'])return 'used';
		if($link['locked_until'] && strtotime((string)$link['locked_until']) > time())return 'locked';

		return 'ok';

	}

	/**
	 * one download counted when the link still allows it (conditional update: two visitors never pass the max together)
	 *
	 * @param int $id
	 *
	 * @return bool false when the link is no longer valid
	 */
	public static function linkCount(int $id):bool
	{

		$now = now();
		return DB()->query("UPDATE xcore_drive_link SET downloads = downloads + 1, last_download_at = :now
							WHERE id = :id AND deleted = 'no' AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > :now2) AND (max_downloads = 0 OR downloads < max_downloads)",
							['id' => $id, 'now' => $now, 'now2' => $now])->rowCount() > 0;

	}

	/**
	 * new random token of a link
	 *
	 * @return string 32 hex
	 */
	public static function linkToken():string
	{

		do
		{

			$token = bin2hex(random_bytes(16));

		}
		while(DB()->query("SELECT id FROM xcore_drive_link WHERE token = :token", ['token' => $token])->fetchOne());

		return $token;

	}

	/**
	 * public address of a link
	 *
	 * @param string $token
	 *
	 * @return string
	 */
	public static function linkUrl(string $token):string
	{

		return rtrim((string)\Core\Config::get('url'), '/')."/s/{$token}/";

	}

}
