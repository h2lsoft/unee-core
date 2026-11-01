<?php

namespace Core;

/**
 * in-app notifications: one message (xcore_notification) and one row per recipient (xcore_notification_recipient, read_at)
 * shown by the bell of the backend header, each read is logged (action notification_read, record_id = the notification)
 * levels: info, success, warning, error ; link: path of the backend, ex: cron/ (opened on click)
 */
class Notification
{

	public const array LEVELS = ['info', 'success', 'warning', 'error'];

	/**
	 * notifies one or several users
	 *
	 * @param int|array $user_ids
	 * @param string $title english, translated by the caller (ts) when needed
	 * @param string $message
	 * @param string $link path of the backend, ex: cron/
	 * @param string $level info, success, warning, error
	 * @param string $category free text, ex: Cron
	 *
	 * @return int id of the notification, 0 when no active recipient
	 */
	public static function send(int|array $user_ids, string $title, string $message='', string $link='', string $level='info', string $category=''):int
	{

		return self::create('user', (array)$user_ids, $title, $message, $link, $level, $category);

	}

	/**
	 * notifies the active users of one or several groups, ex: \Model\User::GROUP_SUPERADMIN
	 *
	 * @param int|array $group_ids
	 * @param string $title
	 * @param string $message
	 * @param string $link
	 * @param string $level
	 * @param string $category
	 *
	 * @return int id of the notification, 0 when no active recipient
	 */
	public static function sendGroup(int|array $group_ids, string $title, string $message='', string $link='', string $level='info', string $category=''):int
	{

		return self::create('group', (array)$group_ids, $title, $message, $link, $level, $category);

	}

	/**
	 * active users of groups or among users, system group excluded, each user once
	 *
	 * @param string $type group or user
	 * @param array $ids
	 *
	 * @return int[]
	 */
	public static function recipients(string $type, array $ids):array
	{

		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if(!$ids)return [];

		$in = implode(',', $ids);
		$column = ($type === 'group') ? 'xcore_group_id' : 'id';
		return array_map('intval', DB()->query("SELECT id FROM xcore_user WHERE {$column} IN ({$in}) AND xcore_group_id <> :system AND active = 'yes' AND deleted = 'no' ORDER BY id", ['system' => \Model\User::GROUP_SYSTEM])->fetchAllOne());

	}

	/**
	 * rows of the recipients of a notification
	 *
	 * @param int $notification_id
	 * @param int[] $user_ids
	 *
	 * @return void
	 */
	public static function addRecipients(int $notification_id, array $user_ids):void
	{

		$author = self::author();
		foreach($user_ids as $user_id)
			DB('xcore_notification_recipient')->insert(['xcore_notification_id' => $notification_id, 'xcore_user_id' => (int)$user_id], $author);

	}

	/**
	 * marks notifications of a user as read and logs each read
	 *
	 * @param int $user_id
	 * @param int $notification_id 0 = every unread notification of the user
	 *
	 * @return void
	 */
	public static function read(int $user_id, int $notification_id=0):void
	{

		$where = "r.xcore_user_id = :user_id AND r.read_at IS NULL AND r.deleted = 'no'".(($notification_id > 0) ? ' AND r.xcore_notification_id = :id' : '');
		$binds = ['user_id' => $user_id] + (($notification_id > 0) ? ['id' => $notification_id] : []);
		$unread = DB()->query("SELECT r.id, r.xcore_notification_id, n.title FROM xcore_notification_recipient r INNER JOIN xcore_notification n ON n.id = r.xcore_notification_id WHERE {$where}", $binds)->fetchAll();

		foreach($unread as $rec)
		{

			DB()->query("UPDATE xcore_notification_recipient SET read_at = NOW() WHERE id = :id", ['id' => $rec['id']]);
			Log::write('notification_read', (string)$rec['title'], [], (int)$rec['xcore_notification_id'], 'info', 'notification');

		}

	}

	private static function create(string $type, array $ids, string $title, string $message, string $link, string $level, string $category):int
	{

		$recipients = self::recipients($type, $ids);
		if(!$recipients)return 0;

		$id = (int)DB('xcore_notification')->insert([
			'category' => mb_substr($category, 0, 100),
			'level' => in_array($level, self::LEVELS, true) ? $level : 'info',
			'title' => mb_substr($title, 0, 255),
			'message' => $message,
			'link' => mb_substr(ltrim($link, '/'), 0, 255),
			'target_type' => $type,
			'target_ids' => json_encode(array_values(array_map('intval', $ids))),
		], self::author());

		self::addRecipients($id, $recipients);
		return $id;

	}

	private static function author():string
	{

		return (string)(Session::get('auth.login') ?: 'system');

	}

}
