<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * notifications (xcore_notification, \Core\Notification): one message sent to groups or users, one receipt per recipient
 * list, manual sending, delete; no edit: a notification sent stays as it is; reads are logged (action notification_read)
 */
#[Route('/api/notification', name: 'api.notification.')]
class NotificationController extends \Core\CrudController
{

	public string $table = 'xcore_notification';
	public string $title = 'Notifications';
	public string $object_label = 'notification';

	private const array LEVELS = ['info' => 'blue', 'success' => 'green', 'warning' => 'orange', 'error' => 'red'];

	// avatars shown in the recipients column, the others counted
	private const int STACK_MAX = 5;

	// recipients resolved by the rules, saved once the notification is created
	private array $recipients = [];

	protected function grid(DataGrid $grid):void
	{

		$levels = array_combine(array_keys(self::LEVELS), array_keys(self::LEVELS));
		$categories = DB()->query("SELECT DISTINCT category FROM xcore_notification WHERE deleted = 'no' AND category <> '' ORDER BY category")->fetchAllOne();

		$grid->qSelect("(SELECT COUNT(*) FROM xcore_notification_recipient r WHERE r.xcore_notification_id = xcore_notification.id AND r.deleted = 'no')", 'recipients_count');
		$grid->qSelect("(SELECT COUNT(*) FROM xcore_notification_recipient r WHERE r.xcore_notification_id = xcore_notification.id AND r.deleted = 'no' AND r.read_at IS NOT NULL)", 'reads_count');
		$grid->setSearch(['title', 'message', 'category'], 'Search title, message...');
		$grid->addFilterChips('level', $levels)->chipColors(self::LEVELS);
		$grid->addFilter('category', 'Category', 'select', array_combine($categories, $categories));
		$grid->addFilter('created_at', 'Date', 'date', [], 'DATE(created_at)');
		$grid->addFilter('title', 'Title');
		$grid->addFilterTags();
		$grid->setOrderByFirst('id', 'desc');
		$grid->setMobile('title', 'category');

		$grid->addCol('id', 'ID', true)->width(48);
		$grid->addColDatetime('created_at', 'Date', true)->width(120)->small();
		$grid->addColBadge('level', 'Level', true, self::LEVELS)->width(1);
		$grid->addCol('category', 'Category', true)->width(120);
		$grid->addCol('title', 'Title', true)->subText('message');
		$grid->addColTags()->width(140);
		$grid->addColStack('recipients', 'Recipients')->width(150);
		$grid->addCol('reads', 'Reads', false, 'text', ['align' => 'center'])->link('log/?search[]=action||notification_read&search[]=record_id||{id}')->width(80);
		$grid->addColWebLink('link', 'Link')->width(50);

		$grid->hookData(function(array $row):array{

			$row['reads'] = "{$row['reads_count']} / {$row['recipients_count']}";
			$row['recipients'] = $this->stack((string)$row['target_type'], (array)json_decode((string)$row['target_ids'], true));
			return $row;

		});

	}

	protected function form(Form $form, array $record):void
	{

		$groups = [];
		foreach(DB()->query("SELECT id, name FROM xcore_group WHERE deleted = 'no' AND active = 'yes' AND id <> :system ORDER BY priority, name", ['system' => \Model\User::GROUP_SYSTEM])->fetchAll() as $rec)
			$groups[$rec['id']] = $rec['name'];

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_notification WHERE deleted = 'no' AND category <> '' ORDER BY category")->fetchAllOne();

		// virtual fields (_): written as target_type and target_ids
		$form->addRadio('_recipient_type', 'Send to', ['group' => 'Groups', 'user' => 'Users'], true)->defaultValue('group')->protect();
		$form->addSelectMultiple('_recipient_groups', 'Groups', $groups)->set('translate', false)->showIf('_recipient_type', ['group'])->protect();
		$form->addSelectMultipleUser('_recipient_users', 'Users')->showIf('_recipient_type', ['user'])->protect();
		$form->addSelect('category', 'Category', array_combine($categories, $categories))->autocomplete(true)->maxLength(100)->set('translate', false);
		$form->addRadio('level', 'Level', array_combine(array_keys(self::LEVELS), array_keys(self::LEVELS)), true)->defaultValue('info');
		$form->addText('title', 'Title', true)->maxLength(255);
		$form->addTextarea('message', 'Message');
		$form->addText('link', 'Link')->maxLength(255)->help('page of the backend opened on click, ex: cron/')->set('open_link', 'backend')->protect();
		$form->addTagManager($this->table);

	}

	protected function rulesOnCreate(\Core\Validator $v):void
	{

		$type = ($v->inputGet('_recipient_type') === 'user') ? 'user' : 'group';
		$field = "_recipient_{$type}s";
		$this->recipients = \Core\Notification::recipients($type, $this->ids($v->inputGet($field)));
		if(!$this->recipients)$v->input($field)->addError(($type === 'user') ? ts('choose at least one user') : ts('these groups have no active user'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		$type = (($row['_recipient_type'] ?? '') === 'user') ? 'user' : 'group';
		$row['target_type'] = $type;
		$row['target_ids'] = json_encode($this->ids($row["_recipient_{$type}s"] ?? ''));
		$row['link'] = ltrim((string)($row['link'] ?? ''), '/');

		return $row;

	}

	protected function onSaveAfterCreate(int $id, array $row):void
	{

		\Core\Notification::addRecipients($id, $this->recipients);

	}

	protected function onDeleteAfter(array $record):void
	{

		DB()->query("UPDATE xcore_notification_recipient SET deleted = 'yes', deleted_at = NOW(), deleted_by = :author WHERE xcore_notification_id = :id AND deleted = 'no'", ['id' => (int)$record['id'], 'author' => $this->author()]);

	}

	/**
	 * ids of a multiselect (json array or array)
	 *
	 * @param mixed $value
	 *
	 * @return int[]
	 */
	private function ids(mixed $value):array
	{

		$ids = is_array($value) ? $value : (array)json_decode((string)$value, true);
		return array_values(array_unique(array_filter(array_map('intval', $ids))));

	}

	/**
	 * targets of a notification for the stack column: avatars of the users (STACK_MAX, the others counted) or names of the groups
	 *
	 * @param string $type user or group
	 * @param array $ids
	 *
	 * @return array {"items": [{"avatar", "title"} | {"label"}], "more"}
	 */
	private function stack(string $type, array $ids):array
	{

		$ids = $this->ids($ids);
		if(!$ids)return ['items' => [], 'more' => 0];

		$in = implode(',', $ids);
		$items = [];
		if($type === 'user')
		{

			foreach(DB()->query("SELECT id, firstname, lastname, login, avatar FROM xcore_user WHERE id IN ({$in}) ORDER BY firstname, lastname LIMIT ".self::STACK_MAX)->fetchAll() as $rec)
				$items[] = \Core\Widget::avatar($rec);

		}
		else
		{

			foreach(DB()->query("SELECT name FROM xcore_group WHERE id IN ({$in}) ORDER BY priority, name LIMIT ".self::STACK_MAX)->fetchAllOne() as $name)
				$items[] = ['label' => $name];

		}

		return ['items' => $items, 'more' => max(0, count($ids) - count($items))];

	}

}
