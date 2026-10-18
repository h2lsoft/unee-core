<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * activity of the entities (xcore_entity_log, \Core\Entity::log()): read only, detail on click, purge (right purge)
 */
#[Route('/api/entity-log', name: 'api.entity_log.')]
class Entity_LogController extends \Core\CrudController
{

	public string $table = 'xcore_entity_log';
	public string $title = 'Entity log';
	public string $object_label = 'entry';

	protected function grid(DataGrid $grid):void
	{

		$grid->setSearch(['entity', 'action', 'parameters'], 'Search entity, action...');
		$grid->addFilterChips('category');
		$grid->addFilter('date', 'Date', 'date', [], 'DATE(`date`)');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('type', 'Type');
		$grid->addFilter('entity', 'Entity');
		$grid->addFilter('entity_id', 'Entity ID', 'number');
		$grid->addFilter('action', 'Action');
		$grid->addFilter('entity2', 'Linked entity');
		$grid->addFilter('entity2_id', 'Linked entity ID', 'number');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setOrderByFirst('id', 'desc');
		$grid->setMobile('action', 'date');
		$grid->setBatchMode(false);
		$grid->setDetail();
		$grid->gridAction('purge', 'Purge entity log', 'delete_sweep', 'purge', 'Purge the whole entity log?', true);

		$grid->addCol('id', 'ID', true)->width(80);
		$grid->addColDatetime('date', 'Date', true)->width(150);
		$grid->addCol('category', 'Category', true)->width(120);
		$grid->addCol('type', 'Type', true)->width(110);
		$grid->addCol('entity', 'Entity', true);
		$grid->addCol('action', 'Action', true)->width(140);
		$grid->addCol('entity2', 'Linked entity', true);
		$grid->addCol('created_by', 'Author', true)->width(120);

		$grid->hookData(function(array $row):array{

			if((int)$row['entity_id'])$row['entity'] .= " #{$row['entity_id']}";
			if((int)$row['entity2_id'])$row['entity2'] .= " #{$row['entity2_id']}";

			return $row;

		});

	}

	// read only: no form (no add or edit right declared on the plugin)
	protected function form(Form $form, array $record):void
	{
	}

	protected function detail(array $record):array
	{

		$parameters = json_decode((string)$record['parameters'], true);
		$parameters = ($parameters === null || $parameters === []) ? '' : json_encode($parameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return [
			'title' => ts('Entry').' #'.$record['id'],
			'fields' => [
				['label' => ts('Date'), 'value' => $record['date']],
				['label' => ts('Author'), 'value' => (string)$record['created_by']],
				['label' => ts('Category'), 'value' => $record['category']],
				['label' => ts('Type'), 'value' => $record['type']],
				['label' => ts('Entity'), 'value' => $record['entity'].(((int)$record['entity_id']) ? " #{$record['entity_id']}" : '')],
				['label' => ts('Action'), 'value' => $record['action']],
				['label' => ts('Linked entity'), 'value' => $record['entity2'].(((int)$record['entity2_id']) ? " #{$record['entity2_id']}" : '')],
				['label' => ts('Parameters'), 'value' => $parameters, 'type' => 'code', 'language' => 'json', 'wide' => true],
			],
		];

	}

	/**
	 * empties the entity log, the purge itself is logged
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	protected function actionPurge(Request $request):\Core\JsonResponse
	{

		$count = (int)DB()->query("SELECT COUNT(*) FROM xcore_entity_log")->fetchOne();
		DB()->query("TRUNCATE TABLE xcore_entity_log");
		\Core\Log::write('purge', "{$count} entity log entries deleted", [], 0, 'warning', $this->module);

		return new \Core\JsonResponse(['message' => ts('[COUNT] entries deleted', ['COUNT' => $count])]);

	}

}
