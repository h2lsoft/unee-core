<?php

namespace Plugin\Core;

use Symfony\Component\Routing\Attribute\Route;

/**
 * notifications of the logged user (bell of the header, \Core\Notification), every user, no right needed
 * the module Notifications (core/notification) lists and sends them for the admins
 * the list is polled every minute: it does not count as an activity (auth.api middleware)
 */
#[Route('/api/notification-bell', name: 'api.notification_bell.')]
class Notification_BellController extends \Core\Controller
{

	// notifications listed in the bell
	private const int LIST_LIMIT = 20;

	/**
	 * unread count and last notifications
	 *
	 * @return \Core\JsonResponse {"unread", "items": [{"id", "level", "category", "title", "message", "link", "date", "read"}]}
	 */
	#[Route('/', name: 'list', methods: ['GET'])]
	public function list():\Core\JsonResponse
	{

		$items = [];
		foreach(DB()->query("SELECT n.id, n.level, n.category, n.title, n.message, n.link, n.created_at, r.read_at
							 FROM xcore_notification_recipient r
							 INNER JOIN xcore_notification n ON n.id = r.xcore_notification_id AND n.deleted = 'no'
							 WHERE r.deleted = 'no' AND r.xcore_user_id = :id
							 ORDER BY n.id DESC LIMIT ".self::LIST_LIMIT, ['id' => \Model\User::getUID()])->fetchAll() as $rec)
		{

			$items[] = ['id' => (int)$rec['id'], 'level' => $rec['level'], 'category' => $rec['category'], 'title' => $rec['title'], 'message' => (string)$rec['message'], 'link' => (string)$rec['link'], 'date' => $rec['created_at'], 'read' => $rec['read_at'] !== null];

		}

		return new \Core\JsonResponse(['unread' => $this->unreadCount(), 'items' => $items]);

	}

	/**
	 * marks a notification of the logged user as read (logged)
	 *
	 * @param int $id notification
	 *
	 * @return \Core\JsonResponse {"unread"}
	 */
	#[Route('/{id}/read/', name: 'read', methods: ['POST'], requirements: ['id' => '\d+'])]
	public function read(int $id):\Core\JsonResponse
	{

		\Core\Notification::read(\Model\User::getUID(), $id);
		return new \Core\JsonResponse(['unread' => $this->unreadCount()]);

	}

	/**
	 * marks every notification of the logged user as read (each read logged)
	 *
	 * @return \Core\JsonResponse {"unread"}
	 */
	#[Route('/read-all/', name: 'read.all', methods: ['POST'])]
	public function readAll():\Core\JsonResponse
	{

		\Core\Notification::read(\Model\User::getUID());
		return new \Core\JsonResponse(['unread' => $this->unreadCount()]);

	}

	private function unreadCount():int
	{

		return (int)DB()->query("SELECT COUNT(*) FROM xcore_notification_recipient r INNER JOIN xcore_notification n ON n.id = r.xcore_notification_id AND n.deleted = 'no' WHERE r.deleted = 'no' AND r.xcore_user_id = :id AND r.read_at IS NULL", ['id' => \Model\User::getUID()])->fetchOne();

	}

}
