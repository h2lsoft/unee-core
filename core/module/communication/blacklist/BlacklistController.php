<?php

namespace Plugin\Communication;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * blacklisted emails (xcore_blacklist): never sent a newsletter
 */
#[Route('/api/blacklist', name: 'api.blacklist.')]
class BlacklistController extends \Core\CrudController
{

	public string $table = 'xcore_blacklist';
	public string $title = 'Blacklist';
	public string $object_label = 'email';

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['email'], 'Search email...');
		$grid->addFilter('email', 'Email');
		$grid->addFilter('date', 'Date', 'date');
		$grid->setMobile('email', 'date');
		$grid->setOrderByFirst('id', 'desc');

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addCol('email', 'Email', true);
		$grid->addColDatetime('date', 'Date', true)->width(160);

	}

	protected function form(Form $form, array $record):void
	{

		$form->addEmail('email', 'Email', true)->maxLength(255)->unique();
		$form->addDatetime('date', 'Date', true)->defaultValue(date('Y-m-d H:i:s'));

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(isset($row['email']))$row['email'] = mb_strtolower($row['email']);
		return $row;

	}

}
