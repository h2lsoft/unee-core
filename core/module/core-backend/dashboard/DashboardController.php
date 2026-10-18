<?php

namespace Plugin\Core_Backend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * home page of the backend: tab dashboard (widgets of xcore_widget, personal notes) and tab plugins (plugins allowed to the user, by category)
 */
#[Route('/api/dashboard', name: 'api.dashboard.')]
class DashboardController extends \Core\Controller
{

	public const array NOTE_COLORS = ['yellow', 'green', 'blue', 'pink', 'purple'];

	/**
	 * content of the home page for the logged user
	 *
	 * @return \Core\JsonResponse {"menu": [{"name", "icon", "color", "items": [...]}], "widgets": [{"id", "name", "icon", "route", "size", "position", "refresh"}]}
	 */
	#[Route('/', name: 'index', methods: ['GET'])]
	public function index():\Core\JsonResponse
	{

		return new \Core\JsonResponse(['menu' => \Model\Menu::forCurrentUser(), 'widgets' => \Core\Widget::forCurrentUser()]);

	}

	/**
	 * data of a widget (list right on its plugin)
	 *
	 * @param int $id
	 *
	 * @return \Core\JsonResponse see \Core\Widget
	 */
	#[Route('/widgets/{id}/', name: 'widget', methods: ['GET'])]
	public function widget(int $id):\Core\JsonResponse
	{

		$data = \Core\Widget::data($id);
		if($data === null)return \Core\JsonResponse::error(404, 'widget not found');

		return new \Core\JsonResponse($data);

	}

	/**
	 * notes of the logged user, last changed first
	 *
	 * @return \Core\JsonResponse {"notes": [{"id", "text", "color", "date"}]}
	 */
	#[Route('/notes/', name: 'notes', methods: ['GET'])]
	public function notes():\Core\JsonResponse
	{

		$recs = DB()->query("SELECT id, text, color, COALESCE(updated_at, created_at) AS date FROM xcore_user_note WHERE deleted = 'no' AND xcore_user_id = :me ORDER BY COALESCE(updated_at, created_at) DESC, id DESC LIMIT 100", ['me' => \Model\User::getUID()])->fetchAll();
		foreach($recs as &$rec)$rec['id'] = (int)$rec['id'];

		return new \Core\JsonResponse(['notes' => \Core\Widget::isoDates($recs)]);

	}

	/**
	 * adds a note, json body {"text", "color"}
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"id"}
	 */
	#[Route('/notes/', name: 'notes.create', methods: ['POST'])]
	public function noteCreate(Request $request):\Core\JsonResponse
	{

		if(!$row = $this->noteRow($request))return \Core\JsonResponse::error(422, ts('the note is empty or longer than 2000 characters'));

		$row['xcore_user_id'] = \Model\User::getUID();
		$id = (int)DB('xcore_user_note')->insert($row, (string)\Core\Session::get('auth.login'));

		return new \Core\JsonResponse(['id' => $id], 201);

	}

	/**
	 * changes a note of the logged user, json body {"text", "color"}
	 *
	 * @param Request $request
	 * @param int $id
	 *
	 * @return \Core\JsonResponse {"id"}
	 */
	#[Route('/notes/{id}/', name: 'notes.update', methods: ['PUT'])]
	public function noteUpdate(Request $request, int $id):\Core\JsonResponse
	{

		if(!$this->noteFind($id))return \Core\JsonResponse::error(404, 'note not found');
		if(!$row = $this->noteRow($request))return \Core\JsonResponse::error(422, ts('the note is empty or longer than 2000 characters'));

		DB('xcore_user_note')->update($row, $id, -1, (string)\Core\Session::get('auth.login'));
		return new \Core\JsonResponse(['id' => $id]);

	}

	/**
	 * deletes a note of the logged user
	 *
	 * @param int $id
	 *
	 * @return \Core\JsonResponse 204
	 */
	#[Route('/notes/{id}/', name: 'notes.delete', methods: ['DELETE'])]
	public function noteDelete(int $id):\Core\JsonResponse
	{

		if(!$this->noteFind($id))return \Core\JsonResponse::error(404, 'note not found');

		DB('xcore_user_note')->delete($id, -1, (string)\Core\Session::get('auth.login'));
		return new \Core\JsonResponse(null, 204);

	}

	// a note of the logged user
	private function noteFind(int $id):bool
	{

		return (bool)DB()->query("SELECT id FROM xcore_user_note WHERE id = :id AND deleted = 'no' AND xcore_user_id = :me", ['id' => $id, 'me' => \Model\User::getUID()])->fetchOne();

	}

	// plain text (shown as text by react), 1 to 2000 characters, color of NOTE_COLORS
	private function noteRow(Request $request):?array
	{

		$text = trim(str_replace("\r", '', (string)$request->request->get('text')));
		if($text === '' || mb_strlen($text) > 2000)return null;

		$color = (string)$request->request->get('color');
		return ['text' => $text, 'color' => in_array($color, self::NOTE_COLORS, true) ? $color : 'yellow'];

	}

}
