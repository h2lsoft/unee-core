<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * system events (xcore_event): names registered by \Core\EventManager::emit() in debug mode, listened with \Core\EventManager::on()
 * the name is set by the code, only the description is edited here
 */
#[Route('/api/event', name: 'api.event.')]
class EventController extends \Core\CrudController
{

	public string $table = 'xcore_event';
	public string $title = 'System events';
	public string $object_label = 'event';
	public array $ucfirst = ['description'];

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['name', 'description'], 'Search name...');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('description', 'Description');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setOrderByFirst('name');
		$grid->setMobile('name', 'description');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('name', 'Name', true)->subText('description');
		$grid->addColDatetime('created_at', 'Registered', true)->width(150)->humanize()->small();

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name')->readonly()->help('registered by the code (debug mode), listened with \Core\EventManager::on()')->protect();
		$form->addText('description', 'Description')->maxLength(2000);

	}

	// the name never changes from the backoffice
	protected function onSaveBefore(array $row, array $record):array
	{

		unset($row['name']);
		return $row;

	}

}
