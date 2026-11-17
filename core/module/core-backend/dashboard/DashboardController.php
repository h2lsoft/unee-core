<?php

namespace Plugin\Core_Backend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * home page of the backend: tab dashboard (widgets of xcore_widget placed by the user in xcore_user_widget, personal notes) and tab plugins (plugins allowed to the user, by category)
 */
#[Route('/api/dashboard', name: 'api.dashboard.')]
class DashboardController extends \Core\Controller
{

	public const array NOTE_COLORS = ['yellow', 'green', 'primary'];
	public const int WIDGETS_MAX = 50;

	/**
	 * content of the home page for the logged user
	 *
	 * @return \Core\JsonResponse {"menu": [{"name", "icon", "color", "items": [...]}], "widgets": dashboard of the user in his order with their "key", "row", "config", "catalog": widgets he can add, both [{"id", "type", "name", "icon", "route", "size", "position", "refresh", "url", "height"}]}
	 */
	#[Route('/', name: 'index', methods: ['GET'])]
	public function index():\Core\JsonResponse
	{

		$catalog = \Core\Widget::forCurrentUser();

		return new \Core\JsonResponse(['menu' => \Model\Menu::forCurrentUser(), 'widgets' => \Core\Widget::layoutForCurrentUser($catalog), 'catalog' => $catalog]);

	}

	/**
	 * saves the dashboard of the logged user, json body {"rows": [[{"key", "widget", "config", "title"}, ...], ...]} rows from the top, widgets from the left (empty = empty dashboard)
	 * key: id of a widget he placed (0 = added), widget: id of xcore_widget, config: settings of a configurable widget {name: value}, title: his title of the widget (plain text, 60 characters at most, '' = name)
	 * each widget allowed, placed once unless multiple, a row holds 4 columns at most (sizes of the widgets)
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"widgets"} the dashboard saved (keys of the added widgets)
	 */
	#[Route('/layout/', name: 'layout', methods: ['PUT'])]
	public function layout(Request $request):\Core\JsonResponse
	{

		$rows = $request->request->all()['rows'] ?? null;
		if(!is_array($rows) || !array_is_list($rows))return \Core\JsonResponse::error(422, ts('invalid list of widgets'));

		$catalog = array_column(\Core\Widget::forCurrentUser(), null, 'id');
		$mine = array_column(DB()->query("SELECT id, xcore_widget_id FROM xcore_user_widget WHERE deleted = 'no' AND xcore_user_id = :me", ['me' => \Model\User::getUID()])->fetchAll() ?: [], 'xcore_widget_id', 'id');

		$list = [];
		$placed = [];
		$keys = [];
		$count = 0;
		foreach($rows as $row)
		{

			if(!is_array($row) || !$row || !array_is_list($row))return \Core\JsonResponse::error(422, ts('invalid list of widgets'));

			$items = [];
			$width = 0;
			foreach($row as $item)
			{

				$id = is_array($item) ? ($item['widget'] ?? null) : null;
				$key = is_array($item) ? ($item['key'] ?? 0) : null;
				if(!is_int($id) || !is_int($key) || $key < 0)return \Core\JsonResponse::error(422, ts('invalid list of widgets'));
				if(!isset($catalog[$id]))return \Core\JsonResponse::error(422, ts('widget not allowed'));
				if(isset($placed[$id]) && !$catalog[$id]['multiple'])return \Core\JsonResponse::error(422, ts('a widget is placed once'));

				// a key of the user, for this widget, once
				if($key && ((int)($mine[$key] ?? 0) !== $id || isset($keys[$key])))return \Core\JsonResponse::error(422, ts('invalid list of widgets'));

				// settings: the fields of the widget only
				$config = [];
				$values = $item['config'] ?? [];
				if(!is_array($values))return \Core\JsonResponse::error(422, ts('invalid list of widgets'));
				foreach($catalog[$id]['fields'] as $f)
				{

					if(!array_key_exists($f['name'], $values))continue;
					if($error = \Core\Widget::configError($f, $values[$f['name']]))return \Core\JsonResponse::error(422, $error);
					$config[$f['name']] = $values[$f['name']];

				}

				// title of the user: plain text on one line
				$title = $item['title'] ?? '';
				if(!is_string($title))return \Core\JsonResponse::error(422, ts('invalid list of widgets'));
				$title = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title) ?? '');
				if(mb_strlen($title) > 60)return \Core\JsonResponse::error(422, ts('the title of a widget holds 60 characters at most'));

				$placed[$id] = true;
				if($key)$keys[$key] = true;
				$width += \Core\Widget::SPANS[$catalog[$id]['size']] ?? \Core\Widget::COLUMNS;
				$items[] = ['key' => $key, 'widget' => $id, 'config' => \Core\Widget::config($catalog[$id]['fields'], $config), 'title' => $title];
				$count++;

			}

			if($width > \Core\Widget::COLUMNS)return \Core\JsonResponse::error(422, ts('a row holds 4 columns at most'));
			$list[] = $items;

		}

		if($count > self::WIDGETS_MAX)return \Core\JsonResponse::error(422, ts('invalid list of widgets'));

		\Core\Widget::saveLayout($list);

		return new \Core\JsonResponse(['widgets' => \Core\Widget::layoutForCurrentUser(array_values($catalog))]);

	}

	/**
	 * data of a widget placed by the user (widget right on its plugin, or access all)
	 *
	 * @param int $id id of the placed widget (xcore_user_widget)
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
	 * widget of the personal notes (\Core\Widget::NOTES): drawn by the dashboard itself
	 *
	 * @return array
	 */
	public static function widgetNotes():array
	{

		return ['type' => 'notes'];

	}

	/**
	 * widget Clock (\Core\Widget::CLOCK): date and time of the browser, drawn by the dashboard itself
	 *
	 * @return array
	 */
	public static function widgetClock():array
	{

		return ['type' => 'clock'];

	}

	/**
	 * widget Weather: forecast of the place of its settings (city, latitude, longitude), else of the globals widget.weather.* (see \Core\Weather)
	 *
	 * @param array $config settings of the placed widget
	 *
	 * @return array
	 */
	public static function widgetWeather(array $config=[]):array
	{

		return \Core\Weather::widget($config);

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
