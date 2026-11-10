<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * sms (xcore_sms, \Core\Sms): one-off (numbers, users, groups) or campaign (mailing lists), sent by the cron task SMS sender (core/cron/sms_sender.php)
 * settings of the provider and of the credits: GET / PUT /api/sms/settings/ (right settings), test POST /api/sms/settings/action/send-test/
 * delivery reports of the provider: /api/sms/report/<token>/ (public, token of the globals sms.report.token)
 */
#[Route('/api/sms', name: 'api.sms.')]
class SmsController extends \Core\CrudController
{

	public string $table = 'xcore_sms';
	public string $title = 'SMS';
	public string $object_label = 'SMS';

	// set by the sending: never bound to a workflow
	public array $managed_columns = ['status'];
	// the message is sent as typed
	public array $ucfirst = [];

	public const array STATUSES = ['draft' => 'draft', 'scheduled' => 'scheduled', 'sending' => 'sending', 'sent' => 'sent', 'cancelled' => 'cancelled', 'error' => 'error'];
	public const array STATUS_COLORS = ['draft' => 'grey', 'scheduled' => 'blue', 'sending' => 'orange', 'sent' => 'green', 'cancelled' => 'grey', 'error' => 'red'];

	// statuses chosen in the form, the others are set by the sending
	private const array USER_STATUSES = ['draft', 'scheduled', 'cancelled'];

	// mailing lists: id => name (with the number of mobiles)
	private static function lists(bool $counts=false):array
	{

		$lists = [];
		foreach(DB()->query("SELECT l.id, l.name, (SELECT COUNT(*) FROM xcore_mailinglist_subscriber s WHERE s.deleted = 'no' AND s.unsubscribe_date IS NULL AND s.mobile != '' AND s.xcore_mailinglist_id = l.id) AS n FROM xcore_mailinglist l WHERE l.deleted = 'no' ORDER BY l.name")->fetchAll() as $rec)
			$lists[$rec['id']] = ($counts) ? $rec['name'].' ('.ts('[COUNT] mobiles', ['COUNT' => $rec['n']]).')' : $rec['name'];

		return $lists;

	}

	protected function grid(DataGrid $grid):void
	{

		\Core\Sms::register();
		$lists = self::lists();

		$grid->qSelect("COALESCE(sent_at, programming_date, created_at)", 'date');
		$grid->setSearch(['message', 'numbers', 'sender'], 'Search message, number...');
		$grid->addFilterChips('status', self::STATUSES);
		$grid->addFilter('type', 'Type', 'select', \Core\Sms::TYPES);
		$grid->addFilter('date', 'Date', 'date', [], 'COALESCE(sent_at, programming_date, created_at)');
		$grid->setMobile('message', 'date');
		$grid->setOrderByFirst('id', 'desc');
		$grid->rowAction('cancel', 'Cancel the sending', 'cancel', 'edit');

		// credits page: hidden module sms-credit
		if(\Model\User::hasRight('sms-credit', 'list'))
		{

			$c = \Core\Sms::credits();
			$grid->gridAction('credits', ($c['unlimited']) ? ts('Credits') : ts('Credits').' ('.number_format($c['remaining'], 0, ',', ' ').')', 'payments', 'list', url: 'sms-credit/');

		}
		$grid->gridAction('settings', 'Settings', 'settings', 'settings', form: 'sms/settings/');

		$grid->hookData(function(array $row) use($lists):array{

			$sub = [];
			if($row['sender'] !== '')$sub[] = $row['sender'];
			if($row['type'] === 'campaign')$sub[] = implode(', ', array_intersect_key($lists, array_flip(\Core\Sms::ids($row['mailinglist_ids']))));
			else
			{

				$numbers = \Core\Sms::numbers($row['numbers']);
				if($numbers)$sub[] = (count($numbers) <= 2) ? implode(', ', $numbers) : ts('[COUNT] numbers', ['COUNT' => count($numbers)]);
				if($users = count(\Core\Sms::ids($row['xcore_user_ids'])))$sub[] = ts('[COUNT] users', ['COUNT' => $users]);
				if($groups = count(\Core\Sms::ids($row['xcore_group_ids'])))$sub[] = ts('[COUNT] groups', ['COUNT' => $groups]);

			}
			if($row['marketing'] === 'yes')$sub[] = ts('marketing');
			if((int)$row['parts'] > 0)$sub[] = ts('[COUNT] SMS', ['COUNT' => $row['parts']]);
			if($row['error_message'] !== '')$sub[] = $row['error_message'];
			$row['_sub'] = implode(' - ', array_filter($sub, 'strlen'));

			$recipients = (int)$row['recipients'];
			$delivered = (int)$row['delivered'];
			$row['_delivered'] = ($delivered > 0) ? number_format($delivered, 0, ',', ' ').' / '.number_format($recipients, 0, ',', ' ').' ('.(int)round($delivered * 100 / max(1, $recipients)).'%)' : '-';

			return $row;

		});

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addColDatetime('date', 'Date', true)->width(140);
		$grid->addColBadge('type', 'Type', true, ['one-off' => 'grey', 'campaign' => 'blue'])->labels(\Core\Sms::TYPES)->width(100);
		$grid->addCol('message', 'Message')->subText('_sub');
		$grid->addColNumber('recipients', 'Recipients', true)->width(110);
		$grid->addColBadge('status', 'Status', true, self::STATUS_COLORS)->labels(self::STATUSES)->width(100);
		$grid->addColNumber('credits_used', 'Credits', true)->width(90);
		$grid->addCol('_delivered', 'Delivered')->width(150);

	}

	protected function form(Form $form, array $record):void
	{

		$s = \Core\Sms::settings();
		$groups = DB()->query("SELECT id, name FROM xcore_group WHERE deleted = 'no' AND id != :system ORDER BY priority, name", ['system' => \Model\User::GROUP_SYSTEM])->fetchAll(\PDO::FETCH_KEY_PAIR);
		$credits = \Core\Sms::credits();
		$credits_text = ($credits['unlimited']) ? ts('Credits: unlimited') : ts('Credits: [AVAILABLE] available', ['AVAILABLE' => number_format(\Core\Sms::available(), 0, ',', ' ')]);
		$stop = trim($s['sms.stop_text']);
		$stop_help = ($stop !== '') ? ts('the STOP mention is added: [TEXT]', ['TEXT' => $stop]) : (($s['sms.provider'] === 'ovh') ? ts('the STOP mention is added by OVHcloud (11 characters)') : ts('no STOP mention in the settings: write it in the message'));

		$form->addRadio('type', 'Type', \Core\Sms::TYPES, true)->defaultValue('one-off')->protect();
		$form->addTags('numbers', 'Numbers', false, [], '/^\+?[0-9 ().\/-]{6,24}$/')->placeholder(ts('Type a mobile number, Enter'))->help(ts('international format, a number starting with 0 gets the default country ([CODE])', ['CODE' => $s['sms.country_code']]))->showIf('type', ['one-off'])->protect();
		$form->addSelectMultipleUser('xcore_user_ids', 'Users')->help('their mobile, users without one left out')->showIf('type', ['one-off'])->protect();
		$form->addSelectMultiple('xcore_group_ids', 'Groups', $groups)->set('translate', false)->help('the active users of the groups with a mobile')->showIf('type', ['one-off'])->protect();
		$form->addSelectMultiple('mailinglist_ids', 'Mailing lists', self::lists(true))->set('translate', false)->help('subscribers with a mobile, the blacklisted numbers (STOP) left out')->showIf('type', ['campaign'])->protect();
		$form->addBoolean('marketing', 'Marketing message')->help($stop_help)->showIf('type', ['campaign'])->protect();
		$form->addText('sender', 'Sender')->maxLength(16)->defaultValue($s['sms.sender'])->help('3 to 11 letters or digits, or a number, declared at the provider');
		$form->addTextarea('message', 'Message', true, 4)->maxLength(1600)->help(ts('Variables: [VARIABLES]', ['VARIABLES' => '[[firstname]], [[lastname]]']))->set('counter', ['type' => 'sms', 'field' => 'marketing', 'extra' => \Core\Sms::stopLength()])->protect();
		$form->addRadio('status', 'Status', self::STATUSES, true)->defaultValue('draft')->help($credits_text)->protect();
		$form->addText('error_message', 'Error')->readonly()->showIf('status', ['error'])->protect();
		$form->addDatetime('programming_date', 'Send on')->help('empty = sent at the next run of the cron task SMS sender (every 5 minutes)');
		if($record)$form->addText('_estimate', 'Recipients')->readonly();

		$form->addAction('send-test', 'Send a test to my mobile', 'send', 'edit');
		$form->addAction('estimate', 'Estimate the credits', 'calculate', 'list');

	}

	// estimate or result of the sending (virtual field)
	protected function getRecord(array $values, array $record):array
	{

		if(!$record)return $values;

		if(in_array($record['status'], ['draft', 'scheduled'], true))$values['_estimate'] = $this->estimateText(\Core\Sms::estimate($record));
		else $values['_estimate'] = ts('[RECIPIENTS] recipients, [CREDITS] credits used, [DELIVERED] delivered, [FAILED] failed', ['RECIPIENTS' => $record['recipients'], 'CREDITS' => $record['credits_used'], 'DELIVERED' => $record['delivered'], 'FAILED' => $record['failed']]);

		return $values;

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$type = (string)$v->inputGet('type', $record['type'] ?? 'one-off');
		if($type === 'one-off')
		{

			$numbers = (array)$v->inputGet('numbers', []);
			$v->input('numbers');
			foreach($numbers as $number)
				if(\Core\Sms::normalize((string)$number) === ''){ $v->addError(ts('`[FIELD]` holds an invalid number: [NUMBER]', ['NUMBER' => $number])); break; }
			if(!$numbers && !(array)$v->inputGet('xcore_user_ids', []) && !(array)$v->inputGet('xcore_group_ids', []))$v->addError(ts('choose numbers, users or groups'));

		}
		else
		{

			$v->input('mailinglist_ids');
			if(!(array)$v->inputGet('mailinglist_ids', []))$v->addError(ts('choose at least one mailing list'));

		}

		$sender = trim((string)$v->inputGet('sender', ''));
		$v->input('sender');
		if($sender !== '' && !preg_match('/^[A-Za-z0-9 ]{3,11}$/', $sender) && !preg_match('/^\+?\d{3,15}$/', $sender))$v->addError(ts('`[FIELD]` must be 3 to 11 letters or digits, or a number'));

		// status: draft, scheduled or cancelled by hand, the others by the sending (a copy starts as a draft)
		$status = (string)$v->inputGet('status', $record['status'] ?? 'draft');
		$v->input('status');
		if($record && $status !== $record['status'] && !in_array($status, self::USER_STATUSES, true))$v->addError(ts('this status is set by the sending'));

		// scheduled: recipients and credits checked, a sms resumed after an error keeps its reserved credits
		$started = ($record) && DB()->query("SELECT 1 FROM xcore_sms_data WHERE xcore_sms_id = :id AND deleted = 'no' LIMIT 1", ['id' => $record['id']])->fetchOne();
		if($status === 'scheduled' && !$started && !$v->fails())
		{

			$input = [];
			foreach(['type', 'sender', 'message', 'marketing', 'numbers', 'xcore_user_ids', 'xcore_group_ids', 'mailinglist_ids'] as $col)
				if(($value = $v->inputGet($col, null)) !== null)$input[$col] = $value;

			$estimate = \Core\Sms::estimate($this->fromInput($input, $record));
			$available = \Core\Sms::available();
			if($estimate['recipients'] === 0)$v->addError(ts('no recipient with a valid mobile'));
			elseif($estimate['credits'] > $available)$v->addError(ts('insufficient credits: [NEEDED] needed, [AVAILABLE] available', ['NEEDED' => $estimate['credits'], 'AVAILABLE' => $available]));

		}

	}

	// being sent or sent: never edited, sending: never deleted
	protected function rulesOnUpdate(\Core\Validator $v, array $record):void
	{

		if($record['status'] === 'sending')$v->addError(ts('the SMS is being sent: cancel it first'));
		if($record['status'] === 'sent')$v->addError(ts('the SMS is sent: duplicate it to send it again'));

	}

	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		if($record['status'] === 'sending')$v->addError(ts('the SMS is being sent: cancel it first'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		// targets of the other type emptied, numbers in international format
		if(($row['type'] ?? $record['type'] ?? '') === 'campaign')
		{

			$row['numbers'] = '[]';
			$row['xcore_user_ids'] = '[]';
			$row['xcore_group_ids'] = '[]';

		}
		else
		{

			$row['mailinglist_ids'] = '[]';
			$row['marketing'] = 'no';
			if(isset($row['numbers']))$row['numbers'] = json_encode(array_values(array_unique(array_map(fn($n) => \Core\Sms::normalize($n) ?: $n, \Core\Sms::numbers($row['numbers'])))));

		}

		if(!$record && !in_array($row['status'] ?? 'draft', self::USER_STATUSES, true))$row['status'] = 'draft';
		if(($row['status'] ?? '') === 'scheduled' && ($record['status'] ?? '') !== 'scheduled')$row['error_message'] = '';

		return $row;

	}

	/**
	 * sms from the values of the form (not saved): json columns like the table
	 *
	 * @param array $input
	 * @param array $record
	 *
	 * @return array
	 */
	private function fromInput(array $input, array $record):array
	{

		$sms = $record;
		foreach(['type', 'sender', 'message', 'marketing'] as $col)
			if(isset($input[$col]) && is_scalar($input[$col]))$sms[$col] = trim((string)$input[$col]);
		foreach(['numbers', 'xcore_user_ids', 'xcore_group_ids', 'mailinglist_ids'] as $col)
			if(isset($input[$col]))$sms[$col] = json_encode(array_values(array_map('strval', (array)$input[$col])));

		$sms['type'] ??= 'one-off';
		if($sms['type'] !== 'campaign')$sms['marketing'] = 'no';

		return $sms;

	}

	private function estimateText(array $estimate):string
	{

		$text = ts('[RECIPIENTS] recipients, [CREDITS] credits needed, [AVAILABLE] available', ['RECIPIENTS' => number_format($estimate['recipients'], 0, ',', ' '), 'CREDITS' => number_format($estimate['credits'], 0, ',', ' '), 'AVAILABLE' => (\Core\Sms::credits()['unlimited']) ? ts('unlimited') : number_format(\Core\Sms::available(), 0, ',', ' ')]);
		if($estimate['skipped'] > 0)$text .= ' - '.ts('[COUNT] left out (invalid or blacklisted)', ['COUNT' => $estimate['skipped']]);

		return $text;

	}

	// actions **********************************************************************************************************

	/**
	 * estimate with the values of the form
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	protected function rowEstimate(array $record, Request $request):\Core\JsonResponse
	{

		return new \Core\JsonResponse(['message' => $this->estimateText(\Core\Sms::estimate($this->fromInput($request->request->all(), $record)))]);

	}

	/**
	 * the message of the form sent to the mobile of the logged user (variables = his names), 1 credit at least
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	protected function rowSendTest(array $record, Request $request):\Core\JsonResponse
	{

		$user = DB()->query("SELECT mobile, firstname, lastname FROM xcore_user WHERE id = :id", ['id' => \Model\User::getUID()])->fetch() ?: [];
		$number = \Core\Sms::normalize((string)($user['mobile'] ?? ''));
		if($number === '')return \Core\JsonResponse::error(422, ts('add a valid mobile to your profile first'));

		$sms = $this->fromInput($request->request->all(), $record);
		if(trim((string)($sms['message'] ?? '')) === '')return \Core\JsonResponse::error(422, ts('the message is empty'));

		$message = str_replace(['[[firstname]]', '[[lastname]]'], [(string)$user['firstname'], (string)$user['lastname']], (string)$sms['message']);
		$result = \Core\Sms::send([$number], $message, ['sender' => (string)($sms['sender'] ?? ''), 'marketing' => ($sms['marketing'] ?? 'no') === 'yes', 'author' => $this->author()]);
		if($result['error'] !== '' || $result['sent'] === 0)return \Core\JsonResponse::error(422, ts('SMS not sent: [ERROR]', ['ERROR' => $result['error'] ?: $this->rowError($result['id'])]));

		return new \Core\JsonResponse(['message' => ts('Test sent to [NUMBER]', ['NUMBER' => $number])]);

	}

	protected function rowCancel(array $record):\Core\JsonResponse
	{

		if(!in_array($record['status'], ['scheduled', 'sending', 'error'], true))return \Core\JsonResponse::error(422, ts('the SMS is not scheduled or being sent'));

		\Core\Sms::cancel((int)$record['id']);
		\Core\Log::write('edit', $this->object_label, ['status' => [$record['status'], 'cancelled']], (int)$record['id'], 'info', $this->module);

		return new \Core\JsonResponse(['message' => ts('Sending cancelled')]);

	}

	// error of the first failed row of a sms
	private function rowError(int $sms_id):string
	{

		return (string)DB()->query("SELECT error FROM xcore_sms_data WHERE xcore_sms_id = :id AND status = 'failed' ORDER BY id LIMIT 1", ['id' => $sms_id])->fetchOne();

	}

	/**
	 * widget of the dashboard: credits (next to the newsletter one)
	 *
	 * @return array see \Core\Widget
	 */
	public static function widgetCredits():array
	{

		return \Core\Sms::widget();

	}

	// settings *********************************************************************************************************

	/**
	 * form of the settings and its fields => globals
	 *
	 * @return array{0: Form, 1: array}
	 */
	private function settingsForm():array
	{

		$s = \Core\Sms::settings();
		$form = new Form();
		$map = [];
		$name = function(string $global) use(&$map):string{

			$field = str_replace('.', '_', substr($global, 4));
			$map[$field] = $global;
			return $field;

		};

		$form->addRadio($name('sms.provider'), 'Provider', array_map(fn($p) => $p['label'], \Core\SmsProvider::PROVIDERS), true)->set('translate', false);
		foreach(\Core\SmsProvider::PROVIDERS as $provider => $p)
		{

			$first = true;
			foreach($p['fields'] as $key => $f)
			{

				$global = "sms.{$provider}.{$key}";
				$field = $name($global);
				match($f['type'] ?? 'text')
				{
					'secret' => $form->addPassword($field, $f['label'], !empty($f['required']))->set('generate', false)->placeholder(($s[$global] !== '') ? ts('******** (kept, type to change)') : ''),
					'select' => $form->addSelect($field, $f['label'], $f['options'], !empty($f['required']))->set('translate', false),
					'radio' => $form->addRadio($field, $f['label'], $f['options'], !empty($f['required']))->set('translate', false),
					'textarea' => $form->addTextarea($field, $f['label'], !empty($f['required']), 3)->maxLength(2000),
					default => $form->addText($field, $f['label'], !empty($f['required']))->maxLength(255),
				};
				if(!empty($f['placeholder']) && ($f['type'] ?? '') !== 'secret')$form->placeholder($f['placeholder']);
				$help = trim((($first) ? ts($p['help']) : '').' '.(isset($f['help']) ? ts($f['help']) : ''));
				if($help !== '')$form->help($help);
				$form->showIf('provider', [$provider]);
				$first = false;

			}

		}

		$form->addText($name('sms.sender'), 'Default sender')->maxLength(16)->help('3 to 11 letters or digits, or a number, declared at the provider');
		$form->addText($name('sms.country_code'), 'Default country code', true)->maxLength(5)->help('for the numbers typed with a leading 0, ex: +33');
		$form->addText($name('sms.stop_text'), 'STOP mention')->maxLength(60)->placeholder('STOP au XXXXX')->help('added to the marketing messages (counted in the length): Octopush STOP au XXXXX, Brevo STOP au [STOP_CODE], empty for OVHcloud and SMSFactor which add their own');
		$form->addText('_report_url', 'Delivery reports url')->readonly()->help('to declare at the provider (OVHcloud, SMSFactor, Octopush), sent with each message to Twilio and Brevo');
		$form->addRadio($name('sms.credits.mode'), 'Credits', \Core\Sms::MODES, true);
		$form->addNumber($name('sms.credits.monthly'), 'Monthly allowance', true, true)->help('0 = unlimited, the unused credits are lost on the reset day')->showIf('credits_mode', ['monthly']);
		$form->addNumber($name('sms.credits.reset_day'), 'Reset day', true, true)->help('day of the month (1-28)')->showIf('credits_mode', ['monthly']);
		$form->addNumber($name('sms.alert.below'), 'Alert below', false, true)->help('a notification to the super admins, once by period or by purchase, 0 = never');
		$form->addText('_test_mobile', 'Test mobile')->maxLength(24)->placeholder('+33 6 12 34 56 78')->help('saved settings or not, the test uses the values of the form');
		$form->addAction('send-test', 'Send a test', 'send', 'settings');

		return [$form, $map];

	}

	/**
	 * settings of the provider and of the credits (secrets never sent)
	 *
	 * @return \Core\JsonResponse {"form", "record"}
	 */
	#[Route('/settings/', name: 'settings.read', methods: ['GET'])]
	public function apiSettings():\Core\JsonResponse
	{

		if($denied = $this->deny('settings'))return $denied;

		[$form, $map] = $this->settingsForm();
		$s = \Core\Sms::settings();
		$values = [];
		foreach($map as $field => $global)
			$values[$field] = (\Core\Sms::isSecret($global)) ? '' : $s[$global];
		$values['_report_url'] = \Core\Sms::reportUrl();
		$values['_test_mobile'] = (string)DB()->query("SELECT mobile FROM xcore_user WHERE id = :id", ['id' => \Model\User::getUID()])->fetchOne();

		return new \Core\JsonResponse(['form' => $form->definition(), 'record' => $values]);

	}

	/**
	 * saves the settings, a secret left empty is kept
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"id": 0}, 422 {"fields"}
	 */
	#[Route('/settings/', name: 'settings.update', methods: ['PUT'])]
	public function apiSettingsUpdate(Request $request):\Core\JsonResponse
	{

		if($denied = $this->deny('settings'))return $denied;

		[$form, $map] = $this->settingsForm();
		$v = $this->validator;
		$this->settingsRules($form, $v);
		if($v->fails())return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $v->result()['error_stack_deep']]);

		$s = \Core\Sms::settings();
		$values = [];
		$changed = [];
		foreach($form->hydrate($request->request->all(), true) as $field => $value)
		{

			if(!isset($map[$field]))continue;
			$global = $map[$field];
			$value = (string)$value;
			if($value === $s[$global])continue;

			$values[$global] = $value;
			$changed[$global] = (\Core\Sms::isSecret($global)) ? '********' : [$s[$global], $value];

		}

		if($values)
		{

			\Core\Sms::put($values);
			\Core\Log::write('edit', 'settings', $changed, 0, 'info', $this->module);

		}

		return new \Core\JsonResponse(['id' => 0]);

	}

	/**
	 * test of the settings form: values of the form (not saved), secrets left empty = saved ones, a one-off sms to the test mobile
	 *
	 * @param Request $request
	 * @param string $action
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	#[Route('/settings/action/{action}/', name: 'settings.action', methods: ['POST'], requirements: ['action' => 'send-test'])]
	public function apiSettingsAction(Request $request, string $action):\Core\JsonResponse
	{

		if($denied = $this->deny('settings'))return $denied;

		[$form, $map] = $this->settingsForm();
		$v = $this->validator;
		$this->settingsRules($form, $v);
		if($v->fails())return \Core\JsonResponse::error(422, implode(' / ', array_merge(...array_values($v->result()['error_stack_deep']))));

		$settings = \Core\Sms::settings();
		foreach($form->hydrate($request->request->all(), true) as $field => $value)
			if(isset($map[$field]))$settings[$map[$field]] = (string)$value;

		$number = \Core\Sms::normalize((string)$request->request->get('_test_mobile', ''));
		if($number === '')return \Core\JsonResponse::error(422, ts('the test mobile is not a valid number'));

		\Core\Sms::$settings = $settings;
		try
		{

			$result = \Core\Sms::send([$number], ts('This is a test SMS.'), ['author' => $this->author()]);

		}
		finally
		{

			\Core\Sms::$settings = null;

		}

		if($result['error'] !== '' || $result['sent'] === 0)return \Core\JsonResponse::error(422, ts('SMS not sent: [ERROR]', ['ERROR' => $result['error'] ?: $this->rowError($result['id'])]));

		return new \Core\JsonResponse(['message' => ts('Test sent by [PROVIDER] to [NUMBER]', ['PROVIDER' => \Core\SmsProvider::PROVIDERS[$settings['sms.provider']]['label'], 'NUMBER' => $number])]);

	}

	private function settingsRules(Form $form, \Core\Validator $v):void
	{

		// 1 = update: a secret left empty is kept
		$form->rules($v, 'xcore_globals', 1);

		$v->input('sender');
		$sender = trim((string)$v->inputGet('sender', ''));
		if($sender !== '' && !preg_match('/^[A-Za-z0-9 ]{3,11}$/', $sender) && !preg_match('/^\+?\d{3,15}$/', $sender))$v->addError(ts('`[FIELD]` must be 3 to 11 letters or digits, or a number'));

		$v->input('country_code');
		if(!preg_match('/^\+?\d{1,4}$/', trim((string)$v->inputGet('country_code', ''))))$v->addError(ts('`[FIELD]` must be a country code, ex: +33'));

		if((string)$v->inputGet('credits_mode', '') === 'monthly')
		{

			$v->input('credits_reset_day');
			$day = (int)$v->inputGet('credits_reset_day', 0);
			if($day < 1 || $day > 28)$v->addError(ts('`[FIELD]` must be between 1 and 28'));

		}

		if((string)$v->inputGet('provider', '') === 'generic')
		{

			$v->input('generic_url');
			if(!preg_match('#^https?://\S+$#i', trim((string)$v->inputGet('generic_url', ''))))$v->addError(ts('`[FIELD]` must be an url (https://...)'));

		}

	}

	// delivery reports *************************************************************************************************

	/**
	 * delivery reports of the provider (GET query string or POST form / json), always 200 for a valid token
	 *
	 * @param Request $request
	 * @param string $token
	 *
	 * @return Response
	 */
	#[Route('/report/{token}/', name: 'public.report', methods: ['GET', 'POST'], defaults: ['_public' => true, '_csrf' => false], requirements: ['token' => '[a-f0-9]{16,64}'], priority: 10)]
	public function publicReport(Request $request, string $token):Response
	{

		if(!hash_equals((string)\Core\Sms::settings()['sms.report.token'], $token))return new Response('not found', 404, ['Content-Type' => 'text/plain']);

		$data = array_merge($request->query->all(), $request->request->all());
		$json = json_decode((string)$request->getContent(), true);
		if(is_array($json))$data = array_merge($data, $json);

		\Core\Sms::report($data);

		return new Response('OK', 200, ['Content-Type' => 'text/plain']);

	}

}
