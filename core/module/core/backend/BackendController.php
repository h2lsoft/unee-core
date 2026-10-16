<?php

namespace Plugin\Core;

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

}
