<?php

namespace Plugin\Crm;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * answer templates of the tickets (xcore_ticket_template)
 */
#[Route('/api/ticket-template', name: 'api.ticket_template.')]
class Ticket_TemplateController extends \Core\CrudController
{

	public string $table = 'xcore_ticket_template';
	public string $title = 'Ticket templates';
	public string $object_label = 'template';

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("LEFT(content, 140)", 'excerpt');
		$grid->setSearch(['name', 'content'], 'Search name...');
		$grid->addFilter('name', 'Name');
		$grid->setMobile('name', 'excerpt');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('name', 'Name', true)->width(240);
		$grid->addCol('excerpt', 'Content');

	}

	protected function form(Form $form, array $record):void
	{

		$form->addText('name', 'Name', true)->maxLength(255)->unique();
		$form->addTextarea('content', 'Content', true, 10)->maxLength(65000);

	}

}
