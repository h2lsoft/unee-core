<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * mails sent by the application (xcore_mail_template, \Core\Mailer::sendTemplate()): sender, subject and message by language, in a layout
 * the variables ([[name]]) are declared by the code that creates the template (column variables), shown as help
 */
#[Route('/api/mail-template', name: 'api.mail_template.')]
class Mail_TemplateController extends \Core\CrudController
{

	public string $table = 'xcore_mail_template';
	public string $title = 'Mail templates';
	public string $object_label = 'template';
	public array $ucfirst = ['category', 'description']; # name is a key of the code

	/**
	 * flag of a language (country code of backend/src/components/Flag.tsx)
	 */
	private const array FLAGS = ['en' => 'gb', 'cs' => 'cz', 'da' => 'dk', 'el' => 'gr', 'he' => 'il', 'ja' => 'jp', 'ko' => 'kr', 'nb' => 'no', 'sv' => 'se', 'uk' => 'ua', 'zh' => 'cn', 'ar' => 'sa'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT l.name FROM xcore_layout l WHERE l.id = xcore_mail_template.xcore_layout_id AND l.deleted = 'no')", 'layout');
		$grid->setSearch(['name', 'description', 'subject_en'], 'Search name...');
		$grid->addFilterChips('category');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('name', 'description');
		$grid->batchEdit(['category', 'xcore_layout_id']);

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('category', 'Category', true)->width(140);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('layout', 'Layout')->width(160);
		$grid->addCol('description', 'Description');

	}

	protected function form(Form $form, array $record):void
	{

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_mail_template WHERE deleted = 'no' AND category != '' ORDER BY category")->fetchAllOne();
		$variables = trim((string)($record['variables'] ?? ''));

		$form->addSelect('category', 'Category', array_combine($categories, $categories), true)->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addText('name', 'Name', true)->maxLength(255)->unique()->help('used by the code, ex: core.password.forgot');
		$form->addText('description', 'Description')->maxLength(255);
		$form->addSelectSql('xcore_layout_id', 'Layout', 'xcore_layout', 'name', false, "category = 'mail'");

		foreach($this->languages() as $lang => $label)
		{

			$form->tab($lang, $label, self::FLAGS[$lang] ?? $lang);
			$form->addText("sender_{$lang}", 'Sender')->maxLength(255)->placeholder('Name|email@example.com')->help('empty = noreply_email of the config');
			$form->addText("subject_{$lang}", 'Subject', true)->maxLength(255);
			$form->addCode("body_{$lang}", 'Message', true, 'html');
			if($variables !== '')$form->help(ts('Variables: [VARIABLES]', ['VARIABLES' => $variables]));

		}
		$form->tab('');

		$form->addAction('send-test', ts('Send a test to [EMAIL]', ['EMAIL' => (string)\Core\Session::get('auth.email')]), 'send', 'edit');

	}

	// no layout: 0 in the table, empty in the form
	protected function getRecord(array $values, array $record):array
	{

		if((string)($values['xcore_layout_id'] ?? '') === '0')$values['xcore_layout_id'] = '';
		return $values;

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(($row['xcore_layout_id'] ?? '') === '')$row['xcore_layout_id'] = 0;
		return $row;

	}

	/**
	 * mail of the language shown in the form (body: values of the form and tab), to the logged user, variables left as typed
	 *
	 * @param array $record
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	protected function rowSendTest(array $record, Request $request):\Core\JsonResponse
	{

		$lang = (string)$request->request->get('tab');
		if(!array_key_exists($lang, $this->languages()))$lang = array_key_first($this->languages());

		$subject = trim((string)$request->request->get("subject_{$lang}"));
		$body = (string)$request->request->get("body_{$lang}");
		if($subject === '' || trim($body) === '')return \Core\JsonResponse::error(422, 'fill the subject and the message first');

		$layout = \Model\Layout::findById((int)$request->request->get('xcore_layout_id'));
		if($layout)$body = str_replace('[CONTENT]', $body, $layout['content']);

		$to = (string)\Core\Session::get('auth.email');
		if(!\Core\Mailer::send(trim((string)$request->request->get("sender_{$lang}")), $to, "[test] {$subject}", $body))
			return \Core\JsonResponse::error(422, ts('mail not sent: [ERROR]', ['ERROR' => \Core\Mailer::getLastError()]));

		\Core\Log::write('send_test', $to, [], (int)$record['id'], 'info', $this->module);

		return new \Core\JsonResponse(['message' => ts('Mail sent to [EMAIL]', ['EMAIL' => $to])]);

	}

	/**
	 * languages of the backend that have their columns (sender_xx, subject_xx, body_xx)
	 *
	 * @return array code => name
	 */
	private function languages():array
	{

		static $languages = null;
		if($languages !== null)return $languages;

		$columns = DB()->query("SHOW COLUMNS FROM xcore_mail_template LIKE 'body\\_%'")->fetchAllOne();
		$languages = array_filter(\Core\Config::get('backend.languages'), fn($code) => in_array("body_{$code}", $columns, true), ARRAY_FILTER_USE_KEY);

		return $languages;

	}

}
