<?php

namespace Plugin\Core;

use Symfony\Component\HttpFoundation\Request;

/**
 * shell of the react backend
 */
class BackendController extends \Core\Controller
{

	/**
	 * menu of the logged user, filtered by his rights
	 *
	 * @return \Core\JsonResponse {"menu": [{"name", "icon", "items": [{"name", "icon", "route", "url", "rights"}]}]}
	 */
	#[\Core\Route('/api/backend/menu/', name: 'api.backend.menu')]
	public function menu():\Core\JsonResponse
	{

		return new \Core\JsonResponse(['menu' => \Model\Menu::forCurrentUser()]);

	}

	/**
	 * saved filters of a grid: the user's own ones and the ones shared with his group
	 *
	 * @param Request $request query string: module (route_prefix_name)
	 *
	 * @return \Core\JsonResponse {"filters": [{"id", "name", "shared", "mine", "match", "conditions"}]}
	 */
	#[\Core\Route('/api/backend/filters/', name: 'api.backend.filters')]
	public function filters(Request $request):\Core\JsonResponse
	{

		$module = (string)$request->query->get('module');
		if(!\Model\User::hasRight($module, 'list'))return \Core\JsonResponse::error(403, 'access denied');

		$recs = DB()->query("SELECT id, xcore_user_id, xcore_group_id, name, conditions
							 FROM xcore_user_filter
							 WHERE deleted = 'no' AND plugin = :plugin AND (xcore_user_id = :user_id OR xcore_group_id = :group_id)
							 ORDER BY (xcore_user_id = :user_id2) DESC, name", ['plugin' => $module, 'user_id' => \Model\User::getUID(), 'user_id2' => \Model\User::getUID(), 'group_id' => (int)\Core\Session::get('auth.group_id')])->fetchAll();

		$filters = [];
		foreach($recs as $rec)
		{

			$data = json_decode((string)$rec['conditions'], true) ?: [];
			$filters[] = [
				'id' => (int)$rec['id'],
				'name' => $rec['name'],
				'shared' => ($rec['xcore_group_id'] !== null),
				'mine' => ((int)$rec['xcore_user_id'] === \Model\User::getUID()),
				'match' => (($data['match'] ?? '') === 'any') ? 'any' : 'all',
				'conditions' => array_values((array)($data['conditions'] ?? [])),
			];

		}

		return new \Core\JsonResponse(['filters' => $filters]);

	}

	/**
	 * save a filter, json body: {"module", "name", "shared": bool, "match": "all|any", "conditions": [{"f", "o", "v"}]}
	 * conditions are checked against the grid when they are applied (\Component\DataGrid whitelist)
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse 201 {"id"}
	 */
	#[\Core\Route('/api/backend/filters/', methods: 'POST', name: 'api.backend.filters.create')]
	public function filterCreate(Request $request):\Core\JsonResponse
	{

		$module = (string)$request->request->get('module');
		if(!\Model\User::hasRight($module, 'list'))return \Core\JsonResponse::error(403, 'access denied');

		$this->validator->input('name')->required('required')->maxLength(255, '[LENGTH] characters maximum');
		if($this->validator->fails())
			return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $this->validator->result()['error_stack_deep']]);

		$conditions = [];
		foreach(array_slice((array)$request->request->all('conditions'), 0, \Component\DataGrid::MAX_CONDITIONS) as $c)
		{

			if(!is_array($c) || !is_string($c['f'] ?? null) || !is_string($c['o'] ?? null))continue;
			$conditions[] = ['f' => mb_substr($c['f'], 0, 64), 'o' => mb_substr($c['o'], 0, 16), 'v' => mb_substr((string)($c['v'] ?? ''), 0, 255)];

		}
		if(!$conditions)return \Core\JsonResponse::error(422, 'add at least one condition');

		$name = trim((string)$request->request->get('name'));
		$found = DB()->query("SELECT id FROM xcore_user_filter WHERE deleted = 'no' AND xcore_user_id = :user_id AND plugin = :plugin AND name = :name", ['user_id' => \Model\User::getUID(), 'plugin' => $module, 'name' => $name])->fetchOne();
		if($found)return \Core\JsonResponse::error(422, 'validation failed', ['fields' => ['name' => [ts('already used')]]]);

		$id = (int)DB('xcore_user_filter')->insert([
			'xcore_user_id' => \Model\User::getUID(),
			'xcore_group_id' => ($request->request->get('shared')) ? (int)\Core\Session::get('auth.group_id') : null,
			'plugin' => $module,
			'name' => $name,
			'conditions' => json_encode(['match' => ($request->request->get('match') === 'any') ? 'any' : 'all', 'conditions' => $conditions]),
		], (string)\Core\Session::get('auth.login'));

		return new \Core\JsonResponse(['id' => $id], 201);

	}

	/**
	 * delete one of the user's saved filters
	 *
	 * @param int $id
	 *
	 * @return \Core\JsonResponse 204
	 */
	#[\Core\Route('/api/backend/filters/{id}/', methods: 'DELETE', name: 'api.backend.filters.delete')]
	public function filterDelete(int $id):\Core\JsonResponse
	{

		$deleted = DB('xcore_user_filter')->delete(['id = :id AND xcore_user_id = :user_id AND deleted = \'no\'', ['id' => $id, 'user_id' => \Model\User::getUID()]], 1, (string)\Core\Session::get('auth.login'));
		if(!$deleted)return \Core\JsonResponse::error(404, 'record not found');

		return new \Core\JsonResponse(null, 204);

	}

}
