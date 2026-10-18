<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * application log (xcore_log, \Core\Log::write()): read only, tabs by level, detail on click, purge (right purge)
 */
#[Route('/api/log', name: 'api.log.')]
class LogController extends \Core\CrudController
{

	public string $table = 'xcore_log';
	public string $title = 'Logs';
	public string $object_label = 'log';

	private const array LEVELS = ['info' => 'blue', 'success' => 'green', 'warning' => 'orange', 'error' => 'red', 'critical' => 'red', 'fatal' => 'red', 'debug' => 'grey'];

	protected function grid(DataGrid $grid):void
	{

		$levels = array_combine(array_keys(self::LEVELS), array_keys(self::LEVELS));

		$grid->setSearch(['message', 'action', 'author'], 'Search message...');
		$grid->addFilterChips('level', $levels);
		$grid->addFilter('date', 'Date', 'date', [], 'DATE(`date`)');
		$grid->addFilter('level', 'Level', 'select', $levels);
		$grid->addFilter('application', 'Application');
		$grid->addFilter('plugin', 'Plugin');
		$grid->addFilter('action', 'Action');
		$grid->addFilter('author', 'Author');
		$grid->addFilter('record_id', 'Record', 'number');
		$grid->addFilter('message', 'Message');
		$grid->addFilter('ip', 'IP');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setOrderByFirst('id', 'desc');
		$grid->setMobile('message', 'date');
		$grid->setBatchMode(false);
		$grid->setDetail();
		$grid->gridAction('purge', 'Purge logs', 'delete_sweep', 'purge', 'Purge every log?', true);

		$grid->addCol('id', 'ID', true)->width(80);
		$grid->addColDatetime('date', 'Date', true)->width(150);
		$grid->addColBadge('level', 'Level', true, self::LEVELS)->width(90);
		$grid->addCol('application', 'Application', true)->width(110);
		$grid->addCol('plugin', 'Plugin', true)->width(110);
		$grid->addCol('action', 'Action', true)->width(130);
		$grid->addCol('author', 'Author', true)->width(120);
		$grid->addCol('record_id', 'Record', true, 'text', ['align' => 'right'])->width(80);
		$grid->addCol('message', 'Message');

		$grid->hookData(function(array $row):array{

			if(!(int)$row['record_id'])$row['record_id'] = '';
			return $row;

		});

	}

	// read only: no form (no add or edit right declared on the plugin)
	protected function form(Form $form, array $record):void
	{
	}

	protected function detail(array $record):array
	{

		$values = json_decode((string)$record['values'], true);
		$values = ($values === null || $values === []) ? '' : json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return [
			'title' => ts('Log').' #'.$record['id'],
			'fields' => [
				['label' => ts('Date'), 'value' => $record['date']],
				['label' => ts('Action'), 'value' => $record['action']],
				['label' => ts('Level'), 'value' => $record['level'], 'type' => 'badge', 'color' => self::LEVELS[$record['level']] ?? 'grey'],
				['label' => ts('Author'), 'value' => (string)$record['author']],
				['label' => ts('Application'), 'value' => $record['application']],
				['label' => ts('Record'), 'value' => ((int)$record['record_id']) ? '#'.$record['record_id'] : ''],
				['label' => ts('Plugin'), 'value' => $record['plugin']],
				['label' => ts('IP'), 'value' => $record['ip']],
				['label' => ts('Message'), 'value' => (string)$record['message'], 'wide' => true],
				['label' => ts('Values'), 'value' => $values, 'type' => 'code', 'language' => 'json', 'wide' => true],
			],
		];

	}

	/**
	 * empties the log, the purge itself is logged
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	protected function actionPurge(Request $request):\Core\JsonResponse
	{

		$count = (int)DB()->query("SELECT COUNT(*) FROM xcore_log")->fetchOne();
		DB()->query("TRUNCATE TABLE xcore_log");
		\Core\Log::write('purge', "{$count} logs deleted", [], 0, 'warning', $this->module);

		return new \Core\JsonResponse(['message' => ts('[COUNT] logs deleted', ['COUNT' => $count])]);

	}

}
