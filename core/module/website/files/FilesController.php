<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * files to download from the website (xcore_files), by category, stored in private_dir/files
 * download: GET /api/files/{id}/download/ (public, counted), restricted to the groups of xcore_group_ids when set
 */
#[Route('/api/files', name: 'api.files.')]
class FilesController extends \Core\CrudController
{

	public string $table = 'xcore_files';
	public string $title = 'Files';
	public string $object_label = 'file';
	public array $ucfirst = ['category', 'title'];

	// config files/extensions and files/max_size (bytes) replace them
	public const array EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv', 'zip', 'jpg', 'jpeg', 'png', 'webp', 'avif'];
	public const int MAX_SIZE = 20 * 1048576;

	protected function grid(DataGrid $grid):void
	{

		$groups = $this->groups();

		$grid->setSearch(['title', 'category', 'source'], 'Search title, category...');
		$grid->addFilterChips('category');
		$grid->addFilter('title', 'Title');
		$grid->addFilter('source', 'Source');
		$grid->addFilter('date', 'Date', 'date');
		$grid->addFilter('downloads', 'Downloads', 'number');
		$grid->addFilter('visible', 'Visible', 'boolean');
		$grid->addFilterTags();
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('title', 'category');
		$grid->batchEdit(['category', 'visible']);
		$grid->setOrderByFirst('date', 'desc');

		// names of the groups allowed to download
		$grid->hookData(function(array $rec) use ($groups):array
		{

			$ids = json_decode((string)($rec['xcore_group_ids'] ?? ''), true);
			$rec['_groups'] = implode(', ', array_filter(array_map(fn($id) => $groups[$id] ?? '', (is_array($ids)) ? $ids : [])));
			return $rec;

		});

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addColDate('date', 'Date', true)->width(110);
		$grid->addCol('category', 'Category', true)->filterOnClick('category');
		$grid->addCol('title', 'Title', true);
		$grid->addColFile('url', 'File');
		$grid->addColTags()->width(180);
		$grid->addCol('source', 'Source', true);
		$grid->addCol('_groups', 'Restricted to');
		$grid->addColNumber('downloads', 'Downloads', true)->width(110);
		$grid->addColBoolean('visible', 'Visible', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_files WHERE deleted = 'no' AND category <> '' ORDER BY category")->fetchAll(\PDO::FETCH_COLUMN);

		$form->addDate('date', 'Date', true)->defaultValue(date('Y-m-d'));
		$form->addSelect('category', 'Category', array_combine($categories, $categories), true)->autocomplete(true)->set('translate', false)->maxLength(255);
		$form->addText('title', 'Title', true)->maxLength(255);
		$sources = DB()->query("SELECT DISTINCT source FROM xcore_files WHERE deleted = 'no' AND source <> '' ORDER BY source")->fetchAll(\PDO::FETCH_COLUMN);
		$form->addSelect('source', 'Source', array_combine($sources, $sources))->autocomplete(true)->set('translate', false)->maxLength(255)->placeholder('ex: Ministry of health');
		$form->addFile('url', 'File', true, 'files', (array)\Core\Config::get('files/extensions', self::EXTENSIONS), $this->maxSize());
		$form->addSelectMultiple('xcore_group_ids', 'Restricted to', $this->groups())->set('translate', false)->help('Empty: everybody can download the file');
		$form->addBoolean('visible', 'Visible')->defaultValue('yes');
		$form->addTagManager($this->table);

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(isset($row['category']))$row['category_slug'] = slugify($row['category']);
		return $row;

	}

	/**
	 * download of a file (public route), counted: hidden files for the users of the module only, restricted files for their groups (and the users of the module)
	 *
	 * @param int $id
	 *
	 * @return mixed
	 */
	#[Route('/{id}/download/', name: 'download', methods: ['GET'], defaults: ['_public' => true])]
	public function apiDownload(int $id):mixed
	{

		$rec = DB()->query("SELECT id, url, visible, xcore_group_ids FROM xcore_files WHERE id = :id AND deleted = 'no'", ['id' => $id])->fetch();
		$manager = \Model\User::isLogon() && \Model\User::hasRight($this->module, 'list');
		if(!$rec || ($rec['visible'] !== 'yes' && !$manager))return \Core\JsonResponse::error(404, 'file not found');

		$groups = json_decode((string)$rec['xcore_group_ids'], true);
		if(is_array($groups) && $groups && !$manager)
		{

			if(!\Model\User::isLogon())return \Core\JsonResponse::error(401, 'authentication required');

			// group read in the database: the session of a public route is not refreshed by the auth middleware
			$group = (string)DB()->query("SELECT u.xcore_group_id FROM xcore_user u INNER JOIN xcore_group g ON g.id = u.xcore_group_id AND g.deleted = 'no' AND g.active = 'yes' WHERE u.id = :id AND u.deleted = 'no' AND u.active = 'yes'", ['id' => \Model\User::getUID()])->fetchOne();
			if(!in_array($group, array_map('strval', $groups), true))return \Core\JsonResponse::error(403, 'access denied');

		}

		$response = \Core\File::serve((string)$rec['url'], true);
		if($response->getStatusCode() === 200)DB()->query("UPDATE xcore_files SET downloads = downloads + 1 WHERE id = :id", ['id' => $id]);

		return $response;

	}

	/**
	 * groups id => name
	 *
	 * @return array
	 */
	private function groups():array
	{

		$groups = [];
		foreach(DB()->query("SELECT id, name FROM xcore_group WHERE deleted = 'no' ORDER BY priority, name")->fetchAll() as $rec)
			$groups[(string)$rec['id']] = $rec['name'];

		return $groups;

	}

	/**
	 * config files/max_size, never above upload_max_filesize and post_max_size of php
	 *
	 * @return int bytes
	 */
	private function maxSize():int
	{

		$bytes = function(string $ini):int
		{

			$value = trim((string)ini_get($ini));
			$number = (int)$value;
			return match(strtolower(substr($value, -1)))
			{
				'g' => $number * 1073741824,
				'm' => $number * 1048576,
				'k' => $number * 1024,
				default => $number,
			};

		};

		$limits = array_filter([(int)\Core\Config::get('files/max_size', self::MAX_SIZE), $bytes('upload_max_filesize'), $bytes('post_max_size')]);
		return ($limits) ? min($limits) : self::MAX_SIZE;

	}

}
