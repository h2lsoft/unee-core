<?php

namespace Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\HttpFoundation\Request;

/**
 * json crud of a module for the react backend, the routes are inherited (@module = folder of the module)
 * rights (xcore_plugin.route_prefix_name = folder of the module): list, add, edit, delete
 *
 * a module declares $table, grid() and form(), then overrides the hooks it needs:
 * rulesOnSubmit, rulesOnCreate, rulesOnUpdate, rulesOnDelete, getRecord, onSaveBefore, onSaveAfterCreate, onSaveAfterEdit, onDeleteAfter
 */
abstract class CrudController extends Controller
{

	/**
	 * table with the soft delete columns
	 */
	public string $table = '';

	/**
	 * page title (plural) and record label (singular), english, translated
	 */
	public string $title = '';
	public string $object_label = 'record';

	/**
	 * columns, filters and sql of the list
	 *
	 * @param DataGrid $grid
	 *
	 * @return void
	 */
	abstract protected function grid(DataGrid $grid):void;

	/**
	 * fields of the form
	 *
	 * @param Form $form
	 * @param array $record current values, [] on create
	 *
	 * @return void
	 */
	abstract protected function form(Form $form, array $record):void;

	// routes **********************************************************************************************************

	/**
	 * list: definition of the grid and one page of rows (query string: see \Component\DataGrid)
	 *
	 * @param Request $request
	 *
	 * @return JsonResponse {"title", "label", "rights", "columns", "filters", "rows", "pagination", ...}
	 */
	#[\Core\Route('/api/@module/', name: 'api.@module.list')]
	public function apiList(Request $request):JsonResponse
	{

		if($denied = $this->deny('list'))return $denied;

		$grid = $this->buildGrid();
		return new JsonResponse(['title' => ts($this->title), 'label' => ts($this->object_label), 'rights' => \Model\User::getRights($this->module)] + $grid->render($request));

	}

	/**
	 * form of a new record
	 *
	 * @return JsonResponse {"form": {"fields"}, "record": {...defaults}}
	 */
	#[\Core\Route('/api/@module/form/', name: 'api.@module.form')]
	public function apiForm():JsonResponse
	{

		if($denied = $this->deny('add'))return $denied;

		$form = $this->buildForm([]);
		return new JsonResponse(['form' => $form->definition(), 'record' => (object)$form->defaults()]);

	}

	/**
	 * form of a record with its values (password fields are never sent)
	 *
	 * @param int $id
	 *
	 * @return JsonResponse {"form": {"fields"}, "record": {...}}
	 */
	#[\Core\Route('/api/@module/{id}/', name: 'api.@module.read')]
	public function apiRead(int $id):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');

		$form = $this->buildForm($record);
		$values = ['id' => $id];
		foreach($form->fields as $name => $field)
			$values[$name] = ($field['type'] === 'password') ? '' : ($record[$name] ?? '');

		return new JsonResponse(['form' => $form->definition(), 'record' => $this->getRecord($values, $record)]);

	}

	/**
	 * create, json body: the fields of the form
	 *
	 * @param Request $request
	 *
	 * @return JsonResponse 201 {"id"}, 422 {"fields"}
	 */
	#[\Core\Route('/api/@module/', methods: 'POST', name: 'api.@module.create')]
	public function apiCreate(Request $request):JsonResponse
	{

		if($denied = $this->deny('add'))return $denied;
		return $this->save($request, []);

	}

	/**
	 * update, json body: the fields of the form
	 *
	 * @param Request $request
	 * @param int $id
	 *
	 * @return JsonResponse {"id"}, 422 {"fields"}
	 */
	#[\Core\Route('/api/@module/{id}/', methods: 'PUT', name: 'api.@module.update')]
	public function apiUpdate(Request $request, int $id):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');

		return $this->save($request, $record);

	}

	/**
	 * soft delete of a record
	 *
	 * @param int $id
	 *
	 * @return JsonResponse 204, 422 when a rule refuses
	 */
	#[\Core\Route('/api/@module/{id}/', methods: 'DELETE', name: 'api.@module.delete')]
	public function apiDelete(int $id):JsonResponse
	{

		if($denied = $this->deny('delete'))return $denied;
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');

		if($error = $this->deleteRecord($record))return JsonResponse::error(422, $error);

		return new JsonResponse(null, 204);

	}

	/**
	 * soft delete of several records, json body: {"ids": [1, 2]}, all or nothing
	 *
	 * @param Request $request
	 *
	 * @return JsonResponse {"deleted": 2}, 422 with the first refusal
	 */
	#[\Core\Route('/api/@module/batch/delete/', methods: 'POST', name: 'api.@module.batch.delete')]
	public function apiBatchDelete(Request $request):JsonResponse
	{

		if($denied = $this->deny('delete'))return $denied;

		$ids = array_values(array_unique(array_filter(array_map('intval', (array)$request->request->all('ids')))));
		if(!$ids || count($ids) > 500)return JsonResponse::error(422, 'select between 1 and 500 records');

		$records = [];
		foreach($ids as $id)
		{

			if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');
			$records[] = $record;

		}

		DB()->beginTransaction();
		foreach($records as $record)
		{

			if($error = $this->deleteRecord($record))
			{

				DB()->rollBack();
				return JsonResponse::error(422, $error, ['id' => (int)$record['id']]);

			}

		}
		DB()->commit();

		return new JsonResponse(['deleted' => count($records)]);

	}

	/**
	 * form of the mass edit: the fields declared with $grid->batchEdit()
	 *
	 * @return JsonResponse {"form": {"fields"}}
	 */
	#[\Core\Route('/api/@module/batch/form/', name: 'api.@module.batch.form')]
	public function apiBatchForm():JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;

		$form = $this->batchForm();
		return new JsonResponse(['form' => $form->definition()]);

	}

	/**
	 * mass edit, json body: {"ids": [1, 2], "values": {"field": "value"}}, only the fields sent are changed, all or nothing
	 * form rules on the values, then rulesOnSubmit and rulesOnUpdate on each record with the new values (no save hooks)
	 *
	 * @param Request $request
	 *
	 * @return JsonResponse {"updated": 2}, 422 {"fields"}
	 */
	#[\Core\Route('/api/@module/batch/update/', methods: 'POST', name: 'api.@module.batch.update')]
	public function apiBatchUpdate(Request $request):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$records = $this->batchRecords($request))return JsonResponse::error(422, 'select between 1 and 500 records');

		$values = (array)$request->request->all('values');
		$form = $this->batchForm();
		$form->fields = array_intersect_key($form->fields, $values);
		if(!$form->fields)return JsonResponse::error(422, 'choose at least one field');

		// rules of the chosen fields on the values sent (the validator reads the body)
		$v = new Validator(App()->locale, $values);
		$v->addLocaleMessages(App()->locale, \Core\I18n::messages());
		$form->rules($v, $this->table, 0);
		if($v->fails())return JsonResponse::error(422, 'validation failed', ['fields' => $v->result()['error_stack_deep']]);

		// rules of the module on each record with the new values (ex: type and value of a global)
		$labels = array_map(fn($f) => ts($f['label']), $this->buildForm([])->fields);
		foreach($records as $record)
		{

			$vr = new Validator(App()->locale, array_merge($record, $values));
			$vr->addLocaleMessages(App()->locale, \Core\I18n::messages());
			$vr->setInputNames($labels);
			$this->rulesOnSubmit($vr, $record);
			$this->rulesOnUpdate($vr, $record);
			if($vr->fails())return JsonResponse::error(422, ts('record #[ID]: [MESSAGE]', ['ID' => $record['id'], 'MESSAGE' => $vr->result()['error_stack'][0]]), ['id' => (int)$record['id']]);

		}

		$row = $form->hydrate($values, true);

		DB()->beginTransaction();
		foreach($records as $record)
		{

			$changed = [];
			foreach($row as $key => $value)
			{

				if((string)($record[$key] ?? '') !== (string)$value)$changed[$key] = [$record[$key] ?? null, $value];

			}

			if(!$changed)continue;
			DB($this->table)->update($row, (int)$record['id'], 1, $this->author());
			\Core\Log::write('batch_edit', $this->object_label, $this->masked($changed), (int)$record['id'], 'info', $this->module);

		}
		DB()->commit();

		return new JsonResponse(['updated' => count($records)]);

	}

	/**
	 * switch of a boolean column declared with toggle in the grid, json body: {"column": "active", "value": "yes"}
	 *
	 * @param Request $request
	 * @param int $id
	 *
	 * @return JsonResponse {"id", "column", "value"}
	 */
	#[\Core\Route('/api/@module/{id}/toggle/', methods: 'PATCH', name: 'api.@module.toggle')]
	public function apiToggle(Request $request, int $id):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');

		$column = (string)$request->request->get('column');
		$value = (string)$request->request->get('value');
		$col = $this->buildGrid()->columns[$column] ?? null;

		if(!$col || $col['type'] !== 'boolean' || empty($col['toggle']))return JsonResponse::error(422, 'column not editable');
		if(!in_array($value, ['yes', 'no'], true))return JsonResponse::error(422, 'invalid value');

		DB($this->table)->update([$column => $value], $id, 1, $this->author());
		\Core\Log::write('toggle', "{$column} = {$value}", [$column => [$record[$column], $value]], $id, 'info', $this->module);

		return new JsonResponse(['id' => $id, 'column' => $column, 'value' => $value]);

	}

	/**
	 * action of the module declared with $grid->batchAction(), json body: {"ids": [1, 2]}
	 *
	 * @param Request $request
	 * @param string $action
	 *
	 * @return JsonResponse what batch<Action>() returns, ex: {"message"}
	 */
	#[\Core\Route('/api/@module/batch/{action}/', methods: 'POST', name: 'api.@module.batch.action')]
	public function apiBatchAction(Request $request, string $action):JsonResponse
	{

		$declared = $this->buildGrid()->batch_actions[$action] ?? null;
		$method = 'batch'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action)));
		if(!$declared || !method_exists($this, $method))return JsonResponse::error(404, 'action not found');

		if($denied = $this->deny($declared['right']))return $denied;
		if(!$records = $this->batchRecords($request))return JsonResponse::error(422, 'select between 1 and 500 records');

		return $this->$method($records);

	}

	// hooks ***********************************************************************************************************

	protected function rulesOnSubmit(Validator $v, array $record):void{}
	protected function rulesOnCreate(Validator $v):void{}
	protected function rulesOnUpdate(Validator $v, array $record):void{}

	/**
	 * refuse a deletion: add an error to the validator
	 */
	protected function rulesOnDelete(Validator $v, array $record):void{}

	/**
	 * values sent to the form of a record
	 *
	 * @param array $values values of the form fields and id
	 * @param array $record full record
	 *
	 * @return array
	 */
	protected function getRecord(array $values, array $record):array{return $values;}

	/**
	 * row written in the database, after the rules
	 *
	 * @param array $row declared fields only
	 * @param array $record current record, [] on create
	 *
	 * @return array
	 */
	protected function onSaveBefore(array $row, array $record):array{return $row;}
	protected function onSaveAfterCreate(int $id, array $row):void{}
	protected function onSaveAfterEdit(int $id, array $row, array $changed):void{}
	protected function onDeleteAfter(array $record):void{}

	// internals *******************************************************************************************************

	/**
	 * 403 response when the logged user has not the right, null otherwise
	 *
	 * @param string $action
	 *
	 * @return JsonResponse|null
	 */
	protected function deny(string $action):?JsonResponse
	{

		return (\Model\User::hasRight($this->module, $action)) ? null : JsonResponse::error(403, 'access denied');

	}

	protected function buildGrid():DataGrid
	{

		$grid = new DataGrid($this->table);
		$this->grid($grid);

		return $grid;

	}

	protected function buildForm(array $record):Form
	{

		$form = new Form();
		$this->form($form, $record);

		return $form;

	}

	/**
	 * mass edit form: fields of the form declared with $grid->batchEdit(), unique and readonly fields left out
	 *
	 * @return Form
	 */
	protected function batchForm():Form
	{

		$form = $this->buildForm([]);
		$names = array_flip($this->buildGrid()->batch_edit);
		$form->fields = array_filter(array_intersect_key($form->fields, $names), fn($f) => empty($f['unique']) && empty($f['readonly']) && $f['type'] !== 'password');

		return $form;

	}

	/**
	 * selected records of a batch request ({"ids"}), [] when the selection is invalid or a record is missing
	 *
	 * @param Request $request
	 *
	 * @return array
	 */
	protected function batchRecords(Request $request):array
	{

		$ids = array_values(array_unique(array_filter(array_map('intval', (array)$request->request->all('ids')))));
		if(!$ids || count($ids) > 500)return [];

		$records = [];
		foreach($ids as $id)
		{

			if(!$record = $this->findRecord($id))return [];
			$records[] = $record;

		}

		return $records;

	}

	protected function findRecord(int $id):array|false
	{

		return DB()->query("SELECT * FROM `{$this->table}` WHERE id = :id AND deleted = 'no'", ['id' => $id])->fetch();

	}

	protected function author():string
	{

		return (string)\Core\Session::get('auth.login');

	}

	/**
	 * create or update with the automatic rules of the form and the hooks
	 *
	 * @param Request $request
	 * @param array $record [] on create
	 *
	 * @return JsonResponse
	 */
	protected function save(Request $request, array $record):JsonResponse
	{

		$id = (int)($record['id'] ?? 0);
		$form = $this->buildForm($record);
		$v = $this->validator;

		$form->rules($v, $this->table, $id);
		$this->rulesOnSubmit($v, $record);
		($id) ? $this->rulesOnUpdate($v, $record) : $this->rulesOnCreate($v);

		if($v->fails())
			return JsonResponse::error(422, 'validation failed', ['fields' => $v->result()['error_stack_deep']]);

		$row = $this->onSaveBefore($form->hydrate($request->request->all(), $id > 0), $record);

		if(!$id)
		{

			$id = (int)DB($this->table)->insert($row, $this->author());
			\Core\Log::write('add', $this->object_label, $this->masked($row), $id, 'info', $this->module);
			$this->onSaveAfterCreate($id, $row);

			return new JsonResponse(['id' => $id], 201);

		}

		$changed = [];
		foreach($row as $key => $value)
		{

			if((string)($record[$key] ?? '') !== (string)$value)$changed[$key] = [$record[$key] ?? null, $value];

		}

		if($changed)
		{

			DB($this->table)->update($row, $id, 1, $this->author());
			\Core\Log::write('edit', $this->object_label, $this->masked($changed), $id, 'info', $this->module);

		}

		$this->onSaveAfterEdit($id, $row, $changed);

		return new JsonResponse(['id' => $id]);

	}

	/**
	 * soft delete with rulesOnDelete
	 *
	 * @param array $record
	 *
	 * @return string error message, '' when deleted
	 */
	protected function deleteRecord(array $record):string
	{

		$v = new Validator(App()->locale, []);
		$v->addLocaleMessages(App()->locale, \Core\I18n::messages());
		$this->rulesOnDelete($v, $record);

		if($v->fails())return (string)($v->result()['error_stack'][0] ?? 'deletion refused');

		DB($this->table)->delete((int)$record['id'], 1, $this->author());
		\Core\Log::write('delete', $this->object_label, [], (int)$record['id'], 'info', $this->module);
		$this->onDeleteAfter($record);

		return '';

	}

	/**
	 * values of the logs, columns named like a password are masked
	 *
	 * @param array $values
	 *
	 * @return array
	 */
	protected function masked(array $values):array
	{

		foreach($values as $key => $value)
		{

			if(str_contains((string)$key, 'password') || str_contains((string)$key, 'secret') || str_contains((string)$key, 'token'))
				$values[$key] = '********';

		}

		return $values;

	}

}
