<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * images of the galleries (xcore_gallery_card), opened from the count of a gallery: gallery-card/?search[]=xcore_gallery_id||<id>
 */
#[Route('/api/gallery-card', name: 'api.gallery_card.')]
class Gallery_CardController extends \Core\CrudController
{

	public string $table = 'xcore_gallery_card';
	public string $title = 'Gallery images';
	public string $object_label = 'image';

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT g.name FROM xcore_gallery g WHERE g.id = xcore_gallery_card.xcore_gallery_id)", 'gallery');
		$grid->setSearch(['title', 'image'], 'Search title...');
		$grid->addFilter('xcore_gallery_id', 'Gallery', 'select', DB()->query("SELECT id, name FROM xcore_gallery WHERE deleted = 'no' ORDER BY name")->fetchAll(\PDO::FETCH_KEY_PAIR));
		$grid->addFilter('title', 'Title');
		$grid->addFilter('visible', 'Visible', 'boolean');
		$grid->setMobile('title', 'gallery');
		$grid->batchEdit(['xcore_gallery_id', 'visible']);

		$grid->addColPosition();
		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('gallery', 'Gallery')->width(160);
		$grid->addCol('title', 'Title', true);
		$grid->addCol('image', 'Image');
		$grid->addColBoolean('visible', 'Visible', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addSelectSql('xcore_gallery_id', 'Gallery', 'xcore_gallery', 'name', true);
		$form->addText('title', 'Title')->maxLength(255);
		$form->addText('image', 'Image', true)->maxLength(255)->placeholder('/upload/gallery/image.jpg');
		$form->addTextarea('description', 'Description', false, 3)->maxLength(5000);
		$form->addBoolean('visible', 'Visible')->defaultValue('yes');

	}

}
