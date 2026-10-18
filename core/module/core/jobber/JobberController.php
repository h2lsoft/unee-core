<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * job queue (xcore_job_queue, \Core\Jobber): tabs by status, detail on click, retry of failed jobs, purge of completed jobs
 */
#[Route('/api/jobber', name: 'api.jobber.')]
class JobberController extends \Core\CrudController
{

	public string $table = 'xcore_job_queue';
	public string $title = 'Jobber';
	public string $object_label = 'job';
	public array $ucfirst = [];

	// failed is never stored: a failed job goes back to pending until dead
	private const array STATUSES = ['pending' => 'blue', 'processing' => 'orange', 'completed' => 'green', 'dead' => 'red'];

	protected function grid(DataGrid $grid):void
	{

		$statuses = array_combine(array_keys(self::STATUSES), array_keys(self::STATUSES));
		$types = array_keys(\Core\Config::get('jobs', []));

		$grid->setSearch(['type', 'comment', 'error_message'], 'Search type, comment...');
		$grid->addFilterChips('status', $statuses);
		$grid->addFilter('type', 'Type', 'select', array_combine($types, $types));
		$grid->addFilter('status', 'Status', 'select', $statuses);
		$grid->addFilter('date', 'Date', 'date', [], 'DATE(`date`)');
		$grid->addFilter('comment', 'Comment');
		$grid->addFilter('priority', 'Priority', 'number');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setOrderByFirst('id', 'desc');
		$grid->setMobile('type', 'comment');
		$grid->setDetail();
		$grid->batchAction('retry', 'Retry', 'replay', 'edit');
		$grid->rowAction('retry', 'Retry', 'replay', 'edit');
		$grid->gridAction('purge', 'Purge completed jobs', 'delete_sweep', 'delete', 'Delete every completed job?', true);

		$grid->addCol('id', 'ID', true)->width(72);
		$grid->addColDatetime('date', 'Date', true)->width(150);
		$grid->addCol('type', 'Type', true)->width(130);
		$grid->addCol('comment', 'Comment');
		$grid->addColNumber('priority', 'Priority', true)->width(80);
		$grid->addColBadge('status', 'Status', true, self::STATUSES)->labels($statuses)->width(110);
		$grid->addCol('attempts', 'Attempts', false, 'text', ['align' => 'right'])->width(90);
		$grid->addColDatetime('scheduled_at', 'Scheduled', true)->width(150);
		$grid->addCol('error_message', 'Error');

		$grid->hookData(function(array $row):array{

			$row['attempts'] = "{$row['attempts']} / {$row['max_attempts']}";
			$row['error_message'] = mb_strimwidth((string)$row['error_message'], 0, 120, '...');

			return $row;

		});

	}

	// the payload and the scheduling of a job not yet run can be changed
	protected function form(Form $form, array $record):void
	{

		$form->addText('comment', 'Comment')->maxLength(255);
		$form->addNumber('priority', 'Priority', true, true)->help('0 to 255, higher runs first');
		$form->addDatetime('scheduled_at', 'Scheduled')->help('empty = as soon as possible');
		$form->addNumber('max_attempts', 'Max attempts', true, true);
		$form->addCode('payload', 'Payload', true, 'json');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		if(($record['status'] ?? '') === 'processing')$v->addError(ts('the job is being processed'));

		$payload = (string)$v->inputGet('payload', $record['payload'] ?? '');
		if($payload !== '' && !is_array(json_decode($payload, true)))$v->input('payload')->addError(ts('`[FIELD]` must be a json object'));

		$priority = (int)$v->inputGet('priority', $record['priority'] ?? 0);
		if($priority < 0 || $priority > 255)$v->input('priority')->addError(ts('`[FIELD]` must be between 0 and 255'));

		if((int)$v->inputGet('max_attempts', $record['max_attempts'] ?? 1) < 1)$v->input('max_attempts')->addError(ts('`[FIELD]` must be at least 1'));

	}

	protected function detail(array $record):array
	{

		$json = function(?string $value):string{

			$data = json_decode((string)$value, true);
			return ($data === null) ? (string)$value : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		};

		return [
			'title' => ts('Job').' #'.$record['id'],
			'fields' => [
				['label' => ts('Type'), 'value' => $record['type']],
				['label' => ts('Status'), 'value' => ts($record['status']), 'type' => 'badge', 'color' => self::STATUSES[$record['status']] ?? 'grey'],
				['label' => ts('Date'), 'value' => $record['date']],
				['label' => ts('Scheduled'), 'value' => (string)$record['scheduled_at']],
				['label' => ts('Attempts'), 'value' => "{$record['attempts']} / {$record['max_attempts']}"],
				['label' => ts('Completed'), 'value' => (string)$record['completed_at']],
				['label' => ts('Worker'), 'value' => (string)$record['locked_by']],
				['label' => ts('Priority'), 'value' => (string)$record['priority']],
				['label' => ts('Comment'), 'value' => (string)$record['comment'], 'wide' => true],
				['label' => ts('Error'), 'value' => (string)$record['error_message'], 'wide' => true],
				['label' => ts('Payload'), 'value' => $json($record['payload']), 'type' => 'code', 'language' => 'json', 'wide' => true],
				['label' => ts('Result'), 'value' => $json($record['result']), 'type' => 'code', 'language' => 'json', 'wide' => true],
			],
		];

	}

	/**
	 * back to pending with its attempts reset
	 *
	 * @param array $record
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	protected function rowRetry(array $record):\Core\JsonResponse
	{

		if($record['status'] === 'processing')return \Core\JsonResponse::error(422, 'the job is being processed');

		$this->retry([(int)$record['id']]);
		return new \Core\JsonResponse(['message' => ts('Job queued again')]);

	}

	/**
	 * selected jobs back to pending (processing jobs left out)
	 *
	 * @param array $records
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	protected function batchRetry(array $records):\Core\JsonResponse
	{

		$ids = array_map(fn($r) => (int)$r['id'], array_filter($records, fn($r) => $r['status'] !== 'processing'));
		$this->retry($ids);

		return new \Core\JsonResponse(['message' => ts('[COUNT] jobs queued again', ['COUNT' => count($ids)])]);

	}

	private function retry(array $ids):void
	{

		if(!$ids)return;

		$in = implode(',', array_map('intval', $ids));
		DB()->query("UPDATE xcore_job_queue SET status = 'pending', attempts = 0, scheduled_at = NULL, locked_by = '', locked_at = NULL, error_message = '', updated_at = :now, updated_by = :author WHERE id IN ({$in}) AND deleted = 'no'", ['now' => now(), 'author' => $this->author()]);
		\Core\Log::write('retry', count($ids).' job(s) queued again', ['ids' => $ids], 0, 'info', $this->module);

	}

	/**
	 * soft deletes the completed jobs
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	protected function actionPurge(Request $request):\Core\JsonResponse
	{

		DB()->query("UPDATE xcore_job_queue SET deleted = 'yes', deleted_at = :now, deleted_by = :author WHERE status = 'completed' AND deleted = 'no'", ['now' => now(), 'author' => $this->author()]);
		$count = (int)DB()->rowCount();
		\Core\Log::write('purge', "{$count} completed jobs deleted", [], 0, 'warning', $this->module);

		return new \Core\JsonResponse(['message' => ts('[COUNT] jobs deleted', ['COUNT' => $count])]);

	}

	/**
	 * widget of the dashboard: worker state (cron task of core/cron/jobber.php run in the last 5 minutes) and jobs by status
	 *
	 * @return array see \Core\Widget
	 */
	public static function widgetJobber():array
	{

		$counts = DB()->query("SELECT status, COUNT(*) AS total FROM xcore_job_queue WHERE deleted = 'no' GROUP BY status")->fetchAll();
		$by = array_column($counts, 'total', 'status');

		$last = DB()->query("SELECT MAX(last_execution_date_start) FROM xcore_crontask WHERE deleted = 'no' AND active = 'yes' AND script_path LIKE '%cron/jobber.php'")->fetchOne();
		$active = ($last && strtotime($last) >= time() - 300);

		return [
			'type' => 'counters',
			'status' => ['label' => ts('Worker'), 'badge' => ['text' => ts(($active) ? 'active' : 'inactive'), 'color' => ($active) ? 'green' : 'grey']],
			'note' => ($last) ? ['date' => $last, 'prefix' => ts('last run')] : ['text' => ts('never run')],
			'tiles' => [
				['value' => (int)($by['pending'] ?? 0), 'label' => ts('pending'), 'color' => 'yellow'],
				['value' => (int)($by['processing'] ?? 0), 'label' => ts('processing'), 'color' => 'blue'],
				['value' => (int)($by['completed'] ?? 0), 'label' => ts('completed'), 'color' => 'green'],
				['value' => (int)($by['dead'] ?? 0), 'label' => ts('dead'), 'color' => 'red'],
			],
		];

	}

}
