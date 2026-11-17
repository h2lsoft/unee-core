<?php

namespace Core;

/**
 * workflow of a module (xcore_workflow, one per module): states, transitions, history of each record, approvals
 * the state of a record is a column of the table of the module (state_column), changed by the transitions only
 * transition: from a state ('' = creation of the record) to another, fired by groups (empty = edit right, add right for the creation)
 * or by the code only (system), with required fields, a required comment, several approvals of different users
 * state: groups that edit the record in it (empty = locked), the superadmin always edits and fires
 * deadline of a state (sla_hours): due date kept on the history row of each entry, handled once by deadlines() (cron task Workflow deadlines)
 * effects of a transition (json array): "set" fills a column with the change of state, the others run in the background (job workflow_effect, retried):
 * {"type": "set", "field": "checked_at", "value": "@now|@today|@user|@user_id|@empty|text"}
 * {"type": "email", "template": "mail template name", "to": recipients}, {"type": "notify", "to": recipients, "title", "message", "level"}
 * {"type": "webhook", "url": "https://..."} (POST json, header X-Unee-Signature: sha256=hmac of the body with globals workflow.webhook.secret)
 * {"type": "job", "job": "type of config jobs", "payload": {}}
 * recipients: creator, actor (user who fired), followers (chatter), group:<id>, user:<id>, field:<column> (a user id or an email)
 * texts of notify (title, message) and emails: [[column]] of the record, [[id]], [[record]], [[transition]], [[from]], [[to]], [[comment]], [[author]], [[url]]
 * errors are \DomainException with the http code (403, 404, 409, 422)
 */
class Workflow
{

	private static array $cache = [];

	/**
	 * forgets the workflows read (after a change of their definition)
	 *
	 * @return void
	 */
	public static function reset():void
	{

		self::$cache = [];

	}

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
		[$table, $managed, $object_label] = self::table($module);
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
				'sla_hours' => (int)$rec['sla_hours'], 'sla_action' => $rec['sla_action'], 'sla_group' => (int)$rec['sla_xcore_group_id'], 'sla_transition' => (string)($rec['sla_transition'] ?? ''),
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

		return self::$cache[$module] = ['id' => (int)$wf['id'], 'name' => $wf['name'], 'module' => $module, 'table' => $table, 'state_column' => $wf['state_column'], 'managed' => $managed, 'object_label' => $object_label, 'states' => $states, 'transitions' => $transitions];

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

		$user_id = \Model\User::getUID();
		$history_id = self::write($wf, $record_id, $start['transition'], '', $start['state'], '', $user_id);

		// effects of the transition from the creation
		$t = $wf['transitions'][$start['transition']] ?? null;
		if(!$t)return;

		self::setFields($wf, $t, $record_id, $user_id);
		self::queue($wf, $t, $record_id, $history_id, '', '', $user_id);

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

			// changed only from the state read: also safe without transaction nor row lock (MyISAM), 409 when another change came first
			DB()->query("UPDATE `{$wf['table']}` SET `{$wf['state_column']}` = :state, updated_at = :now, updated_by = :author WHERE id = :id AND `{$wf['state_column']}` = :from", ['state' => $t['to'], 'now' => date('Y-m-d H:i:s'), 'author' => self::author(), 'id' => $record_id, 'from' => $from]);
			if((int)DB()->rowCount() !== 1)throw new \DomainException(ts('the record changed meanwhile, reload it'), 409);
			// the deadline of the state left is closed, the state entered gets its own
			DB()->query("UPDATE xcore_workflow_history SET sla_done_at = :now WHERE module = :module AND record_id = :record_id AND sla_due_at IS NOT NULL AND sla_done_at IS NULL", ['now' => date('Y-m-d H:i:s'), 'module' => $module, 'record_id' => $record_id]);
			$history_id = self::write($wf, $record_id, $code, $from, $t['to'], $comment, $user_id);

			// effects "set": with the change of state, in the same transaction
			self::setFields($wf, $t, $record_id, $user_id);

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
		self::queue($wf, $t, $record_id, $history_id, $from, $comment, $user_id);

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

	/**
	 * records without state (older than the workflow) get one, with a history row (system) and the deadline of the state
	 *
	 * @param array $wf
	 * @param string $state
	 *
	 * @return int records initialized
	 */
	public static function initialize(array $wf, string $state):int
	{

		if(!isset($wf['states'][$state]))throw new \DomainException(ts('unknown state'), 422);

		$column = $wf['state_column'];
		$ids = array_map('intval', DB()->query("SELECT id FROM `{$wf['table']}` WHERE deleted = 'no' AND (`{$column}` = '' OR `{$column}` IS NULL) ORDER BY id")->fetchAllOne());
		foreach(array_chunk($ids, 500) as $chunk)
		{

			DB()->beginTransaction();
			try
			{

				$list = implode(',', $chunk);
				DB()->query("UPDATE `{$wf['table']}` SET `{$column}` = :state WHERE id IN ({$list}) AND (`{$column}` = '' OR `{$column}` IS NULL)", ['state' => $state]);
				foreach($chunk as $id)
					self::write($wf, $id, '', '', $state, ts('initialization of the workflow'), 0);
				DB()->commit();

			}
			catch(\Throwable $e)
			{

				DB()->rollBack();
				throw $e;

			}

		}

		return count($ids);

	}

	// effects ************************************************************************************************************

	// columns never set by an effect
	private const array LOCKED_COLUMNS = ['id', 'deleted', 'deleted_key', 'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at', 'deleted_by'];

	private static array $columns = [];

	/**
	 * columns of a table: name => nullable
	 *
	 * @param string $table
	 *
	 * @return array
	 */
	public static function columns(string $table):array
	{

		if(!isset(self::$columns[$table]))
		{

			self::$columns[$table] = [];
			foreach(DB()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll() as $c)
				self::$columns[$table][$c['Field']] = ($c['Null'] === 'YES');

		}

		return self::$columns[$table];

	}

	/**
	 * error of an effect (editor of the workflows and before it runs), '' when valid
	 *
	 * @param array $wf workflow (table, state_column, managed)
	 * @param array $e
	 *
	 * @return string
	 */
	public static function effectError(array $wf, array $e):string
	{

		$to = (string)($e['to'] ?? '');
		$recipients = fn() => (preg_match('/^(creator|actor|followers|group:\d+|user:\d+|field:[a-z0-9_]+)$/', $to) && (!str_starts_with($to, 'field:') || isset(self::columns($wf['table'])[substr($to, 6)]))) ? '' : ts('invalid recipients: [TO]', ['TO' => $to]);

		return match((string)($e['type'] ?? ''))
		{
			'set' => (!isset(self::columns($wf['table'])[$e['field'] ?? '']) || in_array($e['field'], self::LOCKED_COLUMNS, true) || $e['field'] === $wf['state_column'] || in_array($e['field'], (array)($wf['managed'] ?? []), true)) ? ts('this column can not be set: [FIELD]', ['FIELD' => (string)($e['field'] ?? '')]) : '',
			'email' => (!DB()->query("SELECT id FROM xcore_mail_template WHERE name = :name AND deleted = 'no'", ['name' => (string)($e['template'] ?? '')])->fetchOne()) ? ts('unknown mail template: [NAME]', ['NAME' => (string)($e['template'] ?? '')]) : $recipients(),
			'notify' => (trim((string)($e['title'] ?? '')) === '') ? ts('the notification needs a title') : $recipients(),
			'webhook' => (!preg_match('#^https?://[^\s]+$#i', (string)($e['url'] ?? '')) || filter_var($e['url'], FILTER_VALIDATE_URL) === false) ? ts('invalid url') : '',
			'job' => (!isset(Config::get('jobs', [])[$e['job'] ?? '']) || ($e['job'] ?? '') === 'workflow_effect') ? ts('unknown job: [JOB]', ['JOB' => (string)($e['job'] ?? '')]) : '',
			default => ts('unknown effect'),
		};

	}

	/**
	 * effects "set" of a transition, with the change of state: columns of the record, invalid ones skipped (logged)
	 *
	 * @param array $wf
	 * @param array $t
	 * @param int $record_id
	 * @param int $user_id 0 = system
	 *
	 * @return void
	 */
	private static function setFields(array $wf, array $t, int $record_id, int $user_id):void
	{

		$row = [];
		foreach($t['effects'] as $e)
		{

			if(!is_array($e) || ($e['type'] ?? '') !== 'set')continue;
			if($error = self::effectError($wf, $e))
			{

				Log::write('workflow_effect', $error, ['transition' => $t['code'], 'effect' => $e], $record_id, 'warning', $wf['module']);
				continue;

			}

			$field = (string)$e['field'];
			$row[$field] = match((string)($e['value'] ?? ''))
			{
				'@now' => date('Y-m-d H:i:s'),
				'@today' => date('Y-m-d'),
				'@user' => self::userName($user_id),
				'@user_id' => $user_id,
				'@empty' => (self::columns($wf['table'])[$field]) ? null : '',
				default => (string)$e['value'],
			};

		}

		if($row)DB($wf['table'])->update($row, $record_id, 1, self::author());

	}

	/**
	 * the other effects of a transition, in the background (job workflow_effect, 5 attempts), the effect kept in the payload
	 *
	 * @return int jobs pushed
	 */
	private static function queue(array $wf, array $t, int $record_id, int $history_id, string $from, string $comment, int $user_id):int
	{

		$count = 0;
		foreach($t['effects'] as $e)
		{

			if(!is_array($e) || !in_array($e['type'] ?? '', ['email', 'notify', 'webhook', 'job'], true))continue;

			Jobber::push('workflow_effect', ['module' => $wf['module'], 'record_id' => $record_id, 'transition' => $t['code'], 'from' => $from, 'to' => $t['to'], 'comment' => $comment, 'user_id' => $user_id, 'history_id' => $history_id, 'effect' => $e], "{$wf['module']} #{$record_id}: {$t['code']} ({$e['type']})", 5, null, 5);
			$count++;

		}

		return $count;

	}

	/**
	 * runs an effect in the background (job workflow_effect): an exception makes the job retry
	 *
	 * @param array $p payload pushed by queue()
	 *
	 * @return array report of the job
	 */
	public static function runEffect(array $p):array
	{

		$wf = self::get((string)($p['module'] ?? '')) ?? throw new \RuntimeException('no workflow on the module '.($p['module'] ?? ''));
		$record = DB()->query("SELECT * FROM `{$wf['table']}` WHERE id = :id", ['id' => (int)($p['record_id'] ?? 0)])->fetch() ?: throw new \RuntimeException('record not found');
		$e = (array)($p['effect'] ?? []);
		if($error = self::effectError($wf, $e))throw new \InvalidArgumentException($error);

		$vars = self::vars($wf, $record, $p);

		if($e['type'] === 'email')
		{

			// one job send_email by recipient: each one is retried alone
			$jobs = 0;
			foreach(self::recipients((string)$e['to'], $wf, $record, $p) as $r)
			{

				Jobber::push('send_email', ['template' => (string)$e['template'], 'to' => $r['email'], 'vars' => ['firstname' => $r['firstname']] + $vars, 'locale' => $r['language'], 'options' => ['category' => 'transactional', 'module' => $wf['module'], 'action' => 'workflow', 'record_id' => (int)$record['id'], 'xcore_user_id' => $r['id'], 'application' => 'cron']], "workflow {$wf['module']} #{$record['id']}", 5);
				$jobs++;

			}

			return ['emails' => $jobs];

		}

		if($e['type'] === 'notify')
		{

			$ids = array_values(array_filter(array_column(self::recipients((string)$e['to'], $wf, $record, $p), 'id')));
			$level = in_array($e['level'] ?? '', ['info', 'success', 'warning', 'error'], true) ? $e['level'] : 'info';
			if($ids)Notification::send($ids, self::replace((string)$e['title'], $vars), self::replace((string)($e['message'] ?? ''), $vars), $vars['link'], $level, 'workflow');

			return ['notified' => count($ids)];

		}

		if($e['type'] === 'webhook')
		{

			$body = json_encode([
				'event' => "{$wf['module']}.{$p['transition']}",
				'module' => $wf['module'],
				'record_id' => (int)$record['id'],
				'transition' => (string)$p['transition'],
				'from' => (string)$p['from'],
				'to' => (string)$p['to'],
				'comment' => (string)$p['comment'],
				'user_id' => (int)$p['user_id'],
				'date' => date(DATE_ATOM),
				'record' => self::publicRecord($record),
			], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

			$headers = ['Content-Type: application/json', 'X-Unee-Event: '."{$wf['module']}.{$p['transition']}", 'X-Unee-Delivery: '.(int)($p['history_id'] ?? 0)];
			$secret = (string)Globals::get('workflow.webhook.secret', '', true, 'text', 'secret of the signature of the workflow webhooks (header X-Unee-Signature: sha256=hmac of the body), empty = not signed');
			if($secret !== '')$headers[] = 'X-Unee-Signature: sha256='.hash_hmac('sha256', $body, $secret);

			$ch = curl_init((string)$e['url']);
			curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'Unee', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false]);
			$response = curl_exec($ch);
			$code = ($response === false) ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			if($code < 200 || $code >= 300)throw new \RuntimeException("webhook {$e['url']}: ".(($response === false) ? curl_error($ch) : "http {$code}"));

			return ['webhook' => $code];

		}

		// job of a developer, with the context of the transition
		$id = Jobber::push((string)$e['job'], (array)($e['payload'] ?? []) + ['module' => $wf['module'], 'record_id' => (int)$record['id'], 'transition' => (string)$p['transition'], 'from' => (string)$p['from'], 'to' => (string)$p['to'], 'user_id' => (int)$p['user_id']], "workflow {$wf['module']} #{$record['id']}");

		return ['job' => $id];

	}

	/**
	 * active users (or emails of a column) of the recipients of an effect
	 *
	 * @return array [{"id", "email", "firstname", "language"}], id 0 for an email of a column
	 */
	private static function recipients(string $to, array $wf, array $record, array $p):array
	{

		$where = '';
		$binds = [];
		if($to === 'creator')
		{

			$where = 'login = :login';
			$binds['login'] = (string)($record['created_by'] ?? '');

		}
		elseif($to === 'actor')
		{

			$where = 'id = :id';
			$binds['id'] = (int)($p['user_id'] ?? 0);

		}
		elseif($to === 'followers')$where = "id IN (SELECT xcore_user_id FROM xcore_chatter_follower WHERE plugin = :plugin AND record_id = :record_id AND deleted = 'no')";
		elseif(str_starts_with($to, 'group:'))
		{

			$where = 'xcore_group_id = :group';
			$binds['group'] = (int)substr($to, 6);

		}
		elseif(str_starts_with($to, 'user:'))
		{

			$where = 'id = :id';
			$binds['id'] = (int)substr($to, 5);

		}
		elseif(str_starts_with($to, 'field:'))
		{

			$value = trim((string)($record[substr($to, 6)] ?? ''));
			if(filter_var($value, FILTER_VALIDATE_EMAIL))return [['id' => 0, 'email' => $value, 'firstname' => '', 'language' => '']];

			$where = 'id = :id';
			$binds['id'] = (int)$value;

		}

		if($where === '')return [];
		if($to === 'followers')$binds += ['plugin' => $wf['module'], 'record_id' => (int)$record['id']];

		return array_map(fn($u) => ['id' => (int)$u['id'], 'email' => (string)$u['email'], 'firstname' => (string)$u['firstname'], 'language' => (string)$u['language']],
			DB()->query("SELECT id, email, firstname, language FROM xcore_user WHERE deleted = 'no' AND active = 'yes' AND id > 1 AND ({$where})", $binds)->fetchAll());

	}

	/**
	 * variables of the texts of an effect: columns of the record (secrets left out) and context of the transition
	 */
	private static function vars(array $wf, array $record, array $p):array
	{

		$vars = [];
		foreach(self::publicRecord($record) as $key => $value)
			if(is_scalar($value) || $value === null)$vars[$key] = (string)$value;

		$link = "{$wf['module']}/?edit={$record['id']}";

		return [
			'id' => (string)$record['id'],
			'record' => self::recordLabel($wf, (int)$record['id']),
			'transition' => ts((string)($wf['transitions'][$p['transition']]['label'] ?? $p['transition'])),
			'from' => self::label($wf, (string)$p['from']),
			'to' => self::label($wf, (string)$p['to']),
			'comment' => (string)($p['comment'] ?? ''),
			'author' => self::userName((int)($p['user_id'] ?? 0)),
			'url' => rtrim((string)Config::get('url', ''), '/').'/@backend/'.$link,
			'link' => $link,
		] + $vars;

	}

	/**
	 * label of a record in the notifications, ex: Ticket #12
	 *
	 * @param array $wf
	 * @param int $record_id
	 *
	 * @return string
	 */
	public static function recordLabel(array $wf, int $record_id):string
	{

		return mb_strtoupper(mb_substr(ts($wf['object_label'] ?? 'record'), 0, 1)).mb_substr(ts($wf['object_label'] ?? 'record'), 1).' #'.$record_id;

	}

	// [[name]] of a plain text replaced by the variables
	private static function replace(string $text, array $vars):string
	{

		return preg_replace_callback('/\[\[([a-z0-9_]+)\]\]/i', fn($m) => $vars[$m[1]] ?? $m[0], $text);

	}

	// record without the columns named like a secret
	private static function publicRecord(array $record):array
	{

		return array_filter($record, fn($key) => !preg_match('/password|pwd|secret|token|deleted_key/i', (string)$key), ARRAY_FILTER_USE_KEY);

	}

	private static function userName(int $user_id):string
	{

		if($user_id <= 0)return ts('System');
		$u = DB()->query("SELECT firstname, lastname, login FROM xcore_user WHERE id = :id", ['id' => $user_id])->fetch();

		return ($u) ? (trim("{$u['firstname']} {$u['lastname']}") ?: (string)$u['login']) : ts('System');

	}

	private static function write(array $wf, int $record_id, string $code, string $from, string $to, string $comment, int $user_id):int
	{

		// deadline of the state entered
		$hours = (int)($wf['states'][$to]['sla_hours'] ?? 0);
		$due = ($hours > 0) ? date('Y-m-d H:i:s', time() + $hours * 3600) : null;

		return (int)DB('xcore_workflow_history')->insert(['module' => $wf['module'], 'record_id' => $record_id, 'transition' => $code, 'from_state' => $from, 'to_state' => $to, 'xcore_user_id' => $user_id, 'comment' => $comment, 'sla_due_at' => $due], self::author());

	}

	/**
	 * deadlines passed (cron task Workflow deadlines), each handled once: notify the group of the state (superadmins when none),
	 * remind the creator of the record (the group when he is not found), or fire the system transition of the state
	 * a record that left the state, was deleted, or a deadline removed since: closed without action
	 * a transition refused (ex: a required field empty) notifies the group of the state
	 *
	 * @param int $limit deadlines handled at most
	 *
	 * @return array {"notified", "reminded", "fired", "closed", "failed"}
	 */
	public static function deadlines(int $limit=500):array
	{

		$counts = ['notified' => 0, 'reminded' => 0, 'fired' => 0, 'closed' => 0, 'failed' => 0];
		$limit = max(1, min(5000, $limit));
		$recs = DB()->query("SELECT id, module, record_id, to_state FROM xcore_workflow_history
							 WHERE sla_done_at IS NULL AND sla_due_at IS NOT NULL AND sla_due_at <= :now AND deleted = 'no'
							 ORDER BY sla_due_at, id LIMIT {$limit}", ['now' => date('Y-m-d H:i:s')])->fetchAll();

		foreach($recs as $h)
		{

			$record_id = (int)$h['record_id'];
			// claims the deadline: false when another run handled it meanwhile
			$done = function() use($h):bool
			{

				DB()->query("UPDATE xcore_workflow_history SET sla_done_at = :now WHERE id = :id AND sla_done_at IS NULL", ['now' => date('Y-m-d H:i:s'), 'id' => (int)$h['id']]);
				return ((int)DB()->rowCount() === 1);

			};

			try
			{

				$wf = self::get((string)$h['module']);
				$state = $wf['states'][$h['to_state']] ?? null;
				$record = ($wf && $state) ? DB()->query("SELECT * FROM `{$wf['table']}` WHERE id = :id AND deleted = 'no'", ['id' => $record_id])->fetch() : false;

				if(!$record || self::state($wf, $record) !== $h['to_state'] || $state['sla_hours'] <= 0)
				{

					$done();
					$counts['closed']++;
					continue;

				}

				if(!$done())continue;
				$link = "{$wf['module']}/?edit={$record_id}";
				$title = ts('Deadline passed: [STATE]', ['STATE' => self::label($wf, $state['code'])]);
				$message = ts('[RECORD] is in the state [STATE] for [HOURS] h', ['RECORD' => self::recordLabel($wf, $record_id), 'STATE' => self::label($wf, $state['code']), 'HOURS' => $state['sla_hours']]);
				$group = ($state['sla_group'] > 0) ? $state['sla_group'] : 1;

				if($state['sla_action'] === 'transition')
				{

					try
					{

						self::fire($wf['module'], $record_id, $state['sla_transition'], ts('deadline passed'), true);
						$counts['fired']++;

					}
					catch(\DomainException $e)
					{

						Notification::sendGroup($group, ts('Deadline passed, transition refused: [STATE]', ['STATE' => self::label($wf, $state['code'])]), "{$message} - {$e->getMessage()}", $link, 'error', 'workflow');
						Log::write('workflow_deadline', $e->getMessage(), ['transition' => $state['sla_transition']], $record_id, 'warning', $wf['module'], 'system', 'cron');
						$counts['failed']++;

					}

					continue;

				}

				if($state['sla_action'] === 'remind')
				{

					$creator = (int)DB()->query("SELECT id FROM xcore_user WHERE login = :login AND deleted = 'no' AND active = 'yes' AND id > 1 LIMIT 1", ['login' => (string)($record['created_by'] ?? '')])->fetchOne();
					if($creator)
					{

						Notification::send($creator, $title, $message, $link, 'warning', 'workflow');
						$counts['reminded']++;
						continue;

					}

				}

				Notification::sendGroup($group, $title, $message, $link, 'warning', 'workflow');
				$counts['notified']++;

			}
			catch(\Throwable $e)
			{

				$done();
				Log::write('workflow_deadline', $e->getMessage(), ['history' => (int)$h['id']], $record_id, 'error', (string)$h['module'], 'system', 'cron');
				$counts['failed']++;

			}

		}

		return $counts;

	}

	/**
	 * table and columns set by the code of the crud controller of a module ($table, $managed_columns)
	 *
	 * @param string $module
	 *
	 * @return array [table, managed columns, object label], table '' when the module has no crud controller
	 */
	public static function table(string $module):array
	{

		foreach(Controller::getAll() as $path)
		{

			if(basename(dirname($path)) !== $module)continue;

			$class = Controller::getInfo($path)['class_path'];
			if(!class_exists($class) || !is_subclass_of($class, CrudController::class))continue;

			$defaults = (new \ReflectionClass($class))->getDefaultProperties();
			return [(string)($defaults['table'] ?? ''), (array)($defaults['managed_columns'] ?? []), (string)($defaults['object_label'] ?? 'record')];

		}

		return ['', [], 'record'];

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
