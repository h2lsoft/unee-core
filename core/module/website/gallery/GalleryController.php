<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * image galleries of the website (xcore_gallery), their images are the cards (xcore_gallery_card, module gallery-card)
 */
#[Route('/api/gallery', name: 'api.gallery.')]
class GalleryController extends \Core\CrudController
{

	public string $table = 'xcore_gallery';
	public string $title = 'Galleries';
	public string $object_label = 'gallery';

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT COUNT(*) FROM xcore_gallery_card c WHERE c.xcore_gallery_id = xcore_gallery.id AND c.deleted = 'no')", 'cards');
		$grid->setSearch(['name', 'description'], 'Search name...');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->setMobile('name', 'description');
		$grid->batchEdit(['active']);

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('description', 'Description');
		$grid->addColCount('cards', 'Images', 'photo_library')->width(100);
		if(\Model\User::hasRight('gallery-card', 'list'))$grid->link('gallery-card/?search[]=xcore_gallery_id||{id}');
		$grid->addColBoolean('active', 'Active', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(255)->unique()->help('used by the code, ex: home');
		$form->addText('description', 'Description')->maxLength(255);
		$form->addText('class', 'Css class')->maxLength(255)->placeholder('gallery-grid');
		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	// the cards go with the gallery
	protected function onDeleteAfter(array $record):void
	{

		DB()->query("UPDATE xcore_gallery_card SET deleted = 'yes', deleted_at = :now, deleted_by = :author WHERE xcore_gallery_id = :id AND deleted = 'no'", ['id' => (int)$record['id'], 'now' => now(), 'author' => $this->author()]);

	}

}
