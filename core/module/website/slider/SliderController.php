<?php

namespace Plugin\Website;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * sliders of the website (xcore_slider) with their swiper options, their slides are the cards (xcore_slider_card, module slider-card)
 */
#[Route('/api/slider', name: 'api.slider.')]
class SliderController extends \Core\CrudController
{

	public string $table = 'xcore_slider';
	public string $title = 'Sliders';
	public string $object_label = 'slider';

	public const array EFFECTS = ['fade' => 'fade', 'coverflow' => 'coverflow', 'flip' => 'flip', 'card' => 'card', 'creative' => 'creative', 'cube' => 'cube', 'none' => 'none'];
	public const array DIRECTIONS = ['horizontal' => 'horizontal', 'vertical' => 'vertical'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT COUNT(*) FROM xcore_slider_card c WHERE c.xcore_slider_id = xcore_slider.id AND c.deleted = 'no')", 'cards');
		$grid->setSearch(['name', 'description'], 'Search name...');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->setMobile('name', 'description');
		$grid->batchEdit(['active', 'option_effect', 'option_autoplay']);

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('description', 'Description');
		$grid->addCol('option_effect', 'Effect', true)->width(110);
		$grid->addColCount('cards', 'Slides', 'view_carousel')->width(100);
		if(\Model\User::hasRight('slider-card', 'list'))$grid->link('slider-card/?search[]=xcore_slider_id||{id}');
		$grid->addColBoolean('active', 'Active', true, true)->width(90);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(255)->unique()->help('used by the code, ex: home');
		$form->addText('description', 'Description')->maxLength(255);
		$form->addText('class', 'Css class')->maxLength(255);
		$form->addBoolean('active', 'Active')->defaultValue('yes');

		$form->tab('options', 'Options');
		$form->addBoolean('js', 'Load the script')->defaultValue('yes');
		$form->addRadio('option_effect', 'Effect', self::EFFECTS, true)->defaultValue('fade');
		$form->addRadio('option_direction', 'Direction', self::DIRECTIONS, true)->defaultValue('horizontal');
		$form->addNumber('option_speed', 'Speed (ms)', true, true)->defaultValue('400');
		$form->addNumber('option_slider_per_view', 'Slides per view', true, true)->defaultValue('1');
		$form->addNumber('option_gap', 'Gap (px)', true, true)->defaultValue('0');
		$form->addBoolean('option_autoplay', 'Autoplay');
		$form->addNumber('option_autoplay_delay_ms', 'Autoplay delay (ms)', true, true)->defaultValue('3000')->showIf('option_autoplay', ['yes']);
		$form->addBoolean('option_loop', 'Loop')->defaultValue('yes');
		$form->addBoolean('option_centered_slides', 'Centered slides')->defaultValue('yes');
		$form->addBoolean('option_navigation', 'Navigation arrows')->defaultValue('yes');
		$form->addBoolean('option_pagination', 'Pagination')->defaultValue('yes');
		$form->addBoolean('option_allow_touch', 'Touch')->defaultValue('yes');
		$form->addBoolean('option_grab_cursor', 'Grab cursor')->defaultValue('yes');
		$form->addBoolean('option_keyboard', 'Keyboard')->defaultValue('yes');
		$form->addBoolean('option_mousewheel', 'Mouse wheel')->defaultValue('yes');
		$form->addBoolean('option_zoom', 'Zoom')->defaultValue('yes');
		$form->addCode('option_breakpoints', 'Breakpoints', false, 'json')->help('swiper breakpoints, ex: {"768": {"slidesPerView": 2}}');
		$form->tab('');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$json = trim((string)$v->inputGet('option_breakpoints', $record['option_breakpoints'] ?? ''));
		$v->input('option_breakpoints');
		if($json !== '' && !is_array(json_decode($json, true)))$v->addError(ts('`[FIELD]` must be a json object'));

	}

	// the slides go with the slider
	protected function onDeleteAfter(array $record):void
	{

		DB()->query("UPDATE xcore_slider_card SET deleted = 'yes', deleted_at = :now, deleted_by = :author WHERE xcore_slider_id = :id AND deleted = 'no'", ['id' => (int)$record['id'], 'now' => now(), 'author' => $this->author()]);

	}

}
