<?php

namespace Plugin\Core_Backend;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * projects (xcore_project): board by steps (xcore_project_step), tasks (xcore_project_task) with attachments (xcore_project_task_attachment, private files), gantt
 * the progress of a project is the mean progress of its tasks
 */
#[Route('/api/project', name: 'api.project.')]
class ProjectController extends \Core\CrudController
{

	public string $table = 'xcore_project';

	/**
	 * currencies of the budget: iso 4217 code => symbol
	 */
	public const array CURRENCIES = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF', 'CAD' => 'CA$', 'JPY' => '¥'];
	public string $title = 'Projects';
	public string $object_label = 'project';

	public const array STATUSES = ['active' => 'active', 'paused' => 'paused', 'completed' => 'completed', 'cancelled' => 'cancelled'];
	public const array PRIORITIES = ['low' => 'low', 'normal' => 'normal', 'high' => 'high', 'urgent' => 'urgent'];
	public const array STATUS_COLORS = ['active' => 'blue', 'paused' => 'grey', 'completed' => 'green', 'cancelled' => 'red'];
	public const array STEP_COLORS = ['grey', 'blue', 'orange', 'green', 'red', 'purple', 'yellow'];
	public const array DEFAULT_STEPS = [['To do', 'grey', 'no'], ['In progress', 'blue', 'no'], ['Review', 'orange', 'no'], ['Done', 'green', 'yes']];

	// attachments: 20 MB, images, pdf, office, text, archives
	public const int MAX_SIZE = 20 * 1024 * 1024;
	public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'csv', 'zip'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT TRIM(CONCAT(u.firstname, ' ', u.lastname)) FROM xcore_user u WHERE u.id = xcore_project.xcore_user_id)", 'responsible');
		$grid->setSearch(['name', 'description'], 'Search name...');
		$grid->addFilterChips('status', self::STATUSES);
		$grid->addFilter('name', 'Name');
		$grid->addFilter('status', 'Status', 'select', self::STATUSES);
		$grid->addFilter('priority', 'Priority', 'select', self::PRIORITIES);
		$grid->addFilter('date_end', 'End date', 'date');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('name', 'responsible');
		$grid->batchEdit(['status', 'priority']);
		$grid->rowAction('board', 'Board', 'view_kanban', 'list', 'project/?board={id}');
		$grid->rowAction('gantt', 'Gantt', 'view_timeline', 'list', 'project/?gantt={id}');

		$grid->addCol('id', 'ID', true)->width(64);
		$grid->addColColor('color', '')->width(40);
		$grid->addCol('name', 'Name', true)->link('project/?board={id}');
		$grid->addCol('responsible', 'Responsible')->width(160);
		$grid->addColBadge('priority', 'Priority', true, ['low' => 'grey', 'normal' => 'blue', 'high' => 'orange', 'urgent' => 'red'])->labels(self::PRIORITIES)->width(90);
		$grid->addCol('progress', 'Progress', true, 'text', ['align' => 'right'])->width(90);
		$grid->addColDate('date_start', 'Start', true)->width(110);
		$grid->addColDate('date_end', 'End', true)->width(110);
		$grid->addColBadge('status', 'Status', true, self::STATUS_COLORS)->labels(self::STATUSES)->width(100);

		$grid->hookData(function(array $row):array{

			$row['progress'] = "{$row['progress']}%";
			return $row;

		});

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addText('description', 'Description')->maxLength(5000);
		$form->addSelect('xcore_user_id', 'Responsible', self::users((int)($record['xcore_user_id'] ?? 0)), true)->autocomplete()->defaultValue((string)\Model\User::getUID())->set('translate', false);
		$form->addRadio('priority', 'Priority', self::PRIORITIES, true)->defaultValue('normal');
		$form->addRadio('status', 'Status', self::STATUSES, true)->defaultValue('active');
		$form->addDate('date_start', 'Start date');
		$form->addDate('date_end', 'End date')->set('inline', true);
		$form->addNumber('budget', 'Budget')->defaultValue('0')->set('size', 150)->set('align', 'center')->set('mask', 'thousands');
		$currency = (string)\Core\Globals::get('backend.currency.default', 'EUR', true, 'text', 'currency selected by default for the amounts (iso 4217 code: EUR, USD, GBP, CHF, CAD, JPY)');
		$form->addSelect('currency', 'Currency', self::CURRENCIES, true)->defaultValue(isset(self::CURRENCIES[$currency]) ? $currency : 'EUR')->set('translate', false)->set('inline', true)->set('size', 80)->set('hide_label', true);
		$form->addColor('color', 'Color')->defaultValue('#4169e1');
		$form->addTextarea('note', 'Note')->maxLength(5000);

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$start = (string)$v->inputGet('date_start', $record['date_start'] ?? '');
		$end = (string)$v->inputGet('date_end', $record['date_end'] ?? '');
		$v->input('date_end');
		if($start !== '' && $end !== '' && $end < $start)$v->addError(ts('`[FIELD]` must be after the start'));

	}

	// optional responsible and dates
	protected function onSaveBefore(array $row, array $record):array
	{

		if(array_key_exists('xcore_user_id', $row))$row['xcore_user_id'] = (int)$row['xcore_user_id'];
		foreach(['date_start', 'date_end'] as $col)
			if(array_key_exists($col, $row) && $row[$col] === '')$row[$col] = null;

		return $row;

	}

	// default steps of a new project
	protected function onSaveAfterCreate(int $id, array $row):void
	{

		foreach(self::DEFAULT_STEPS as $i => [$name, $color, $final])
			DB('xcore_project_step')->insert(['xcore_project_id' => $id, 'name' => ts($name), 'color' => $color, 'position' => $i + 1, 'is_final' => $final], $this->author());

	}

	// steps and tasks go with the project
	protected function onDeleteAfter(array $record):void
	{

		$binds = ['id' => (int)$record['id'], 'now' => now(), 'author' => $this->author()];
		DB()->query("UPDATE xcore_project_step SET deleted = 'yes', deleted_at = :now, deleted_by = :author WHERE xcore_project_id = :id AND deleted = 'no'", $binds);
		DB()->query("UPDATE xcore_project_task SET deleted = 'yes', deleted_at = :now, deleted_by = :author WHERE xcore_project_id = :id AND deleted = 'no'", $binds);

	}

	/**
	 * active users, value => name (the current one kept when inactive)
	 *
	 * @param int $current
	 *
	 * @return array
	 */
	private static function users(int $current = 0):array
	{

		$recs = DB()->query("SELECT id, TRIM(CONCAT(firstname, ' ', lastname)) AS name FROM xcore_user WHERE deleted = 'no' AND (active = 'yes' OR id = :current) AND id > 1 ORDER BY firstname, lastname", ['current' => $current])->fetchAll();
		return array_column($recs, 'name', 'id');

	}

	// ================================================================================================================
	// board
	// ================================================================================================================

	/**
	 * project, steps and tasks of the board and the gantt (list right)
	 *
	 * @param int $id
	 *
	 * @return \Core\JsonResponse {"project", "steps", "tasks", "users", "categories", "rights"}
	 */
	#[Route('/{id}/board/', name: 'board', methods: ['GET'])]
	public function apiBoard(int $id):\Core\JsonResponse
	{

		if($denied = $this->deny('list'))return $denied;
		if(!$project = $this->findRecord($id))return \Core\JsonResponse::error(404, 'record not found');

		$responsible = DB()->query("SELECT id, firstname, lastname, login, avatar FROM xcore_user WHERE id = :id", ['id' => (int)$project['xcore_user_id']])->fetch();

		$steps = DB()->query("SELECT id, name, color, position, is_final FROM xcore_project_step WHERE deleted = 'no' AND xcore_project_id = :id ORDER BY position, id", ['id' => $id])->fetchAll();
		foreach($steps as &$step)
		{

			$step['id'] = (int)$step['id'];
			$step['position'] = (int)$step['position'];
			$step['final'] = ($step['is_final'] === 'yes');
			unset($step['is_final']);

		}
		unset($step);

		$tasks = DB()->query("SELECT t.*, u.firstname, u.lastname, u.login, u.avatar,
								(SELECT COUNT(*) FROM xcore_project_task_attachment a WHERE a.xcore_project_task_id = t.id AND a.deleted = 'no') AS attachments,
								(SELECT a.id FROM xcore_project_task_attachment a WHERE a.xcore_project_task_id = t.id AND a.deleted = 'no' AND a.mime LIKE 'image/%' ORDER BY a.id LIMIT 1) AS image_id
							  FROM xcore_project_task t LEFT JOIN xcore_user u ON u.id = t.xcore_user_id
							  WHERE t.deleted = 'no' AND t.xcore_project_id = :id ORDER BY t.position, t.id", ['id' => $id])->fetchAll();

		return new \Core\JsonResponse([
			'project' => [
				'id' => (int)$project['id'],
				'name' => $project['name'],
				'color' => $project['color'] ?: '#4169e1',
				'status' => $project['status'],
				'status_label' => ts($project['status']),
				'status_color' => self::STATUS_COLORS[$project['status']] ?? 'grey',
				'progress' => (int)$project['progress'],
				'date_start' => $project['date_start'],
				'date_end' => $project['date_end'],
				'responsible' => ($responsible) ? ['name' => trim("{$responsible['firstname']} {$responsible['lastname']}"), 'avatar' => \Core\Widget::avatar($responsible)['avatar']] : null,
			],
			'steps' => $steps,
			'tasks' => array_map(fn($t) => $this->taskOut($t), $tasks),
			'users' => array_map(fn($id, $name) => ['id' => (int)$id, 'name' => $name], array_keys($users = self::users()), $users),
			'categories' => DB()->query("SELECT DISTINCT category FROM xcore_project_task WHERE deleted = 'no' AND xcore_project_id = :id AND category != '' ORDER BY category", ['id' => $id])->fetchAllOne(),
			'rights' => \Model\User::getRights($this->module),
		]);

	}

	// task as sent to react
	private function taskOut(array $t):array
	{

		$user = ((int)$t['xcore_user_id']) ? \Core\Widget::avatar(['id' => $t['xcore_user_id'], 'firstname' => $t['firstname'] ?? '', 'lastname' => $t['lastname'] ?? '', 'login' => $t['login'] ?? '', 'avatar' => $t['avatar'] ?? '']) : null;

		return [
			'id' => (int)$t['id'],
			'step_id' => (int)$t['xcore_project_step_id'],
			'user_id' => (int)$t['xcore_user_id'],
			'user' => ($user) ? ['name' => $user['title'], 'avatar' => $user['avatar']] : null,
			'depends_on' => (int)$t['depends_on_task_id'],
			'category' => (string)$t['category'],
			'title' => $t['title'],
			'excerpt' => mb_strimwidth(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], ' ', (string)$t['description']))))), 0, 90, '...'),
			'priority' => $t['priority'],
			'progress' => (int)$t['progress'],
			'date_start' => $t['date_start'],
			'date_end' => $t['date_end'],
			'position' => (int)$t['position'],
			'attachments' => (int)($t['attachments'] ?? 0),
			'image' => ($t['image_id'] ?? null) ? "project/attachments/{$t['image_id']}/" : null,
		];

	}

	// ================================================================================================================
	// tasks
	// ================================================================================================================

	/**
	 * a task with its description and attachments (list right)
	 *
	 * @param int $task
	 *
	 * @return \Core\JsonResponse {"task": {..., "description", "attachments": [{"id", "name", "mime", "size", "url"}]}}
	 */
	#[Route('/tasks/{task}/', name: 'task', methods: ['GET'], requirements: ['task' => '\d+'])]
	public function apiTask(int $task):\Core\JsonResponse
	{

		if($denied = $this->deny('list'))return $denied;
		if(!$rec = $this->taskFind($task))return \Core\JsonResponse::error(404, 'task not found');

		$out = $this->taskOut($rec);
		$out['description'] = (string)$rec['description'];
		$out['attachments'] = array_map(fn($a) => ['id' => (int)$a['id'], 'name' => $a['name'], 'mime' => $a['mime'], 'size' => (int)$a['size'], 'url' => "project/attachments/{$a['id']}/"], DB()->query("SELECT id, name, mime, size FROM xcore_project_task_attachment WHERE deleted = 'no' AND xcore_project_task_id = :id ORDER BY id", ['id' => $task])->fetchAll());

		return new \Core\JsonResponse(['task' => $out]);

	}

	/**
	 * adds a task to a project (add right), json body: title, category, description, priority, step_id, user_id, date_start, date_end, depends_on, progress
	 *
	 * @param Request $request
	 * @param int $id project
	 *
	 * @return \Core\JsonResponse {"id"}, 422 {"fields"}
	 */
	#[Route('/{id}/tasks/', name: 'task.create', methods: ['POST'])]
	public function apiTaskCreate(Request $request, int $id):\Core\JsonResponse
	{

		if($denied = $this->deny('add'))return $denied;
		if(!$this->findRecord($id))return \Core\JsonResponse::error(404, 'record not found');

		$row = $this->taskRow($request, $id, 0, $errors);
		if($errors)return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $errors]);

		$row['xcore_project_id'] = $id;
		$row['position'] = (int)DB()->query("SELECT COALESCE(MAX(position), 0) + 1 FROM xcore_project_task WHERE deleted = 'no' AND xcore_project_step_id = :step", ['step' => $row['xcore_project_step_id']])->fetchOne();
		$task = (int)DB('xcore_project_task')->insert($row, $this->author());

		\Core\Log::write('add', 'task', $row, $task, 'info', $this->module);
		$this->progressUpdate($id);

		return new \Core\JsonResponse(['id' => $task], 201);

	}

	/**
	 * changes a task (edit right), same body as the creation
	 *
	 * @param Request $request
	 * @param int $task
	 *
	 * @return \Core\JsonResponse {"id"}, 422 {"fields"}
	 */
	#[Route('/tasks/{task}/', name: 'task.update', methods: ['PUT'], requirements: ['task' => '\d+'])]
	public function apiTaskUpdate(Request $request, int $task):\Core\JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$rec = $this->taskFind($task))return \Core\JsonResponse::error(404, 'task not found');

		$row = $this->taskRow($request, (int)$rec['xcore_project_id'], $task, $errors);
		if($errors)return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $errors]);

		if((int)$row['xcore_project_step_id'] !== (int)$rec['xcore_project_step_id'])
			$row['position'] = (int)DB()->query("SELECT COALESCE(MAX(position), 0) + 1 FROM xcore_project_task WHERE deleted = 'no' AND xcore_project_step_id = :step", ['step' => $row['xcore_project_step_id']])->fetchOne();

		DB('xcore_project_task')->update($row, $task, 1, $this->author());
		$this->shiftDependents($task, $rec, $row);

		\Core\Log::write('edit', 'task', $row, $task, 'info', $this->module);
		$this->progressUpdate((int)$rec['xcore_project_id']);

		return new \Core\JsonResponse(['id' => $task]);

	}

	/**
	 * deletes a task (delete right), refused when another task depends on it
	 *
	 * @param int $task
	 *
	 * @return \Core\JsonResponse 204
	 */
	#[Route('/tasks/{task}/', name: 'task.delete', methods: ['DELETE'], requirements: ['task' => '\d+'])]
	public function apiTaskDelete(int $task):\Core\JsonResponse
	{

		if($denied = $this->deny('delete'))return $denied;
		if(!$rec = $this->taskFind($task))return \Core\JsonResponse::error(404, 'task not found');

		$dependents = (int)DB()->query("SELECT COUNT(*) FROM xcore_project_task WHERE deleted = 'no' AND depends_on_task_id = :id", ['id' => $task])->fetchOne();
		if($dependents)return \Core\JsonResponse::error(422, ts('[COUNT] task(s) depend on this task', ['COUNT' => $dependents]));

		DB()->query("UPDATE xcore_project_task SET deleted = 'yes', deleted_at = :now, deleted_by = :author WHERE id = :id", ['now' => now(), 'author' => $this->author(), 'id' => $task]);
		\Core\Log::write('delete', 'task', ['title' => $rec['title']], $task, 'info', $this->module);
		$this->progressUpdate((int)$rec['xcore_project_id']);

		return new \Core\JsonResponse(null, 204);

	}

	/**
	 * moves a task on the board (edit right), json body {"step_id", "index"}: index in the target step
	 *
	 * @param Request $request
	 * @param int $task
	 *
	 * @return \Core\JsonResponse {"id"}
	 */
	#[Route('/tasks/{task}/move/', name: 'task.move', methods: ['POST'], requirements: ['task' => '\d+'])]
	public function apiTaskMove(Request $request, int $task):\Core\JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$rec = $this->taskFind($task))return \Core\JsonResponse::error(404, 'task not found');

		$step = (int)$request->request->get('step_id');
		if(!$this->stepFind($step, (int)$rec['xcore_project_id']))return \Core\JsonResponse::error(422, 'step not found');

		$ids = DB()->query("SELECT id FROM xcore_project_task WHERE deleted = 'no' AND xcore_project_step_id = :step AND id != :id ORDER BY position, id", ['step' => $step, 'id' => $task])->fetchAllOne();
		$index = max(0, min(count($ids), (int)$request->request->get('index')));
		array_splice($ids, $index, 0, [$task]);

		foreach($ids as $i => $tid)
			DB()->query("UPDATE xcore_project_task SET position = :position, xcore_project_step_id = :step WHERE id = :id", ['position' => $i + 1, 'step' => $step, 'id' => (int)$tid]);

		// a task dropped in a final step is done
		if($this->stepFind($step, (int)$rec['xcore_project_id'])['is_final'] === 'yes')DB()->query("UPDATE xcore_project_task SET progress = 100 WHERE id = :id", ['id' => $task]);

		$this->progressUpdate((int)$rec['xcore_project_id']);
		return new \Core\JsonResponse(['id' => $task]);

	}

	/**
	 * changes the dates of a task from the gantt (edit right), json body {"date_start", "date_end"}, the dependent tasks are shifted
	 *
	 * @param Request $request
	 * @param int $task
	 *
	 * @return \Core\JsonResponse {"id"}
	 */
	#[Route('/tasks/{task}/dates/', name: 'task.dates', methods: ['POST'], requirements: ['task' => '\d+'])]
	public function apiTaskDates(Request $request, int $task):\Core\JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$rec = $this->taskFind($task))return \Core\JsonResponse::error(404, 'task not found');

		$start = (string)$request->request->get('date_start');
		$end = (string)$request->request->get('date_end');
		if(!self::isDate($start) || !self::isDate($end) || $end < $start)return \Core\JsonResponse::error(422, 'invalid dates');

		$row = ['date_start' => $start, 'date_end' => $end];
		DB('xcore_project_task')->update($row, $task, 1, $this->author());
		$this->shiftDependents($task, $rec, $row);

		\Core\Log::write('edit', 'task', $row, $task, 'info', $this->module);
		return new \Core\JsonResponse(['id' => $task]);

	}

	// task of a project not deleted
	private function taskFind(int $task):array|false
	{

		return DB()->query("SELECT t.*, u.firstname, u.lastname, u.login, u.avatar FROM xcore_project_task t INNER JOIN xcore_project p ON p.id = t.xcore_project_id AND p.deleted = 'no' LEFT JOIN xcore_user u ON u.id = t.xcore_user_id WHERE t.id = :id AND t.deleted = 'no'", ['id' => $task])->fetch();

	}

	// step of a project not deleted
	private function stepFind(int $step, int $project):array|false
	{

		return DB()->query("SELECT * FROM xcore_project_step WHERE id = :id AND xcore_project_id = :project AND deleted = 'no'", ['id' => $step, 'project' => $project])->fetch();

	}

	private static function isDate(string $value):bool
	{

		return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4));

	}

	/**
	 * checked row of a task from the body
	 *
	 * @param Request $request
	 * @param int $project
	 * @param int $task 0 on create
	 * @param array|null $errors field => [messages]
	 *
	 * @return array
	 */
	private function taskRow(Request $request, int $project, int $task, ?array &$errors):array
	{

		$errors = [];
		$in = $request->request;
		$title = trim((string)$in->get('title'));
		$title = preg_replace_callback('/^(\s*)(\p{Ll})/u', fn($m) => $m[1].mb_strtoupper($m[2]), $title);
		$category = trim((string)$in->get('category'));
		$category = preg_replace_callback('/^(\s*)(\p{Ll})/u', fn($m) => $m[1].mb_strtoupper($m[2]), $category);

		if($title === '' || mb_strlen($title) > 255)$errors['title'][] = ts('`[FIELD]` is required (255 characters max)', ['FIELD' => ts('Title')]);
		if(mb_strlen($category) > 255)$errors['category'][] = ts('`[FIELD]` is too long', ['FIELD' => ts('Category')]);

		$priority = (string)$in->get('priority', 'normal');
		if(!isset(self::PRIORITIES[$priority]))$errors['priority'][] = ts('`[FIELD]` is invalid', ['FIELD' => ts('Priority')]);

		$step = (int)$in->get('step_id');
		if(!$this->stepFind($step, $project))$errors['step_id'][] = ts('`[FIELD]` is invalid', ['FIELD' => ts('Step')]);

		$user = (int)$in->get('user_id');
		if($user && !isset(self::users($user)[$user]))$errors['user_id'][] = ts('`[FIELD]` is invalid', ['FIELD' => ts('Assigned to')]);

		$start = (string)$in->get('date_start');
		$end = (string)$in->get('date_end');
		if($start !== '' && !self::isDate($start))$errors['date_start'][] = ts('`[FIELD]` is invalid', ['FIELD' => ts('Start date')]);
		if($end !== '' && !self::isDate($end))$errors['date_end'][] = ts('`[FIELD]` is invalid', ['FIELD' => ts('End date')]);
		if($start !== '' && $end !== '' && $end < $start)$errors['date_end'][] = ts('`[FIELD]` must be after the start', ['FIELD' => ts('End date')]);

		// a task depends on another task of the project, never on itself or on a task depending on it
		$depends = (int)$in->get('depends_on');
		if($depends)
		{

			$chain = $depends;
			for($i = 0; $chain && $i < 100; $i++)
			{

				if($chain === $task){ $errors['depends_on'][] = ts('circular dependency'); break; }
				$chain = (int)DB()->query("SELECT depends_on_task_id FROM xcore_project_task WHERE id = :id AND deleted = 'no' AND xcore_project_id = :project", ['id' => $chain, 'project' => $project])->fetchOne();

			}

			if(!DB()->query("SELECT id FROM xcore_project_task WHERE id = :id AND deleted = 'no' AND xcore_project_id = :project", ['id' => $depends, 'project' => $project])->fetchOne())$errors['depends_on'][] = ts('`[FIELD]` is invalid', ['FIELD' => ts('Depends on')]);

		}

		$description = \Core\Sanitizer::clean((string)$in->get('description'), 'basic');

		return [
			'xcore_project_step_id' => $step,
			'xcore_user_id' => $user,
			'depends_on_task_id' => $depends,
			'category' => $category,
			'title' => $title,
			'description' => $description,
			'priority' => $priority,
			'progress' => max(0, min(100, (int)$in->get('progress'))),
			'date_start' => ($start !== '') ? $start : null,
			'date_end' => ($end !== '') ? $end : null,
		];

	}

	/**
	 * when the end of a task moves, the tasks depending on it move by the same number of days (and theirs, recursively)
	 *
	 * @param int $task
	 * @param array $before row before the change
	 * @param array $after row after the change
	 *
	 * @return void
	 */
	private function shiftDependents(int $task, array $before, array $after):void
	{

		if(empty($before['date_end']) || empty($after['date_end']) || $before['date_end'] === $after['date_end'])return;

		$days = (int)round((strtotime($after['date_end']) - strtotime($before['date_end'])) / 86400);
		$queue = [$task];
		$seen = [];
		while($queue && count($seen) < 500)
		{

			$id = array_shift($queue);
			foreach(DB()->query("SELECT id, date_start, date_end FROM xcore_project_task WHERE deleted = 'no' AND depends_on_task_id = :id", ['id' => $id])->fetchAll() as $dep)
			{

				if(isset($seen[$dep['id']]))continue;
				$seen[$dep['id']] = true;
				$queue[] = (int)$dep['id'];

				$row = [];
				foreach(['date_start', 'date_end'] as $col)
					if($dep[$col])$row[$col] = date('Y-m-d', strtotime("{$dep[$col]} {$days} days"));
				if($row)DB('xcore_project_task')->update($row, (int)$dep['id'], 1, $this->author());

			}

		}

	}

	// progress of a project: mean progress of its tasks
	private function progressUpdate(int $project):void
	{

		$progress = (int)round((float)DB()->query("SELECT COALESCE(AVG(progress), 0) FROM xcore_project_task WHERE deleted = 'no' AND xcore_project_id = :id", ['id' => $project])->fetchOne());
		DB()->query("UPDATE xcore_project SET progress = :progress WHERE id = :id", ['progress' => $progress, 'id' => $project]);

	}

	// ================================================================================================================
	// steps
	// ================================================================================================================

	/**
	 * adds a step at the end of the board (edit right), json body {"name", "color"}
	 *
	 * @param Request $request
	 * @param int $id project
	 *
	 * @return \Core\JsonResponse {"id"}
	 */
	#[Route('/{id}/steps/', name: 'step.create', methods: ['POST'])]
	public function apiStepCreate(Request $request, int $id):\Core\JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$this->findRecord($id))return \Core\JsonResponse::error(404, 'record not found');
		if(!$row = $this->stepRow($request))return \Core\JsonResponse::error(422, ts('the name of the step is required (100 characters max)'));

		$row['xcore_project_id'] = $id;
		$row['position'] = (int)DB()->query("SELECT COALESCE(MAX(position), 0) + 1 FROM xcore_project_step WHERE deleted = 'no' AND xcore_project_id = :id", ['id' => $id])->fetchOne();
		$step = (int)DB('xcore_project_step')->insert($row, $this->author());

		return new \Core\JsonResponse(['id' => $step], 201);

	}

	/**
	 * renames a step, changes its color or makes it final (edit right), json body {"name", "color", "final"}
	 *
	 * @param Request $request
	 * @param int $step
	 *
	 * @return \Core\JsonResponse {"id"}
	 */
	#[Route('/steps/{step}/', name: 'step.update', methods: ['PUT'], requirements: ['step' => '\d+'])]
	public function apiStepUpdate(Request $request, int $step):\Core\JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$rec = DB()->query("SELECT * FROM xcore_project_step WHERE id = :id AND deleted = 'no'", ['id' => $step])->fetch())return \Core\JsonResponse::error(404, 'step not found');
		if(!$row = $this->stepRow($request))return \Core\JsonResponse::error(422, ts('the name of the step is required (100 characters max)'));

		DB('xcore_project_step')->update($row, $step, 1, $this->author());
		return new \Core\JsonResponse(['id' => $step]);

	}

	/**
	 * deletes an empty step (edit right)
	 *
	 * @param int $step
	 *
	 * @return \Core\JsonResponse 204
	 */
	#[Route('/steps/{step}/', name: 'step.delete', methods: ['DELETE'], requirements: ['step' => '\d+'])]
	public function apiStepDelete(int $step):\Core\JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!DB()->query("SELECT id FROM xcore_project_step WHERE id = :id AND deleted = 'no'", ['id' => $step])->fetchOne())return \Core\JsonResponse::error(404, 'step not found');

		$tasks = (int)DB()->query("SELECT COUNT(*) FROM xcore_project_task WHERE deleted = 'no' AND xcore_project_step_id = :id", ['id' => $step])->fetchOne();
		if($tasks)return \Core\JsonResponse::error(422, ts('move the [COUNT] task(s) of this step first', ['COUNT' => $tasks]));

		DB()->query("UPDATE xcore_project_step SET deleted = 'yes', deleted_at = :now, deleted_by = :author WHERE id = :id", ['now' => now(), 'author' => $this->author(), 'id' => $step]);
		return new \Core\JsonResponse(null, 204);

	}

	/**
	 * order of the steps (edit right), json body {"ids": [3, 1, 2]}
	 *
	 * @param Request $request
	 * @param int $id project
	 *
	 * @return \Core\JsonResponse {"updated"}
	 */
	#[Route('/{id}/steps/order/', name: 'step.order', methods: ['POST'])]
	public function apiStepOrder(Request $request, int $id):\Core\JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$this->findRecord($id))return \Core\JsonResponse::error(404, 'record not found');

		$ids = array_map('intval', (array)$request->request->all('ids'));
		foreach(array_values($ids) as $i => $step)
			DB()->query("UPDATE xcore_project_step SET position = :position WHERE id = :id AND xcore_project_id = :project", ['position' => $i + 1, 'id' => $step, 'project' => $id]);

		return new \Core\JsonResponse(['updated' => count($ids)]);

	}

	// name (1 to 100 characters), color of STEP_COLORS, final
	private function stepRow(Request $request):?array
	{

		$name = trim((string)$request->request->get('name'));
		if($name === '' || mb_strlen($name) > 100)return null;

		$color = (string)$request->request->get('color');
		return ['name' => $name, 'color' => in_array($color, self::STEP_COLORS, true) ? $color : 'grey', 'is_final' => ($request->request->get('final') === true || $request->request->get('final') === 'yes') ? 'yes' : 'no'];

	}

	// ================================================================================================================
	// attachments
	// ================================================================================================================

	/**
	 * uploads a file to a task (edit right), multipart field "file": 20 MB, images, pdf, office, text, zip
	 *
	 * @param Request $request
	 * @param int $task
	 *
	 * @return \Core\JsonResponse {"id"}
	 */
	#[Route('/tasks/{task}/attachments/', name: 'attachment.create', methods: ['POST'], requirements: ['task' => '\d+'])]
	public function apiAttachmentCreate(Request $request, int $task):\Core\JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$rec = $this->taskFind($task))return \Core\JsonResponse::error(404, 'task not found');

		$file = $request->files->get('file');
		if(!$file || !$file->isValid())return \Core\JsonResponse::error(422, ts('the upload failed'));
		if($file->getSize() > self::MAX_SIZE)return \Core\JsonResponse::error(422, ts('the file is larger than [SIZE] MB', ['SIZE' => self::MAX_SIZE / 1048576]));

		$extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
		if(!in_array($extension, self::EXTENSIONS, true))return \Core\JsonResponse::error(422, ts('file type not allowed: [TYPES]', ['TYPES' => implode(', ', self::EXTENSIONS)]));

		// stored under a random name, never executed or served directly
		$mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
		$dir = "project/{$rec['xcore_project_id']}";
		$name = bin2hex(random_bytes(16)).".{$extension}";
		$file->move(\Core\File::privateDir($dir), $name);

		$id = (int)DB('xcore_project_task_attachment')->insert([
			'xcore_project_task_id' => $task,
			'name' => mb_substr(preg_replace('/[\x00-\x1f"\\\\\/]/u', '_', $file->getClientOriginalName()), 0, 255),
			'path' => "{$dir}/{$name}",
			'mime' => $mime,
			'size' => (int)filesize(\Core\File::privateDir($dir)."/{$name}"),
		], $this->author());

		\Core\Log::write('upload', 'attachment', ['name' => $file->getClientOriginalName()], $task, 'info', $this->module);
		return new \Core\JsonResponse(['id' => $id], 201);

	}

	/**
	 * file of an attachment (list right): images and pdf shown inline, the others downloaded
	 *
	 * @param int $attachment
	 *
	 * @return mixed
	 */
	#[Route('/attachments/{attachment}/', name: 'attachment', methods: ['GET'], requirements: ['attachment' => '\d+'])]
	public function apiAttachment(int $attachment):mixed
	{

		if($denied = $this->deny('list'))return $denied;
		if(!$rec = $this->attachmentFind($attachment))return \Core\JsonResponse::error(404, 'attachment not found');

		$path = \Core\File::privateDir()."/{$rec['path']}";
		if(!is_file($path))return \Core\JsonResponse::error(404, 'file not found');

		$inline = (str_starts_with($rec['mime'], 'image/') && $rec['mime'] !== 'image/svg+xml') || $rec['mime'] === 'application/pdf';
		$response = new BinaryFileResponse($path);
		$response->headers->set('Content-Type', $rec['mime'] ?: 'application/octet-stream');
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		$response->setContentDisposition(($inline) ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $rec['name'], 'file.'.pathinfo($rec['path'], PATHINFO_EXTENSION));

		return $response;

	}

	/**
	 * deletes an attachment (edit right), the file is removed
	 *
	 * @param int $attachment
	 *
	 * @return \Core\JsonResponse 204
	 */
	#[Route('/attachments/{attachment}/', name: 'attachment.delete', methods: ['DELETE'], requirements: ['attachment' => '\d+'])]
	public function apiAttachmentDelete(int $attachment):\Core\JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$rec = $this->attachmentFind($attachment))return \Core\JsonResponse::error(404, 'attachment not found');

		DB()->query("UPDATE xcore_project_task_attachment SET deleted = 'yes', deleted_at = :now, deleted_by = :author WHERE id = :id", ['now' => now(), 'author' => $this->author(), 'id' => $attachment]);
		@unlink(\Core\File::privateDir()."/{$rec['path']}");
		\Core\Log::write('delete', 'attachment', ['name' => $rec['name']], (int)$rec['xcore_project_task_id'], 'info', $this->module);

		return new \Core\JsonResponse(null, 204);

	}

	private function attachmentFind(int $attachment):array|false
	{

		return DB()->query("SELECT a.* FROM xcore_project_task_attachment a INNER JOIN xcore_project_task t ON t.id = a.xcore_project_task_id AND t.deleted = 'no' WHERE a.id = :id AND a.deleted = 'no'", ['id' => $attachment])->fetch();

	}

	// ================================================================================================================
	// widget
	// ================================================================================================================

	/**
	 * widget of the dashboard: active and paused projects with their progress
	 *
	 * @return array see \Core\Widget
	 */
	public static function widgetProjects():array
	{

		$recs = DB()->query("SELECT id, name, color, progress, status FROM xcore_project WHERE deleted = 'no' AND status IN ('active', 'paused') ORDER BY FIELD(status, 'active', 'paused'), date_end IS NULL, date_end, id LIMIT 8")->fetchAll();

		return ['type' => 'progress', 'empty' => ts('No project in progress'), 'items' => array_map(fn($r) => ['name' => $r['name'], 'link' => "project/?board={$r['id']}", 'color' => $r['color'] ?: '#4169e1', 'percent' => (int)$r['progress'], 'badge' => ['text' => ts($r['status']), 'color' => self::STATUS_COLORS[$r['status']] ?? 'grey']], $recs)];

	}

}
