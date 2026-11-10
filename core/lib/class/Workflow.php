<?php

namespace Core;

/**
 * workflow of a module (xcore_workflow, one per module): states, transitions, history of each record, approvals
 * the state of a record is a column of the table of the module (state_column), changed by the transitions only
 * transition: from a state ('' = creation of the record) to another, fired by groups (empty = edit right, add right for the creation)
 * or by the code only (system), with required fields, a required comment, several approvals of different users
 * state: groups that edit the record in it (empty = locked), the superadmin always edits and fires
 * errors are \DomainException with the http code (403, 404, 409, 422)
 */
class Workflow
{

	private static array $cache = [];

	/**
	 * active workflow of a module with its states and transitions by code
	 *
	 * @param string $module route_prefix_name of the plugin (folder of the module)
	 *
	 * @return array|null {"id", "name", "module", "table", "state_column", "states": {code: {...}}, "transitions": {code: {...}}}
	 */
	public static function get(string $module):?array
	{

		if(array_key_exists($module, self::$cache))return self::$cache[$module];

		$wf = DB()->query("SELECT w.id, w.name, w.state_column FROM xcore_workflow w
						   INNER JOIN xcore_plugin p ON p.id = w.xcore_plugin_id AND p.deleted = 'no'
						   WHERE p.route_prefix_name = :module AND w.deleted = 'no' AND w.active = 'yes' ORDER BY w.id LIMIT 1", ['module' => $module])->fetch();
		if(!$wf)return self::$cache[$module] = null;

		if(!preg_match('/^[a-z_][a-z0-9_]*$/', (string)$wf['state_column']))throw new \LogicException("workflow #{$wf['id']}: invalid state column");
		[$table, $managed] = self::table($module);
		if($table === '')throw new \LogicException("workflow #{$wf['id']}: no crud controller for the module {$module}");
		if(in_array($wf['state_column'], $managed, true))throw new \LogicException("workflow #{$wf['id']}: the column {$wf['state_column']} of the module {$module} is set by its code");

		$ids = fn($json) => array_values(array_map('intval', (array)json_decode((string)$json, true)));
		$words = fn($json) => array_values(array_map('strval', (array)json_decode((string)$json, true)));

		$states = [];
		foreach(DB()->query("SELECT * FROM xcore_workflow_state WHERE xcore_workflow_id = :id AND deleted = 'no' ORDER BY position, id", ['id' => $wf['id']])->fetchAll() as $rec)
		{

			$states[$rec['code']] = [
				'code' => $rec['code'], 'label' => $rec['label'], 'color' => $rec['color'],
				'initial' => $rec['initial'] === 'yes', 'final' => $rec['final'] === 'yes',
				'edit_groups' => $ids($rec['edit_xcore_group_ids']),
				'sla_hours' => (int)$rec['sla_hours'], 'sla_action' => $rec['sla_action'], 'sla_group' => (int)$rec['sla_xcore_group_id'],
			];

		}

		$transitions = [];
		foreach(DB()->query("SELECT * FROM xcore_workflow_transition WHERE xcore_workflow_id = :id AND deleted = 'no' ORDER BY position, id", ['id' => $wf['id']])->fetchAll() as $rec)
		{

			$transitions[$rec['code']] = [
				'code' => $rec['code'], 'label' => $rec['label'], 'from' => $rec['from_state'], 'to' => $rec['to_state'],
				'groups' => $ids($rec['xcore_group_ids']), 'system' => $rec['system'] === 'yes',
				'fields' => $words($rec['required_fields']), 'comment' => $rec['comment_required'] === 'yes',
				'approvals' => max(1, (int)$rec['approvals']), 'effects' => (array)json_decode((string)$rec['effects'], true),
			];

		}

		return self::$cache[$module] = ['id' => (int)$wf['id'], 'name' => $wf['name'], 'module' => $module, 'table' => $table, 'state_column' => $wf['state_column'], 'states' => $states, 'transitions' => $transitions];

	}

	/**
	 * state of a record ('' = none, ex: a record older than the workflow)
	 *
	 * @param array $wf
	 * @param array $record
	 *
	 * @return string
	 */
	public static function state(array $wf, array $record):string
	{

		return (string)($record[$wf['state_column']] ?? '');

	}

	/**
	 * label of a state, translated
	 *
	 * @param array $wf
	 * @param string $code
	 *
	 * @return string
	 */
	public static function label(array $wf, string $code):string
	{

		if($code === '')return ts('New record');
		return ts($wf['states'][$code]['label'] ?? $code);

	}

	/**
	 * the logged user can fire the transition (the state of the record is not checked), never a system one
	 *
	 * @param array $wf
	 * @param array $transition
	 *
	 * @return bool
	 */
	public static function allowed(array $wf, array $transition):bool
	{

		if($transition['system'])return false;
		if(\Model\User::isSuperAdmin())return true;
		if($transition['groups'])return in_array(self::groupId(), $transition['groups'], true);

		return \Model\User::hasRight($wf['module'], ($transition['from'] === '') ? 'add' : 'edit');

	}

	/**
	 * the logged user can edit the record in its state (a record without state or in an unknown state is not locked)
	 *
	 * @param array $wf
	 * @param array $record
	 *
	 * @return bool
	 */
	public static function editable(array $wf, array $record):bool
	{

		if(\Model\User::isSuperAdmin())return true;

		$state = $wf['states'][self::state($wf, $record)] ?? null;
		return !$state || in_array(self::groupId(), $state['edit_groups'], true);

	}

	/**
	 * state of a new record: target of the transition from the creation the user can fire (system ones left out),
	 * else the initial state, else the first state
	 *
	 * @param array $wf
	 * @param array $row values of the new record
	 * @param array $labels column => label, for the message of the required fields
	 *
	 * @return array {"state", "transition"}
	 */
	public static function start(array $wf, array $row, array $labels=[]):array
	{

		$creations = array_filter($wf['transitions'], fn($t) => $t['from'] === '' && !$t['system']);
		if($creations)
		{

			$allowed = array_filter($creations, fn($t) => self::allowed($wf, $t));
			if(!$allowed)throw new \DomainException(ts('access denied'), 403);

			$t = reset($allowed);
			self::checkFields($t, $row, $labels);
			return ['state' => $t['to'], 'transition' => $t['code']];

		}

		$initial = array_filter($wf['states'], fn($s) => $s['initial']);
		$state = ($initial) ? reset($initial) : reset($wf['states']);

		return ['state' => ($state) ? $state['code'] : '', 'transition' => ''];

	}

	/**
	 * history of the creation of a record (after its insert)
	 *
	 * @param array $wf
	 * @param int $record_id
	 * @param array $start what start() returned
	 *
	 * @return void
	 */
	public static function created(array $wf, int $record_id, array $start):void
	{

		if($start['state'] === '')return;
		self::write($wf['module'], $record_id, $start['transition'], '', $start['state'], '', \Model\User::getUID());

	}

	/**
	 * fires a transition on a record: state checked under lock, fields and comment checked, approvals counted,
	 * state column updated, history written and logged
	 *
	 * @param string $module
	 * @param int $record_id
	 * @param string $code transition
	 * @param string $comment
	 * @param bool $system true = called by the code (job, cron): no right checked, system transitions allowed, no approval needed
	 * @param array $labels column => label, for the message of the required fields
	 *
	 * @return array {"done", "state", "approvals", "needed"}, done = false while approvals are missing
	 */
	public static function fire(string $module, int $record_id, string $code, string $comment='', bool $system=false, array $labels=[]):array
	{

		[$wf, $t] = self::check($module, $code, $comment, $system);
		$comment = trim($comment);

		$user_id = ($system) ? 0 : \Model\User::getUID();
		$approvals = 1;

		DB()->beginTransaction();
		try
		{

			// row locked until the commit: two users can not move the record at the same time
			$record = DB()->query("SELECT * FROM `{$wf['table']}` WHERE id = :id AND deleted = 'no' FOR UPDATE", ['id' => $record_id])->fetch();
			if(!$record)throw new \DomainException(ts('record not found'), 404);

			$from = self::state($wf, $record);
			if($from !== $t['from'])throw new \DomainException(ts('the record is now in the state [STATE], reload it', ['STATE' => self::label($wf, $from)]), 409);
			self::checkFields($t, $record, $labels);

			if($t['approvals'] > 1 && !$system)
			{

				$users = array_map('intval', DB()->query("SELECT xcore_user_id FROM xcore_workflow_approval WHERE module = :module AND record_id = :record_id AND transition = :code AND xcore_workflow_history_id = 0 AND deleted = 'no'", ['module' => $module, 'record_id' => $record_id, 'code' => $code])->fetchAllOne());
				if(in_array($user_id, $users, true))throw new \DomainException(ts('you already approved it, another user must approve it'), 409);

				DB('xcore_workflow_approval')->insert(['module' => $module, 'record_id' => $record_id, 'transition' => $code, 'xcore_user_id' => $user_id, 'comment' => $comment], self::author());
				$approvals = count($users) + 1;

				if($approvals < $t['approvals'])
				{

					DB()->commit();
					Log::write('workflow_approval', ts($t['label'])." ({$approvals}/{$t['approvals']})", [], $record_id, 'info', $module);
					return ['done' => false, 'state' => $from, 'approvals' => $approvals, 'needed' => $t['approvals']];

				}

			}

			DB()->query("UPDATE `{$wf['table']}` SET `{$wf['state_column']}` = :state, updated_at = :now, updated_by = :author WHERE id = :id", ['state' => $t['to'], 'now' => date('Y-m-d H:i:s'), 'author' => self::author(), 'id' => $record_id]);
			$history_id = self::write($module, $record_id, $code, $from, $t['to'], $comment, $user_id);

			// a change of state closes the pending approvals of the record (those of the other transitions too)
			DB()->query("UPDATE xcore_workflow_approval SET xcore_workflow_history_id = :history_id WHERE module = :module AND record_id = :record_id AND xcore_workflow_history_id = 0", ['history_id' => $history_id, 'module' => $module, 'record_id' => $record_id]);

			DB()->commit();

		}
		catch(\Throwable $e)
		{

			DB()->rollBack();
			throw $e;

		}

		Log::write('workflow', ts($t['label']).': '.self::label($wf, $from).' -> '.self::label($wf, $t['to']), [$wf['state_column'] => [$from, $t['to']]], $record_id, 'info', $module);
		return ['done' => true, 'state' => $t['to'], 'approvals' => $approvals, 'needed' => $t['approvals']];

	}

	/**
	 * checks of a transition that do not depend on the record: known, not from the creation, allowed, comment
	 *
	 * @param string $module
	 * @param string $code
	 * @param string $comment
	 * @param bool $system
	 *
	 * @return array [workflow, transition]
	 */
	public static function check(string $module, string $code, string $comment='', bool $system=false):array
	{

		$wf = self::get($module) ?? throw new \DomainException(ts('no workflow on this module'), 404);
		$t = $wf['transitions'][$code] ?? throw new \DomainException(ts('unknown transition'), 404);
		if($t['from'] === '')throw new \DomainException(ts('this transition is fired by the creation of a record'), 422);
		if(!$system && !self::allowed($wf, $t))throw new \DomainException(ts('access denied'), 403);
		if($t['comment'] && trim($comment) === '')throw new \DomainException(ts('a comment is required'), 422);

		return [$wf, $t];

	}

	/**
	 * transitions the logged user can fire, whatever the record (grid: row menu and selection menu by the state of the rows)
	 *
	 * @param array $wf
	 *
	 * @return array [{"code", "label", "from", "from_label", "to", "to_label", "comment", "fields", "approvals"}]
	 */
	public static function transitions(array $wf):array
	{

		$list = [];
		foreach($wf['transitions'] as $t)
		{

			if($t['from'] === '' || !self::allowed($wf, $t))continue;
			$list[] = ['code' => $t['code'], 'label' => ts($t['label']), 'from' => $t['from'], 'from_label' => self::label($wf, $t['from']), 'to' => $t['to'], 'to_label' => self::label($wf, $t['to']), 'comment' => $t['comment'], 'fields' => $t['fields'], 'approvals' => $t['approvals']];

		}

		return $list;

	}

	/**
	 * state colors of the workflow (badges of the react form)
	 *
	 * @param array $wf
	 *
	 * @return array code => {"label", "color"}
	 */
	public static function states(array $wf):array
	{

		return array_map(fn($s) => ['label' => ts($s['label']), 'color' => $s['color']], $wf['states']);

	}

	/**
	 * state of a record and the transitions the logged user can fire from it, with the approvals already given
	 *
	 * @param array $wf
	 * @param array $record
	 *
	 * @return array {"state": {"code", "label", "color", "final"}|null, "editable", "transitions": [{"code", "label", "to", "to_label", "comment", "fields", "approvals": {"needed", "given", "mine"}}]}
	 */
	public static function summary(array $wf, array $record):array
	{

		$from = self::state($wf, $record);
		$state = $wf['states'][$from] ?? null;

		$given = [];
		$names = [];
		foreach(DB()->query("SELECT a.transition, a.xcore_user_id, u.firstname, u.lastname, u.login FROM xcore_workflow_approval a LEFT JOIN xcore_user u ON u.id = a.xcore_user_id
							 WHERE a.module = :module AND a.record_id = :record_id AND a.xcore_workflow_history_id = 0 AND a.deleted = 'no' ORDER BY a.id", ['module' => $wf['module'], 'record_id' => (int)$record['id']])->fetchAll() as $rec)
		{

			$given[$rec['transition']][] = (int)$rec['xcore_user_id'];
			$names[$rec['transition']][] = trim("{$rec['firstname']} {$rec['lastname']}") ?: (string)$rec['login'];

		}

		$transitions = [];
		foreach($wf['transitions'] as $t)
		{

			if($t['from'] === '' || $t['from'] !== $from || !self::allowed($wf, $t))continue;

			$users = $given[$t['code']] ?? [];
			$transitions[] = [
				'code' => $t['code'], 'label' => ts($t['label']), 'to' => $t['to'], 'to_label' => self::label($wf, $t['to']),
				'comment' => $t['comment'], 'fields' => $t['fields'],
				'approvals' => ['needed' => $t['approvals'], 'given' => count($users), 'mine' => in_array(\Model\User::getUID(), $users, true), 'users' => $names[$t['code']] ?? []],
			];

		}

		$last = DB()->query("SELECT MAX(created_at) AS since, COUNT(*) AS n FROM xcore_workflow_history WHERE module = :module AND record_id = :record_id AND deleted = 'no'", ['module' => $wf['module'], 'record_id' => (int)$record['id']])->fetch();
		$groups = ($state && $state['edit_groups']) ? DB()->query("SELECT name FROM xcore_group WHERE id IN (".implode(',', $state['edit_groups']).") AND deleted = 'no' ORDER BY priority, name")->fetchAllOne() : [];

		return [
			'state' => ($state) ? ['code' => $state['code'], 'label' => ts($state['label']), 'color' => $state['color'], 'final' => $state['final']] : null,
			'since' => $last['since'] ?? null,
			'editable' => self::editable($wf, $record),
			'editable_by' => array_values($groups),
			'transitions' => $transitions,
			'states' => self::states($wf),
			'history_count' => (int)($last['n'] ?? 0),
		];

	}

	/**
	 * changes of state of a record, last first
	 *
	 * @param array $wf
	 * @param int $record_id
	 *
	 * @return array [{"date", "user", "avatar", "transition", "from", "from_state", "to", "to_state", "comment"}], user System for the code
	 */
	public static function history(array $wf, int $record_id):array
	{

		$list = [];
		foreach(DB()->query("SELECT h.created_at, h.transition, h.from_state, h.to_state, h.comment, h.xcore_user_id, u.firstname, u.lastname, u.login, u.avatar
							 FROM xcore_workflow_history h
							 LEFT JOIN xcore_user u ON u.id = h.xcore_user_id
							 WHERE h.module = :module AND h.record_id = :record_id AND h.deleted = 'no'
							 ORDER BY h.id DESC", ['module' => $wf['module'], 'record_id' => $record_id])->fetchAll() as $rec)
		{

			$user_id = (int)$rec['xcore_user_id'];
			$t = $wf['transitions'][$rec['transition']] ?? null;

			$list[] = [
				'date' => $rec['created_at'],
				'user' => ($user_id) ? (trim("{$rec['firstname']} {$rec['lastname']}") ?: (string)$rec['login']) : ts('System'),
				'avatar' => ($user_id) ? Widget::avatar(['id' => $user_id] + $rec)['avatar'] : null,
				'transition' => ($t) ? ts($t['label']) : (($rec['transition'] === '') ? ts('Creation') : $rec['transition']),
				'from' => self::label($wf, $rec['from_state']),
				'from_state' => $rec['from_state'],
				'to' => self::label($wf, $rec['to_state']),
				'to_state' => $rec['to_state'],
				'comment' => (string)$rec['comment'],
			];

		}

		return $list;

	}

	/**
	 * required fields of a transition filled (0 for a *_id, [] for a json list = empty)
	 *
	 * @param array $t
	 * @param array $values
	 * @param array $labels
	 *
	 * @return void
	 */
	private static function checkFields(array $t, array $values, array $labels):void
	{

		$missing = [];
		foreach($t['fields'] as $field)
		{

			$value = $values[$field] ?? null;
			$empty = is_array($value) ? !$value : (trim((string)$value) === '' || $value === '[]' || (str_ends_with($field, '_id') && (string)$value === '0'));
			if($empty)$missing[] = $labels[$field] ?? $field;

		}

		if($missing)throw new \DomainException(ts('fill first: [FIELDS]', ['FIELDS' => implode(', ', $missing)]), 422);

	}

	private static function write(string $module, int $record_id, string $code, string $from, string $to, string $comment, int $user_id):int
	{

		return (int)DB('xcore_workflow_history')->insert(['module' => $module, 'record_id' => $record_id, 'transition' => $code, 'from_state' => $from, 'to_state' => $to, 'xcore_user_id' => $user_id, 'comment' => $comment], self::author());

	}

	/**
	 * table and columns set by the code of the crud controller of a module ($table, $managed_columns)
	 *
	 * @param string $module
	 *
	 * @return array [table, managed columns], table '' when the module has no crud controller
	 */
	private static function table(string $module):array
	{

		foreach(Controller::getAll() as $path)
		{

			if(basename(dirname($path)) !== $module)continue;

			$class = Controller::getInfo($path)['class_path'];
			if(!class_exists($class) || !is_subclass_of($class, CrudController::class))continue;

			$defaults = (new \ReflectionClass($class))->getDefaultProperties();
			return [(string)($defaults['table'] ?? ''), (array)($defaults['managed_columns'] ?? [])];

		}

		return ['', []];

	}

	private static function groupId():int
	{

		return (int)Session::get('auth.group_id', 0);

	}

	private static function author():string
	{

		return (string)(Session::get('auth.login') ?: 'system');

	}

}
