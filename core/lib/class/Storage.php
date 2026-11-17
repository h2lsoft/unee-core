<?php

namespace Core;

/**
 * storage used by the instance and its quota (config storage/quota, MB, 0 = unlimited)
 * files: counter of xcore_storage moved by add() on each write and delete, measured again by recount() (files of upload_dir and private_dir, tmp folders left out)
 * database: data + indexes of the schema of the instance (information_schema), measured by recount() only
 */
class Storage
{

	// percent of the quota from which the bar turns orange
	public const int WARNING_PERCENT = 80;

	/**
	 * quota of the instance in bytes
	 *
	 * @return int 0 = unlimited
	 */
	public static function quota():int
	{

		return max(0, (int)\Core\Config::get('storage/quota', 0)) * 1048576;

	}

	/**
	 * storage used, measured the first time it is read
	 *
	 * @return array ['quota', 'files', 'database', 'used', 'percent', 'full'] (bytes, percent of the quota, 0 when unlimited)
	 */
	public static function usage():array
	{

		$rows = DB()->query("SELECT scope, bytes, measured_at FROM xcore_storage")->fetchAll();
		$scopes = array_column($rows, null, 'scope');

		// never measured: first full measure now
		if(!isset($scopes['files']['measured_at']) || $scopes['files']['measured_at'] === null)
		{

			self::recount();
			$scopes = array_column(DB()->query("SELECT scope, bytes, measured_at FROM xcore_storage")->fetchAll(), null, 'scope');

		}

		$files = max(0, (int)($scopes['files']['bytes'] ?? 0));
		$database = max(0, (int)($scopes['database']['bytes'] ?? 0));
		$quota = self::quota();
		$used = $files + $database;

		return [
			'quota' => $quota,
			'files' => $files,
			'database' => $database,
			'used' => $used,
			'percent' => ($quota > 0) ? min(100, (int)round($used * 100 / $quota)) : 0,
			'full' => ($quota > 0 && $used >= $quota),
		];

	}

	/**
	 * can a file of this size be written by a user (uploads): reading, deleting and the files written by the system are never refused
	 *
	 * @param int $bytes
	 *
	 * @return bool
	 */
	public static function canStore(int $bytes):bool
	{

		$quota = self::quota();
		if($quota === 0)return true;

		return (self::usage()['used'] + max(0, $bytes) <= $quota);

	}

	/**
	 * moves the files counter after a write (positive) or a delete (negative)
	 *
	 * @param int $bytes
	 *
	 * @return void
	 */
	public static function add(int $bytes):void
	{

		if($bytes === 0)return;

		// one statement: safe against a concurrent upload without transaction
		DB()->query("UPDATE xcore_storage SET bytes = GREATEST(0, CAST(bytes AS SIGNED) + :bytes), updated_at = :now WHERE scope = 'files'", ['bytes' => $bytes, 'now' => now()]);

	}

	/**
	 * full measure of the files and of the database (nightly cron task, first read)
	 *
	 * @return array ['files', 'database'] bytes
	 */
	public static function recount():array
	{

		$files = 0;
		$skip = [rtrim(str_replace('\\', '/', (string)\Core\Config::get('tmp_dir')), '/')];

		foreach(array_unique([(string)\Core\Config::get('upload_dir'), (string)\Core\Config::get('private_dir')]) as $root)
		{

			$root = rtrim(str_replace('\\', '/', $root), '/');
			if($root === '' || !is_dir($root))continue;

			$skip[] = "{$root}/tmp";
			$files += self::dirSize($root, $skip);

		}

		$database = (int)DB()->query("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetchOne();

		$now = now();
		DB()->query("UPDATE xcore_storage SET bytes = :bytes, measured_at = :now, updated_at = :now2 WHERE scope = 'files'", ['bytes' => $files, 'now' => $now, 'now2' => $now]);
		DB()->query("UPDATE xcore_storage SET bytes = :bytes, measured_at = :now, updated_at = :now2 WHERE scope = 'database'", ['bytes' => $database, 'now' => $now, 'now2' => $now]);

		return ['files' => $files, 'database' => $database];

	}

	/**
	 * size of the files of a folder and of its subfolders, symbolic links never followed
	 *
	 * @param string $dir
	 * @param array $skip folders left out (absolute, with /)
	 *
	 * @return int bytes
	 */
	private static function dirSize(string $dir, array $skip):int
	{

		$size = 0;
		foreach(scandir($dir) ?: [] as $entry)
		{

			if($entry === '.' || $entry === '..')continue;

			$path = "{$dir}/{$entry}";
			if(is_link($path))continue;

			if(is_dir($path))
			{

				if(!in_array($path, $skip, true))$size += self::dirSize($path, $skip);

			}
			elseif(is_file($path))
			{

				$size += (int)filesize($path);

			}

		}

		return $size;

	}

}
