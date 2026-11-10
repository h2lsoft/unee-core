<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * http redirects (xcore_url_redirect): the first active rule, in the order of the list, matching the full url (scheme://host/path?query) redirects
 * the rule is a regex without delimiters, tested on the full url, or on the path (/path?query) when it starts with ^/
 * ex: ^https?://www\.example\.com/(.*)$ or ^/old/
 */
#[Route('/api/url-redirect', name: 'api.url_redirect.')]
class Url_RedirectController extends \Core\CrudController
{

	public string $table = 'xcore_url_redirect';
	public string $title = 'Url redirects';
	public string $object_label = 'rule';

	private const array CODES = [
		'301' => '301 Moved Permanently',
		'302' => '302 Found',
		'303' => '303 See Other',
		'307' => '307 Temporary Redirect',
		'308' => '308 Permanent Redirect',
	];

	/**
	 * php pattern of a rule: delimiters added, a # of the rule escaped
	 *
	 * @param string $rule regex without delimiters
	 *
	 * @return string
	 */
	public static function pattern(string $rule):string
	{

		return '#'.preg_replace('/(?<!\\\\)#/', '\\#', $rule).'#';

	}

	/**
	 * redirection of the first active rule, in the order of the list, matching the full url (scheme://host[:port]/path?query), or the path for a rule starting with ^/
	 * called by the url.redirect middleware and by run.php for the backend (answered before the kernel)
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 *
	 * @return \Core\RedirectResponse|null null: no rule matches, or cli
	 */
	public static function redirect(\Symfony\Component\HttpFoundation\Request $request):?\Core\RedirectResponse
	{

		if(APP_CLI_MODE)return null;

		$url_complete = $request->getSchemeAndHttpHost().$request->getRequestUri();
		$recs = DB()->query("SELECT rule, url, code FROM xcore_url_redirect WHERE deleted = 'no' AND active = 'yes' ORDER BY position")->fetchAll();
		foreach($recs as $rec)
		{

			// a rule saved before the validation could be invalid: skipped
			$subject = str_starts_with($rec['rule'], '^/') ? $request->getRequestUri() : $url_complete;
			if(@preg_match(self::pattern($rec['rule']), $subject) === 1)
				return new \Core\RedirectResponse($rec['url'], (int)$rec['code']);

		}

		return null;

	}

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['rule', 'url', 'description'], 'Search rule, url...');
		$grid->addFilter('rule', 'Rule');
		$grid->addFilter('url', 'Url');
		$grid->addFilter('code', 'Code', 'select', self::CODES);
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('rule', 'url');
		$grid->batchEdit(['code', 'active']);

		$grid->addColPosition();
		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('rule', 'Rule')->subText('description');
		$grid->addColBadge('code', 'Code', true, ['301' => 'blue', '302' => 'orange', '303' => 'orange', '307' => 'orange', '308' => 'blue'])->width(80);
		$grid->addCol('url', 'Url', false, 'url');
		$grid->addColBoolean('active', 'Active', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('rule', 'Rule', true)->maxLength(1000)->placeholder('^/old/(.*)$')->help('regex tested on the full url, or on the path when it starts with ^/, ex: ^https?://example\.com/blog/ or ^/blog/')->protect();
		$form->addText('description', 'Description')->maxLength(255);
		$form->addSelect('code', 'Code', self::CODES, true)->defaultValue('301');
		$form->addText('url', 'Url', true)->maxLength(1000)->placeholder('https://')->help('url or path of the redirection, ex: /new/')->set('open_link', true)->protect();
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		// an invalid regex would raise a warning on every request
		$rule = (string)$v->inputGet('rule', $record['rule'] ?? '');
		// the delimiters are added by pattern()
		if(preg_match('/^#.*#[a-z]*$/s', $rule))
			$v->input('rule')->addError(ts('`[FIELD]` is a regex without delimiters: remove the # around it'));
		elseif($rule !== '' && @preg_match(self::pattern($rule), '') === false)
		{

			$error = preg_replace('/^preg_match\(\): /', '', (string)(error_get_last()['message'] ?? ''));
			$v->input('rule')->addError(ts('`[FIELD]` is not a valid regex: [ERROR]', ['ERROR' => $error]));

		}

		$url = (string)$v->inputGet('url', $record['url'] ?? '');
		if($url !== '' && !preg_match('#^(https?://[^\s]+|/[^\s]*)$#i', $url))
			$v->input('url')->addError(ts('`[FIELD]` must be an url (https://...) or a path (/...)'));

	}

}
