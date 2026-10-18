<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * articles of the website (xcore_article): news, blog posts, with tags, a publication date and an archive flag
 */
#[Route('/api/article', name: 'api.article.')]
class ArticleController extends \Core\CrudController
{

	public string $table = 'xcore_article';
	public string $title = 'Articles';
	public string $object_label = 'article';
	public array $ucfirst = ['title', 'type'];

	public const array STATUSES = ['draft' => 'draft', 'published' => 'published'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT TRIM(CONCAT(u.firstname, ' ', u.lastname)) FROM xcore_user u WHERE u.id = xcore_article.xcore_user_id)", 'author');
		$grid->setSearch(['title', 'resume', 'source'], 'Search title...');
		$grid->addFilterChips('status', self::STATUSES);
		$grid->addFilter('title', 'Title');
		$grid->addFilter('type', 'Type');
		$grid->addFilter('language', 'Language', 'select', PageController::languages());
		$grid->addFilter('date', 'Date', 'date');
		$grid->addFilter('archived', 'Archived', 'boolean');
		$grid->addFilterTags();
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('title', 'date');
		$grid->batchEdit(['status', 'type', 'archived']);
		$grid->setOrderByFirst('date', 'desc');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addColDate('date', 'Date', true)->width(110);
		if(count(PageController::languages()) > 1)$grid->addCol('language', 'Lang.', true)->width(70);
		$grid->addCol('type', 'Type', true)->width(120);
		$grid->addCol('title', 'Title', true);
		$grid->addColTags()->width(180);
		$grid->addCol('author', 'Author')->width(150);
		$grid->addColBoolean('archived', 'Archived', true)->width(90);
		$grid->addColBadge('status', 'Status', true, ['draft' => 'grey', 'published' => 'green'])->labels(self::STATUSES)->width(100);

	}

	protected function form(Form $form, array $record):void
	{

		$languages = PageController::languages();
		$types = DB()->query("SELECT DISTINCT type FROM xcore_article WHERE deleted = 'no' AND type != '' ORDER BY type")->fetchAllOne() ?: ['News'];
		$users = DB()->query("SELECT id, TRIM(CONCAT(firstname, ' ', lastname)) FROM xcore_user WHERE deleted = 'no' AND (active = 'yes' OR id = :current) AND id > 1 ORDER BY firstname, lastname", ['current' => (int)($record['xcore_user_id'] ?? 0)])->fetchAll(\PDO::FETCH_KEY_PAIR);

		if(count($languages) > 1)$form->addRadio('language', 'Language', $languages, true)->defaultValue((string)array_key_first($languages))->set('translate', false);
		$form->addSelect('type', 'Type', array_combine($types, $types), true)->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addDate('date', 'Date', true)->defaultValue(date('Y-m-d'));
		$form->addText('title', 'Title', true)->maxLength(255);
		$form->addText('resume', 'Summary')->maxLength(120)->help('120 characters max, shown in the lists');
		$form->addHtml('content', 'Content');
		$form->addText('header_image', 'Header image')->maxLength(255)->placeholder('/upload/articles/image.jpg');
		$form->addTagManager($this->table);
		$form->addSelect('xcore_user_id', 'Author', $users)->autocomplete()->defaultValue((string)\Model\User::getUID())->set('translate', false);
		$form->addText('source', 'Source')->maxLength(255);
		$form->addText('url', 'Url')->maxLength(255)->placeholder('/news/my-article/');
		$form->addRadio('status', 'Status', self::STATUSES, true)->defaultValue('draft');
		$form->addDatetime('publication_date', 'Publication date')->help('empty = as soon as published');
		$form->addBoolean('archived', 'Archived');
		$form->addTextarea('note', 'Note', false, 3)->maxLength(5000)->help('internal, never shown on the website');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$url = (string)$v->inputGet('url', $record['url'] ?? '');
		$v->input('url');
		if($url !== '' && !preg_match('#^(https?://\S+|/\S*)$#i', $url))$v->addError(ts('`[FIELD]` must be an url (https://...) or a path (/...)'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(!isset($row['language']))$row['language'] = (string)($record['language'] ?? array_key_first(PageController::languages()));

		// archive date kept from the first archiving
		if(($row['archived'] ?? 'no') === 'yes' && ($record['archived'] ?? 'no') !== 'yes')$row['archived_date'] = now();
		if(($row['archived'] ?? 'no') === 'no')$row['archived_date'] = null;

		return $row;

	}

}
