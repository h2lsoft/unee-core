<?php

namespace Plugin\Core;

use Component\DataGrid;
use Component\Form;
use Symfony\Component\Routing\Attribute\Route;

/**
 * scheduled tasks (xcore_crontask), run by \Core\Cron from one crontab entry: php vendor/bin/unee cron (every minute)
 */
#[Route('/api/cron', name: 'api.cron.')]
class CronController extends \Core\CrudController
{

	public string $table = 'xcore_crontask';
	public string $title = 'Cron';
	public string $object_label = 'task';

	private const array STATUSES = ['idle' => 'grey', 'running' => 'orange', 'success' => 'green', 'error' => 'red'];

	protected function grid(DataGrid $grid):void
	{

		$statuses = array_combine(array_keys(self::STATUSES), array_keys(self::STATUSES));

		$grid->setSearch(['name', 'description', 'script_path'], 'Search name, script...');
		$grid->addFilterChips('category');
		$grid->addFilter('category', 'Category');
		$grid->addFilter('name', 'Name');
		$grid->addFilter('status', 'Status', 'select', $statuses);
		$grid->addFilter('locked', 'Locked', 'boolean');
		$grid->addFilter('active', 'Active', 'boolean');
		$grid->addFilter('id', 'ID', 'number');
		$grid->setOrderByFirst('priority');
		$grid->setMobile('name', 'schedule');
		$grid->batchEdit(['category', 'priority', 'active']);
		$grid->rowAction('run', 'Run now', 'play_arrow', 'edit');

		$grid->addColNumber('priority', 'Priority', true)->width(80);
		$grid->addCol('id', 'ID', true)->width(64);
		$grid->addCol('category', 'Category', true)->width(110);
		$grid->addCol('name', 'Name', true);
		$grid->addCol('schedule', 'Schedule')->width(170);
		$grid->addColBadge('status', 'Status', true, self::STATUSES)->labels(['idle' => 'idle', 'running' => 'running', 'success' => 'success', 'error' => 'error'])->width(100);
		$grid->addColDatetime('last_execution_date_start', 'Last run', true)->width(150);
		$grid->addCol('duration', 'Duration', false, 'text', ['align' => 'right'])->width(90);
		$grid->addColBoolean('locked', 'Locked', true, true)->width(80);
		$grid->addColBoolean('active', 'Active', true, true)->width(80);

		$grid->hookData(function(array $row):array{

			$row['schedule'] = "{$row['repetition_minutes']} {$row['repetition_hours']} {$row['repetition_days']} {$row['repetition_months']}";
			if($row['status'] === '')$row['status'] = 'idle';

			$row['duration'] = '';
			if($row['last_execution_date_start'] && $row['last_execution_date_end'])
				$row['duration'] = max(0, strtotime($row['last_execution_date_end']) - strtotime($row['last_execution_date_start'])).' s';

			return $row;

		});

	}

	protected function form(Form $form, array $record):void
	{

		$categories = DB()->query("SELECT DISTINCT category FROM xcore_crontask WHERE deleted = 'no' AND category != '' ORDER BY category")->fetchAllOne();

		$form->addSelect('category', 'Category', array_combine($categories, $categories), true)->autocomplete(true)->maxLength(255)->set('translate', false);
		$form->addText('name', 'Name', true)->maxLength(255);
		$form->addTextarea('description', 'Description')->maxLength(2000);
		$form->addText('script_path', 'Script', true)->maxLength(500)->placeholder('cron/purge.php')->help('php file relative to the project, receives $params and $cron, what it prints is the report');
		$form->addText('script_parameters', 'Parameters')->maxLength(1000)->placeholder('days=30&limit=500')->help('query string, read as $params in the script');

		$form->tab('schedule', 'Schedule');
		$form->addText('repetition_minutes', 'Minutes', true)->maxLength(255)->defaultValue('0')->help('0-59: *, a list (0,30), a range (0-10) or a step (*/5)');
		$form->addText('repetition_hours', 'Hours', true)->maxLength(255)->defaultValue('*')->help('0-23');
		$form->addText('repetition_days', 'Days', true)->maxLength(255)->defaultValue('*')->help('1-31');
		$form->addText('repetition_months', 'Months', true)->maxLength(255)->defaultValue('*')->help('1-12');
		$form->addNumber('priority', 'Priority', true, true)->defaultValue('0')->help('lower runs first');

		$form->tab('report', 'Report');
		$form->addBoolean('email_report', 'Email report')->defaultValue('no');
		$form->addBoolean('email_report_failure', 'On failure only')->defaultValue('no')->showIf('email_report', ['yes']);
		$form->addText('email_report_subject', 'Subject')->maxLength(255)->help('empty = Cron #id name: status')->showIf('email_report', ['yes']);
		$form->addTextarea('email_report_recipients', 'Recipients', true)->maxLength(2000)->placeholder('admin@example.com; dev@example.com')->showIf('email_report', ['yes']);
		$form->tab('');

		$form->addBoolean('active', 'Active')->defaultValue('yes');

	}

	protected function rulesOnSubmit(\Core\Validator $v, array $record):void
	{

		$script = (string)$v->inputGet('script_path', $record['script_path'] ?? '');
		if($script !== '' && \Core\Cron::scriptPath($script) === '')
			$v->input('script_path')->addError(ts('`[FIELD]` must be an existing php file of the project'));

		foreach(array_keys(\Core\Cron::RULES) as $name)
		{

			$rule = (string)$v->inputGet($name, $record[$name] ?? '');
			$error = ($rule === '') ? '' : \Core\Cron::ruleError($name, $rule);
			if($error !== '')$v->input($name)->addError(ts('`[FIELD]`: [ERROR]', ['ERROR' => $error]));

		}

		if($v->inputGet('email_report', $record['email_report'] ?? 'no') === 'yes')
		{

			$recipients = preg_split('/[;,\s]+/', trim((string)$v->inputGet('email_report_recipients', $record['email_report_recipients'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
			foreach($recipients as $email)
				if(!filter_var($email, FILTER_VALIDATE_EMAIL))$v->input('email_report_recipients')->addError(ts('`[FIELD]`: invalid email [EMAIL]', ['EMAIL' => $email]));

		}

	}

	protected function onSaveBefore(array $row, array $record):array
	{

		if(!$record)
		{

			$row['status'] = 'idle';
			$row['last_error_message'] = '';

		}

		foreach(array_keys(\Core\Cron::RULES) as $name)
			if(isset($row[$name]))$row[$name] = str_replace([' ', ';'], ['', ','], trim((string)$row[$name]));

		return $row;

	}

	/**
	 * runs the task now, due or not (also from the console: unee cron --id=)
	 *
	 * @param array $record
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	protected function rowRun(array $record):\Core\JsonResponse
	{

		if($record['locked'] === 'yes')return \Core\JsonResponse::error(422, ts('the task is locked (running or stopped): unlock it first'));

		set_time_limit(300);
		$lines = [];
		\Core\Cron::run((int)$record['id'], function(string $line) use (&$lines){ $lines[] = $line; });

		$row = DB()->query("SELECT status, last_error_message FROM xcore_crontask WHERE id = :id", ['id' => $record['id']])->fetch();
		if(($row['status'] ?? '') === 'error')return \Core\JsonResponse::error(422, ts('Error: [ERROR]', ['ERROR' => $row['last_error_message']]));

		return new \Core\JsonResponse(['message' => ts('Task executed')]);

	}

	/**
	 * widget of the dashboard: active tasks with their status and last run
	 *
	 * @return array see \Core\Widget
	 */
	public static function widgetCron():array
	{

		$recs = DB()->query("SELECT name, status, repetition_minutes, repetition_hours, repetition_days, repetition_months, last_execution_date_start FROM xcore_crontask WHERE deleted = 'no' AND active = 'yes' ORDER BY priority, id LIMIT 10")->fetchAll();

		$rows = [];
		foreach($recs as $rec)
		{

			$status = ($rec['status'] === '') ? 'idle' : $rec['status'];
			$rows[] = [
				['text' => $rec['name'], 'grow' => true, 'strong' => true],
				['text' => "{$rec['repetition_minutes']} {$rec['repetition_hours']} {$rec['repetition_days']} {$rec['repetition_months']}", 'mono' => true, 'muted' => true],
				['badge' => self::STATUSES[$status] ?? 'grey', 'text' => ts($status), 'width' => 80],
				($rec['last_execution_date_start']) ? ['date' => $rec['last_execution_date_start'], 'align' => 'right', 'width' => 110] : ['text' => '-', 'muted' => true, 'align' => 'right', 'width' => 110],
			];

		}

		return ['type' => 'rows', 'rows' => $rows, 'empty' => ts('No active task')];

	}

}
