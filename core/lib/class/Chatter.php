<?php

namespace Core;

/**
 * chatter under the records of a module (xcore_plugin.chatter = yes), used by the routes of \Core\CrudController
 * messages (to the followers, also by email when asked) and internal notes (never sent by email), attachments in private_dir/chatter,
 * followers (author of a message, mentioned users, added by hand) notified in-app, activities stored in xcore_user_task (plugin + record_id)
 * a message is never modified, only the superadmin deletes one (logged)
 */
class Chatter
{

	// kinds of activity, english labels translated
	public const array ACTIVITIES = ['call' => 'Call', 'email' => 'Email', 'meeting' => 'Meeting', 'todo' => 'To do', 'document' => 'Document'];

	// length of a message at most (html)
	public const int MAX_LENGTH = 20000;

	/**
	 * the chatter is on for the plugin
	 *
	 * @param string $plugin route_prefix_name
	 *
	 * @return bool
	 */
	public static function enabled(string $plugin):bool
	{

		$p = \Model\Plugin::findActive($plugin);
		return ($p && ($p['chatter'] ?? 'no') === 'yes');

	}

	/**
	 * thread of a record, oldest first: messages, notes and lines of the activities done, mixed with the events given (versions, workflow)
	 *
	 * @param string $plugin
	 * @param int $record_id
	 * @param array $events [{"date", "user", "text"}] grey lines
	 *
	 * @return array [{"kind": "message|note|activity|event", "id", "date", "user", "avatar", "mine", "body", "email", "attachments"}]
	 */
	public static function thread(string $plugin, int $record_id, array $events=[]):array
	{

		$recs = DB()->query("SELECT m.id, m.type, m.body, m.email, m.created_at, m.xcore_user_id, u.firstname, u.lastname, u.login, u.avatar
							 FROM xcore_chatter_message m
							 LEFT JOIN xcore_user u ON u.id = m.xcore_user_id
							 WHERE m.plugin = :plugin AND m.record_id = :record_id AND m.deleted = 'no'
							 ORDER BY m.id", ['plugin' => $plugin, 'record_id' => $record_id])->fetchAll();

		$files = [];
		if($recs)
		{

			$ids = implode(',', array_map(fn($r) => (int)$r['id'], $recs));
			foreach(DB()->query("SELECT id, xcore_chatter_message_id, name, size FROM xcore_chatter_attachment WHERE deleted = 'no' AND xcore_chatter_message_id IN ({$ids}) ORDER BY id")->fetchAll() as $f)
				$files[(int)$f['xcore_chatter_message_id']][] = ['id' => (int)$f['id'], 'name' => $f['name'], 'size' => (int)$f['size']];

		}

		$me = \Model\User::getUID();
		$items = [];
		foreach($recs as $rec)
		{

			$user = (int)$rec['xcore_user_id'];
			$cell = Widget::avatar(['id' => $user] + $rec);
			$items[] = [
				'kind' => $rec['type'],
				'id' => (int)$rec['id'],
				'user_id' => $user,
				'date' => date(DATE_ATOM, strtotime((string)$rec['created_at'])),
				'user' => ($cell['title'] !== '') ? $cell['title'] : ((string)$rec['login'] ?: ts('System')),
				'avatar' => ($user) ? $cell['avatar'] : null,
				'mine' => ($user === $me),
				'body' => (string)$rec['body'],
				'email' => ($rec['email'] === 'yes'),
				'attachments' => $files[(int)$rec['id']] ?? [],
			];

		}

		foreach($events as $event)
			$items[] = ['kind' => 'event', 'id' => 0, 'date' => date(DATE_ATOM, strtotime((string)$event['date'])), 'user' => (string)$event['user'], 'text' => (string)$event['text']];

		// stable sort by date: a message and an event of the same second keep their order
		$order = array_keys($items);
		array_multisort(array_column($items, 'date'), SORT_ASC, $order, SORT_ASC, $items);

		return $items;

	}

	/**
	 * followers of a record with their avatar
	 *
	 * @param string $plugin
	 * @param int $record_id
	 *
	 * @return array [{"id", "name", "avatar"}]
	 */
	public static function followers(string $plugin, int $record_id):array
	{

		$list = [];
		foreach(DB()->query("SELECT u.id, u.firstname, u.lastname, u.login, u.avatar FROM xcore_chatter_follower f INNER JOIN xcore_user u ON u.id = f.xcore_user_id AND u.deleted = 'no'
							 WHERE f.plugin = :plugin AND f.record_id = :record_id AND f.deleted = 'no' ORDER BY u.firstname, u.lastname", ['plugin' => $plugin, 'record_id' => $record_id])->fetchAll() as $rec)
		{

			$cell = Widget::avatar($rec);
			$list[] = ['id' => (int)$rec['id'], 'name' => ($cell['title'] !== '') ? $cell['title'] : (string)$rec['login'], 'avatar' => $cell['avatar']];

		}

		return $list;

	}

	/**
	 * users who opened the thread of a record, with the last time (double check "read" of a message posted before)
	 *
	 * @param string $plugin
	 * @param int $record_id
	 *
	 * @return array [{"id", "name", "read_at"}]
	 */
	public static function reads(string $plugin, int $record_id):array
	{

		$list = [];
		foreach(DB()->query("SELECT u.id, u.firstname, u.lastname, u.login, u.avatar, r.read_at FROM xcore_chatter_read r INNER JOIN xcore_user u ON u.id = r.xcore_user_id AND u.deleted = 'no'
							 WHERE r.plugin = :plugin AND r.record_id = :record_id AND r.deleted = 'no' ORDER BY r.read_at", ['plugin' => $plugin, 'record_id' => $record_id])->fetchAll() as $rec)
		{

			$cell = Widget::avatar($rec);
			$list[] = ['id' => (int)$rec['id'], 'name' => ($cell['title'] !== '') ? $cell['title'] : (string)$rec['login'], 'read_at' => date(DATE_ATOM, strtotime((string)$rec['read_at']))];

		}

		return $list;

	}

	/**
	 * the user read the thread of a record now (one row per user and record, also safe on MyISAM)
	 *
	 * @param string $plugin
	 * @param int $record_id
	 * @param int $user_id
	 *
	 * @return void
	 */
	public static function read(string $plugin, int $record_id, int $user_id):void
	{

		if($user_id <= 0)return;

		$now = date('Y-m-d H:i:s');
		$author = (string)(Session::get('auth.login') ?: 'system');
		DB()->query("INSERT INTO xcore_chatter_read (plugin, record_id, xcore_user_id, read_at, deleted, created_at, created_by) VALUES (:plugin, :record_id, :user_id, :now, 'no', :created, :author)
					 ON DUPLICATE KEY UPDATE read_at = VALUES(read_at), updated_at = VALUES(read_at), updated_by = VALUES(created_by)", ['plugin' => $plugin, 'record_id' => $record_id, 'user_id' => $user_id, 'now' => $now, 'created' => $now, 'author' => $author]);

	}

	/**
	 * messages, notes and activities done by the others, posted after the last reading of the user
	 *
	 * @param string $plugin
	 * @param int $record_id
	 * @param int $user_id
	 *
	 * @return int
	 */
	public static function unread(string $plugin, int $record_id, int $user_id):int
	{

		return (int)DB()->query("SELECT COUNT(*) FROM xcore_chatter_message m
								 LEFT JOIN xcore_chatter_read r ON r.plugin = m.plugin AND r.record_id = m.record_id AND r.xcore_user_id = :user_id AND r.deleted = 'no'
								 WHERE m.plugin = :plugin AND m.record_id = :record_id AND m.deleted = 'no' AND m.xcore_user_id <> :author_id AND (r.read_at IS NULL OR m.created_at > r.read_at)",
								 ['plugin' => $plugin, 'record_id' => $record_id, 'user_id' => $user_id, 'author_id' => $user_id])->fetchOne();

	}

	/**
	 * active users that can follow or be mentioned (system account excluded)
	 *
	 * @return array [{"id", "name", "avatar"}]
	 */
	public static function users():array
	{

		$list = [];
		foreach(DB()->query("SELECT id, firstname, lastname, login, avatar FROM xcore_user WHERE deleted = 'no' AND active = 'yes' AND id > 1 ORDER BY firstname, lastname")->fetchAll() as $rec)
		{

			$cell = Widget::avatar($rec);
			$list[] = ['id' => (int)$rec['id'], 'name' => ($cell['title'] !== '') ? $cell['title'] : (string)$rec['login'], 'avatar' => $cell['avatar']];

		}

		return $list;

	}

	/**
	 * adds a follower (an active user, once)
	 *
	 * @param string $plugin
	 * @param int $record_id
	 * @param int $user_id
	 *
	 * @return bool false when the user cannot follow
	 */
	public static function follow(string $plugin, int $record_id, int $user_id):bool
	{

		if($user_id <= 1 || !DB()->query("SELECT id FROM xcore_user WHERE id = :id AND deleted = 'no' AND active = 'yes'", ['id' => $user_id])->fetchOne())return false;
		if(self::isFollower($plugin, $record_id, $user_id))return true;

		DB('xcore_chatter_follower')->insert(['plugin' => $plugin, 'record_id' => $record_id, 'xcore_user_id' => $user_id], self::author());
		return true;

	}

	public static function unfollow(string $plugin, int $record_id, int $user_id):void
	{

		DB()->query("UPDATE xcore_chatter_follower SET deleted = 'yes', deleted_at = NOW(), deleted_by = :author WHERE plugin = :plugin AND record_id = :record_id AND xcore_user_id = :user AND deleted = 'no'", ['author' => self::author(), 'plugin' => $plugin, 'record_id' => $record_id, 'user' => $user_id]);

	}

	public static function isFollower(string $plugin, int $record_id, int $user_id):bool
	{

		return (bool)DB()->query("SELECT id FROM xcore_chatter_follower WHERE plugin = :plugin AND record_id = :record_id AND xcore_user_id = :user AND deleted = 'no'", ['plugin' => $plugin, 'record_id' => $record_id, 'user' => $user_id])->fetchOne();

	}

	/**
	 * new message or note of the logged user: html cleaned (profile basic), uploads attached, the author and the mentioned users follow the record
	 * message: followers notified in-app, by email too when asked (email credits); note: the mentioned users only, never by email
	 *
	 * @param string $plugin
	 * @param int $record_id
	 * @param string $type message, note
	 * @param string $body html
	 * @param bool $email also sent by email (message only)
	 * @param array $uploads upload:<token> of POST chatter/upload/
	 * @param array $mentions user ids
	 * @param string $record label of the record, ex: Ticket #12
	 *
	 * @return int|string id of the message, or the error (translated)
	 */
	public static function post(string $plugin, int $record_id, string $type, string $body, bool $email, array $uploads, array $mentions, string $record):int|string
	{

		if(!in_array($type, ['message', 'note'], true))return ts('invalid type');
		if(mb_strlen($body) > self::MAX_LENGTH)return ts('the message is too long');

		$body = trim($body);
		if($body !== '')$body = Sanitizer::clean($body, 'basic');
		$text = self::text($body);

		$files = [];
		foreach(array_slice(array_unique(array_map('strval', $uploads)), 0, 10) as $token)
		{

			if(!$upload = File::uploadGet($token))return ts('the uploaded file expired, upload it again');
			$files[] = ['token' => $token, 'name' => $upload['name'], 'size' => $upload['size']];

		}

		if($text === '' && !$files)return ts('write a message or attach a file');

		$me = \Model\User::getUID();
		$id = (int)DB('xcore_chatter_message')->insert(['plugin' => $plugin, 'record_id' => $record_id, 'type' => $type, 'xcore_user_id' => $me, 'body' => $body, 'email' => ($email && $type === 'message') ? 'yes' : 'no'], self::author());

		foreach($files as $f)
		{

			$path = File::uploadMove($f['token'], "chatter/{$plugin}");
			if($path !== '')DB('xcore_chatter_attachment')->insert(['xcore_chatter_message_id' => $id, 'name' => $f['name'], 'path' => $path, 'size' => $f['size']], self::author());

		}

		self::follow($plugin, $record_id, $me);

		// mentioned users follow the record and are notified (a note too)
		$mentioned = [];
		foreach(array_unique(array_map('intval', $mentions)) as $user_id)
			if($user_id !== $me && self::follow($plugin, $record_id, $user_id))$mentioned[] = $user_id;

		$author = self::userName($me);
		$link = self::link($plugin, $record_id);
		$excerpt = mb_strimwidth($text, 0, 200, '...');
		if($mentioned)Notification::send($mentioned, ts('[AUTHOR] mentioned you on [RECORD]', ['AUTHOR' => $author, 'RECORD' => $record]), $excerpt, $link, 'info', 'chatter');

		if($type === 'message')
		{

			$followers = array_values(array_diff(array_column(self::followers($plugin, $record_id), 'id'), [$me], $mentioned));
			if($followers)Notification::send($followers, ts('[AUTHOR] wrote on [RECORD]', ['AUTHOR' => $author, 'RECORD' => $record]), $excerpt, $link, 'info', 'chatter');

			// email to every follower but the author (template core.chatter.message, email credits)
			if($email)
			{

				$recipients = array_values(array_diff(array_column(self::followers($plugin, $record_id), 'id'), [$me]));
				foreach(self::mailUsers($recipients) as $user)
					Mailer::sendTemplate('core.chatter.message', $user['email'], ['firstname' => $user['firstname'], 'author' => $author, 'record' => $record, 'message' => $text, 'url' => self::url($link)], (string)($user['language'] ?? ''), [], ['category' => 'transactional', 'module' => $plugin, 'action' => 'chatter', 'record_id' => $record_id, 'xcore_user_id' => (int)$user['id']]);

			}

		}

		Log::write('chatter', $type, ['message' => $id, 'attachments' => count($files), 'email' => $email], $record_id, 'info', $plugin);

		return $id;

	}

	/**
	 * attachment of a record (path relative to private_dir)
	 *
	 * @param string $plugin
	 * @param int $record_id
	 * @param int $attachment_id
	 *
	 * @return array|null ['name', 'path']
	 */
	public static function attachment(string $plugin, int $record_id, int $attachment_id):?array
	{

		$rec = DB()->query("SELECT a.name, a.path FROM xcore_chatter_attachment a INNER JOIN xcore_chatter_message m ON m.id = a.xcore_chatter_message_id AND m.deleted = 'no'
							WHERE a.id = :id AND a.deleted = 'no' AND m.plugin = :plugin AND m.record_id = :record_id", ['id' => $attachment_id, 'plugin' => $plugin, 'record_id' => $record_id])->fetch();

		return ($rec) ? $rec : null;

	}

	/**
	 * deletes a message and its files (superadmin only, checked by the route), logged with its text
	 *
	 * @param string $plugin
	 * @param int $record_id
	 * @param int $message_id
	 *
	 * @return bool false when not found
	 */
	public static function remove(string $plugin, int $record_id, int $message_id):bool
	{

		$rec = DB()->query("SELECT id, type, body FROM xcore_chatter_message WHERE id = :id AND plugin = :plugin AND record_id = :record_id AND deleted = 'no'", ['id' => $message_id, 'plugin' => $plugin, 'record_id' => $record_id])->fetch();
		if(!$rec)return false;

		foreach(DB()->query("SELECT id, path FROM xcore_chatter_attachment WHERE xcore_chatter_message_id = :id AND deleted = 'no'", ['id' => $message_id])->fetchAll() as $f)
		{

			File::remove((string)$f['path']);
			DB('xcore_chatter_attachment')->delete((int)$f['id'], 1, self::author());

		}

		DB('xcore_chatter_message')->delete($message_id, 1, self::author());
		Log::write('chatter_delete', $rec['type'], ['message' => $message_id, 'text' => mb_strimwidth(self::text((string)$rec['body']), 0, 500, '...')], $record_id, 'warning', $plugin);

		return true;

	}

	/**
	 * open activities of a record, due first, with their state: late (red), today (orange), planned (green)
	 *
	 * @param string $plugin
	 * @param int $record_id
	 *
	 * @return array [{"id", "activity", "activity_label", "title", "note", "due_date", "state", "user": {"id", "name", "avatar"}}]
	 */
	public static function activities(string $plugin, int $record_id):array
	{

		$today = date('Y-m-d');
		$list = [];
		foreach(DB()->query("SELECT t.id, t.activity, t.title, t.description, t.due_date, t.responsible_id, u.firstname, u.lastname, u.login, u.avatar
							 FROM xcore_user_task t LEFT JOIN xcore_user u ON u.id = t.responsible_id
							 WHERE t.plugin = :plugin AND t.record_id = :record_id AND t.deleted = 'no' AND t.status != 'done'
							 ORDER BY t.due_date IS NULL, t.due_date, t.id", ['plugin' => $plugin, 'record_id' => $record_id])->fetchAll() as $rec)
		{

			$due = (string)($rec['due_date'] ?? '');
			$cell = Widget::avatar(['id' => (int)$rec['responsible_id']] + $rec);
			$list[] = [
				'id' => (int)$rec['id'],
				'activity' => $rec['activity'],
				'activity_label' => ts(self::ACTIVITIES[$rec['activity']] ?? 'To do'),
				'title' => $rec['title'],
				'note' => self::text((string)$rec['description']),
				'due_date' => $due,
				'state' => ($due === '') ? 'planned' : (($due < $today) ? 'late' : (($due === $today) ? 'today' : 'planned')),
				'user' => ['id' => (int)$rec['responsible_id'], 'name' => ($cell['title'] !== '') ? $cell['title'] : (string)$rec['login'], 'avatar' => $cell['avatar']],
			];

		}

		return $list;

	}

	/**
	 * schedules an activity: a task of My tasks linked to the record, its responsible follows the record and is notified
	 *
	 * @param string $plugin
	 * @param int $record_id
	 * @param array $data activity, responsible_id, due_date (Y-m-d), title, note, email (bool)
	 * @param string $record label of the record
	 *
	 * @return int|string id of the task, or the error (translated)
	 */
	public static function schedule(string $plugin, int $record_id, array $data, string $record):int|string
	{

		$activity = (string)($data['activity'] ?? '');
		$title = trim((string)($data['title'] ?? ''));
		$due = trim((string)($data['due_date'] ?? ''));
		$responsible = (int)($data['responsible_id'] ?? 0);
		$note = trim((string)($data['note'] ?? ''));

		if(!isset(self::ACTIVITIES[$activity]))return ts('choose the kind of activity');
		if($title === '' || mb_strlen($title) > 255)return ts('write a summary of 255 characters at most');
		if($due !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || !checkdate((int)substr($due, 5, 2), (int)substr($due, 8, 2), (int)substr($due, 0, 4))))return ts('invalid due date');
		if(mb_strlen($note) > self::MAX_LENGTH)return ts('the note is too long');
		if(!self::follow($plugin, $record_id, $responsible))return ts('choose an active user');

		$me = \Model\User::getUID();
		$id = (int)DB('xcore_user_task')->insert([
			'xcore_user_id' => $me,
			'responsible_id' => $responsible,
			'category' => ts('Activities'),
			'title' => $title,
			'description' => ($note === '') ? '' : nl2br(htmlspecialchars($note, ENT_QUOTES)),
			'priority' => 'medium',
			'due_date' => ($due === '') ? null : $due,
			'status' => 'pending',
			'progress' => 0,
			'plugin' => $plugin,
			'record_id' => $record_id,
			'activity' => $activity,
			'remind_email' => (!empty($data['email'])) ? 'yes' : 'no',
		], self::author());

		if($responsible !== $me)Notification::send($responsible, ts('[AUTHOR] scheduled an activity for you on [RECORD]', ['AUTHOR' => self::userName($me), 'RECORD' => $record]), ts(self::ACTIVITIES[$activity]).': '.$title.(($due !== '') ? " ({$due})" : ''), self::link($plugin, $record_id), 'info', 'chatter');
		Log::write('chatter_activity', $activity, ['task' => $id, 'responsible' => $responsible, 'due_date' => $due], $record_id, 'info', $plugin);

		return $id;

	}

	/**
	 * marks an activity done: its task is done, a line is added to the thread with the comment
	 *
	 * @param string $plugin
	 * @param int $record_id
	 * @param int $task_id
	 * @param string $comment
	 *
	 * @return string error (translated), '' when done
	 */
	public static function done(string $plugin, int $record_id, int $task_id, string $comment):string
	{

		$task = DB()->query("SELECT id, activity, title FROM xcore_user_task WHERE id = :id AND plugin = :plugin AND record_id = :record_id AND deleted = 'no' AND status != 'done'", ['id' => $task_id, 'plugin' => $plugin, 'record_id' => $record_id])->fetch();
		if(!$task)return ts('activity not found');

		$comment = trim($comment);
		if(mb_strlen($comment) > 2000)return ts('the comment is too long');

		DB('xcore_user_task')->update(['status' => 'done', 'progress' => 100], (int)$task['id'], 1, self::author());

		$line = '<p><strong>'.htmlspecialchars(ts(self::ACTIVITIES[$task['activity']] ?? 'To do'), ENT_QUOTES).'</strong>: '.htmlspecialchars((string)$task['title'], ENT_QUOTES).'</p>';
		if($comment !== '')$line .= '<p>'.nl2br(htmlspecialchars($comment, ENT_QUOTES)).'</p>';
		DB('xcore_chatter_message')->insert(['plugin' => $plugin, 'record_id' => $record_id, 'type' => 'activity', 'xcore_user_id' => \Model\User::getUID(), 'body' => $line, 'email' => 'no'], self::author());
		Log::write('chatter_activity_done', (string)$task['activity'], ['task' => $task_id], $record_id, 'info', $plugin);

		return '';

	}

	/**
	 * reminders of the activities due (cron task Chatter reminders): in-app to the responsible, by email when asked, once
	 *
	 * @return int activities reminded
	 */
	public static function remind():int
	{

		$count = 0;
		foreach(DB()->query("SELECT t.id, t.plugin, t.record_id, t.activity, t.title, t.due_date, t.remind_email, IF(t.responsible_id > 0, t.responsible_id, t.xcore_user_id) AS user_id
							 FROM xcore_user_task t
							 WHERE t.plugin != '' AND t.deleted = 'no' AND t.status != 'done' AND t.reminded_at IS NULL AND t.due_date IS NOT NULL AND t.due_date <= CURDATE()
							 ORDER BY t.id LIMIT 500")->fetchAll() as $task)
		{

			$link = self::link((string)$task['plugin'], (int)$task['record_id']);
			$activity = ts(self::ACTIVITIES[$task['activity']] ?? 'To do');
			$record = "{$task['plugin']} #{$task['record_id']}";
			Notification::send((int)$task['user_id'], ts('Activity due: [TITLE]', ['TITLE' => $task['title']]), "{$activity} - {$record} ({$task['due_date']})", $link, 'warning', 'chatter');

			if($task['remind_email'] === 'yes')
				foreach(self::mailUsers([(int)$task['user_id']]) as $user)
					Mailer::sendTemplate('core.chatter.activity', $user['email'], ['firstname' => $user['firstname'], 'activity' => $activity, 'title' => $task['title'], 'record' => $record, 'due_date' => $task['due_date'], 'url' => self::url($link)], (string)($user['language'] ?? ''), [], ['category' => 'transactional', 'module' => (string)$task['plugin'], 'action' => 'chatter_reminder', 'record_id' => (int)$task['record_id'], 'application' => 'cron', 'xcore_user_id' => (int)$user['id']]);

			DB()->query("UPDATE xcore_user_task SET reminded_at = NOW() WHERE id = :id", ['id' => (int)$task['id']]);
			$count++;

		}

		return $count;

	}

	/**
	 * path of the record in the backend: the module page opens its form
	 *
	 * @param string $plugin
	 * @param int $record_id
	 *
	 * @return string
	 */
	public static function link(string $plugin, int $record_id):string
	{

		return "{$plugin}/?edit={$record_id}";

	}

	// absolute url of a path of the backend, for the emails (/@backend/ = folder of the backend)
	private static function url(string $link):string
	{

		return rtrim((string)Config::get('url', ''), '/').'/@backend/'.$link;

	}

	// plain text of a message
	private static function text(string $html):string
	{

		return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br />', '</li>'], ' ', $html)), ENT_QUOTES | ENT_HTML5)));

	}

	// active users with an email
	private static function mailUsers(array $ids):array
	{

		$ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 1));
		if(!$ids)return [];

		return DB()->query("SELECT id, email, firstname, language FROM xcore_user WHERE deleted = 'no' AND active = 'yes' AND email != '' AND id IN (".implode(',', $ids).")")->fetchAll();

	}

	private static function userName(int $user_id):string
	{

		$rec = DB()->query("SELECT id, firstname, lastname, login FROM xcore_user WHERE id = :id", ['id' => $user_id])->fetch();
		if(!$rec)return ts('System');

		return trim("{$rec['firstname']} {$rec['lastname']}") ?: (string)$rec['login'];

	}

	private static function author():string
	{

		return (string)(Session::get('auth.login') ?: 'system');

	}

}
