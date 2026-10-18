<?php

namespace Plugin\Core_Backend;

use Symfony\Component\Routing\Attribute\Route;

/**
 * home page of the backend: tab dashboard (widgets, later) and tab menu (plugins allowed to the user, by category)
 */
#[Route('/api/dashboard', name: 'api.dashboard.')]
class DashboardController extends \Core\Controller
{

	/**
	 * content of the home page for the logged user
	 *
	 * @return \Core\JsonResponse {"menu": [{"name", "icon", "color", "items": [{"name", "icon", "route", "url", "rights"}]}]}
	 */
	#[Route('/', name: 'index', methods: ['GET'])]
	public function index():\Core\JsonResponse
	{

		return new \Core\JsonResponse(['menu' => \Model\Menu::forCurrentUser()]);

	}

}
