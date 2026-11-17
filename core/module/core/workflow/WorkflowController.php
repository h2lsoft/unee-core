<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Core\JsonResponse;
use Core\Workflow;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * workflows of the modules (xcore_workflow, \Core\Workflow): one active workflow per module, bound to a column of its table
 * the grid and the form hold the binding, the editor (workflow/?editor=<id>, WorkflowEditor.tsx) the states and the transitions:
 * GET|PUT /api/workflow/{id}/definition/, POST /api/workflow/{id}/initialize/ (records without state)
 */
#[Route('/api/workflow', name: 'api.workflow.')]
class WorkflowController extends \Core\CrudController
{

	public string $table = 'xcore_workflow';
	public string $title = 'Workflows';
	public string $object_label = 'workflow';

	// colors of the states (badge colors of the grids)
	public const array COLORS = ['grey', 'blue', 'green', 'orange', 'red', 'purple', 'yellow', 'cyan'];

	public const int MAX_STATES = 30;
	public const int MAX_TRANSITIONS = 100;

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT p.name FROM xcore_plugin p WHERE p.id = xcore_workflow.xcore_plugin_id)", 'plugin');
		$grid->qSelect("(SELECT p.route_prefix_name FROM xcore_plugin p WHERE p.id = xcore_workflow.xcore_plugin_id)", 'module');
		$grid->qSelect("(SELECT COUNT(*) FROM xcore_workflow_state s WHERE s.xcore_workflow_id = xcore_workflow.id AND s.deleted = 'no')", 'states');
		$grid->qSelect("(SELECT COUNT(*) FROM xcore_workflow_transition t WHERE t.xcore_workflow_id = xcore_workflow.id AND t.deleted = 'no')", 'transitions');
		$grid->setSearch(['name', 'description'], 'Search name...');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->setMobile('name', 'plugin');
		$grid->duplicate(false);
		$grid->rowAction('editor', 'States and transitions', 'account_tree', 'edit', 'workflow/?editor={id}');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('name', 'Name', true)->subText('description');
		$grid->addCol('plugin', 'Module', true)->width(200);
		$grid->addCol('state_column', 'State column', true)->width(150)->code();
		$grid->addCol('states', 'States', true, 'text', ['align' => 'right'])->width(90);
		$grid->addCol('transitions', 'Transitions', true, 'text', ['align' => 'right'])->width(110);
		$grid->addColBoolean('active', 'Active', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addSelect('xcore_plugin_id', 'Module', self::plugins(), true)->autocomplete()->set('translate', false)->help('crud module whose records follow the workflow');
		$form->addText('state_column', 'State column', true)->maxLength(64)->defaultValue('state')->set('lowercase', true)->help('varchar column of the table of the module holding the state, declared by its migration');
		$form->addTextarea('description', 'Description', false, 3);
		$form->addBoolean('active', 'Active')->defaultValue('yes')->help('one active workflow per module');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$plugin_id = (int)$v->inputGet('xcore_plugin_id', $record['xcore_plugin_id'] ?? 0);
		$column = (string)$v->inputGet('state_column', $record['state_column'] ?? '');
		$active = (string)$v->inputGet('active', $record['active'] ?? 'yes');

		$module = (string)DB()->query("SELECT route_prefix_name FROM xcore_plugin WHERE id = :id AND deleted = 'no'", ['id' => $plugin_id])->fetchOne();
		[$table, $managed] = ($module !== '') ? Workflow::table($module) : ['', []];

		$v->input('xcore_plugin_id');
		if($table === '')
		{

			$v->addError(ts('`[FIELD]` must be a crud module'));
			return;

		}

		$v->input('state_column');
		$columns = Workflow::columns($table);
		if(!preg_match('/^[a-z_][a-z0-9_]*$/', $column) || !isset($columns[$column]))$v->addError(ts('`[FIELD]`: no column [COLUMN] in the table [TABLE]', ['COLUMN' => $column, 'TABLE' => $table]));
		elseif(in_array($column, $managed, true))$v->addError(ts('`[FIELD]`: the column [COLUMN] is set by the code of the module', ['COLUMN' => $column]));
		elseif(in_array($column, ['id', 'deleted', 'created_by', 'updated_by', 'deleted_by'], true))$v->addError(ts('`[FIELD]`: the column [COLUMN] can not hold a state', ['COLUMN' => $column]));

		// one active workflow per module
		$v->input('active');
		if($active === 'yes' && DB()->query("SELECT id FROM xcore_workflow WHERE xcore_plugin_id = :plugin AND active = 'yes' AND deleted = 'no' AND id != :id LIMIT 1", ['plugin' => $plugin_id, 'id' => (int)($record['id'] ?? 0)])->fetchOne())
			$v->addError(ts('this module already has an active workflow'));

	}

	// a workflow that changed records is kept for their history: deactivate it
	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		$module = (string)DB()->query("SELECT route_prefix_name FROM xcore_plugin WHERE id = :id", ['id' => (int)$record['xcore_plugin_id']])->fetchOne();
		if(DB()->query("SELECT id FROM xcore_workflow_history WHERE module = :module AND deleted = 'no' LIMIT 1", ['module' => $module])->fetchOne())
			$v->addError(ts('records went through this workflow, deactivate it instead'));

	}

	protected function onSaveAfterCreate(int $id, array $row):void
	{

		// a first state, initial
		DB('xcore_workflow_state')->insert(['xcore_workflow_id' => $id, 'code' => 'new', 'label' => 'New', 'color' => 'blue', 'initial' => 'yes', 'edit_xcore_group_ids' => '[]', 'x' => 160, 'y' => 160, 'position' => 1], $this->author());
		Workflow::reset();

	}

	protected function onSaveAfterEdit(int $id, array $row, array $changed):void
	{

		Workflow::reset();

	}

	/**
	 * states, transitions and what the editor needs (edit right)
	 *
	 * @param int $id
	 *
	 * @return JsonResponse {"workflow", "states", "transitions", "without_state", "groups", "columns", "templates", "jobs", "colors"}
	 */
	#[Route('/{id}/definition/', name: 'definition', methods: ['GET'])]
	public function apiDefinition(int $id):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$wf = $this->binding($id))return JsonResponse::error(404, 'record not found');

		return new JsonResponse($this->definitionData($wf));

	}

	/**
	 * saves the states and the transitions (edit right), json body {"states": [...], "transitions": [...]} as GET gives them
	 * a state that holds records is never removed nor renamed, every rule is checked before anything is written
	 *
	 * @param Request $request
	 * @param int $id
	 *
	 * @return JsonResponse the definition saved, 422 {"message", "errors": [...]}
	 */
	#[Route('/{id}/definition/', name: 'definition.save', methods: ['PUT'])]
	public function apiDefinitionSave(Request $request, int $id):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$wf = $this->binding($id))return JsonResponse::error(404, 'record not found');

		$states = array_values(array_filter((array)$request->request->all('states'), 'is_array'));
		$transitions = array_values(array_filter((array)$request->request->all('transitions'), 'is_array'));
		$errors = $this->check($wf, $states, $transitions);
		if($errors)return JsonResponse::error(422, $errors[0], ['errors' => $errors]);

		$ids = fn($list) => json_encode(array_values(array_unique(array_map('intval', (array)$list))));
		$words = fn($list) => json_encode(array_values(array_unique(array_map('strval', (array)$list))));

		DB()->beginTransaction();
		try
		{

			$this->sync('xcore_workflow_state', $id, array_map(fn($s, $i) => [
				'code' => $s['code'], 'label' => trim((string)$s['label']), 'color' => $s['color'],
				'initial' => !empty($s['initial']) ? 'yes' : 'no', 'final' => !empty($s['final']) ? 'yes' : 'no',
				'edit_xcore_group_ids' => $ids($s['edit_groups'] ?? []),
				'sla_hours' => max(0, (int)($s['sla_hours'] ?? 0)), 'sla_action' => (string)($s['sla_action'] ?? 'notify'), 'sla_xcore_group_id' => (int)($s['sla_group'] ?? 0), 'sla_transition' => (string)($s['sla_transition'] ?? ''),
				'x' => (int)($s['x'] ?? 0), 'y' => (int)($s['y'] ?? 0), 'position' => $i + 1,
			], $states, array_keys($states)));

			$this->sync('xcore_workflow_transition', $id, array_map(fn($t, $i) => [
				'code' => $t['code'], 'label' => trim((string)$t['label']), 'from_state' => (string)($t['from'] ?? ''), 'to_state' => (string)$t['to'],
				'xcore_group_ids' => $ids($t['groups'] ?? []), 'system' => !empty($t['system']) ? 'yes' : 'no',
				'required_fields' => $words($t['fields'] ?? []), 'comment_required' => !empty($t['comment']) ? 'yes' : 'no',
				'approvals' => max(1, min(10, (int)($t['approvals'] ?? 1))), 'effects' => json_encode(array_values((array)($t['effects'] ?? [])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				'bend' => (int)($t['bend'] ?? 0), 'position' => $i + 1,
			], $transitions, array_keys($transitions)));

			DB()->commit();

		}
		catch(\Throwable $e)
		{

			DB()->rollBack();
			throw $e;

		}

		Workflow::reset();
		\Core\Log::write('edit', 'workflow definition', ['states' => count($states), 'transitions' => count($transitions)], $id, 'info', $this->module);

		return new JsonResponse($this->definitionData($this->binding($id)));

	}

	/**
	 * records without state get one (edit right), json body {"state"}: written with a history row (system), deadline included
	 *
	 * @param Request $request
	 * @param int $id
	 *
	 * @return JsonResponse {"initialized"}
	 */
	#[Route('/{id}/initialize/', name: 'initialize', methods: ['POST'])]
	public function apiInitialize(Request $request, int $id):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$wf = $this->binding($id))return JsonResponse::error(404, 'record not found');

		$active = Workflow::get($wf['module']);
		if(!$active || $active['id'] !== $id)return JsonResponse::error(422, ts('activate the workflow first'));

		$state = (string)$request->request->get('state', '');
		if(!isset($active['states'][$state]))return JsonResponse::error(422, ts('unknown state'));

		$count = Workflow::initialize($active, $state);
		\Core\Log::write('workflow_initialize', $state, ['records' => $count], $id, 'info', $this->module);

		return new JsonResponse(['initialized' => $count]);

	}

	/**
	 * workflow with its module, table and column, null when not found
	 */
	private function binding(int $id):?array
	{

		$rec = DB()->query("SELECT w.*, p.route_prefix_name AS module, p.name AS plugin FROM xcore_workflow w LEFT JOIN xcore_plugin p ON p.id = w.xcore_plugin_id WHERE w.id = :id AND w.deleted = 'no'", ['id' => $id])->fetch();
		if(!$rec)return null;

		[$table, $managed] = ($rec['module']) ? Workflow::table((string)$rec['module']) : ['', []];

		return ['id' => (int)$rec['id'], 'name' => $rec['name'], 'module' => (string)$rec['module'], 'plugin' => (string)$rec['plugin'], 'table' => $table, 'state_column' => $rec['state_column'], 'managed' => $managed, 'active' => $rec['active'] === 'yes'];

	}

	private function definitionData(array $wf):array
	{

		$counts = [];
		$without = 0;
		$columns = [];
		if($wf['table'] !== '' && isset(Workflow::columns($wf['table'])[$wf['state_column']]))
		{

			foreach(DB()->query("SELECT `{$wf['state_column']}` AS state, COUNT(*) AS n FROM `{$wf['table']}` WHERE deleted = 'no' GROUP BY `{$wf['state_column']}`")->fetchAll() as $rec)
			{

				if((string)$rec['state'] === '')$without = (int)$rec['n'];
				else $counts[(string)$rec['state']] = (int)$rec['n'];

			}

			$locked = ['id', 'deleted', 'deleted_key', 'created_at', 'created_by', 'updated_at', 'updated_by', 'deleted_at', 'deleted_by', $wf['state_column']];
			$columns = array_values(array_diff(array_keys(Workflow::columns($wf['table'])), $locked));

		}

		$ids = fn($json) => array_values(array_map('intval', (array)json_decode((string)$json, true)));

		$states = [];
		foreach(DB()->query("SELECT * FROM xcore_workflow_state WHERE xcore_workflow_id = :id AND deleted = 'no' ORDER BY position, id", ['id' => $wf['id']])->fetchAll() as $s)
		{

			$states[] = ['code' => $s['code'], 'label' => $s['label'], 'color' => $s['color'], 'initial' => $s['initial'] === 'yes', 'final' => $s['final'] === 'yes', 'edit_groups' => $ids($s['edit_xcore_group_ids']),
				'sla_hours' => (int)$s['sla_hours'], 'sla_action' => $s['sla_action'], 'sla_group' => (int)$s['sla_xcore_group_id'], 'sla_transition' => (string)$s['sla_transition'],
				'x' => (int)$s['x'], 'y' => (int)$s['y'], 'count' => $counts[$s['code']] ?? 0];

		}

		$transitions = [];
		foreach(DB()->query("SELECT * FROM xcore_workflow_transition WHERE xcore_workflow_id = :id AND deleted = 'no' ORDER BY position, id", ['id' => $wf['id']])->fetchAll() as $t)
		{

			$transitions[] = ['code' => $t['code'], 'label' => $t['label'], 'from' => $t['from_state'], 'to' => $t['to_state'], 'groups' => $ids($t['xcore_group_ids']), 'system' => $t['system'] === 'yes',
				'fields' => array_values(array_map('strval', (array)json_decode((string)$t['required_fields'], true))), 'comment' => $t['comment_required'] === 'yes',
				'approvals' => (int)$t['approvals'], 'effects' => array_values((array)json_decode((string)$t['effects'], true)), 'bend' => (int)$t['bend']];

		}

		return [
			'workflow' => ['id' => $wf['id'], 'name' => $wf['name'], 'module' => $wf['module'], 'plugin' => $wf['plugin'], 'table' => $wf['table'], 'state_column' => $wf['state_column'], 'active' => $wf['active']],
			'states' => $states,
			'transitions' => $transitions,
			'without_state' => $without,
			'groups' => DB()->query("SELECT id, name FROM xcore_group WHERE deleted = 'no' AND id != 2 ORDER BY priority, name")->fetchAll(),
			'columns' => $columns,
			'templates' => DB()->query("SELECT name FROM xcore_mail_template WHERE deleted = 'no' ORDER BY name")->fetchAllOne(),
			'jobs' => array_values(array_diff(array_keys((array)\Core\Config::get('jobs', [])), ['workflow_effect'])),
			'colors' => self::COLORS,
		];

	}

	/**
	 * rules of a definition, every error listed (translated)
	 *
	 * @return array errors
	 */
	private function check(array $wf, array $states, array $transitions):array
	{

		$errors = [];
		if($wf['table'] === '')return [ts('the module of this workflow has no crud controller')];
		if(!$states)$errors[] = ts('a workflow needs at least one state');
		if(count($states) > self::MAX_STATES)$errors[] = ts('[MAX] states at most', ['MAX' => self::MAX_STATES]);
		if(count($transitions) > self::MAX_TRANSITIONS)$errors[] = ts('[MAX] transitions at most', ['MAX' => self::MAX_TRANSITIONS]);

		$groups = array_map('intval', DB()->query("SELECT id FROM xcore_group WHERE deleted = 'no'")->fetchAllOne());
		$columns = Workflow::columns($wf['table']);
		$codes = [];
		$initial = 0;
		foreach($states as $s)
		{

			$code = (string)($s['code'] ?? '');
			$name = ($code !== '') ? $code : '?';
			if(!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $code))$errors[] = ts('state [CODE]: the key holds lowercase letters, digits and _ (50 at most)', ['CODE' => $name]);
			elseif(isset($codes[$code]))$errors[] = ts('state [CODE]: key used twice', ['CODE' => $name]);
			$codes[$code] = true;

			$label = trim((string)($s['label'] ?? ''));
			if($label === '' || mb_strlen($label) > 100)$errors[] = ts('state [CODE]: a label of 100 characters at most', ['CODE' => $name]);
			if(!in_array($s['color'] ?? '', self::COLORS, true))$errors[] = ts('state [CODE]: unknown color', ['CODE' => $name]);
			if(!empty($s['initial']))$initial++;
			if(array_diff(array_map('intval', (array)($s['edit_groups'] ?? [])), $groups))$errors[] = ts('state [CODE]: unknown group', ['CODE' => $name]);

			$hours = (int)($s['sla_hours'] ?? 0);
			if($hours < 0 || $hours > 8760)$errors[] = ts('state [CODE]: the deadline is 0 to 8760 hours', ['CODE' => $name]);
			if($hours > 0)
			{

				$action = (string)($s['sla_action'] ?? '');
				if(!in_array($action, ['notify', 'remind', 'transition'], true))$errors[] = ts('state [CODE]: unknown deadline action', ['CODE' => $name]);
				if($action === 'notify' && (int)($s['sla_group'] ?? 0) && !in_array((int)$s['sla_group'], $groups, true))$errors[] = ts('state [CODE]: unknown group', ['CODE' => $name]);
				if($action === 'transition')
				{

					$t = array_values(array_filter($transitions, fn($t) => ($t['code'] ?? '') === ($s['sla_transition'] ?? '')))[0] ?? null;
					if(!$t || ($t['from'] ?? '') !== $code || empty($t['system']))$errors[] = ts('state [CODE]: the deadline runs a system transition from this state', ['CODE' => $name]);

				}

			}

		}
		if($initial > 1)$errors[] = ts('one initial state at most');

		// a state that holds records is kept (its records would have an unknown state)
		$data = $this->definitionData($wf);
		foreach($data['states'] as $old)
			if($old['count'] > 0 && !isset($codes[$old['code']]))$errors[] = ts('state [CODE]: [COUNT] records are in this state, move them first', ['CODE' => $old['code'], 'COUNT' => $old['count']]);

		$tcodes = [];
		$creation = 0;
		foreach($transitions as $t)
		{

			$code = (string)($t['code'] ?? '');
			$name = ($code !== '') ? $code : '?';
			if(!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $code))$errors[] = ts('transition [CODE]: the key holds lowercase letters, digits and _ (50 at most)', ['CODE' => $name]);
			elseif(isset($tcodes[$code]))$errors[] = ts('transition [CODE]: key used twice', ['CODE' => $name]);
			$tcodes[$code] = true;

			$label = trim((string)($t['label'] ?? ''));
			if($label === '' || mb_strlen($label) > 100)$errors[] = ts('transition [CODE]: a label of 100 characters at most', ['CODE' => $name]);

			$from = (string)($t['from'] ?? '');
			$to = (string)($t['to'] ?? '');
			if($from !== '' && !isset($codes[$from]))$errors[] = ts('transition [CODE]: unknown state [STATE]', ['CODE' => $name, 'STATE' => $from]);
			if(!isset($codes[$to]))$errors[] = ts('transition [CODE]: unknown state [STATE]', ['CODE' => $name, 'STATE' => $to]);
			if($from === $to)$errors[] = ts('transition [CODE]: the target state is the starting state', ['CODE' => $name]);
			if($from === '' && !empty($t['system']))$errors[] = ts('transition [CODE]: the creation is never a system transition', ['CODE' => $name]);
			if($from === '' && empty($t['system']))$creation++;

			if(array_diff(array_map('intval', (array)($t['groups'] ?? [])), $groups))$errors[] = ts('transition [CODE]: unknown group', ['CODE' => $name]);
			foreach((array)($t['fields'] ?? []) as $field)
				if(!isset($columns[$field]))$errors[] = ts('transition [CODE]: unknown column [COLUMN]', ['CODE' => $name, 'COLUMN' => (string)$field]);

			$approvals = (int)($t['approvals'] ?? 1);
			if($approvals < 1 || $approvals > 10)$errors[] = ts('transition [CODE]: 1 to 10 approvals', ['CODE' => $name]);

			$effects = (array)($t['effects'] ?? []);
			if(count($effects) > 20)$errors[] = ts('transition [CODE]: 20 effects at most', ['CODE' => $name]);
			foreach($effects as $e)
				if($error = Workflow::effectError($wf, (array)$e))$errors[] = ts('transition [CODE]: [ERROR]', ['CODE' => $name, 'ERROR' => $error]);

		}

		return array_values(array_unique($errors));

	}

	/**
	 * rows of a table of the workflow by code: existing ones updated, new ones added, missing ones soft deleted
	 */
	private function sync(string $table, int $workflow_id, array $rows):void
	{

		$current = [];
		foreach(DB()->query("SELECT id, code FROM `{$table}` WHERE xcore_workflow_id = :id AND deleted = 'no'", ['id' => $workflow_id])->fetchAll() as $rec)
			$current[$rec['code']] = (int)$rec['id'];

		foreach($rows as $row)
		{

			if(isset($current[$row['code']]))
			{

				DB($table)->update($row, $current[$row['code']], 1, $this->author());
				unset($current[$row['code']]);

			}
			else DB($table)->insert(['xcore_workflow_id' => $workflow_id] + $row, $this->author());

		}

		foreach($current as $id)
			DB($table)->delete($id, 1, $this->author());

	}

	/**
	 * crud modules (a crud controller, not custom), for the binding
	 *
	 * @return array [id => name]
	 */
	private static function plugins():array
	{

		$options = [];
		foreach(DB()->query("SELECT id, name, route_prefix_name FROM xcore_plugin WHERE deleted = 'no' AND type != 'url' AND custom = 'no' AND route_prefix_name != '' ORDER BY name")->fetchAll() as $p)
			if(Workflow::table((string)$p['route_prefix_name'])[0] !== '')$options[$p['id']] = ts($p['name'])." ({$p['route_prefix_name']})";

		return $options;

	}

}
