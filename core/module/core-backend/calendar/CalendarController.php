<?php

namespace Plugin\Core_Backend;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * calendar of the users (xcore_agenda), opened from the header: month, week and day views of the react page, events moved by drag and drop
 */
#[Route('/api/calendar', name: 'api.calendar.')]
class CalendarController extends \Core\CrudController
{

	public string $table = 'xcore_agenda';
	public string $title = 'Calendar';
	public string $object_label = 'event';
	public array $ucfirst = ['name'];

	public const array COLORS = ['purple' => 'purple', 'blue' => 'blue', 'green' => 'green', 'orange' => 'orange', 'red' => 'red'];
	public const array STATUSES = ['pending' => 'pending', 'confirmed' => 'confirmed', 'completed' => 'completed', 'cancelled' => 'cancelled'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT CONCAT(u.firstname, ' ', u.lastname) FROM xcore_user u WHERE u.id = xcore_agenda.xcore_user_id)", 'user');
		$grid->setSearch(['name', 'address'], 'Search name, address...');
		$grid->addFilter('date', 'Date', 'date');
		$grid->addFilter('status', 'Status', 'select', self::STATUSES);
		$grid->setOrderByFirst('date', 'desc');
		$grid->setMobile('name', 'date');

		$grid->addColDate('date', 'Date', true)->width(110);
		$grid->addCol('hour_start', 'From')->width(70);
		$grid->addCol('hour_end', 'To')->width(70);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('user', 'User')->width(160);
		$grid->addColBadge('status', 'Status', true, ['pending' => 'orange', 'confirmed' => 'blue', 'completed' => 'green', 'cancelled' => 'grey'])->labels(self::STATUSES)->width(110);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addDate('date', 'Date', true);
		$form->addBoolean('all_day', 'All day')->defaultValue('no');
		$form->addTime('hour_start', 'From', true)->defaultValue('09:00')->set('size', 100)->showIf('all_day', ['no']);
		$form->addTime('hour_end', 'To', true)->defaultValue('10:00')->set('size', 100)->set('inline', true)->set('time_from', 'hour_start')->showIf('all_day', ['no']);
		$form->addText('address', 'Address')->maxLength(255)->set('adornment', 'location_on');
		$form->addHtml('comment', 'Comment');
		$form->addRadio('color', 'Color', self::COLORS, true)->defaultValue('purple')->set('swatch', true);
		$form->addSelectUser('xcore_user_id', 'User', true, (int)($record['xcore_user_id'] ?? 0))->defaultValue((string)\Model\User::getUID());
		$form->addRadio('status', 'Status', self::STATUSES, true)->defaultValue('pending');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$all_day = (string)$v->inputGet('all_day', $record['all_day'] ?? 'no');
		$start = (string)$v->inputGet('hour_start', $record['hour_start'] ?? '');
		$end = (string)$v->inputGet('hour_end', $record['hour_end'] ?? '');

		if($all_day !== 'yes' && $start !== '' && $end !== '' && $end <= $start)
			$v->input('hour_end')->addError(ts('`[FIELD]` must be after the start'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(($row['all_day'] ?? '') === 'yes')
		{

			$row['hour_start'] = '';
			$row['hour_end'] = '';

		}

		return $row;

	}

	/**
	 * events between two dates, for the calendar (list right)
	 *
	 * @param Request $request query string: start, end (Y-m-d, end excluded), user (id, empty = everybody)
	 *
	 * @return \Core\JsonResponse {"events": [{"id", "title", "start", "end", "allDay", "color", "status", "user": {"id", "name", "avatar"}}], "users": [{"id", "name"}]}
	 */
	#[Route('/events/', name: 'events', methods: ['GET'])]
	public function apiEvents(Request $request):\Core\JsonResponse
	{

		if($denied = $this->deny('list'))return $denied;

		$start = (string)$request->query->get('start');
		$end = (string)$request->query->get('end');
		if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))return \Core\JsonResponse::error(422, 'start and end must be dates (Y-m-d)');

		$sql = "SELECT a.*, u.firstname, u.lastname, u.login, u.avatar FROM xcore_agenda a LEFT JOIN xcore_user u ON u.id = a.xcore_user_id WHERE a.deleted = 'no' AND a.date >= :start AND a.date < :end";
		$binds = ['start' => $start, 'end' => $end];
		if((int)$request->query->get('user'))
		{

			$sql .= " AND a.xcore_user_id = :user";
			$binds['user'] = (int)$request->query->get('user');

		}

		$events = [];
		foreach(DB()->query("{$sql} ORDER BY a.date, a.hour_start, a.id LIMIT 2000", $binds)->fetchAll() as $rec)
		{

			$all_day = ($rec['all_day'] === 'yes' || $rec['hour_start'] === '');
			$avatar = \Core\Widget::avatar(['id' => $rec['xcore_user_id'], 'firstname' => $rec['firstname'], 'lastname' => $rec['lastname'], 'login' => $rec['login'], 'avatar' => $rec['avatar']]);
			$events[] = [
				'id' => (int)$rec['id'],
				'title' => $rec['name'],
				'start' => ($all_day) ? $rec['date'] : "{$rec['date']}T{$rec['hour_start']}:00",
				'end' => ($all_day) ? null : "{$rec['date']}T".(($rec['hour_end'] !== '') ? $rec['hour_end'] : $rec['hour_start']).":00",
				'allDay' => $all_day,
				'color' => (isset(self::COLORS[$rec['color']])) ? $rec['color'] : 'purple',
				'status' => $rec['status'],
				'user' => ['id' => (int)$rec['xcore_user_id'], 'name' => $avatar['title'], 'avatar' => $avatar['avatar']],
			];

		}

		$users = DB()->query("SELECT id, CONCAT(firstname, ' ', lastname) AS name FROM xcore_user WHERE deleted = 'no' AND active = 'yes' AND id > 1 ORDER BY firstname, lastname")->fetchAll();
		foreach($users as &$user)$user['id'] = (int)$user['id'];

		return new \Core\JsonResponse(['events' => $events, 'users' => $users, 'rights' => \Model\User::getRights($this->module)]);

	}

	/**
	 * moves an event (drag and drop), json body {"date", "all_day", "hour_start", "hour_end"}, edit right
	 *
	 * @param Request $request
	 * @param int $id
	 *
	 * @return \Core\JsonResponse {"id"}
	 */
	#[Route('/{id}/move/', name: 'move', methods: ['POST'])]
	public function apiMove(Request $request, int $id):\Core\JsonResponse
	{

		if($denied = $this->deny('edit'))return $denied;
		if(!$record = $this->findRecord($id))return \Core\JsonResponse::error(404, 'record not found');

		$date = (string)$request->request->get('date');
		$all_day = ($request->request->get('all_day') === 'yes');
		$start = ($all_day) ? '' : (string)$request->request->get('hour_start');
		$end = ($all_day) ? '' : (string)$request->request->get('hour_end');

		if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4)))return \Core\JsonResponse::error(422, 'invalid date');
		if(!$all_day && (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $end <= $start))return \Core\JsonResponse::error(422, 'invalid hours');

		$row = ['date' => $date, 'all_day' => ($all_day) ? 'yes' : 'no', 'hour_start' => $start, 'hour_end' => $end];
		DB($this->table)->update($row, $id, 1, $this->author());
		\Core\Log::write('move', $this->object_label, ['date' => [$record['date'], $date], 'hour_start' => [$record['hour_start'], $start], 'hour_end' => [$record['hour_end'], $end]], $id, 'info', $this->module);
		$this->versionSave($id);

		return new \Core\JsonResponse(['id' => $id]);

	}

}
