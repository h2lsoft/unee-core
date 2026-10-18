<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * slides of the sliders (xcore_slider_card), opened from the count of a slider: slider-card/?search[]=xcore_slider_id||<id>
 */
#[Route('/api/slider-card', name: 'api.slider_card.')]
class Slider_CardController extends \Core\CrudController
{

	public string $table = 'xcore_slider_card';
	public string $title = 'Slides';
	public string $object_label = 'slide';
	public array $ucfirst = ['name'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT s.name FROM xcore_slider s WHERE s.id = xcore_slider_card.xcore_slider_id)", 'slider');
		$grid->setSearch(['name', 'title', 'text'], 'Search name...');
		$grid->addFilter('xcore_slider_id', 'Slider', 'select', DB()->query("SELECT id, name FROM xcore_slider WHERE deleted = 'no' ORDER BY name")->fetchAll(\PDO::FETCH_KEY_PAIR));
		$grid->addFilter('name', 'Name');
		$grid->addFilter('visible', 'Visible', 'boolean');
		$grid->setMobile('name', 'slider');
		$grid->batchEdit(['xcore_slider_id', 'visible']);

		$grid->addColPosition();
		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('slider', 'Slider')->width(160);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('title', 'Title');
		$grid->addColDatetime('date_start', 'Start', true)->width(140);
		$grid->addColDatetime('date_end', 'End', true)->width(140);
		$grid->addColBoolean('visible', 'Visible', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addSelectSql('xcore_slider_id', 'Slider', 'xcore_slider', 'name', true);
		$form->addText('name', 'Name', true)->maxLength(255)->help('internal');
		$form->addText('image', 'Image', true)->maxLength(255)->placeholder('/upload/slider/image.jpg');
		$form->addText('image_mobile', 'Mobile image')->maxLength(255)->help('empty = the image');
		$form->addDatetime('date_start', 'Start');
		$form->addDatetime('date_end', 'End');
		$form->addBoolean('visible', 'Visible')->defaultValue('yes');

		$form->tab('text', 'Text');
		$form->addText('title', 'Title')->maxLength(255);
		$form->addColor('title_color', 'Title color');
		$form->addTextarea('text', 'Text', false, 3)->maxLength(5000);
		$form->addColor('text_color', 'Text color');

		$form->tab('button', 'Button');
		$form->addText('button_text', 'Text')->maxLength(255);
		$form->addText('button_href', 'Link')->maxLength(255)->placeholder('/contact/');
		$form->addColor('button_text_color', 'Text color');
		$form->addColor('button_bg_color', 'Background color');
		$form->addText('button_class', 'Css class')->maxLength(255);
		$form->tab('');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$start = (string)$v->inputGet('date_start', $record['date_start'] ?? '');
		$end = (string)$v->inputGet('date_end', $record['date_end'] ?? '');
		$v->input('date_end');
		if($start !== '' && $end !== '' && $end < $start)$v->addError(ts('`[FIELD]` must be after the start'));

	}

}
