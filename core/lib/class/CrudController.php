<?php

namespace Core;

use Symfony\Component\Routing\Attribute\Route;
use Component\DataGrid;
use Component\Form;
use Symfony\Component\HttpFoundation\Request;

/**
 * json crud of a module for the react backend, the routes are inherited and relative to the class prefix of the module:
 * #[Route('/api/globals', name: 'api.globals.')] gives /api/globals/{id}/ named api.globals.update
 * rights (xcore_plugin.route_prefix_name = folder of the module): list, add, edit, delete
 *
 * a module declares $table, grid() and form(), then overrides the hooks it needs:
 * rulesOnSubmit, rulesOnCreate, rulesOnUpdate, rulesOnDelete, getRecord, onSaveBefore, onSaveAfterCreate, onSaveAfterEdit, onDeleteAfter
 * a module with a workflow (\Core\Workflow): its state column changes by the transitions only, a record locked in its state is not edited
 * a field whose name starts with _ is virtual (filled by getRecord, read by the hooks): never written in the table
 */
abstract class CrudController extends Controller
{

	// rows of a csv export at most
	public const int EXPORT_MAX_ROWS = 50000;

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
	 * fields saved with an uppercase initial (html too: first letter of the text), [] for modules of keys (ex: globals)
	 */
	public array $ucfirst = ['category', 'title', 'name', 'description'];

	/**
	 * columns set by the code of the module (ex: a status moved by a cron): never bound to a workflow, read only in the Studio
	 */
	public array $managed_columns = [];

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
	#[Route('/', name: 'list', methods: ['GET'])]
	public function apiList(Request $request):JsonResponse
	{

		if($denied = $this->deny('list'))return $denied;

		$grid = $this->buildGrid();
		$workflow = Workflow::get($this->module);

		return new JsonResponse([
			'title' => ts($this->title), 'label' => ts($this->object_label), 'rights' => \Model\User::getRights($this->module), 'export' => $grid->export && \Model\User::hasRight($this->module, 'export'),
			// transitions of the user: row menu (state of the row) and selection menu
			'workflow' => ($workflow) ? ['column' => $workflow['state_column'], 'states' => Workflow::states($workflow), 'transitions' => Workflow::transitions($workflow)] : null,
		] + $grid->render($request));

	}

	/**
	 * csv of the grid with its search, filters and sort ($grid->allowExport() and right export), utf-8 with bom and ; for excel
	 *
	 * @param Request $request same query as the list
	 *
	 * @return \Symfony\Component\HttpFoundation\Response text/csv attachment
	 */
	#[Route('/export/', name: 'export', methods: ['GET'])]
	public function apiExport(Request $request):\Symfony\Component\HttpFoundation\Response
	{

		if($denied = $this->deny('export'))return $denied;

		$grid = $this->buildGrid();
		if(!$grid->export)return JsonResponse::error(404, 'export not allowed');

		// shortcut: rows held in memory up to EXPORT_MAX_ROWS, stream them if exports get bigger
		$data = $grid->render($request, self::EXPORT_MAX_ROWS);
		$columns = array_filter($data['columns'], fn($c) => !in_array($c['type'], ['position', 'icon', 'color'], true));

		$out = fopen('php://temp', 'r+');
		fwrite($out, "\xEF\xBB\xBF");
		fputcsv($out, array_map(fn($c) => $c['label'], $columns), ';', '"', '');
		foreach($data['rows'] as $row)
		{

			$line = [];
			foreach($columns as $c)
			{

				$value = $row[$c['name']] ?? '';
				if($c['type'] === 'boolean')$value = ($value === 'yes') ? ts('Yes') : ts('No');
				elseif(isset($c['labels']))$value = ((array)$c['labels'])[(string)$value] ?? $value;
				$value = is_scalar($value) ? (string)$value : '';

				// formula injection: a text starting like a formula is not run by the spreadsheet
				if($value !== '' && !is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true))$value = "'{$value}";
				$line[] = $value;

			}
			fputcsv($out, $line, ';', '"', '');

		}
		rewind($out);
		$csv = stream_get_contents($out);
		fclose($out);

		\Core\Log::write('export', count($data['rows']).' rows exported', [], 0, 'info', $this->module);

		$filename = $this->module.'-'.date('Y-m-d-His').'.csv';
		return new \Symfony\Component\HttpFoundation\Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"{$filename}\""]);

	}

	/**
	 * form of a new record
	 *
	 * @return JsonResponse {"form": {"fields"}, "record": {...defaults}}
	 */
	#[Route('/form/', name: 'form', methods: ['GET'])]
	public function apiForm():JsonResponse
	{

		if($denied = $this->deny('add'))return $denied;

		$form = $this->buildForm([]);
		return new JsonResponse(['form' => $this->definition($form, 0), 'record' => (object)$form->defaults()]);

	}

	/**
	 * form of a record with its values (password fields are never sent)
	 *
	 * @param int $id
	 *
	 * @return JsonResponse {"form": {"fields"}, "record": {...}}
	 */
	#[Route('/{id}/', name: 'read', methods: ['GET'])]
	public function apiRead(int $id):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');

		$form = $this->buildForm($record);
		$values = ['id' => $id];
		foreach($form->fields as $name => $field)
		{

			$values[$name] = match($field['type'])
			{
				'password' => '',
				'tags', 'multiselect' => (!empty($field['manager'])) ? \Core\Tag::get($field['manager'], $id) : ((is_array($words = json_decode((string)($record[$name] ?? ''), true))) ? array_values(array_map('strval', $words)) : []),
				default => $record[$name] ?? '',
			};

		}

		$payload = ['form' => $this->definition($form, $id), 'record' => $this->getRecord($values, $record)];
		if($workflow = Workflow::get($this->module))$payload['workflow'] = Workflow::summary($workflow, $record);
		if($this->versioning())$payload['versions'] = (int)DB()->query("SELECT COUNT(*) FROM xcore_versioning WHERE deleted = 'no' AND `table` = :table AND record_id = :id", ['table' => $this->table, 'id' => $id])->fetchOne();

		return new JsonResponse($payload);

	}

	/**
	 * upload of a file field (add or edit right), kept until the form is saved with the token as value
	 *
	 * @param Request $request multipart, field file
	 * @param string $field name of the file field
	 *
	 * @return JsonResponse {"token": "upload:<token>", "name", "size"}
	 */
	#[Route('/upload/{field}/', name: 'upload', methods: ['POST'], requirements: ['field' => '[a-z0-9_]+'])]
	public function apiUpload(Request $request, string $field):JsonResponse
	{

		if(!\Model\User::hasRight($this->module, 'add') && !\Model\User::hasRight($this->module, 'edit'))return JsonResponse::error(403, 'access denied');

		$f = $this->buildForm([])->fields[$field] ?? null;
		if(($f['type'] ?? '') !== 'file' || !empty($f['readonly']))return JsonResponse::error(404, 'field not found');

		$result = \Core\File::uploadStore($request->files->get('file'), $f['extensions'], (int)$f['max_size'], (array)($f['convert'] ?? []));
		if(is_string($result))return JsonResponse::error(422, $result);

		return new JsonResponse($result, 201);

	}

	/**
	 * file of a record (list right): images and pdf shown inline, the others downloaded
	 *
	 * @param int $id
	 * @param string $field name of the file field
	 *
	 * @return mixed
	 */
	#[Route('/{id}/file/{field}/', name: 'file', methods: ['GET'], requirements: ['field' => '[a-z0-9_]+'])]
	public function apiFile(int $id, string $field):mixed
	{

		if($denied = $this->deny('list'))return $denied;
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');
		if(($this->buildForm($record)->fields[$field]['type'] ?? '') !== 'file')return JsonResponse::error(404, 'field not found');

		return \Core\File::serve((string)($record[$field] ?? ''));

	}

	/**
	 * versions of a record (plugin with versioning), last first, each with its changes from the previous version
	 *
	 * @param int $id
	 *
	 * @return JsonResponse {"versions": [{"id", "date", "author", "created", "changes": [{"field", "label", "before", "after"}]}]}
	 */
	#[Route('/{id}/versions/', name: 'versions', methods: ['GET'])]
	public function apiVersions(int $id):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$this->versioning())return JsonResponse::error(404, 'versioning is not enabled');
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');

		$recs = DB()->query("SELECT v.id, v.date, v.data_json, v.created_by, u.firstname, u.lastname FROM xcore_versioning v LEFT JOIN xcore_user u ON u.id = v.xcore_user_id WHERE v.deleted = 'no' AND v.`table` = :table AND v.record_id = :id ORDER BY v.id", ['table' => $this->table, 'id' => $id])->fetchAll();
		$fields = $this->buildForm($record)->definition()['fields'];

		$versions = [];
		$previous = null;
		foreach($recs as $rec)
		{

			$data = (array)json_decode((string)$rec['data_json'], true);
			$changes = [];
			foreach($fields as $field)
			{

				$name = $field['name'];
				if($field['type'] === 'password' || !array_key_exists($name, $data))continue;

				$before = ($previous === null) ? null : ($previous[$name] ?? '');
				if($previous !== null && $this->versionText($field, $before) === $this->versionText($field, $data[$name]))continue;
				if($previous === null && $this->versionText($field, $data[$name]) === '')continue;

				$changes[] = ['field' => $name, 'label' => $field['label'], 'before' => ($previous === null) ? '' : $this->versionText($field, $before), 'after' => $this->versionText($field, $data[$name])];

			}

			$versions[] = ['id' => (int)$rec['id'], 'date' => date(DATE_ATOM, strtotime($rec['date'])), 'author' => trim("{$rec['firstname']} {$rec['lastname']}") ?: (string)$rec['created_by'], 'created' => ($previous === null), 'changes' => $changes];
			$previous = $data;

		}

		return new JsonResponse(['versions' => array_reverse($versions)]);

	}

	/**
	 * puts the values of a version back in the record (edit right), a new version is saved
	 *
	 * @param int $id
	 * @param int $version
	 *
	 * @return JsonResponse {"message"}
	 */
	#[Route('/{id}/versions/{version}/restore/', name: 'versions.restore', methods: ['POST'], requirements: ['version' => '\d+'])]
	public function apiVersionRestore(int $id, int $version):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$this->versioning())return JsonResponse::error(404, 'versioning is not enabled');
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');
		if($locked = $this->locked($record))return $locked;

		// rules of the module still apply (ex: a record locked by its status)
		$v = new Validator(App()->locale, $record);
		$v->addLocaleMessages(App()->locale, \Core\I18n::messages());
		$this->rulesOnUpdate($v, $record);
		if($v->fails())return JsonResponse::error(422, (string)($v->result()['error_stack'][0] ?? 'restore refused'));

		$json = DB()->query("SELECT data_json FROM xcore_versioning WHERE id = :version AND deleted = 'no' AND `table` = :table AND record_id = :id", ['version' => $version, 'table' => $this->table, 'id' => $id])->fetchOne();
		if(!$json)return JsonResponse::error(404, 'version not found');

		$data = (array)json_decode((string)$json, true);
		$form = $this->buildForm($record);
		$row = [];
		$changed = [];
		foreach($form->fields as $name => $field)
		{

			// masked values (password, secret, token) are never written back
			if(!array_key_exists($name, $data) || $field['type'] === 'password' || $field['type'] === 'file' || !empty($field['readonly']) || !empty($field['manager']) || $data[$name] === '********' || $name === $this->stateColumn() || str_starts_with($name, '_'))continue;

			$value = is_array($data[$name]) ? json_encode(array_values($data[$name]), JSON_UNESCAPED_UNICODE) : $data[$name];
			if((string)($record[$name] ?? '') === (string)$value)continue;

			$row[$name] = $value;
			$changed[$name] = [$record[$name] ?? null, $value];

		}

		if($form->tagManager() !== '' && isset($data['_tags']) && is_array($data['_tags']))
			\Core\Tag::set($form->tagManager(), $id, $data['_tags'], $this->author());

		if($row)DB($this->table)->update($row, $id, 1, $this->author());
		\Core\Log::write('restore', ts('version #[VERSION] restored', ['VERSION' => $version]), $this->masked($changed), $id, 'info', $this->module);
		$this->versionSave($id);

		return new JsonResponse(['message' => ts('Version restored')]);

	}

	/**
	 * create, json body: the fields of the form
	 *
	 * @param Request $request
	 *
	 * @return JsonResponse 201 {"id"}, 422 {"fields"}
	 */
	#[Route('/', name: 'create', methods: ['POST'])]
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
	#[Route('/{id}/', name: 'update', methods: ['PUT'])]
	public function apiUpdate(Request $request, int $id):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');
		if($locked = $this->locked($record))return $locked;

		return $this->save($request, $record);

	}

	/**
	 * soft delete of a record
	 *
	 * @param int $id
	 *
	 * @return JsonResponse 204, 422 when a rule refuses
	 */
	#[Route('/{id}/', name: 'delete', methods: ['DELETE'])]
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
	#[Route('/batch/delete/', name: 'batch.delete', methods: ['POST'])]
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
	#[Route('/batch/form/', name: 'batch.form', methods: ['GET'])]
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
	#[Route('/batch/update/', name: 'batch.update', methods: ['POST'])]
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

			if($locked = $this->locked($record))return $locked;

			$vr = new Validator(App()->locale, array_merge($record, $values));
			$vr->addLocaleMessages(App()->locale, \Core\I18n::messages());
			$vr->setInputNames($labels);
			$this->rulesOnSubmit($vr, $record);
			$this->rulesOnUpdate($vr, $record);
			if($vr->fails())return JsonResponse::error(422, ts('record #[ID]: [MESSAGE]', ['ID' => $record['id'], 'MESSAGE' => $vr->result()['error_stack'][0]]), ['id' => (int)$record['id']]);

		}

		$row = $this->ucfirst($form->hydrate($values, true));
		unset($row[$this->stateColumn()]);
		if(!$row)return JsonResponse::error(422, 'choose at least one field');

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
			$this->versionSave((int)$record['id']);

		}
		DB()->commit();

		return new JsonResponse(['updated' => count($records)]);

	}

	/**
	 * manual order of the grid (addColPosition), json body: {"ids": [3, 1, 2]} = the rows in their new order
	 * the rows keep their positions, given in the new order: the other pages are not moved
	 *
	 * @param Request $request
	 *
	 * @return JsonResponse {"updated": 3}
	 */
	#[Route('/batch/position/', name: 'batch.position', methods: ['POST'])]
	public function apiBatchPosition(Request $request):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;

		$column = $this->buildGrid()->position;
		if($column === '')return JsonResponse::error(404, 'action not found');
		if(!$records = $this->batchRecords($request))return JsonResponse::error(422, 'select between 1 and 500 records');

		$positions = array_map(fn($r) => (int)$r[$column], $records);
		sort($positions);

		// duplicates or holes: 1..n from the smallest
		if(count(array_unique($positions)) !== count($positions))$positions = range($positions[0], $positions[0] + count($positions) - 1);

		DB()->beginTransaction();
		foreach($records as $i => $record)
		{

			if((int)$record[$column] !== $positions[$i])
				DB($this->table)->update([$column => $positions[$i]], (int)$record['id'], 1, $this->author());

		}
		DB()->commit();
		\Core\Log::write('position', $this->object_label, array_column($records, 'id'), 0, 'info', $this->module);

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
	#[Route('/{id}/toggle/', name: 'toggle', methods: ['PATCH'])]
	public function apiToggle(Request $request, int $id):JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');

		if($locked = $this->locked($record))return $locked;

		$column = (string)$request->request->get('column');
		$value = (string)$request->request->get('value');
		$col = $this->buildGrid()->columns[$column] ?? null;

		if(!$col || $col['type'] !== 'boolean' || empty($col['toggle']))return JsonResponse::error(422, 'column not editable');
		if(!in_array($value, ['yes', 'no'], true))return JsonResponse::error(422, 'invalid value');

		// rules of the module on the record with the new value (same as the mass edit)
		$v = new Validator(App()->locale, array_merge($record, [$column => $value]));
		$v->addLocaleMessages(App()->locale, \Core\I18n::messages());
		$v->setInputNames(array_map(fn($f) => ts($f['label']), $this->buildForm($record)->fields));
		$this->rulesOnSubmit($v, $record);
		$this->rulesOnUpdate($v, $record);
		if($v->fails())return JsonResponse::error(422, (string)$v->result()['error_stack'][0]);

		DB($this->table)->update([$column => $value], $id, 1, $this->author());
		\Core\Log::write('toggle', "{$column} = {$value}", [$column => [$record[$column], $value]], $id, 'info', $this->module);
		$this->versionSave($id);

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
	#[Route('/batch/{action}/', name: 'batch.action', methods: ['POST'], priority: -1)]
	public function apiBatchAction(Request $request, string $action):JsonResponse
	{

		$declared = $this->buildGrid()->batch_actions[$action] ?? null;
		$method = 'batch'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action)));
		if(!$declared || !method_exists($this, $method))return JsonResponse::error(404, 'action not found');

		if($denied = $this->deny($declared['right']))return $denied;
		if(!$records = $this->batchRecords($request))return JsonResponse::error(422, 'select between 1 and 500 records');

		return $this->$method($records);

	}

	/**
	 * action of a record declared with $grid->rowAction() (without url) or $form->addAction(), calls row<Action>(array $record, Request $request)
	 * a form action receives the values of the form in the body (not saved)
	 *
	 * @param Request $request
	 * @param int $id
	 * @param string $action
	 *
	 * @return JsonResponse what row<Action>() returns, ex: {"message"} or {"redirect": "/backend/"} (full page reload)
	 */
	#[Route('/{id}/action/{action}/', name: 'row.action', methods: ['POST'], requirements: ['action' => '[a-z0-9]+(-[a-z0-9]+)*'])]
	public function apiRowAction(Request $request, int $id, string $action):JsonResponse
	{

		$method = 'row'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action)));
		if(!method_exists($this, $method))return JsonResponse::error(404, 'action not found');
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');

		$declared = $this->buildGrid()->row_actions[$action] ?? $this->buildForm($record)->actions[$action] ?? null;
		if(!$declared || ($declared['url'] ?? '') !== '')return JsonResponse::error(404, 'action not found');
		if($denied = $this->deny($declared['right']))return $denied;

		return $this->$method($record, $request);

	}

	/**
	 * read only view of a record ($grid->setDetail()), list right
	 *
	 * @param int $id
	 *
	 * @return JsonResponse {"title", "fields": [{"label", "value", "type"}]}, type text, badge, code (json, html)
	 */
	#[Route('/{id}/detail/', name: 'detail', methods: ['GET'])]
	public function apiDetail(int $id):JsonResponse
	{

		if(!$this->buildGrid()->detail)return JsonResponse::error(404, 'action not found');
		if($denied = $this->deny('list'))return $denied;
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');

		return new JsonResponse($this->detail($record));

	}

	/**
	 * fires a transition on a selection (list right, groups of the transition), json body: {"ids", "comment"}
	 * each record on its own: those in another state or refused are reported, the others move or get the approval of the user
	 *
	 * @param Request $request
	 * @param string $transition code of the transition
	 *
	 * @return JsonResponse {"done", "approved", "errors": [{"id", "message"}]}, 4xx when the transition is refused for every record
	 */
	#[Route('/batch/workflow/{transition}/', name: 'batch.workflow', methods: ['POST'], requirements: ['transition' => '[a-z0-9_]+'])]
	public function apiBatchWorkflow(Request $request, string $transition):JsonResponse
	{

		if($denied = $this->deny('list'))return $denied;

		$ids = array_values(array_unique(array_filter(array_map('intval', (array)$request->request->all('ids')))));
		if(!$ids || count($ids) > 500)return JsonResponse::error(422, 'select between 1 and 500 records');

		$comment = (string)$request->request->get('comment', '');
		try
		{

			Workflow::check($this->module, $transition, $comment);

		}
		catch(\DomainException $e)
		{

			return JsonResponse::error($e->getCode() ?: 422, $e->getMessage());

		}

		$labels = $this->labels($this->buildForm([]));
		$result = ['done' => 0, 'approved' => 0, 'errors' => []];
		foreach($ids as $id)
		{

			try
			{

				$fired = Workflow::fire($this->module, $id, $transition, $comment, false, $labels);
				if($fired['done'])$this->versionSave($id);
				$result[($fired['done']) ? 'done' : 'approved']++;

			}
			catch(\DomainException $e)
			{

				$result['errors'][] = ['id' => $id, 'message' => $e->getMessage()];

			}

		}

		return new JsonResponse($result);

	}

	/**
	 * workflow of a record (list right): its state, the transitions the logged user can fire, the history
	 *
	 * @param int $id
	 *
	 * @return JsonResponse {"state", "editable", "transitions", "history"}, 404 when the module has no workflow
	 */
	#[Route('/{id}/workflow/', name: 'workflow', methods: ['GET'])]
	public function apiWorkflow(int $id):JsonResponse
	{

		if($denied = $this->deny('list'))return $denied;
		if(!$workflow = Workflow::get($this->module))return JsonResponse::error(404, 'no workflow on this module');
		if(!$record = $this->findRecord($id))return JsonResponse::error(404, 'record not found');

		return new JsonResponse(Workflow::summary($workflow, $record) + ['history' => Workflow::history($workflow, $id)]);

	}

	/**
	 * fires a transition of the workflow on a record (list right, groups of the transition), json body: {"comment"}
	 * a transition needing several approvals counts the one of the user until the number is reached
	 *
	 * @param Request $request
	 * @param int $id
	 * @param string $transition code of the transition
	 *
	 * @return JsonResponse {"done", "state", "approvals", "needed"}, 403 not allowed, 409 state changed or already approved, 422 comment or fields missing
	 */
	#[Route('/{id}/workflow/{transition}/', name: 'workflow.fire', methods: ['POST'], requirements: ['transition' => '[a-z0-9_]+'])]
	public function apiWorkflowFire(Request $request, int $id, string $transition):JsonResponse
	{

		if($denied = $this->deny('list'))return $denied;

		try
		{

			$result = Workflow::fire($this->module, $id, $transition, (string)$request->request->get('comment', ''), false, $this->labels($this->buildForm([])));

		}
		catch(\DomainException $e)
		{

			return JsonResponse::error($e->getCode() ?: 422, $e->getMessage());

		}

		if($result['done'])$this->versionSave($id);
		return new JsonResponse($result);

	}

	/**
	 * button of the grid declared with $grid->gridAction(), calls action<Action>(Request $request)
	 *
	 * @param Request $request
	 * @param string $action
	 *
	 * @return JsonResponse what action<Action>() returns, ex: {"message"}
	 */
	#[Route('/action/{action}/', name: 'grid.action', methods: ['POST'], requirements: ['action' => '[a-z0-9]+(-[a-z0-9]+)*'])]
	public function apiGridAction(Request $request, string $action):JsonResponse
	{

		$declared = $this->buildGrid()->grid_actions[$action] ?? null;
		$method = 'action'.str_replace(' ', '', ucwords(str_replace('-', ' ', $action)));
		if(!$declared || $declared['url'] !== '' || $declared['form'] !== '' || !method_exists($this, $method))return JsonResponse::error(404, 'action not found');

		if($denied = $this->deny($declared['right']))return $denied;

		return $this->$method($request);

	}

	/**
	 * OpenAPI details of the crud routes (\Core\OpenApi), built from the grid and the form
	 *
	 * @param string $method name of the controller method
	 *
	 * @return array parameters, requestBody, response (schema of the 200 answer)
	 */
	public function apiDoc(string $method):array
	{

		$grid = $this->buildGrid();
		$grid_doc = $grid->apiDoc();
		$form = $this->buildForm([]);
		$ids = ['type' => 'object', 'required' => ['ids'], 'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => 500]]];
		$form_answer = ['type' => 'object', 'properties' => ['form' => ['type' => 'object'], 'record' => $form->schema(false)]];

		return match($method)
		{
			'apiList' => ['parameters' => $grid_doc['parameters'], 'response' => ['type' => 'object', 'properties' => [
				'title' => ['type' => 'string'],
				'rights' => ['type' => 'array', 'items' => ['type' => 'string']],
				'rows' => ['type' => 'array', 'items' => $grid_doc['row']],
				'pagination' => ['type' => 'object', 'properties' => ['total' => ['type' => 'integer'], 'page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'last_page' => ['type' => 'integer']]],
			]]],
			'apiForm', 'apiRead' => ['response' => $form_answer],
			'apiCreate' => ['requestBody' => $form->schema(), 'status' => 201, 'response' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
			'apiUpdate' => ['requestBody' => $form->schema(), 'response' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
			'apiDelete' => ['status' => 204],
			'apiBatchDelete' => ['requestBody' => $ids, 'response' => ['type' => 'object', 'properties' => ['deleted' => ['type' => 'integer']]]],
			'apiBatchForm' => ['response' => ['type' => 'object', 'properties' => ['form' => ['type' => 'object']]]],
			'apiBatchUpdate' => ['requestBody' => ['type' => 'object', 'required' => ['ids', 'values'], 'properties' => $ids['properties'] + ['values' => $this->batchForm()->schema(false)]], 'response' => ['type' => 'object', 'properties' => ['updated' => ['type' => 'integer']]]],
			'apiToggle' => ['requestBody' => ['type' => 'object', 'required' => ['column', 'value'], 'properties' => [
				'column' => ['type' => 'string', 'enum' => array_keys(array_filter($grid->columns, fn($c) => $c['type'] === 'boolean' && !empty($c['toggle'])))],
				'value' => ['type' => 'string', 'enum' => ['yes', 'no']],
			]]],
			'apiBatchPosition' => ($grid->position === '') ? ['skip' => true] : ['requestBody' => $ids, 'response' => ['type' => 'object', 'properties' => ['updated' => ['type' => 'integer']]]],
			'apiBatchAction' => ($grid->batch_actions) ? ['requestBody' => $ids, 'path' => ['action' => ['type' => 'string', 'enum' => array_keys($grid->batch_actions)]]] : ['skip' => true],
			'apiRowAction' => ($row_actions = array_merge(array_keys(array_filter($grid->row_actions, fn($a) => $a['url'] === '')), array_keys($form->actions))) ? ['path' => ['action' => ['type' => 'string', 'enum' => $row_actions]]] : ['skip' => true],
			'apiDetail' => ($grid->detail) ? [] : ['skip' => true],
			'apiGridAction' => ($grid->grid_actions) ? ['path' => ['action' => ['type' => 'string', 'enum' => array_keys($grid->grid_actions)]]] : ['skip' => true],
			'apiVersions', 'apiVersionRestore' => ($this->versioning()) ? [] : ['skip' => true],
			'apiWorkflow' => (Workflow::get($this->module)) ? [] : ['skip' => true],
			'apiBatchWorkflow' => ($workflow = Workflow::get($this->module)) ? ['path' => ['transition' => ['type' => 'string', 'enum' => array_keys(array_filter($workflow['transitions'], fn($t) => $t['from'] !== '' && !$t['system']))]], 'requestBody' => ['type' => 'object', 'required' => ['ids'], 'properties' => $ids['properties'] + ['comment' => ['type' => 'string']]], 'response' => ['type' => 'object', 'properties' => ['done' => ['type' => 'integer'], 'approved' => ['type' => 'integer'], 'errors' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'message' => ['type' => 'string']]]]]]] : ['skip' => true],
			'apiWorkflowFire' => ($workflow = Workflow::get($this->module)) ? ['path' => ['transition' => ['type' => 'string', 'enum' => array_keys(array_filter($workflow['transitions'], fn($t) => $t['from'] !== '' && !$t['system']))]], 'requestBody' => ['type' => 'object', 'properties' => ['comment' => ['type' => 'string']]], 'response' => ['type' => 'object', 'properties' => ['done' => ['type' => 'boolean'], 'state' => ['type' => 'string'], 'approvals' => ['type' => 'integer'], 'needed' => ['type' => 'integer']]]] : ['skip' => true],
			default => [],
		};

	}

	/**
	 * read only view of a record, ex: ['title' => 'Log #12', 'fields' => [['label' => 'Date', 'value' => '...'], ['label' => 'Values', 'value' => '{}', 'type' => 'code', 'language' => 'json', 'wide' => true]]]
	 *
	 * @param array $record
	 *
	 * @return array
	 */
	protected function detail(array $record):array
	{

		$fields = [];
		foreach($this->buildForm($record)->fields as $name => $f)
			if($f['type'] !== 'password')$fields[] = ['label' => ts($f['label']), 'value' => (string)($record[$name] ?? '')];

		return ['title' => ts($this->object_label).' #'.$record['id'], 'fields' => $fields];

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

	/**
	 * uppercase initial of the fields of $ucfirst, leading spaces and html tags skipped
	 *
	 * @param array $row
	 *
	 * @return array
	 */
	protected function ucfirst(array $row):array
	{

		foreach(array_intersect_key($row, array_flip($this->ucfirst)) as $name => $value)
		{

			if(is_string($value))
				$row[$name] = preg_replace_callback('/^((?:\s|<[^>]*>)*)(\p{Ll})/u', fn($m) => $m[1].mb_strtoupper($m[2]), $value);

		}

		return $row;

	}

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

	/**
	 * json definition of a form, with the urls of its file fields
	 *
	 * @param Form $form
	 * @param int $id 0 on create
	 *
	 * @return array
	 */
	protected function definition(Form $form, int $id):array
	{

		$definition = $form->definition();
		foreach($definition['fields'] as &$field)
		{

			if($field['type'] !== 'file')continue;
			$field['upload_url'] = "{$this->module}/upload/{$field['name']}/";
			if(!empty($field['public']))$field['public_url'] = \Core\File::url();
			elseif($id)$field['file_url'] = "/api/{$this->module}/{$id}/file/{$field['name']}/";

		}
		unset($field);

		return $definition;

	}

	protected function buildGrid():DataGrid
	{

		$grid = new DataGrid($this->table);
		$grid->module = $this->module;
		$this->grid($grid);

		// workflow: the state column as a badge of the states, chips by state when the grid has none
		$workflow = Workflow::get($this->module);
		if($workflow && $workflow['states'])
		{

			$column = $workflow['state_column'];
			$labels = array_map(fn($s) => $s['label'], $workflow['states']);
			$colors = array_map(fn($s) => $s['color'], $workflow['states']);
			if(isset($grid->columns[$column]))$grid->columns[$column] = array_merge($grid->columns[$column], ['type' => 'badge', 'colors' => (object)$colors, 'labels' => $labels]);
			else $grid->addColBadge($column, 'State', true, $colors)->labels($labels);
			if(!$grid->chips)$grid->addFilterChips($column, $labels)->chipColors($colors);

		}

		$this->configurationAction($grid);

		return $grid;

	}

	/**
	 * global search (GET /api/backend/search/): rows of the grid matching its search box (search, where, hooks of the grid), title and subtitle of its mobile view
	 *
	 * @param string $q
	 * @param int $limit rows returned (10 at most)
	 *
	 * @return array|null {total, rows: [{id, title, sub}]}, null when the grid has no search box
	 */
	public function searchRows(string $q, int $limit=3):?array
	{

		$grid = $this->buildGrid();
		if(!$grid->search_columns)return null;

		$data = $grid->render(new Request(['search' => ["q||{$q}"], 'per_page' => (string)$grid->per_page_options[0]]));

		// title: column of the mobile view, else the first text column
		$title = $grid->mobile['title'];
		if($title === '')
			foreach($grid->columns as $name => $column)
				if($name !== 'id' && ($column['type'] ?? '') === 'text'){ $title = $name; break; }

		$rows = [];
		foreach(array_slice($data['rows'], 0, min($limit, 10)) as $row)
		{

			$text = fn(string $col):string => (is_scalar($row[$col] ?? null)) ? mb_substr(trim(strip_tags((string)$row[$col])), 0, 120) : '';
			$sub = ($grid->mobile['subtitle'] !== '') ? $text($grid->mobile['subtitle']) : '';
			$rows[] = ['id' => (int)$row['id'], 'title' => ($title !== '' && $text($title) !== '') ? $text($title) : "#{$row['id']}", 'sub' => "#{$row['id']}".(($sub !== '') ? " - {$sub}" : '')];

		}

		return ['total' => (int)$data['pagination']['total'], 'rows' => $rows];

	}

	/**
	 * button Configuration: module Globals filtered on the categories of the plugin (empty = its route), for the users of the module Globals
	 * never on a module with a form of settings of its own (grid action with a form, ex: sms), nor when no global exists in these categories
	 *
	 * @param DataGrid $grid
	 *
	 * @return void
	 */
	private function configurationAction(DataGrid $grid):void
	{

		if($this->module === 'globals' || isset($grid->grid_actions['configuration']) || array_filter($grid->grid_actions, fn($a) => $a['form'] !== '') || !\Model\User::hasRight('globals', 'list'))return;

		$plugin = \Model\Plugin::findActive($this->module);
		$categories = array_values(array_filter((array)json_decode((string)($plugin['config_categories'] ?? ''), true), fn($c) => is_string($c) && preg_match('/^[a-z0-9_-]{1,64}$/', $c))) ?: [$this->module];

		$binds = [];
		foreach($categories as $i => $category)
			$binds["c{$i}"] = $category;
		if(!DB()->query("SELECT 1 FROM xcore_globals WHERE deleted = 'no' AND category IN (:".implode(', :', array_keys($binds)).") LIMIT 1", $binds)->fetchOne())return;

		// one category = the tab of Globals, several = equal conditions matched with any
		$url = (count($categories) === 1) ? "globals/?search[]=category||{$categories[0]}" : 'globals/?'.implode('&', array_map(fn($c) => "search[]=category||eq||{$c}", $categories)).'&match=any';
		$grid->gridAction('configuration', 'Configuration', 'tune', 'list', url: $url);

	}

	protected function buildForm(array $record):Form
	{

		$form = new Form();
		$this->form($form, $record);

		// the react form capitalizes these fields while typing
		foreach(array_intersect_key($form->fields, array_flip($this->ucfirst)) as $name => $field)
			$form->fields[$name]['ucfirst'] = true;

		// workflow: its state column is shown read only (changed by the transitions), so it leaves the mass edit too
		$state = $this->stateColumn();
		if($state !== '' && isset($form->fields[$state]))$form->fields[$state]['readonly'] = true;

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

	/**
	 * 403 when the workflow locks the record in its state for the group of the user
	 *
	 * @param array $record
	 *
	 * @return JsonResponse|null
	 */
	protected function locked(array $record):?JsonResponse
	{

		$workflow = Workflow::get($this->module);
		if(!$workflow || Workflow::editable($workflow, $record))return null;

		return JsonResponse::error(403, ts('this record is locked in the state [STATE]', ['STATE' => Workflow::label($workflow, Workflow::state($workflow, $record))]));

	}

	/**
	 * column of the workflow state, '' without workflow
	 *
	 * @return string
	 */
	protected function stateColumn():string
	{

		return Workflow::get($this->module)['state_column'] ?? '';

	}

	/**
	 * labels of the fields of a form, translated (messages)
	 *
	 * @param Form $form
	 *
	 * @return array name => label
	 */
	protected function labels(Form $form):array
	{

		return array_map(fn($f) => ts($f['label']), $form->fields);

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

		$row = $this->ucfirst($form->hydrate($request->request->all(), $id > 0));

		// workflow: state of a new record (creation transition: its groups and required fields), checked before the files move
		$workflow = Workflow::get($this->module);
		$start = null;
		if($workflow && !$id)
		{

			try
			{

				$start = Workflow::start($workflow, $row, $this->labels($form));

			}
			catch(\DomainException $e)
			{

				return JsonResponse::error($e->getCode() ?: 422, $e->getMessage());

			}

		}

		// file fields: a token moves its upload, an empty value removes the file, any other value keeps the current file
		$removed = self::fileFieldsMove($form, $row, $record);

		$row = $this->onSaveBefore($row, $record);

		// virtual fields (_name) are read by the hooks only
		foreach(array_keys($row) as $key)
			if(str_starts_with($key, '_'))unset($row[$key]);

		// the state changes by the transitions only
		if($workflow)
		{

			unset($row[$workflow['state_column']]);
			if($start && $start['state'] !== '')$row[$workflow['state_column']] = $start['state'];

		}

		if(!$id)
		{

			// manual order: a new record goes last
			$position = $this->buildGrid()->position;
			if($position !== '' && !isset($row[$position]))
				$row[$position] = (int)DB()->query("SELECT COALESCE(MAX(`{$position}`), 0) + 1 FROM `{$this->table}` WHERE deleted = 'no'")->fetchOne();

			$id = (int)DB($this->table)->insert($row, $this->author());
			if($workflow)Workflow::created($workflow, $id, $start);
			if($form->tagManager() !== '')\Core\Tag::set($form->tagManager(), $id, (array)$request->request->all('_tags'), $this->author());
			\Core\Log::write('add', $this->object_label, $this->masked($row), $id, 'info', $this->module);
			$this->versionSave($id);
			$this->onSaveAfterCreate($id, $row);

			return new JsonResponse(['id' => $id], 201);

		}

		$changed = [];
		foreach($row as $key => $value)
		{

			if((string)($record[$key] ?? '') !== (string)$value)$changed[$key] = [$record[$key] ?? null, $value];

		}

		// tags of xcore_tag, logged with the other changes
		if($form->tagManager() !== '')
		{

			$before = \Core\Tag::get($form->tagManager(), $id);
			\Core\Tag::set($form->tagManager(), $id, (array)$request->request->all('_tags'), $this->author());
			$after = \Core\Tag::get($form->tagManager(), $id);
			if($before !== $after)$changed['_tags'] = [implode(', ', $before), implode(', ', $after)];

		}

		if($changed)
		{

			if(array_diff_key($changed, ['_tags' => 0]))DB($this->table)->update($row, $id, 1, $this->author());
			\Core\Log::write('edit', $this->object_label, $this->masked($changed), $id, 'info', $this->module);
			$this->versionSave($id);

		}

		// replaced or removed files
		foreach($removed as [$relative, $public])
			\Core\File::remove($relative, $public);

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
		if(($signature = $this->buildForm($record)->tagManager()) !== '')\Core\Tag::set($signature, (int)$record['id'], [], $this->author());
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
	/**
	 * the plugin of the module has versioning enabled
	 *
	 * @return bool
	 */
	protected function versioning():bool
	{

		$plugin = \Model\Plugin::findActive($this->module);
		return ($plugin && ($plugin['versioning'] ?? 'no') === 'yes');

	}

	/**
	 * file fields of a save (shared with the profile): a token moves its upload (private_dir, or upload_dir with ->set('public', true)),
	 * an empty value removes the file, any other value keeps the current file
	 *
	 * @param Form $form
	 * @param array $row values written, file columns replaced by their stored path
	 * @param array $record current record, [] on create
	 *
	 * @return array files to remove once saved: [[relative path, public]]
	 */
	public static function fileFieldsMove(Form $form, array &$row, array $record):array
	{

		$removed = [];
		foreach($form->fields as $name => $f)
		{

			if($f['type'] !== 'file' || !array_key_exists($name, $row))continue;

			$public = !empty($f['public']);
			$current = (string)($record[$name] ?? '');
			$value = (string)$row[$name];
			if(str_starts_with($value, 'upload:'))$row[$name] = \Core\File::uploadMove($value, $f['dir'], $public);
			elseif($value !== '')$row[$name] = $current;

			if($current !== '' && $row[$name] !== $current)$removed[] = [$current, $public];

		}

		return $removed;

	}

	/**
	 * saves the values of the form fields of a record in xcore_versioning (plugin with versioning), passwords left out
	 *
	 * @param int $id
	 *
	 * @return void
	 */
	protected function versionSave(int $id):void
	{

		if(!$this->versioning())return;

		$record = DB()->query("SELECT * FROM `{$this->table}` WHERE id = :id", ['id' => $id])->fetch();
		if(!$record)return;

		$form = $this->buildForm($record);
		$data = [];
		foreach($form->fields as $name => $field)
		{

			if($field['type'] === 'password')continue;
			if(!empty($field['manager']))$data[$name] = \Core\Tag::get($field['manager'], $id);
			elseif(array_key_exists($name, $record))$data[$name] = $record[$name];

		}

		DB('xcore_versioning')->insert(['xcore_user_id' => \Model\User::getUID(), 'date' => now(), 'application' => 'backend', 'table' => $this->table, 'record_id' => $id, 'data_json' => json_encode($this->masked($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], $this->author());

	}

	/**
	 * value of a field as shown in the history: label of the option, text of the html, words of the tags
	 *
	 * @param array $field definition of the field
	 * @param mixed $value
	 *
	 * @return string
	 */
	protected function versionText(array $field, mixed $value):string
	{

		if(is_array($value))return implode(', ', $value);
		$value = (string)$value;

		if($field['type'] === 'tags' && is_array($words = json_decode($value, true)))return implode(', ', $words);
		if($field['type'] === 'html')$value = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], ' ', $value)))));
		if($field['type'] === 'boolean')return ts($value);

		foreach((array)($field['options'] ?? []) as $option)
			if((string)$option['value'] === $value)return (string)$option['label'];

		return mb_strimwidth($value, 0, 500, '...');

	}

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
