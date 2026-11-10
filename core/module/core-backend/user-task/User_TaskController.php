<?php

namespace Plugin\Core_Backend;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * personal tasks (xcore_user_task): seen and changed by their creator and their responsible only
 */
#[Route('/api/user-task', name: 'api.user_task.')]
class User_TaskController extends \Core\CrudController
{

	public string $table = 'xcore_user_task';
	public string $title = 'My tasks';
	public string $object_label = 'task';

	// set by the code: never bound to a workflow
	public array $managed_columns = ['status', 'progress'];

	private const array PRIORITIES = ['low' => 'low', 'medium' => 'medium', 'high' => 'high'];
	private const array STATUSES = ['pending' => 'pending', 'in progress' => 'in progress', 'done' => 'done'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qWhere('(xcore_user_id = :me OR responsible_id = :me2)', ['me' => \Model\User::getUID(), 'me2' => \Model\User::getUID()]);
		$grid->qSelect("(SELECT TRIM(CONCAT(u.firstname, ' ', u.lastname)) FROM xcore_user u WHERE u.id = xcore_user_task.responsible_id)", 'responsible');
		$grid->setSearch(['title', 'description'], 'Search title...');
		$grid->addFilterChips('category');
		$grid->addFilter('title', 'Title');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('priority', 'Priority', 'select', self::PRIORITIES);
		$grid->addFilter('status', 'Status', 'select', self::STATUSES);
		$grid->addFilter('responsible_id', 'Responsible', 'select', self::users());
		$grid->addFilter('progress', 'Progress', 'number');
		$grid->addFilter('due_date', 'Due date', 'date');
		$grid->addFilterTags();
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('title', 'due_date');
		$grid->batchEdit(['category', 'priority', 'status', 'due_date', 'responsible_id', 'progress']);

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('category', 'Category', true)->width(150);
		$grid->addColBadge('priority', 'Priority', true, ['low' => 'grey', 'medium' => 'blue', 'high' => 'red'])->labels(self::PRIORITIES)->width(100);
		$grid->addCol('title', 'Title', true);
		$grid->addCol('responsible', 'Responsible', true)->width(160);
		$grid->addColDate('due_date', 'Due date', true)->width(120);
		$grid->addColTags();
		$grid->addColBadge('status', 'Status', true, ['pending' => 'orange', 'in progress' => 'blue', 'done' => 'green'])->labels(self::STATUSES)->width(120);
		$grid->addCol('progress', 'Progress', true, 'text', ['align' => 'right'])->width(90);

		$grid->hookData(function(array $row):array{

			$row['progress'] = "{$row['progress']}%";
			return $row;

		});

	}

	protected function form(Form $form, array $record):void
	{

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_user_task WHERE deleted = 'no' AND (xcore_user_id = :me OR responsible_id = :me2) AND category != '' ORDER BY category", ['me' => \Model\User::getUID(), 'me2' => \Model\User::getUID()])->fetchAllOne();

		$form->addSelect('category', 'Category', array_combine($categories, $categories), true)->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addText('title', 'Title', true)->maxLength(255);
		$form->addHtml('description', 'Description');
		$form->addRadio('priority', 'Priority', self::PRIORITIES, true)->defaultValue('medium');
		$form->addSelectUser('responsible_id', 'Responsible', true, (int)($record['responsible_id'] ?? 0))->defaultValue((string)\Model\User::getUID())->protect();
		$form->addDate('due_date', 'Due date');
		$form->addTagManager($this->table);
		$form->addRadio('status', 'Status', self::STATUSES, true)->defaultValue('pending')->protect();
		$form->addNumber('progress', 'Progress', false, true)->defaultValue('0')->help('percent, 100 when the status is done')->protect();

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$progress = (int)$v->inputGet('progress', $record['progress'] ?? 0);
		if($progress < 0 || $progress > 100)$v->input('progress')->addError(ts('`[FIELD]` must be between 0 and 100'));

	}

	// own tasks only, for every route (read, update, delete, toggle, batch)
	protected function findRecord(int $id):array|false
	{

		return DB()->query("SELECT * FROM xcore_user_task WHERE id = :id AND deleted = 'no' AND (xcore_user_id = :me OR responsible_id = :me2)", ['id' => $id, 'me' => \Model\User::getUID(), 'me2' => \Model\User::getUID()])->fetch();

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(!$record)$row['xcore_user_id'] = \Model\User::getUID();
		if(array_key_exists('responsible_id', $row))$row['responsible_id'] = (int)$row['responsible_id'];
		if(($row['status'] ?? '') === 'done')$row['progress'] = 100;
		return $row;

	}

	/**
	 * active users (and the current one) for the filter of the responsible
	 *
	 * @return array [id => name]
	 */
	private static function users():array
	{

		$recs = DB()->query("SELECT id, TRIM(CONCAT(firstname, ' ', lastname)) AS name FROM xcore_user WHERE deleted = 'no' AND id > 1 ORDER BY firstname, lastname")->fetchAll();
		return array_column($recs, 'name', 'id');

	}

	/**
	 * widget of the dashboard: open tasks of the logged user, overdue and due first
	 *
	 * @return array see \Core\Widget
	 */
	public static function widgetMyTasks():array
	{

		$recs = DB()->query("SELECT id, category, title, priority, status, due_date FROM xcore_user_task WHERE deleted = 'no' AND (xcore_user_id = :me OR responsible_id = :me2) AND status != 'done' ORDER BY due_date IS NULL, due_date, FIELD(priority, 'high', 'medium', 'low'), id LIMIT 8", ['me' => \Model\User::getUID(), 'me2' => \Model\User::getUID()])->fetchAll();

		$rows = [];
		foreach($recs as $rec)
		{

			$late = ($rec['due_date'] && $rec['due_date'] <= date('Y-m-d'));
			$rows[] = [
				['badge' => ['low' => 'grey', 'medium' => 'blue', 'high' => 'red'][$rec['priority']] ?? 'grey', 'text' => ts($rec['priority']), 'width' => 80],
				['text' => $rec['title'], 'strong' => true],
				['text' => $rec['category'], 'muted' => true, 'width' => 130],
				['badge' => ($rec['status'] === 'in progress') ? 'blue' : 'orange', 'text' => ts($rec['status']), 'width' => 100],
				($rec['due_date']) ? ['day' => $rec['due_date'], 'align' => 'right', 'color' => ($late) ? 'error' : '', 'width' => 90] : ['text' => '', 'width' => 90],
			];

		}

		return ['type' => 'rows', 'rows' => $rows, 'empty' => ts('No open task')];

	}

}
