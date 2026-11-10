<?php

namespace Plugin\Crm;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * messages of the tickets (xcore_ticket_message), in = from the customer, out = our answer
 * opened from the count of a ticket: ticket-message/?search[]=xcore_ticket_id||<id>
 */
#[Route('/api/ticket-message', name: 'api.ticket_message.')]
class Ticket_MessageController extends \Core\CrudController
{

	public string $table = 'xcore_ticket_message';
	public string $title = 'Ticket messages';
	public string $object_label = 'message';

	public const array DIRECTIONS = ['in' => 'received', 'out' => 'sent'];

	protected function grid(DataGrid $grid):void
	{

		$grid->qSelect("(SELECT CONCAT('#', t.id, ' ', t.subject) FROM xcore_ticket t WHERE t.id = xcore_ticket_message.xcore_ticket_id)", 'ticket');
		$grid->qSelect("LEFT(message, 140)", 'excerpt');
		$grid->setSearch(['message', 'sender_email'], 'Search message...');
		$grid->addFilter('xcore_ticket_id', 'Ticket', 'number');
		$grid->addFilter('direction', 'Direction', 'select', self::DIRECTIONS);
		$grid->addFilter('sender_email', 'Sender');
		$grid->addFilter('date', 'Date', 'date');
		$grid->setMobile('excerpt', 'date');
		$grid->setOrderByFirst('date', 'asc');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('ticket', 'Ticket')->width(220);
		$grid->addColDatetime('date', 'Date', true)->width(140);
		$grid->addColBadge('direction', 'Direction', true, ['in' => 'blue', 'out' => 'green'])->labels(self::DIRECTIONS)->width(100);
		$grid->addCol('sender_email', 'Sender', true)->width(200);
		$grid->addCol('excerpt', 'Message');

	}

	protected function form(Form $form, array $record):void
	{

		$form->addNumber('xcore_ticket_id', 'Ticket', true, true)->protect();
		$form->addDatetime('date', 'Date', true)->defaultValue(date('Y-m-d H:i:s'));
		$form->addRadio('direction', 'Direction', self::DIRECTIONS, true)->defaultValue('out');
		$form->addEmail('sender_email', 'Sender', true)->maxLength(255)->defaultValue((string)\Core\Session::get('auth.email'));
		$form->addTextarea('message', 'Message', true, 10)->maxLength(65000);

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$ticket = (int)$v->inputGet('xcore_ticket_id', $record['xcore_ticket_id'] ?? 0);
		$v->input('xcore_ticket_id');
		if($ticket && !DB()->query("SELECT id FROM xcore_ticket WHERE id = :id AND deleted = 'no'", ['id' => $ticket])->fetchOne())$v->addError(ts('`[FIELD]` is invalid'));

	}

}
