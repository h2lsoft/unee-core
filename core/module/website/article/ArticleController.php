<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * articles of the website (xcore_article): news, blog posts, with tags, a publication date and an archive date (cron task Article archiving)
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
		$grid->addColDatetime('archived_date', 'Archive date', true)->small()->width(130);
		$grid->addColBadge('status', 'Status', true, ['draft' => 'grey', 'published' => 'green'])->labels(self::STATUSES)->width(100);

	}

	protected function form(Form $form, array $record):void
	{

		$languages = PageController::languages();
		$types = DB()->query("SELECT DISTINCT type FROM xcore_article WHERE deleted = 'no' AND type != '' ORDER BY type")->fetchAllOne() ?: ['News'];
		$users = DB()->query("SELECT id, TRIM(CONCAT(firstname, ' ', lastname)) FROM xcore_user WHERE deleted = 'no' AND (active = 'yes' OR id = :current) AND id > 1 ORDER BY firstname, lastname", ['current' => (int)($record['xcore_user_id'] ?? 0)])->fetchAll(\PDO::FETCH_KEY_PAIR);

		if(count($languages) > 1)$form->addRadio('language', 'Language', $languages, true)->defaultValue((string)array_key_first($languages))->set('translate', false)->protect();
		$form->addSelect('type', 'Type', array_combine($types, $types), true)->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addDate('date', 'Date', true)->defaultValue(date('Y-m-d'));
		$form->addText('title', 'Title', true)->maxLength(255);
		$form->addText('resume', 'Summary')->maxLength(120)->help('120 characters max, shown in the lists');
		$form->addHtml('content', 'Content');
		$form->addText('header_image', 'Header image')->maxLength(255)->placeholder('/upload/articles/image.jpg');
		$form->addTagManager($this->table);
		$form->addSelect('xcore_user_id', 'Author', $users)->autocomplete()->defaultValue((string)\Model\User::getUID())->set('translate', false);
		$form->addText('source', 'Source')->maxLength(255);
		$form->addText('url', 'Url')->maxLength(255)->placeholder('/news/my-article/')->protect();
		$form->addRadio('status', 'Status', self::STATUSES, true)->defaultValue('draft');
		$form->addDatetime('publication_date', 'Publication date')->help('empty = as soon as published');
		$form->addDatetime('archived_date', 'Archive date')->help('empty = never, archived automatically at this date')->protect();
		$form->addBoolean('archived', 'Archived')->protect();
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

		// archive date: empty = never, passed = archived, checked by hand = archived now, unchecked by hand = the passed date is removed
		$was = (string)($record['archived'] ?? 'no');
		$archived = (string)($row['archived'] ?? $was);
		$date = (string)(array_key_exists('archived_date', $row) ? $row['archived_date'] : ($record['archived_date'] ?? ''));
		$passed = ($date !== '' && strtotime($date) <= time());

		if($archived === 'yes' && !$passed)$date = now();
		elseif($archived === 'no' && $passed)
		{

			if($was === 'yes')$date = '';
			else $archived = 'yes';

		}

		$row['archived'] = $archived;
		$row['archived_date'] = ($date === '') ? null : $date;

		return $row;

	}

	/**
	 * archives the articles whose archive date passed since their last save (cron task Article archiving),
	 * an article unarchived after its date (batch edit) stays unarchived
	 *
	 * @param int $limit
	 *
	 * @return int articles archived
	 */
	public static function archiveDue(int $limit=500):int
	{

		$ids = DB()->query("SELECT id FROM xcore_article WHERE deleted = 'no' AND archived = 'no' AND archived_date IS NOT NULL AND archived_date <= :now AND archived_date > COALESCE(updated_at, created_at, '1970-01-01') ORDER BY archived_date LIMIT ".max(1, $limit), ['now' => now()])->fetchAllOne();

		$count = 0;
		foreach($ids as $id)
		{

			// conditional update: an article saved meanwhile is left as it is
			$done = DB()->query("UPDATE xcore_article SET archived = 'yes', updated_at = :now, updated_by = 'system' WHERE id = :id AND archived = 'no' AND archived_date <= :now2", ['id' => (int)$id, 'now' => now(), 'now2' => now()])->rowCount();
			if(!$done)continue;

			\Core\Log::write('archive', ts('archived at its archive date'), ['archived' => ['no', 'yes']], (int)$id, 'info', 'article', 'system', 'cron');
			$count++;

		}

		return $count;

	}

}
