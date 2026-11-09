<?php

namespace Core;

/**
 * announcements (xcore_announce): html message shown once in a lightbox to the logged users of the backend, the frontend or both
 * live = active, started and not ended; xcore_group_ids empty = every logged user
 * "I understood" writes one row per user in xcore_announce_read (kept when the logs are purged) and logs it (action announce_read)
 * a read announcement is never shown again to this user, even when it changes
 */
class Announce
{

	public const array APPLICATIONS = ['backend', 'frontend'];

	// state computed from the dates, also the sql of the chips of the module
	public const string STATE_SQL = "CASE WHEN start_at > NOW() THEN 'scheduled' WHEN end_at IS NOT NULL AND end_at <= NOW() THEN 'ended' ELSE 'live' END";

	/**
	 * live announcements not read yet by a user, last started first
	 *
	 * @param int $user_id
	 * @param int $group_id group of the user
	 * @param string $application backend or frontend
	 *
	 * @return array [{"id", "title", "content", "link_label", "link_url", "date"}], content cleaned (basic html), date = start
	 */
	public static function pending(int $user_id, int $group_id, string $application='backend'):array
	{

		if(!in_array($application, self::APPLICATIONS, true))return [];

		$recs = DB()->query("SELECT a.id, a.title, a.content, a.link_label, a.link_url, a.start_at, a.xcore_group_ids
							 FROM xcore_announce a
							 WHERE a.deleted = 'no' AND a.active = 'yes' AND a.target IN (:application, 'both')
							 AND (a.start_at IS NULL OR a.start_at <= NOW()) AND (a.end_at IS NULL OR a.end_at > NOW())
							 AND NOT EXISTS (SELECT 1 FROM xcore_announce_read r WHERE r.xcore_announce_id = a.id AND r.xcore_user_id = :user_id)
							 ORDER BY a.start_at DESC, a.id DESC", ['application' => $application, 'user_id' => $user_id])->fetchAll();

		$list = [];
		foreach($recs as $rec)
		{

			$groups = json_decode((string)$rec['xcore_group_ids'], true);
			if(is_array($groups) && $groups && !in_array($group_id, array_map('intval', $groups), true))continue;

			$list[] = [
				'id' => (int)$rec['id'],
				'title' => (string)$rec['title'],
				'content' => Sanitizer::clean((string)$rec['content'], 'basic'),
				'link_label' => (string)$rec['link_label'],
				'link_url' => (string)$rec['link_url'],
				'date' => (string)$rec['start_at'],
			];

		}

		return $list;

	}

	/**
	 * "I understood": the announcement is read by the user (once, logged), only when it is pending for him
	 *
	 * @param int $user_id
	 * @param int $group_id
	 * @param int $announce_id
	 * @param string $application backend or frontend
	 *
	 * @return bool false when the announcement is not pending (unknown, ended, other group or already read)
	 */
	public static function read(int $user_id, int $group_id, int $announce_id, string $application='backend'):bool
	{

		$found = array_values(array_filter(self::pending($user_id, $group_id, $application), fn($a) => $a['id'] === $announce_id));
		if(!$found)return false;

		try
		{

			DB('xcore_announce_read')->insert([
				'xcore_announce_id' => $announce_id,
				'xcore_user_id' => $user_id,
				'application' => $application,
				'read_at' => date('Y-m-d H:i:s'),
				'ip' => mb_substr(getVisitorIp(), 0, 45),
				'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
			], (string)(Session::get('auth.login') ?: 'system'));

		}
		catch(\PDOException $e)
		{

			// double click: the unique key announce + user already holds the read
			if($e->getCode() === '23000')return false;
			throw $e;

		}

		Log::write('announce_read', $found[0]['title'], [], $announce_id, 'info', 'announce', '', $application);
		return true;

	}

}
