<?php

namespace Plugin\Crm;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * support tickets (xcore_ticket) and their messages (xcore_ticket_message, module ticket-message)
 * entry only: the imap reading comes later
 */
#[Route('/api/ticket', name: 'api.ticket.')]
class TicketController extends \Core\CrudController
{

	public string $table = 'xcore_ticket';
	public string $title = 'Tickets';
	public string $object_label = 'ticket';
	public array $ucfirst = ['subject'];

	public const array STATUSES = ['open' => 'open', 'pending' => 'pending', 'closed' => 'closed'];
	public const array STATUS_COLORS = ['open' => 'blue', 'pending' => 'orange', 'closed' => 'grey'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT COUNT(*) FROM xcore_ticket_message m WHERE m.xcore_ticket_id = xcore_ticket.id AND m.deleted = 'no')", 'messages');
		$grid->setSearch(['subject', 'email'], 'Search subject, email...');
		$grid->addFilterChips('status', self::STATUSES);
		$grid->addFilter('subject', 'Subject');
		$grid->addFilter('email', 'Email');
		$grid->addFilter('order_id', 'Order', 'number');
		$grid->addFilter('date', 'Date', 'date');
		$grid->setMobile('subject', 'email');
		$grid->batchEdit(['status']);
		$grid->setOrderByFirst('date', 'desc');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addColDatetime('date', 'Date', true)->width(140);
		$grid->addCol('email', 'Email', true)->width(220);
		$grid->addCol('subject', 'Subject', true);
		$grid->addCol('order_id', 'Order', true)->width(90);
		$grid->addColCount('messages', 'Messages', 'forum')->width(110);
		if(\Model\User::hasRight('ticket-message', 'list'))$grid->link('ticket-message/?search[]=xcore_ticket_id||{id}');
		$grid->addColBadge('status', 'Status', true, self::STATUS_COLORS)->labels(self::STATUSES)->width(100);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addDatetime('date', 'Date', true)->defaultValue(date('Y-m-d H:i:s'));
		$form->addEmail('email', 'Email', true)->maxLength(255);
		$form->addText('subject', 'Subject', true)->maxLength(255);
		$form->addNumber('order_id', 'Order', false, true)->help('number of the order concerned, if any')->protect();
		$form->addRadio('status', 'Status', self::STATUSES, true)->defaultValue('open');

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(array_key_exists('order_id', $row))$row['order_id'] = (int)$row['order_id'];
		return $row;

	}

	// the messages go with the ticket
	protected function onDeleteAfter(array $record):void
	{

		DB()->query("UPDATE xcore_ticket_message SET deleted = 'yes', deleted_at = :now, deleted_by = :author WHERE xcore_ticket_id = :id AND deleted = 'no'", ['id' => (int)$record['id'], 'now' => now(), 'author' => $this->author()]);

	}

}
