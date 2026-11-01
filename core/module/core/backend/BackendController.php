<?php

namespace Plugin\Core;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

/**
 * shell of the react backend
 */
#[Route('/api/backend', name: 'api.backend.')]
class BackendController extends \Core\Controller
{

	/**
	 * menu of the logged user, filtered by his rights
	 *
	 * @return \Core\JsonResponse {"menu": [{"name", "icon", "items": [{"name", "icon", "route", "url", "rights"}]}]}
	 */
	#[Route('/menu/', name: 'menu', methods: ['GET'])]
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
	#[Route('/filters/', name: 'filters', methods: ['GET'])]
	public function filters(Request $request):\Core\JsonResponse
	{

		$module = (string)$request->query->get('module');
		if(!\Model\User::hasRight($module, 'list'))return \Core\JsonResponse::error(403, 'access denied');

		// mine, or shared with my group (xcore_group_ids: json ["id"])
		$recs = DB()->query("SELECT id, xcore_user_id, xcore_group_ids, name, conditions
							 FROM xcore_user_filter
							 WHERE deleted = 'no' AND plugin = :plugin AND (xcore_user_id = :user_id OR xcore_group_ids LIKE :group_like)
							 ORDER BY (xcore_user_id = :user_id2) DESC, name", ['plugin' => $module, 'user_id' => \Model\User::getUID(), 'user_id2' => \Model\User::getUID(), 'group_like' => '%"'.(int)\Core\Session::get('auth.group_id').'"%'])->fetchAll();

		$filters = [];
		foreach($recs as $rec)
		{

			$data = json_decode((string)$rec['conditions'], true) ?: [];
			$filters[] = [
				'id' => (int)$rec['id'],
				'name' => $rec['name'],
				'shared' => (bool)json_decode((string)$rec['xcore_group_ids'], true),
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
	#[Route('/filters/', name: 'filters.create', methods: ['POST'])]
	public function filterCreate(Request $request):\Core\JsonResponse
	{

		$module = (string)$request->request->get('module');
		if(!\Model\User::hasRight($module, 'list'))return \Core\JsonResponse::error(403, 'access denied');

		$this->validator->input('name', ts('Filter name'))->required()->maxLength(255);
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
		if($found)
		{

			$this->validator->input('name', ts('Filter name'))->addError('`[FIELD]` is already used');
			return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $this->validator->result()['error_stack_deep']]);

		}

		$id = (int)DB('xcore_user_filter')->insert([
			'xcore_user_id' => \Model\User::getUID(),
			'xcore_group_ids' => json_encode(($request->request->get('shared')) ? [(string)(int)\Core\Session::get('auth.group_id')] : []),
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
	#[Route('/filters/{id}/', name: 'filters.delete', methods: ['DELETE'])]
	public function filterDelete(int $id):\Core\JsonResponse
	{

		$deleted = DB('xcore_user_filter')->delete(['id = :id AND xcore_user_id = :user_id AND deleted = \'no\'', ['id' => $id, 'user_id' => \Model\User::getUID()]], 1, (string)\Core\Session::get('auth.login'));
		if(!$deleted)return \Core\JsonResponse::error(404, 'record not found');

		return new \Core\JsonResponse(null, 204);

	}

	/**
	 * bookmarks of the logged user: plugins he still has the list right on, in his order
	 *
	 * @return \Core\JsonResponse {"bookmarks": [{"route", "name", "icon", "url"}]}
	 */
	#[Route('/bookmarks/', name: 'bookmarks', methods: ['GET'])]
	public function bookmarks():\Core\JsonResponse
	{

		$recs = DB()->query("SELECT p.route_prefix_name, p.name, p.icon, p.type, p.url
							 FROM xcore_user_bookmark b
							 INNER JOIN xcore_plugin p ON p.id = b.xcore_plugin_id AND p.deleted = 'no' AND p.active = 'yes'
							 WHERE b.deleted = 'no' AND b.xcore_user_id = :user_id
							 ORDER BY b.position, b.id", ['user_id' => \Model\User::getUID()])->fetchAll();

		$bookmarks = [];
		foreach($recs as $rec)
		{

			if(!\Model\User::hasRight($rec['route_prefix_name'], 'list'))continue;
			$bookmarks[] = ['route' => $rec['route_prefix_name'], 'name' => ts($rec['name']), 'icon' => $rec['icon'], 'url' => ($rec['type'] === 'url') ? $rec['url'] : null];

		}

		return new \Core\JsonResponse(['bookmarks' => $bookmarks]);

	}

	/**
	 * bookmark a module (list right), json body: {"module"}, added last, nothing done when already bookmarked
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"module"}
	 */
	#[Route('/bookmarks/', name: 'bookmarks.create', methods: ['POST'])]
	public function bookmarkCreate(Request $request):\Core\JsonResponse
	{

		$module = (string)$request->request->get('module');
		if(!\Model\User::hasRight($module, 'list'))return \Core\JsonResponse::error(403, 'access denied');

		$plugin = \Model\Plugin::findActive($module);
		$params = ['user_id' => \Model\User::getUID(), 'plugin_id' => (int)$plugin['id']];
		$found = DB()->query("SELECT id FROM xcore_user_bookmark WHERE deleted = 'no' AND xcore_user_id = :user_id AND xcore_plugin_id = :plugin_id", $params)->fetchOne();

		if(!$found)
		{

			$position = (int)DB()->query("SELECT COALESCE(MAX(position), 0) + 1 FROM xcore_user_bookmark WHERE deleted = 'no' AND xcore_user_id = :user_id", ['user_id' => \Model\User::getUID()])->fetchOne();
			DB('xcore_user_bookmark')->insert(['xcore_user_id' => $params['user_id'], 'xcore_plugin_id' => $params['plugin_id'], 'position' => $position], (string)\Core\Session::get('auth.login'));

		}

		return new \Core\JsonResponse(['module' => $module]);

	}

	/**
	 * remove the bookmark of a module
	 *
	 * @param string $module route_prefix_name of the plugin
	 *
	 * @return \Core\JsonResponse 204
	 */
	#[Route('/bookmarks/{module}/', name: 'bookmarks.delete', methods: ['DELETE'], requirements: ['module' => '[a-z0-9]+(-[a-z0-9]+)*'])]
	public function bookmarkDelete(string $module):\Core\JsonResponse
	{

		$plugin_id = (int)DB()->query("SELECT id FROM xcore_plugin WHERE route_prefix_name = :module AND deleted = 'no'", ['module' => $module])->fetchOne();
		DB()->query("UPDATE xcore_user_bookmark SET deleted = 'yes', deleted_at = NOW(), deleted_by = :author WHERE deleted = 'no' AND xcore_user_id = :user_id AND xcore_plugin_id = :plugin_id", ['author' => (string)\Core\Session::get('auth.login'), 'user_id' => \Model\User::getUID(), 'plugin_id' => $plugin_id]);

		return new \Core\JsonResponse(null, 204);

	}

}
