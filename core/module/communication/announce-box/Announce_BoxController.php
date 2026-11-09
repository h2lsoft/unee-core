<?php

namespace Plugin\Communication;

use Symfony\Component\Routing\Attribute\Route;

/**
 * announcements of the logged user (lightbox of the backend, \Core\Announce), every user, no right needed
 * the module Announce (communication/announce) writes them for the admins
 * nothing is shown during a connect as: the read would be written for the other user
 */
#[Route('/api/announce-box', name: 'api.announce_box.')]
class Announce_BoxController extends \Core\Controller
{

	/**
	 * live announcements of the backend not read yet by the logged user, last started first
	 *
	 * @return \Core\JsonResponse {"items": [{"id", "title", "content", "link_label", "link_url", "date"}]}
	 */
	#[Route('/', name: 'list', methods: ['GET'])]
	public function list():\Core\JsonResponse
	{

		if(\Core\Session::get('auth.impersonator'))return new \Core\JsonResponse(['items' => []]);
		return new \Core\JsonResponse(['items' => \Core\Announce::pending(\Model\User::getUID(), (int)\Core\Session::get('auth.group_id', 0))]);

	}

	/**
	 * I understood: the announcement is read by the logged user (once, logged, kept in xcore_announce_read)
	 *
	 * @param int $id announcement
	 *
	 * @return \Core\JsonResponse {"read": true}, 404 when it is not pending for the user
	 */
	#[Route('/{id}/read/', name: 'read', methods: ['POST'])]
	public function read(int $id):\Core\JsonResponse
	{

		if(\Core\Session::get('auth.impersonator'))return \Core\JsonResponse::error(403, 'not during a connect as');
		if(!\Core\Announce::read(\Model\User::getUID(), (int)\Core\Session::get('auth.group_id', 0), $id))return \Core\JsonResponse::error(404, 'announcement not found');
		return new \Core\JsonResponse(['read' => true]);

	}

}
