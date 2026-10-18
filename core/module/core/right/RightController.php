<?php

namespace Plugin\Core;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * rights of a group (xcore_group_right): actions of the plugins, grouped by menu
 * super admin has every right and core plugins are open to every backend user: neither is listed
 */
#[Route('/api/right', name: 'api.right.')]
class RightController extends \Core\Controller
{

	/**
	 * groups, plugins by menu and rights of the chosen group
	 *
	 * @param Request $request query string: group (id, default the first group)
	 *
	 * @return \Core\JsonResponse {"title", "groups": [{"id", "name"}], "group", "menus": [{"name", "icon", "color", "plugins": [{"id", "name", "icon", "actions"}]}], "rights": ["<plugin id>:<action>"], "can_edit"}
	 */
	#[Route('/', name: 'list', methods: ['GET'])]
	public function list(Request $request):\Core\JsonResponse
	{

		if(!\Model\User::hasRight($this->module, 'list'))return \Core\JsonResponse::error(403, 'access denied');

		$groups = $this->groups();
		$group_id = (int)$request->query->get('group', $groups[0]['id'] ?? 0);
		if($groups && !in_array($group_id, array_column($groups, 'id'), true))return \Core\JsonResponse::error(404, 'group not found');

		$recs = DB()->query("SELECT p.id, p.name, p.icon, p.type, p.actions, m.id AS menu_id, m.name AS menu_name, m.icon AS menu_icon, m.color AS menu_color
							 FROM xcore_plugin p
							 INNER JOIN xcore_menu m ON m.id = p.xcore_menu_id AND m.deleted = 'no'
							 WHERE p.deleted = 'no' AND p.type != 'core'
							 ORDER BY m.position, m.name, p.position, p.name")->fetchAll();

		$menus = [];
		foreach($recs as $rec)
		{

			$menus[$rec['menu_id']] ??= ['name' => ts($rec['menu_name']), 'icon' => $rec['menu_icon'], 'color' => $rec['menu_color'], 'plugins' => []];
			$menus[$rec['menu_id']]['plugins'][] = ['id' => (int)$rec['id'], 'name' => ts($rec['name']), 'icon' => $rec['icon'], 'actions' => \Model\Plugin::actions($rec)];

		}

		$rights = [];
		foreach(DB()->query("SELECT xcore_plugin_id, action FROM xcore_group_right WHERE deleted = 'no' AND xcore_group_id = :id", ['id' => $group_id])->fetchAll() as $rec)
			$rights[] = "{$rec['xcore_plugin_id']}:{$rec['action']}";

		return new \Core\JsonResponse([
			'title' => ts('Rights'),
			'groups' => $groups,
			'group' => $group_id,
			'menus' => array_values($menus),
			'rights' => $rights,
			'can_edit' => \Model\User::hasRight($this->module, 'edit'),
		]);

	}

	/**
	 * replace the rights of a group, json body: {"rights": ["<plugin id>:<action>"]}, unknown plugins or actions are refused
	 *
	 * @param Request $request
	 * @param int $id group
	 *
	 * @return \Core\JsonResponse {"saved": 12}
	 */
	#[Route('/{id}/', name: 'update', methods: ['PUT'])]
	public function update(Request $request, int $id):\Core\JsonResponse
	{

		if(!\Model\User::hasRight($this->module, 'edit'))return \Core\JsonResponse::error(403, 'access denied');
		if(!in_array($id, array_column($this->groups(), 'id'), true))return \Core\JsonResponse::error(404, 'group not found');

		// actions declared by each plugin
		$declared = [];
		foreach(DB()->query("SELECT id, type, actions FROM xcore_plugin WHERE deleted = 'no' AND type != 'core'")->fetchAll() as $rec)
			$declared[(int)$rec['id']] = \Model\Plugin::actions($rec);

		$rows = [];
		foreach(array_unique(array_map('strval', (array)$request->request->all('rights'))) as $right)
		{

			[$plugin_id, $action] = array_pad(explode(':', $right, 2), 2, '');
			if(!in_array($action, $declared[(int)$plugin_id] ?? [], true))return \Core\JsonResponse::error(422, ts('unknown right: [RIGHT]', ['RIGHT' => mb_substr($right, 0, 100)]));
			$rows[] = ['xcore_group_id' => $id, 'xcore_plugin_id' => (int)$plugin_id, 'action' => $action];

		}

		$before = DB()->query("SELECT CONCAT(xcore_plugin_id, ':', action) FROM xcore_group_right WHERE deleted = 'no' AND xcore_group_id = :id", ['id' => $id])->fetchAllOne();

		DB()->beginTransaction();
		DB()->query("DELETE FROM xcore_group_right WHERE xcore_group_id = :id", ['id' => $id]);
		foreach($rows as $row)
			DB('xcore_group_right')->insert($row, (string)\Core\Session::get('auth.login'));
		DB()->commit();

		$after = array_map(fn($r) => "{$r['xcore_plugin_id']}:{$r['action']}", $rows);
		\Core\Log::write('edit', 'rights', ['added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))], $id, 'info', $this->module);

		return new \Core\JsonResponse(['saved' => count($rows)]);

	}

	/**
	 * groups whose rights are set: backend access, super admin excluded
	 *
	 * @return array [{"id", "name"}]
	 */
	private function groups():array
	{

		$groups = DB()->query("SELECT id, name FROM xcore_group WHERE deleted = 'no' AND access_backend = 'yes' AND id != :superadmin ORDER BY priority, name", ['superadmin' => \Model\User::GROUP_SUPERADMIN])->fetchAll();
		return array_map(fn($g) => ['id' => (int)$g['id'], 'name' => $g['name']], $groups);

	}

}
