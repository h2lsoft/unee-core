<?php

namespace Plugin\Core_Backend;

use Symfony\Component\Routing\Attribute\Route;

/**
 * notifications of the logged user (bell of the header, \Core\Notification::send())
 * the list is polled every minute: it does not count as an activity (auth.api middleware)
 */
#[Route('/api/notification', name: 'api.notification.')]
class NotificationController extends \Core\Controller
{

	// notifications listed in the bell
	private const int LIST_LIMIT = 20;

	/**
	 * unread count and last notifications
	 *
	 * @return \Core\JsonResponse {"unread", "items": [{"id", "level", "title", "message", "link", "date", "read"}]}
	 */
	#[Route('/', name: 'list', methods: ['GET'])]
	public function list():\Core\JsonResponse
	{

		$user_id = \Model\User::getUID();
		$unread = (int)DB()->query("SELECT COUNT(*) FROM xcore_notification WHERE deleted = 'no' AND xcore_user_id = :id AND read_at IS NULL", ['id' => $user_id])->fetchOne();

		$items = [];
		foreach(DB()->query("SELECT id, level, title, message, link, created_at, read_at FROM xcore_notification WHERE deleted = 'no' AND xcore_user_id = :id ORDER BY id DESC LIMIT ".self::LIST_LIMIT, ['id' => $user_id])->fetchAll() as $rec)
		{

			$items[] = ['id' => (int)$rec['id'], 'level' => $rec['level'], 'title' => $rec['title'], 'message' => (string)$rec['message'], 'link' => (string)$rec['link'], 'date' => $rec['created_at'], 'read' => $rec['read_at'] !== null];

		}

		return new \Core\JsonResponse(['unread' => $unread, 'items' => $items]);

	}

	/**
	 * marks a notification of the logged user as read
	 *
	 * @param int $id
	 *
	 * @return \Core\JsonResponse {"unread"}
	 */
	#[Route('/{id}/read/', name: 'read', methods: ['POST'], requirements: ['id' => '\d+'])]
	public function read(int $id):\Core\JsonResponse
	{

		DB()->query("UPDATE xcore_notification SET read_at = NOW() WHERE id = :id AND xcore_user_id = :user_id AND read_at IS NULL", ['id' => $id, 'user_id' => \Model\User::getUID()]);
		return $this->unread();

	}

	/**
	 * marks every notification of the logged user as read
	 *
	 * @return \Core\JsonResponse {"unread"}
	 */
	#[Route('/read-all/', name: 'read.all', methods: ['POST'])]
	public function readAll():\Core\JsonResponse
	{

		DB()->query("UPDATE xcore_notification SET read_at = NOW() WHERE xcore_user_id = :user_id AND read_at IS NULL", ['user_id' => \Model\User::getUID()]);
		return $this->unread();

	}

	private function unread():\Core\JsonResponse
	{

		return new \Core\JsonResponse(['unread' => (int)DB()->query("SELECT COUNT(*) FROM xcore_notification WHERE deleted = 'no' AND xcore_user_id = :id AND read_at IS NULL", ['id' => \Model\User::getUID()])->fetchOne()]);

	}

}
