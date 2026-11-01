<?php

namespace Core;

/**
 * in-app notifications (xcore_notification), shown by the bell of the backend header
 * levels: info, success, warning, error ; link: path of the backend, ex: cron/ (opened on click)
 */
class Notification
{

	public const array LEVELS = ['info', 'success', 'warning', 'error'];

	/**
	 * notifies a user
	 *
	 * @param int $user_id
	 * @param string $title english, translated by the caller (ts) when needed
	 * @param string $message
	 * @param string $link path of the backend, ex: cron/
	 * @param string $level info, success, warning, error
	 *
	 * @return int id of the notification, 0 when the user is unknown
	 */
	public static function send(int $user_id, string $title, string $message='', string $link='', string $level='info'):int
	{

		if($user_id <= 0)return 0;

		return (int)DB('xcore_notification')->insert([
			'xcore_user_id' => $user_id,
			'level' => in_array($level, self::LEVELS, true) ? $level : 'info',
			'title' => mb_substr($title, 0, 255),
			'message' => $message,
			'link' => mb_substr(ltrim($link, '/'), 0, 255),
		], (string)(Session::get('auth.login') ?: 'system'));

	}

	/**
	 * notifies every active user of a group, ex: \Model\User::GROUP_SUPERADMIN
	 *
	 * @param int $group_id
	 * @param string $title
	 * @param string $message
	 * @param string $link
	 * @param string $level
	 *
	 * @return int notifications sent
	 */
	public static function sendGroup(int $group_id, string $title, string $message='', string $link='', string $level='info'):int
	{

		$ids = DB()->query("SELECT id FROM xcore_user WHERE xcore_group_id = :group_id AND active = 'yes' AND deleted = 'no'", ['group_id' => $group_id])->fetchAllOne();
		foreach($ids as $id)
			self::send((int)$id, $title, $message, $link, $level);

		return count($ids);

	}

}
