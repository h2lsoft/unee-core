<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * http redirects (xcore_url_redirect): the first active rule, in the order of the list, matching the full url (scheme://host/path?query) redirects
 * the rule is a php regex, ex: #^https?://www\.example\.com/(.*)$#
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
		$grid->addCol('rule', 'Rule');
		$grid->addCol('description', 'Description');
		$grid->addColBadge('code', 'Code', true, ['301' => 'blue', '302' => 'orange', '303' => 'orange', '307' => 'orange', '308' => 'blue'])->width(80);
		$grid->addCol('url', 'Url');
		$grid->addColBoolean('active', 'Active', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('rule', 'Rule', true)->maxLength(1000)->placeholder('#^https?://www\.example\.com/old/(.*)$#')->help('php regex tested on the full url, ex: #^https?://example\.com/blog/#');
		$form->addText('description', 'Description')->maxLength(255);
		$form->addSelect('code', 'Code', self::CODES, true)->defaultValue('301');
		$form->addText('url', 'Url', true)->maxLength(1000)->placeholder('https://')->help('url or path of the redirection, ex: /new/');
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		// an invalid regex would raise a warning on every request
		$rule = (string)$v->inputGet('rule', $record['rule'] ?? '');
		if($rule !== '' && @preg_match($rule, '') === false)
		{

			$error = preg_replace('/^preg_match\(\): /', '', (string)(error_get_last()['message'] ?? ''));
			$v->input('rule')->addError(ts('`[FIELD]` is not a valid regex: [ERROR]', ['ERROR' => $error]));

		}

		$url = (string)$v->inputGet('url', $record['url'] ?? '');
		if($url !== '' && !preg_match('#^(https?://[^\s]+|/[^\s]*)$#i', $url))
			$v->input('url')->addError(ts('`[FIELD]` must be an url (https://...) or a path (/...)'));

	}

}
