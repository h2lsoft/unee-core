<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * newsletters (xcore_newsletter): campaign, sender, content (template url or html), subjects (a/b tests), lists and schedule
 * sent by the cron task Newsletter sender (core/cron/newsletter_sender.php, \Core\Newsletter), one xcore_newsletter_data row per recipient
 * public routes (no logon, signed token): v = online view, u = unsubscribe, o = open pixel, c = click
 */
#[Route('/api/newsletter', name: 'api.newsletter.')]
class NewsletterController extends \Core\CrudController
{

	public string $table = 'xcore_newsletter';
	public string $title = 'Newsletters';
	public string $object_label = 'newsletter';

	// set by the code: never bound to a workflow
	public array $managed_columns = ['status'];
	public array $ucfirst = ['category', 'campaign', 'subject', 'subject2', 'subject3'];

	public const array STATUSES = ['draft' => 'draft', 'scheduled' => 'scheduled', 'sending' => 'sending', 'paused' => 'paused', 'sent' => 'sent', 'cancelled' => 'cancelled', 'error' => 'error'];
	public const array STATUS_COLORS = ['draft' => 'grey', 'scheduled' => 'blue', 'sending' => 'orange', 'paused' => 'yellow', 'sent' => 'green', 'cancelled' => 'grey', 'error' => 'red'];

	// statuses chosen in the form, the others are set by the sending
	private const array USER_STATUSES = ['draft', 'scheduled', 'cancelled'];

	// mailing lists: id => name
	private static function lists():array
	{

		return DB()->query("SELECT id, name FROM xcore_mailinglist WHERE deleted = 'no' ORDER BY name")->fetchAll(\PDO::FETCH_KEY_PAIR);

	}

	protected function grid(DataGrid $grid):void
	{

		\Core\Newsletter::register();

		$grid->setSearch(['subject', 'campaign', 'category'], 'Search subject, campaign...');
		$grid->addFilterChips('status', self::STATUSES);
		$grid->addFilter('subject', 'Subject');
		$grid->addFilter('campaign', 'Campaign');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('date', 'Date', 'date');
		$grid->addFilter('programming_date', 'Programmed on', 'date');
		$grid->setMobile('subject', 'date');
		$grid->batchEdit(['category']);
		$grid->setOrderByFirst('date', 'desc');
		$grid->rowAction('pause', 'Pause the sending', 'pause', 'edit');
		$grid->rowAction('resume', 'Resume the sending', 'play_arrow', 'edit');
		$grid->rowAction('cancel', 'Cancel the sending', 'cancel', 'edit');

		$grid->hookData(function(array $row):array{

			static $sent = null;
			$sent ??= DB()->query("SELECT xcore_newsletter_id, COUNT(*) FROM xcore_newsletter_data WHERE deleted = 'no' AND action = 'sent' GROUP BY xcore_newsletter_id")->fetchAll(\PDO::FETCH_KEY_PAIR);
			$row['_sent'] = (int)($sent[$row['id']] ?? 0);

			return $row;

		});

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addColDatetime('date', 'Date', true)->width(140);
		$grid->addCol('category', 'Category', true)->width(130);
		$grid->addCol('campaign', 'Campaign', true)->width(160);
		$grid->addCol('subject', 'Subject', true);
		$grid->addColDatetime('programming_date', 'Programmed on', true)->width(140);
		$grid->addColNumber('_sent', 'Sent')->width(90);
		$grid->addColBadge('status', 'Status', true, self::STATUS_COLORS)->labels(self::STATUSES)->width(100);

	}

	protected function form(Form $form, array $record):void
	{

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_newsletter WHERE deleted = 'no' AND category != '' ORDER BY category")->fetchAllOne();
		$credits = \Core\Mailer::credits();
		$credits_text = ($credits['monthly'] === 0) ? ts('Credits: unlimited') : ts('Credits: [REMAINING] / [MONTHLY] left ([PERCENT]%), reset on [RESET]', ['REMAINING' => number_format($credits['remaining'], 0, ',', ' '), 'MONTHLY' => number_format($credits['monthly'], 0, ',', ' '), 'PERCENT' => $credits['percent'], 'RESET' => $credits['reset']]);
		if($credits['reserve'] > 0)$credits_text .= ', '.ts('[RESERVE] kept for the transactional emails', ['RESERVE' => number_format($credits['reserve'], 0, ',', ' ')]);

		$form->addDatetime('date', 'Date', true)->defaultValue(date('Y-m-d H:i:s'));
		$form->addSelect('category', 'Category', array_combine($categories, $categories))->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addText('campaign', 'Campaign')->maxLength(255)->help('utm_campaign of the links');
		$form->addText('subject', 'Subject', true)->maxLength(255)->help(ts('Variables: [VARIABLES]', ['VARIABLES' => '[[firstname]], [[lastname]], [[email]]']));
		$form->addText('preheader', 'Preheader')->maxLength(255)->help('text shown after the subject by the mail clients');
		$form->addSelectMultiple('mailinglist_ids', 'Mailing lists', self::lists())->set('translate', false)->protect();
		$form->addText('_estimate', 'Estimated recipients')->readonly()->help('duplicates, unsubscribed and blacklisted emails removed, the patterns of the blacklist (*@domain) and invalid emails may reduce it further');
		$form->addRadio('status', 'Status', self::STATUSES, true)->defaultValue('draft')->help($credits_text)->protect();
		$form->addText('error_message', 'Error')->readonly()->showIf('status', ['error'])->protect();
		$form->addDatetime('programming_date', 'Programmed on')->help('empty = sent at the next run of the cron task Newsletter sender (every 15 minutes)');

		$form->tab('content', 'Content');
		$form->addRadio('content_type', 'Source', ['url' => 'Template url', 'html' => 'Html'], true)->defaultValue('url')->help(ts('Variables: [VARIABLES]', ['VARIABLES' => '[[firstname]], [[lastname]], [[email]], [[online_url]], [[unsubscribe_url]] (required)']));
		$form->addText('template_url', 'Template url')->maxLength(255)->placeholder('https://')->help('html page of the message')->showIf('content_type', ['url']);
		$form->addCode('content_html', 'Html', false, 'html')->showIf('content_type', ['html']);

		$form->tab('sender', 'Sender');
		$form->addEmail('expeditor_email', 'Sender email', true)->maxLength(255)->defaultValue((string)\Core\Config::get('noreply_email', ''));
		$form->addText('expeditor_email_label', 'Sender name')->maxLength(255);
		$form->addEmail('reply_to', 'Reply to')->maxLength(255);
		$form->addEmail('return_path', 'Return path')->maxLength(255)->help('address receiving the bounces (mailbox read by the cron task Newsletter bounces)');

		$form->tab('ab', 'A/B tests');
		$form->addText('subject2', 'Subject B')->maxLength(255);
		$form->addText('template_url2', 'Template url B')->maxLength(255)->placeholder('https://')->showIf('content_type', ['url']);
		$form->addText('subject3', 'Subject C')->maxLength(255);
		$form->addText('template_url3', 'Template url C')->maxLength(255)->placeholder('https://')->showIf('content_type', ['url']);

		$form->tab('options', 'Options');
		$form->addTextarea('link_add_parameters', 'Link parameters', false, 3)->maxLength(2000)->placeholder('utm_source=newsletter&utm_medium=email')->help('query string added to the links of the message');
		$form->addDatetime('scheduler_date_start', 'Sending from');
		$form->addDatetime('scheduler_date_end', 'Sending until');
		$form->addBoolean('scheduler_report_email', 'Email report');
		$form->addTextarea('scheduler_report_email_recipients', 'Report recipients', false, 2)->maxLength(2000)->placeholder('a@example.com, b@example.com')->showIf('scheduler_report_email', ['yes']);
		$form->addTextarea('test_emails', 'Test emails', false, 2)->maxLength(2000)->placeholder('a@example.com, b@example.com')->help('empty = your email');

		if($record)
		{

			$form->tab('stats', 'Stats');
			foreach(['_stat_sent' => 'Sent', '_stat_errors' => 'Errors', '_stat_skipped' => 'Skipped (blacklist, invalid)', '_stat_opens' => 'Opens', '_stat_clicks' => 'Clicks', '_stat_unsubscribes' => 'Unsubscribes', '_stat_bounces' => 'Bounces'] as $name => $label)
				$form->addText($name, $label)->readonly();

		}
		$form->tab('');

		$form->addAction('send-test', 'Send a test', 'send', 'edit');
		$form->addAction('estimate', 'Estimate the recipients', 'groups', 'list');

	}

	// estimate and stats (virtual fields)
	protected function getRecord(array $values, array $record):array
	{

		$ids = \Core\Newsletter::listIds($record);
		$values['_estimate'] = number_format(\Core\Newsletter::remainingRecipients(in_array($record['status'] ?? '', ['sending', 'paused', 'sent'], true) ? (int)$record['id'] : 0, $ids), 0, ',', ' ');

		if($record)
		{

			$s = \Core\Newsletter::stats((int)$record['id']);
			$rate = fn(int $value) => ($s['sent'] > 0) ? ' ('.round($value * 100 / $s['sent'], 1).'%)' : '';
			$values['_stat_sent'] = (string)$s['sent'];
			$values['_stat_errors'] = (string)$s['sent-error'];
			$values['_stat_skipped'] = (string)($s['blacklisted'] + $s['refused']);
			$values['_stat_opens'] = $s['view'].$rate($s['view']);
			$values['_stat_clicks'] = $s['click'].$rate($s['click']).' - '.ts('[COUNT] clicks', ['COUNT' => $s['clicks']]);
			$values['_stat_unsubscribes'] = $s['unsubscribe'].$rate($s['unsubscribe']);
			$values['_stat_bounces'] = $s['bounce'].$rate($s['bounce']);

		}

		return $values;

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		foreach(['template_url', 'template_url2', 'template_url3'] as $col)
		{

			$url = (string)$v->inputGet($col, $record[$col] ?? '');
			$v->input($col);
			if($url !== '' && !preg_match('#^https?://\S+$#i', $url))$v->addError(ts('`[FIELD]` must be an url (https://...)'));

		}

		foreach(['scheduler_report_email_recipients', 'test_emails'] as $col)
		{

			$v->input($col);
			foreach(array_filter(array_map('trim', preg_split('/[,;\s]+/', trim((string)$v->inputGet($col, ''))))) as $email)
				if(!filter_var($email, FILTER_VALIDATE_EMAIL)){ $v->addError(ts('`[FIELD]` holds an invalid email: [EMAIL]', ['EMAIL' => $email])); break; }

		}

		// status: draft, scheduled or cancelled by hand, the others by the sending
		$status = (string)$v->inputGet('status', $record['status'] ?? 'draft');
		$v->input('status');
		if($status !== ($record['status'] ?? '') && !in_array($status, self::USER_STATUSES, true))$v->addError(ts('this status is set by the sending'));
		if($status === 'scheduled' && ($record['status'] ?? '') === 'sent')$v->addError(ts('the newsletter is already sent'));

		// scheduled: the message must be sendable and the credits enough
		if($status === 'scheduled' && !$v->fails())
		{

			$newsletter = array_merge($record, $this->values($v));
			try
			{

				foreach(\Core\Newsletter::checks($newsletter, \Core\Newsletter::variants($newsletter)) as $error)
					$v->addError($error);

			}
			catch(\Throwable $e)
			{
				$v->addError($e->getMessage());
			}

			$needed = \Core\Newsletter::remainingRecipients(0, \Core\Newsletter::listIds($newsletter));
			$available = \Core\Newsletter::availableCredits((int)($record['id'] ?? 0));
			if($needed > $available)$v->addError(ts('insufficient credits: [NEEDED] needed, [AVAILABLE] available', ['NEEDED' => $needed, 'AVAILABLE' => $available]));

		}

	}

	// being sent: neither edited nor deleted
	protected function rulesOnUpdate(\Core\Validator $v, array $record):void
	{

		if(in_array($record['status'], \Core\Newsletter::LOCKED, true))$v->addError(ts('the newsletter is being sent: cancel it first'));

	}

	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		if(in_array($record['status'], \Core\Newsletter::LOCKED, true))$v->addError(ts('the newsletter is being sent: cancel it first'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		// rescheduled: the previous error and frozen html are dropped
		if(($row['status'] ?? '') === 'scheduled' && ($record['status'] ?? '') !== 'scheduled')
		{

			$row['error_message'] = '';
			$row['snapshot_json'] = null;

		}

		return $row;

	}

	/**
	 * values of the form sent to the validator (body), mailinglist_ids as stored (json)
	 *
	 * @param \Core\Validator $v
	 *
	 * @return array
	 */
	private function values(\Core\Validator $v):array
	{

		$values = [];
		foreach(['subject', 'subject2', 'subject3', 'preheader', 'campaign', 'link_add_parameters', 'content_type', 'content_html', 'template_url', 'template_url2', 'template_url3', 'expeditor_email', 'expeditor_email_label', 'reply_to', 'return_path'] as $col)
			if(($value = $v->inputGet($col, null)) !== null)$values[$col] = (string)$value;

		$values['mailinglist_ids'] = json_encode(array_values(array_map('strval', (array)$v->inputGet('mailinglist_ids', []))));

		return $values;

	}

	// actions **********************************************************************************************************

	/**
	 * test of every variant (a/b/c) with the values of the form, to test_emails or the logged user, subject prefixed [TEST], 1 email credit (marketing) each
	 *
	 * @param array $record
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	protected function rowSendTest(array $record, Request $request):\Core\JsonResponse
	{

		$newsletter = array_merge($record, array_map(fn($v) => is_array($v) ? $v : (string)$v, $request->request->all()));
		$newsletter['snapshot_json'] = null;

		$emails = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($newsletter['test_emails'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
		if(!$emails)$emails = [(string)\Core\Session::get('auth.email')];

		try
		{
			$variants = \Core\Newsletter::variants($newsletter);
		}
		catch(\Throwable $e)
		{
			return \Core\JsonResponse::error(422, $e->getMessage());
		}

		$errors = array_filter(\Core\Newsletter::checks(array_merge($newsletter, ['mailinglist_ids' => '["0"]']), $variants));
		if($errors)return \Core\JsonResponse::error(422, implode(' / ', $errors));

		if(!\Core\Mailer::canSend('marketing', count($emails) * count($variants)))return \Core\JsonResponse::error(422, ts('mail not sent: [ERROR]', ['ERROR' => ts('no email credits')]));

		$mail = \Core\Newsletter::mailer();
		$subscriber = ['id' => 0, 'email' => '', 'firstname' => (string)\Core\Session::get('auth.firstname'), 'lastname' => (string)\Core\Session::get('auth.lastname')];
		$credit = ['category' => 'marketing', 'module' => $this->module, 'action' => 'test', 'record_id' => (int)$record['id'], 'xcore_user_id' => \Model\User::getUID()];
		foreach($emails as $email)
		{

			foreach($variants as $i => $variant)
			{

				$letter = (count($variants) > 1) ? ' '.chr(65 + $i) : '';
				$rendered = \Core\Newsletter::render($variant, (int)$record['id'], array_merge($subscriber, ['email' => $email]), false);
				$rendered['subject'] = "[TEST{$letter}] {$rendered['subject']}";
				$error = \Core\Newsletter::send($mail, $newsletter, $email, $rendered);
				\Core\Mailer::record(($error === '') ? 'sent' : 'failed', $rendered['subject'], $credit);
				if($error !== '')return \Core\JsonResponse::error(422, ts('mail not sent: [ERROR]', ['ERROR' => $error]));

			}

		}
		if($mail->Mailer === 'smtp')$mail->smtpClose();

		\Core\Log::write('send_test', implode(', ', $emails), [], (int)$record['id'], 'info', $this->module);

		return new \Core\JsonResponse(['message' => ts('Test sent to [EMAIL]', ['EMAIL' => implode(', ', $emails)])]);

	}

	/**
	 * estimate of the lists of the form
	 *
	 * @param array $record
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	protected function rowEstimate(array $record, Request $request):\Core\JsonResponse
	{

		$ids = array_values(array_intersect(array_map('intval', (array)$request->request->all('mailinglist_ids')), array_keys(self::lists())));
		$count = \Core\Newsletter::remainingRecipients(0, $ids);
		$credits = \Core\Mailer::credits();

		$message = ts('[COUNT] recipients estimated (duplicates and blacklist removed)', ['COUNT' => number_format($count, 0, ',', ' ')]);
		if($credits['monthly'] > 0)$message .= ' - '.ts('[REMAINING] credits left', ['REMAINING' => number_format($credits['available'], 0, ',', ' ')]);

		return new \Core\JsonResponse(['message' => $message]);

	}

	protected function rowPause(array $record):\Core\JsonResponse
	{

		if($record['status'] !== 'sending')return \Core\JsonResponse::error(422, ts('the newsletter is not being sent'));
		return $this->setStatus($record, 'paused', ts('Sending paused'));

	}

	protected function rowResume(array $record):\Core\JsonResponse
	{

		if($record['status'] !== 'paused')return \Core\JsonResponse::error(422, ts('the newsletter is not paused'));
		return $this->setStatus($record, 'sending', ts('Sending resumed'));

	}

	protected function rowCancel(array $record):\Core\JsonResponse
	{

		if(!in_array($record['status'], ['scheduled', 'sending', 'paused'], true))return \Core\JsonResponse::error(422, ts('the newsletter is not scheduled or being sent'));
		return $this->setStatus($record, 'cancelled', ts('Sending cancelled'));

	}

	private function setStatus(array $record, string $status, string $message):\Core\JsonResponse
	{

		DB($this->table)->update(['status' => $status], (int)$record['id'], 1, $this->author());
		\Core\Log::write('edit', $this->object_label, ['status' => [$record['status'], $status]], (int)$record['id'], 'info', $this->module);

		return new \Core\JsonResponse(['message' => $message]);

	}

	/**
	 * widget of the dashboard: email credits of the period (every email of the application, newsletters included)
	 *
	 * @return array see \Core\Widget
	 */
	public static function widgetCredits():array
	{

		$c = \Core\Mailer::credits();
		$split = ['value' => $c['transactional'], 'label' => ts('Transactional'), 'color' => 'blue'];
		$marketing = ['value' => $c['marketing'], 'label' => ts('Marketing'), 'color' => 'yellow'];
		if($c['monthly'] === 0)return ['type' => 'counters', 'status' => ['label' => ts('Credits'), 'badge' => ['text' => ts('unlimited'), 'color' => 'green']], 'tiles' => [['value' => $c['used'], 'label' => ts('Sent this month'), 'color' => 'blue'], $split, $marketing]];

		$color = ($c['percent'] <= 10) ? 'red' : (($c['percent'] <= 25) ? 'orange' : 'green');
		return [
			'type' => 'counters',
			'status' => ['label' => ts('Credits left'), 'badge' => ['text' => "{$c['percent']}%", 'color' => $color]],
			'note' => ['text' => ts('reset on [DATE]', ['DATE' => $c['reset']])],
			'tiles' => [
				['value' => $c['remaining'], 'label' => ts('Left'), 'color' => $color],
				$split,
				$marketing,
				['value' => $c['monthly'], 'label' => ts('Monthly'), 'color' => 'grey'],
			],
		];

	}

	// public routes ****************************************************************************************************

	/**
	 * online view of the message of a recipient
	 *
	 * @param string $token
	 *
	 * @return Response
	 */
	#[Route('/v/{token}/', name: 'public.view', methods: ['GET'], defaults: ['_public' => true], requirements: ['token' => '\d+\.\d+\.[a-f0-9]{16}'], priority: 10)]
	public function publicView(string $token):Response
	{

		$found = $this->fromToken($token);
		if(!$found)return $this->page(404, 'This link is no longer valid.');
		[$newsletter, $subscriber] = $found;

		$variants = \Core\Newsletter::variants($newsletter);
		if(!$variants)return $this->page(404, 'This link is no longer valid.');
		$variant = $variants[(int)$subscriber['id'] % count($variants)];
		$rendered = \Core\Newsletter::render($variant, (int)$newsletter['id'], $subscriber, false);

		return new Response($rendered['html'], 200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex']);

	}

	/**
	 * unsubscribe: GET shows a confirmation (link scanners never unsubscribe), POST unsubscribes (button, or one-click of the mail clients rfc 8058)
	 *
	 * @param Request $request
	 * @param string $token
	 *
	 * @return Response
	 */
	#[Route('/u/{token}/', name: 'public.unsubscribe', methods: ['GET', 'POST'], defaults: ['_public' => true, '_csrf' => false], requirements: ['token' => '\d+\.\d+\.[a-f0-9]{16}'], priority: 10)]
	public function publicUnsubscribe(Request $request, string $token):Response
	{

		$found = $this->fromToken($token);
		if(!$found)return $this->page(404, 'This link is no longer valid.');
		[$newsletter, $subscriber] = $found;
		$lang = (string)($subscriber['language'] ?? 'en');

		if(!$request->isMethod('POST'))
			return $this->page(200, 'Unsubscribe [EMAIL] from our newsletters?', $lang, $subscriber['email'], self::url('u', $token));

		// a test (subscriber 0) never unsubscribes anybody
		if((int)$subscriber['id'] > 0)
		{

			\Core\Newsletter::unsubscribe($newsletter, $subscriber);
			\Core\Newsletter::log((int)$newsletter['id'], $subscriber, 'unsubscribe', '', '', (string)$request->getClientIp(), (string)$request->headers->get('User-Agent'));

		}

		return $this->page(200, '[EMAIL] is unsubscribed.', $lang, $subscriber['email']);

	}

	/**
	 * open pixel: first open of a recipient logged
	 *
	 * @param Request $request
	 * @param string $token
	 *
	 * @return Response 1x1 gif
	 */
	#[Route('/o/{token}/', name: 'public.open', methods: ['GET'], defaults: ['_public' => true], requirements: ['token' => '\d+\.\d+\.[a-f0-9]{16}'], priority: 10)]
	public function publicOpen(Request $request, string $token):Response
	{

		$found = $this->fromToken($token);
		if($found && (int)$found[1]['id'] > 0)
		{

			[$newsletter, $subscriber] = $found;
			$seen = DB()->query("SELECT 1 FROM xcore_newsletter_data WHERE deleted = 'no' AND xcore_newsletter_id = :nid AND email = :email AND action = 'view' LIMIT 1", ['nid' => $newsletter['id'], 'email' => $subscriber['email']])->fetchOne();
			if(!$seen)\Core\Newsletter::log((int)$newsletter['id'], $subscriber, 'view', '', '', (string)$request->getClientIp(), (string)$request->headers->get('User-Agent'));

		}

		return new Response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store, no-cache, must-revalidate']);

	}

	/**
	 * click: logged then redirected, the url is signed (never an open redirect)
	 *
	 * @param Request $request
	 * @param string $token
	 *
	 * @return Response 302
	 */
	#[Route('/c/{token}/', name: 'public.click', methods: ['GET'], defaults: ['_public' => true], requirements: ['token' => '\d+\.\d+\.[a-f0-9]{16}'], priority: 10)]
	public function publicClick(Request $request, string $token):Response
	{

		$url = (string)$request->query->get('u', '');
		if(!preg_match('#^https?://#i', $url) || !hash_equals(\Core\Newsletter::clickSignature($token, $url), (string)$request->query->get('s', '')))return $this->page(404, 'This link is no longer valid.');

		$found = $this->fromToken($token);
		if($found && (int)$found[1]['id'] > 0)
			\Core\Newsletter::log((int)$found[0]['id'], $found[1], 'click', '', $url, (string)$request->getClientIp(), (string)$request->headers->get('User-Agent'));

		return new Response('', 302, ['Location' => $url, 'Cache-Control' => 'no-store']);

	}

	/**
	 * newsletter and subscriber of a token (subscriber 0 = test: the logged user or a placeholder), null when invalid
	 *
	 * @param string $token
	 *
	 * @return array|null [newsletter, subscriber]
	 */
	private function fromToken(string $token):?array
	{

		if(!$ids = \Core\Newsletter::parseToken($token))return null;
		[$newsletter_id, $subscriber_id] = $ids;

		$newsletter = DB()->query("SELECT * FROM xcore_newsletter WHERE id = :id AND deleted = 'no'", ['id' => $newsletter_id])->fetch();
		if(!$newsletter)return null;

		if($subscriber_id === 0)return [$newsletter, ['id' => 0, 'xcore_mailinglist_id' => 0, 'email' => 'test@example.com', 'firstname' => 'Test', 'lastname' => 'Test', 'language' => 'en']];

		$subscriber = DB()->query("SELECT id, xcore_mailinglist_id, email, firstname, lastname, language FROM xcore_mailinglist_subscriber WHERE id = :id", ['id' => $subscriber_id])->fetch();
		return ($subscriber) ? [$newsletter, $subscriber] : null;

	}

	private static function url(string $type, string $token):string
	{

		return \Core\Newsletter::url($type, $token);

	}

	/**
	 * small public page (en, fr), a form with one button when $action is set
	 *
	 * @return Response
	 */
	private function page(int $code, string $text, string $lang='en', string $email='', string $action=''):Response
	{

		$fr = [
			'This link is no longer valid.' => 'Ce lien n\'est plus valide.',
			'Unsubscribe [EMAIL] from our newsletters?' => 'Désinscrire [EMAIL] de nos newsletters ?',
			'[EMAIL] is unsubscribed.' => '[EMAIL] est désinscrit.',
			'Unsubscribe' => 'Me désinscrire',
		];
		$t = fn(string $s) => (str_starts_with($lang, 'fr') && isset($fr[$s])) ? $fr[$s] : $s;

		$message = str_replace('[EMAIL]', '<b>'.htmlspecialchars($email).'</b>', htmlspecialchars($t($text)));
		$button = ($action !== '') ? '<form method="post" action="'.htmlspecialchars($action).'"><button type="submit" style="height:40px;padding:0 20px;border:0;border-radius:6px;background:#1f2335;color:#fff;font:600 14px system-ui,sans-serif;cursor:pointer">'.htmlspecialchars($t('Unsubscribe')).'</button></form>' : '';
		$html = '<!doctype html><html lang="'.htmlspecialchars(substr($lang, 0, 2)).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Newsletter</title></head>'
			.'<body style="margin:0;background:#f7f8fb;font:15px/1.5 system-ui,sans-serif;color:#1f2335"><div style="max-width:480px;margin:80px auto;padding:32px;background:#fff;border-radius:8px;text-align:center"><p style="margin:0 0 24px">'.$message.'</p>'.$button.'</div></body></html>';

		return new Response($html, $code, ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex']);

	}

}
