<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * html layouts (xcore_layout), ex: the frame of the mails where [CONTENT] is replaced by the message of a mail template
 */
#[Route('/api/layout', name: 'api.layout.')]
class LayoutController extends \Core\CrudController
{

	public string $table = 'xcore_layout';
	public string $title = 'Layouts';
	public string $object_label = 'layout';
	public array $ucfirst = ['category', 'description']; # name is a key, ex: core.mail

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['name', 'description'], 'Search name...');
		$grid->addFilterChips('category');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('content', 'Content');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setMobile('name', 'description');
		$grid->batchEdit(['category']);

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('category', 'Category', true)->width(160);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('description', 'Description');

	}

	protected function form(Form $form, array $record):void
	{

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_layout WHERE deleted = 'no' AND category != '' ORDER BY category")->fetchAllOne();

		$form->addSelect('category', 'Category', array_combine($categories, $categories), true)->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addText('name', 'Name', true)->maxLength(255)->unique();
		$form->addText('description', 'Description')->maxLength(255);
		$form->addCode('content', 'Content', true, 'html')->help('[CONTENT] is replaced by the message');

	}

	// a layout used by a mail template stays
	protected function rulesOnDelete(\Core\Validator $v, array $record):void
	{

		$count = (int)DB()->query("SELECT COUNT(*) FROM xcore_mail_template WHERE xcore_layout_id = :id AND deleted = 'no'", ['id' => (int)$record['id']])->fetchOne();
		if($count)$v->addError(ts('this layout is used by [COUNT] mail templates', ['COUNT' => $count]));

	}

}
