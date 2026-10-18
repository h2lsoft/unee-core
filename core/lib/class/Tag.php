<?php

namespace Core;

/**
 * tags of the records (xcore_tag): signature = table of the record, one row per tag
 */
class Tag
{

	/**
	 * a tag: 1 to 64 characters, no comma, pipe or angle bracket (the grids join the tags with a pipe)
	 */
	public const string PATTERN = '/^[^,|<>]{1,64}$/u';

	/**
	 * tags of a record
	 *
	 * @param string $signature
	 * @param int $record_id
	 *
	 * @return array sorted
	 */
	public static function get(string $signature, int $record_id):array
	{

		return DB()->query("SELECT tag FROM xcore_tag WHERE deleted = 'no' AND signature = :signature AND record_id = :id ORDER BY tag", ['signature' => $signature, 'id' => $record_id])->fetchAllOne();

	}

	/**
	 * replace the tags of a record (removed ones soft deleted, new ones added)
	 *
	 * @param string $signature
	 * @param int $record_id
	 * @param array $tags
	 * @param string $author
	 *
	 * @return void
	 */
	public static function set(string $signature, int $record_id, array $tags, string $author):void
	{

		$tags = array_values(array_unique(array_filter(array_map(fn($t) => trim((string)$t), $tags), fn($t) => preg_match(self::PATTERN, $t))));
		$current = self::get($signature, $record_id);

		$removed = array_diff($current, $tags);
		if($removed)
		{

			$binds = ['signature' => $signature, 'id' => $record_id, 'author' => $author];
			$in = [];
			foreach(array_values($removed) as $i => $tag)
			{

				$in[] = ":t{$i}";
				$binds["t{$i}"] = $tag;

			}

			DB()->query("UPDATE xcore_tag SET deleted = 'yes', deleted_at = NOW(), deleted_by = :author WHERE deleted = 'no' AND signature = :signature AND record_id = :id AND tag IN(".implode(', ', $in).")", $binds);

		}

		foreach(array_diff($tags, $current) as $tag)
			DB('xcore_tag')->insert(['signature' => $signature, 'record_id' => $record_id, 'tag' => $tag], $author);

	}

	/**
	 * tags already used in a table, for the suggestions
	 *
	 * @param string $signature
	 *
	 * @return array
	 */
	public static function suggestions(string $signature):array
	{

		return DB()->query("SELECT DISTINCT tag FROM xcore_tag WHERE deleted = 'no' AND signature = :signature ORDER BY tag LIMIT 200", ['signature' => $signature])->fetchAllOne();

	}

	/**
	 * sql of the tags of each row of a grid, joined with a pipe
	 *
	 * @param string $table
	 *
	 * @return string
	 */
	public static function sql(string $table):string
	{

		if(!preg_match('/^[a-z0-9_]+$/', $table))throw new \InvalidArgumentException("invalid table `{$table}`");
		return "(SELECT GROUP_CONCAT(t.tag ORDER BY t.tag SEPARATOR '|') FROM xcore_tag t WHERE t.deleted = 'no' AND t.signature = '{$table}' AND t.record_id = `{$table}`.id)";

	}

}
