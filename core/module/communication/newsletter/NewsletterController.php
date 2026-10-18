<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * newsletters (xcore_newsletter): campaign, sender, subjects (a/b tests), html template urls, lists and schedule
 * entry only: the sending (xcore_newsletter_data) comes later
 */
#[Route('/api/newsletter', name: 'api.newsletter.')]
class NewsletterController extends \Core\CrudController
{

	public string $table = 'xcore_newsletter';
	public string $title = 'Newsletters';
	public string $object_label = 'newsletter';
	public array $ucfirst = ['category', 'campaign', 'subject', 'subject2', 'subject3'];

	public const array STATUSES = ['draft' => 'draft', 'scheduled' => 'scheduled', 'sending' => 'sending', 'sent' => 'sent', 'cancelled' => 'cancelled'];
	public const array STATUS_COLORS = ['draft' => 'grey', 'scheduled' => 'blue', 'sending' => 'orange', 'sent' => 'green', 'cancelled' => 'red'];

	// mailing lists: id => name
	private static function lists():array
	{

		return DB()->query("SELECT id, name FROM xcore_mailinglist WHERE deleted = 'no' ORDER BY name")->fetchAll(\PDO::FETCH_KEY_PAIR);

	}

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['subject', 'campaign', 'category'], 'Search subject, campaign...');
		$grid->addFilterChips('status', self::STATUSES);
		$grid->addFilter('subject', 'Subject');
		$grid->addFilter('campaign', 'Campaign');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('date', 'Date', 'date');
		$grid->addFilter('programming_date', 'Programmed on', 'date');
		$grid->setMobile('subject', 'date');
		$grid->batchEdit(['status', 'category']);
		$grid->setOrderByFirst('date', 'desc');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addColDatetime('date', 'Date', true)->width(140);
		$grid->addCol('category', 'Category', true)->width(130);
		$grid->addCol('campaign', 'Campaign', true)->width(160);
		$grid->addCol('subject', 'Subject', true);
		$grid->addColDatetime('programming_date', 'Programmed on', true)->width(140);
		$grid->addColBadge('status', 'Status', true, self::STATUS_COLORS)->labels(self::STATUSES)->width(100);

	}

	protected function form(Form $form, array $record):void
	{

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_newsletter WHERE deleted = 'no' AND category != '' ORDER BY category")->fetchAllOne();

		$form->addDatetime('date', 'Date', true)->defaultValue(date('Y-m-d H:i:s'));
		$form->addSelect('category', 'Category', array_combine($categories, $categories))->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addText('campaign', 'Campaign')->maxLength(255)->help('utm_campaign of the links');
		$form->addText('subject', 'Subject', true)->maxLength(255);
		$form->addText('template_url', 'Template url', true)->maxLength(255)->placeholder('https://')->help('html page of the message');
		$form->addTags('_lists', 'Mailing lists', false, array_values(self::lists()), '/^.{1,255}$/u')->help('names of the mailing lists');
		$form->addRadio('status', 'Status', self::STATUSES, true)->defaultValue('draft');
		$form->addDatetime('programming_date', 'Programmed on');

		$form->tab('sender', 'Sender');
		$form->addEmail('expeditor_email', 'Sender email', true)->maxLength(255)->defaultValue((string)\Core\Config::get('noreply_email', ''));
		$form->addText('expeditor_email_label', 'Sender name')->maxLength(255);
		$form->addEmail('reply_to', 'Reply to')->maxLength(255);
		$form->addEmail('return_path', 'Return path')->maxLength(255)->help('address receiving the bounces');

		$form->tab('ab', 'A/B tests');
		$form->addText('subject2', 'Subject B')->maxLength(255);
		$form->addText('template_url2', 'Template url B')->maxLength(255)->placeholder('https://');
		$form->addText('subject3', 'Subject C')->maxLength(255);
		$form->addText('template_url3', 'Template url C')->maxLength(255)->placeholder('https://');

		$form->tab('options', 'Options');
		$form->addTextarea('link_add_parameters', 'Link parameters', false, 3)->maxLength(2000)->placeholder('utm_source=newsletter&utm_medium=email')->help('query string added to the links of the message');
		$form->addDatetime('scheduler_date_start', 'Sending from');
		$form->addDatetime('scheduler_date_end', 'Sending until');
		$form->addBoolean('scheduler_report_email', 'Email report');
		$form->addTextarea('scheduler_report_email_recipients', 'Report recipients', false, 2)->maxLength(2000)->placeholder('a@example.com, b@example.com')->showIf('scheduler_report_email', ['yes']);
		$form->tab('');

	}

	// mailing lists: "[1][3]" in the table, names in the form
	protected function getRecord(array $values, array $record):array
	{

		preg_match_all('/\d+/', (string)($record['mailinglist_ids'] ?? ''), $m);
		$lists = self::lists();
		$values['_lists'] = array_values(array_filter(array_map(fn($id) => $lists[$id] ?? null, $m[0])));

		return $values;

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$v->input('_lists');
		$unknown = array_diff((array)$v->inputGet('_lists', []), self::lists());
		if($unknown)$v->addError(ts('unknown mailing lists: [LISTS]', ['LISTS' => implode(', ', $unknown)]));

		foreach(['template_url', 'template_url2', 'template_url3'] as $col)
		{

			$url = (string)$v->inputGet($col, $record[$col] ?? '');
			$v->input($col);
			if($url !== '' && !preg_match('#^https?://\S+$#i', $url))$v->addError(ts('`[FIELD]` must be an url (https://...)'));

		}

		$recipients = trim((string)$v->inputGet('scheduler_report_email_recipients', ''));
		$v->input('scheduler_report_email_recipients');
		foreach(array_filter(array_map('trim', preg_split('/[,;\s]+/', $recipients))) as $email)
			if(!filter_var($email, FILTER_VALIDATE_EMAIL)){ $v->addError(ts('`[FIELD]` holds an invalid email: [EMAIL]', ['EMAIL' => $email])); break; }

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(isset($row['_lists']))
		{

			$ids = array_keys(array_intersect(self::lists(), (array)json_decode($row['_lists'], true)));
			$row['mailinglist_ids'] = implode('', array_map(fn($id) => "[{$id}]", $ids));
			unset($row['_lists']);

		}

		return $row;

	}

}
