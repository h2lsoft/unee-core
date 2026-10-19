<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * users of the backend (xcore_user), login and password rules from config auth/security
 * right connect_as: open the session of another user (logged)
 */
#[Route('/api/user', name: 'api.user.')]
class UserController extends \Core\CrudController
{

	public string $table = 'xcore_user';
	public string $title = 'Users';
	public string $object_label = 'user';

	protected function grid(DataGrid $grid):void
	{

		$groups = $this->groups();

		$grid->qWhere('id != :system_id', ['system_id' => 1]);
		$grid->qSelect("CONCAT(lastname, ' ', firstname)", 'fullname');
		$grid->qSelect("(SELECT g.name FROM xcore_group g WHERE g.id = xcore_user.xcore_group_id)", 'group_name');
		$grid->setSearch(['lastname', 'firstname', 'email', 'login'], 'Search name, email...');
		$grid->addFilterChips('xcore_group_id', $groups);
		$grid->addFilter('lastname', 'Last name');
		$grid->addFilter('firstname', 'First name');
		$grid->addFilter('email', 'Email');
		$grid->addFilter('login', 'Login');
		$grid->addFilter('xcore_group_id', 'Group', 'select', $groups);
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->addFilterTags();
		$grid->addFilter('last_connection_date', 'Last connection', 'date', [], 'DATE(last_connection_date)');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setOrderByFirst('fullname', 'asc');
		$grid->setMobile('fullname', 'email');
		$grid->batchEdit(['xcore_group_id', 'active']);
		$grid->rowAction('connect-as', 'Connect as', 'login', 'connect_as');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('group_name', 'Group', true)->width(150)->filterOnClick('xcore_group_id');
		$grid->addCol('fullname', 'Full name', true)->avatar();
		$grid->addColEmail('email', 'Email');
		$grid->addColTags()->width(180);
		$grid->addColBoolean('active', 'Active', true, true)->width(90);
		$grid->addColDatetime('last_connection_date', 'Last connection', true)->width(150)->humanize();

	}

	protected function form(Form $form, array $record):void
	{

		self::accountFields($form, $record, true);
		$form->addBoolean('active', 'Active')->defaultValue('yes');
		$form->addTagManager($this->table)->help('free words to group the users, ex: team north (calendar)');
		$form->addTextarea('note', 'Note');

	}

	/**
	 * fields of an account, shared with the profile of the logged user (module profile): identity, tab credentials, tab information
	 *
	 * @param Form $form
	 * @param array $record [] on create
	 * @param bool $with_group select of the group (not in the profile)
	 *
	 * @return void
	 */
	public static function accountFields(Form $form, array $record, bool $with_group):void
	{

		$login = \Core\Config::get('auth/security/login');
		$password = \Core\Config::get('auth/security/password');
		$languages = \Core\Config::get('backend.languages');

		$form->addSelect('language', 'Language', $languages, true)->defaultValue(array_key_first($languages))->set('translate', false);
		if($with_group)$form->addSelectSql('xcore_group_id', 'Group', 'xcore_group', 'name', true);
		$form->addText('lastname', 'Last name', true)->maxLength(50);
		$form->addText('firstname', 'First name', true)->maxLength(50);
		$form->addEmail('email', 'Email', true)->maxLength((int)\Core\Config::get('auth/security/email/max_length', 255))->unique();

		$form->tab('credentials', 'Credentials');
		$form->addText('login', 'Login', true)->maxLength((int)$login['max_length'])->unique();
		$form->addPassword('password', 'Password', !$record)->maxLength((int)$password['max_length'])
			 ->help(ts('[MIN] to [MAX] characters', ['MIN' => $password['min_length'], 'MAX' => $password['max_length']]).(($record) ? ', '.ts('unchanged when empty') : ''));

		$form->tab('information', 'Information');
		$form->addText('company', 'Company')->maxLength(50);
		$form->addText('address', 'Address')->maxLength(255);
		$form->addText('address2', 'Address 2')->maxLength(255);
		$form->addText('address3', 'Address 3')->maxLength(255);
		$form->addText('zip_code', 'Zip code')->maxLength(20);
		$form->addText('city', 'City')->maxLength(255);
		$form->addText('country', 'Country')->maxLength(255);
		$form->addText('phone', 'Phone')->maxLength(30);
		$form->addText('mobile', 'Mobile')->maxLength(30);
		$form->addDate('birthdate', 'Birthdate');
		$form->addText('service', 'Service')->maxLength(255);
		$form->addText('job', 'Job')->maxLength(255);
		$form->addTextarea('bio', 'Bio');

		$form->tab('');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		self::credentialRules($v, $record);

		if($record && (int)$record['id'] === \Model\User::getUID() && $v->inputGet('active', $record['active']) !== 'yes')
			$v->input('active')->addError(ts('you cannot deactivate your own account'));

	}

	/**
	 * login and password rules of config auth/security, shared with the profile
	 *
	 * @param \Core\Validator $v
	 * @param array $record current record, [] on create
	 *
	 * @return void
	 */
	public static function credentialRules(\Core\Validator $v, array $record):void
	{

		$login = \Core\Config::get('auth/security/login');
		$password = \Core\Config::get('auth/security/password');

		// login and password rules of config auth/security (the message of the config names the field `login`)
		$message = str_replace('`login`', '`[FIELD]`', (string)$login['regex_error_message']);
		foreach((array)($login['regex_error_message_added'] ?? []) as $key => $value)
			$message = str_replace("[{$key}]", $value, $message);

		$v->input('login')->minLength((int)$login['min_length'])->regex($login['regex'], $message);
		// mass edit and toggles merge the stored hash: only a typed password is checked
		if($v->inputGet('password') !== ($record['password'] ?? null))
			$v->input('password')->regex($password['regex'], ts('`[FIELD]` must contain [MIN] to [MAX] characters', ['MIN' => $password['min_length'], 'MAX' => $password['max_length']]));

	}

	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		if((int)$record['id'] === \Model\User::getUID())$v->addError(ts('you cannot delete your own account'));
		if((int)$record['id'] === 1)$v->addError(ts('the system account cannot be deleted'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(isset($row['login']))$row['login'] = mb_strtolower($row['login']);

		if(isset($row['password']))
		{

			$cfg = \Core\Config::get('auth/security/password');
			$row['password'] = password_hash($row['password'], $cfg['algo'], $cfg['algo_options']);

		}

		return $row;

	}

	// own account: the session follows (a new password signs out the other sessions, not this one)
	protected function onSaveAfterEdit(int $id, array $row, array $changed):void
	{

		if($id === \Model\User::getUID() && $changed)self::syncSession($row);

	}

	/**
	 * session of the logged user after a change of his own account (shared with the profile)
	 *
	 * @param array $row columns written
	 *
	 * @return void
	 */
	public static function syncSession(array $row):void
	{

		foreach(['login', 'email', 'firstname', 'lastname', 'language'] as $field)
			if(isset($row[$field]))\Core\Session::set("auth.{$field}", $row[$field]);

		if(isset($row['xcore_group_id']))\Core\Session::set('auth.group_id', (int)$row['xcore_group_id']);
		if(isset($row['password']))\Core\Session::set('auth.password_check', \Model\User::passwordCheck($row['password']));

	}

	// personal data of the user removed with him
	protected function onDeleteAfter(array $record):void
	{

		DB()->query("UPDATE xcore_user_filter SET deleted = 'yes', deleted_at = NOW(), deleted_by = :author WHERE xcore_user_id = :id AND deleted = 'no'", ['id' => (int)$record['id'], 'author' => $this->author()]);
		DB()->query("UPDATE xcore_user_bookmark SET deleted = 'yes', deleted_at = NOW(), deleted_by = :author WHERE xcore_user_id = :id AND deleted = 'no'", ['id' => (int)$record['id'], 'author' => $this->author()]);

	}

	/**
	 * open the session of the user, the backend reloads with his menu and rights
	 *
	 * @param array $record
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"redirect"}
	 */
	protected function rowConnectAs(array $record, Request $request):\Core\JsonResponse
	{

		if((int)$record['id'] === \Model\User::getUID())return \Core\JsonResponse::error(422, 'you are already connected with this account');

		$group = DB()->query("SELECT access_backend, active FROM xcore_group WHERE id = :id AND deleted = 'no'", ['id' => (int)$record['xcore_group_id']])->fetch();
		if($record['active'] !== 'yes' || !$group || $group['access_backend'] !== 'yes' || $group['active'] !== 'yes')
			return \Core\JsonResponse::error(422, 'this account cannot sign in to the backend');

		\Core\Log::write('connect_as', "connect as {$record['login']}", [], (int)$record['id'], 'warning', $this->module);
		\Core\Session::destroy();
		\Model\User::openSession($record);

		return new \Core\JsonResponse(['redirect' => '/'.\Core\Config::get('backend.dirname').'/']);

	}

	/**
	 * groups of the select and the tabs
	 *
	 * @return array id => name
	 */
	private function groups():array
	{

		$groups = [];
		foreach(DB()->query("SELECT id, name FROM xcore_group WHERE deleted = 'no' ORDER BY priority, name")->fetchAll() as $rec)
			$groups[$rec['id']] = $rec['name'];

		return $groups;

	}

	/**
	 * widget of the dashboard: the 10 last connected users of the backend and of the frontend
	 *
	 * @return array see \Core\Widget
	 */
	public static function widgetLastActive():array
	{

		$tabs = [];
		foreach(['backend' => 'Backend', 'frontend' => 'Frontend'] as $application => $label)
		{

			$recs = DB()->query("SELECT u.id, u.firstname, u.lastname, u.login, u.avatar, u.last_connection_date, u.last_connection_ip, g.name AS group_name FROM xcore_user u LEFT JOIN xcore_group g ON g.id = u.xcore_group_id WHERE u.deleted = 'no' AND u.last_connection_application = :app AND u.last_connection_date IS NOT NULL ORDER BY u.last_connection_date DESC LIMIT 10", ['app' => $application])->fetchAll();

			$rows = [];
			foreach($recs as $rec)
			{

				$rows[] = [
					\Core\Widget::avatar($rec),
					['text' => trim("{$rec['firstname']} {$rec['lastname']}") ?: $rec['login'], 'grow' => true, 'strong' => true],
					['text' => ts((string)$rec['group_name']), 'muted' => true, 'width' => 140],
					['text' => (string)$rec['last_connection_ip'], 'mono' => true, 'muted' => true, 'width' => 130],
					['date' => $rec['last_connection_date'], 'align' => 'right', 'width' => 120],
				];

			}

			$tabs[] = ['label' => ts($label), 'rows' => $rows, 'empty' => ts('No connection')];

		}

		return ['type' => 'tabs', 'tabs' => $tabs];

	}

}
