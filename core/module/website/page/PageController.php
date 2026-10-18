<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * pages of the website (xcore_page): tree by zone and parent page, html content with patterns, seo and sitemap settings
 * backoffice only: the website renders them
 */
#[Route('/api/page', name: 'api.page.')]
class PageController extends \Core\CrudController
{

	public string $table = 'xcore_page';
	public string $title = 'Pages';
	public string $object_label = 'page';
	public array $ucfirst = ['name', 'headline'];

	public const array STATUSES = ['draft' => 'draft', 'published' => 'published', 'archived' => 'archived'];
	public const array STATUS_COLORS = ['draft' => 'grey', 'published' => 'green', 'archived' => 'orange'];
	public const array ROBOTS = ['' => 'default', 'index, follow' => 'index, follow', 'noindex, follow' => 'noindex, follow', 'index, nofollow' => 'index, nofollow', 'noindex, nofollow' => 'noindex, nofollow'];
	public const array OG_TYPES = ['' => 'default', 'website' => 'website', 'article' => 'article', 'product' => 'product'];
	public const array FREQS = ['' => 'default', 'always' => 'always', 'hourly' => 'hourly', 'daily' => 'daily', 'weekly' => 'weekly', 'monthly' => 'monthly', 'yearly' => 'yearly', 'never' => 'never'];

	/**
	 * languages of the website content: config website.languages, else backend.languages
	 *
	 * @return array code => label
	 */
	public static function languages():array
	{

		return (array)\Core\Config::get('website.languages', \Core\Config::get('backend.languages', ['en' => 'English']));

	}

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT z.name FROM xcore_page_zone z WHERE z.id = xcore_page.xcore_page_zone_id)", 'zone');
		$grid->qSelect("(SELECT p.name FROM xcore_page p WHERE p.id = xcore_page.xcore_page_id)", 'parent');
		$grid->setSearch(['name', 'url', 'headline', 'meta_title'], 'Search name, url...');
		$grid->addFilterChips('status', self::STATUSES);
		$grid->addFilter('name', 'Name');
		$grid->addFilter('url', 'Url');
		$grid->addFilter('language', 'Language', 'select', self::languages());
		$grid->addFilter('xcore_page_zone_id', 'Zone', 'select', DB()->query("SELECT id, name FROM xcore_page_zone WHERE deleted = 'no' ORDER BY position, name")->fetchAll(\PDO::FETCH_KEY_PAIR));
		$grid->addFilter('xcore_page_id', 'Parent page', 'number');
		$grid->addFilter('template', 'Template');
		$grid->addFilter('menu_visible', 'In menu', 'boolean');
		$grid->addFilter('publication_date', 'Publication', 'date');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('name', 'url');
		$grid->batchEdit(['status', 'xcore_page_zone_id', 'template', 'menu_visible']);

		$grid->addColPosition();
		$grid->addCol('id', 'ID', true)->width(72);
		if(count(self::languages()) > 1)$grid->addCol('language', 'Lang.', true)->width(70);
		$grid->addCol('zone', 'Zone')->width(130);
		$grid->addCol('parent', 'Parent')->width(150);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('url', 'Url', true);
		$grid->addColBoolean('is_homepage', 'Home', true)->width(70);
		$grid->addColBoolean('menu_visible', 'In menu', true, true)->width(90);
		$grid->addColDatetime('publication_date', 'Publication', true)->width(140);
		$grid->addColBadge('status', 'Status', true, self::STATUS_COLORS)->labels(self::STATUSES)->width(100);

	}

	protected function form(Form $form, array $record):void
	{

		$id = (int)($record['id'] ?? 0);
		$languages = self::languages();
		$types = DB()->query("SELECT DISTINCT type FROM xcore_page WHERE deleted = 'no' AND type != '' ORDER BY type")->fetchAllOne() ?: ['page'];
		$templates = DB()->query("SELECT name, CONCAT(name, IF(description != '', CONCAT(' - ', description), '')) FROM xcore_page_template WHERE deleted = 'no' AND (active = 'yes' OR name = :current) ORDER BY collection, name", ['current' => (string)($record['template'] ?? '')])->fetchAll(\PDO::FETCH_KEY_PAIR);
		$parents = DB()->query("SELECT id, CONCAT(name, ' (', url, ')') FROM xcore_page WHERE deleted = 'no' AND id != :id ORDER BY language, xcore_page_zone_id, position", ['id' => $id])->fetchAll(\PDO::FETCH_KEY_PAIR);

		if(count($languages) > 1)$form->addRadio('language', 'Language', $languages, true)->defaultValue((string)array_key_first($languages))->set('translate', false);
		$form->addText('name', 'Name', true)->maxLength(255)->help('title of the page in the menus');
		$form->addText('url', 'Url')->maxLength(255)->placeholder('/about/')->help('empty = made from the name');
		$form->addSelectSql('xcore_page_zone_id', 'Zone', 'xcore_page_zone', 'name');
		$form->addSelect('xcore_page_id', 'Parent page', $parents)->autocomplete()->set('translate', false);
		$form->addSelect('type', 'Type', array_combine($types, $types), true)->autocomplete(true)->maxLength(255)->defaultValue('page')->set('translate', false);
		$form->addSelect('template', 'Template', $templates)->autocomplete()->set('translate', false);
		$form->addRadio('status', 'Status', self::STATUSES, true)->defaultValue('draft');
		$form->addDatetime('publication_date', 'Publication date')->help('empty = as soon as published');
		$form->addBoolean('menu_visible', 'In menu')->defaultValue('yes');
		$form->addBoolean('is_homepage', 'Home page');
		$form->addBoolean('list_subpage', 'List the sub pages');

		$form->tab('content', 'Content');
		$form->addText('headline', 'Headline')->maxLength(255);
		$form->addText('resume', 'Summary')->maxLength(255);
		$form->addText('featured_image', 'Featured image')->maxLength(255)->placeholder('/upload/pages/image.jpg');
		$form->addCode('content', 'Content', false, 'html')->set('snippets', PatternController::snippets());

		$form->tab('seo', 'SEO');
		$form->addText('meta_title', 'Meta title')->maxLength(255)->help('empty = name of the page, 60 characters advised');
		$form->addTextarea('meta_description', 'Meta description', false, 3)->maxLength(255)->help('160 characters advised');
		$form->addText('meta_keywords', 'Meta keywords')->maxLength(255);
		$form->addSelect('meta_robot', 'Robots', self::ROBOTS);
		$form->addSelect('meta_og_type', 'Open Graph type', self::OG_TYPES);
		$form->addText('meta_og_image', 'Open Graph image')->maxLength(255)->placeholder('/upload/pages/share.jpg');

		$form->tab('sitemap', 'Sitemap');
		$form->addSelect('sitemap_priority', 'Priority', ['' => 'default'] + array_combine($p = array_map(fn($i) => number_format($i / 10, 1), range(10, 0)), $p))->set('translate', false);
		$form->addSelect('sitemap_change_freq', 'Change frequency', self::FREQS);
		$form->addText('sitemap_pagination_pattern', 'Pagination pattern')->maxLength(255)->placeholder('?page=[PAGE]');
		$form->addText('sitemap_follow_url_pattern', 'Followed urls pattern')->maxLength(255)->help('php regex of the links of the page added to the sitemap');
		$form->addText('sitemap_follow_url_priority', 'Followed urls priority')->maxLength(255)->placeholder('0.5');
		$form->tab('');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$url = (string)$v->inputGet('url', $record['url'] ?? '');
		$v->input('url');
		if($url !== '' && !preg_match('#^(https?://\S+|/\S*)$#i', $url))$v->addError(ts('`[FIELD]` must be an url (https://...) or a path (/...)'));

		// a page can not be under itself or one of its sub pages
		$parent = (int)$v->inputGet('xcore_page_id', $record['xcore_page_id'] ?? 0);
		$id = (int)($record['id'] ?? 0);
		$v->input('xcore_page_id');
		for($i = 0; $id && $parent && $i < 50; $i++)
		{

			if($parent === $id){ $v->addError(ts('a page can not be under itself or one of its sub pages')); break; }
			$parent = (int)DB()->query("SELECT xcore_page_id FROM xcore_page WHERE id = :id", ['id' => $parent])->fetchOne();

		}

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		// url made from the name and the parent url
		if(($row['url'] ?? '') === '' && ($row['name'] ?? '') !== '')
		{

			$parent_url = ((int)($row['xcore_page_id'] ?? 0)) ? (string)DB()->query("SELECT url FROM xcore_page WHERE id = :id", ['id' => (int)$row['xcore_page_id']])->fetchOne() : '';
			$row['url'] = rtrim(str_starts_with($parent_url, '/') ? $parent_url : '', '/').'/'.slugify($row['name']).'/';

		}

		if(!isset($row['language']))$row['language'] = (string)($record['language'] ?? array_key_first(self::languages()));
		if(empty($record['id']))$row['xcore_user_id'] = \Model\User::getUID();

		return $row;

	}

	// one home page by language
	protected function onSaveAfterCreate(int $id, array $row):void
	{

		$this->homepage($id, $row);

	}

	protected function onSaveAfterEdit(int $id, array $row, array $changed):void
	{

		$this->homepage($id, $row);

	}

	private function homepage(int $id, array $row):void
	{

		if(($row['is_homepage'] ?? 'no') === 'yes')
			DB()->query("UPDATE xcore_page SET is_homepage = 'no' WHERE id != :id AND language = :language AND is_homepage = 'yes'", ['id' => $id, 'language' => (string)$row['language']]);

	}

	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		$count = (int)DB()->query("SELECT COUNT(*) FROM xcore_page WHERE xcore_page_id = :id AND deleted = 'no'", ['id' => (int)$record['id']])->fetchOne();
		if($count)$v->addError(ts('this page has [COUNT] sub pages, move or delete them first', ['COUNT' => $count]));

	}

}
