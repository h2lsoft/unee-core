<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * blocks of the website (xcore_block): html content or php file shown by the templates, with an optional publication period
 */
#[Route('/api/block', name: 'api.block.')]
class BlockController extends \Core\CrudController
{

	public string $table = 'xcore_block';
	public string $title = 'Blocks';
	public string $object_label = 'block';

	public const array TYPES = ['content' => 'content', 'file' => 'file'];

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['name', 'category', 'file_path'], 'Search name...');
		$grid->addFilterChips('category');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('type', 'Type', 'select', self::TYPES);
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->setMobile('name', 'category');
		$grid->batchEdit(['category', 'active']);

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('category', 'Category', true)->width(160);
		$grid->addCol('name', 'Name', true);
		$grid->addColBadge('type', 'Type', true, ['content' => 'blue', 'file' => 'purple'])->labels(self::TYPES)->width(90);
		$grid->addColDatetime('publication_date_start', 'Start', true)->width(140);
		$grid->addColDatetime('publication_date_end', 'End', true)->width(140);
		$grid->addColBoolean('active', 'Active', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_block WHERE deleted = 'no' AND category != '' ORDER BY category")->fetchAllOne();

		$form->addSelect('category', 'Category', array_combine($categories, $categories), true)->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addRadio('type', 'Type', self::TYPES, true)->defaultValue('content')->protect();
		$form->addCode('content', 'Content', false, 'html')->set('snippets', PatternController::snippets())->showIf('type', ['content']);
		$form->addText('file_path', 'File')->maxLength(255)->placeholder('blocks/newsletter.php')->help('php file of the instance, relative to its folder')->showIf('type', ['file'])->protect();
		$form->addDatetime('publication_date_start', 'Publication start')->protect();
		$form->addDatetime('publication_date_end', 'Publication end')->protect();
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$file = (string)$v->inputGet('file_path', $record['file_path'] ?? '');
		$v->input('file_path');
		if($v->inputGet('type', $record['type'] ?? '') === 'file' && ($file === '' || str_contains($file, '..') || !str_ends_with($file, '.php')))
			$v->addError(ts('`[FIELD]` must be a php file of the instance, ex: blocks/newsletter.php'));

		$start = (string)$v->inputGet('publication_date_start', $record['publication_date_start'] ?? '');
		$end = (string)$v->inputGet('publication_date_end', $record['publication_date_end'] ?? '');
		$v->input('publication_date_end');
		if($start !== '' && $end !== '' && $end < $start)$v->addError(ts('`[FIELD]` must be after the start'));

	}

}
