<?php

namespace Command\Core;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * applies pending sql files of the core, the project and the instance, in this order
 * files: <dir>/migrations/YYYYMMDDHHMMSS_description.sql, recorded in xcore_migration as "<scope>/<file name>"
 * a file has an up part, then a line "-- @down" followed by its inverse (used by --rollback, an empty down part = nothing to undo)
 * the up part of an applied migration is never modified: a change is a new file
 */
#[AsCommand(name: 'migrate', description: 'Apply pending sql migrations (core, project, instance), or roll back the last ones')]
class Migrate extends Command
{

	public const string DOWN_MARK = '/^--[ \t]*@down[ \t]*\r?$/m';

	protected function configure():void
	{

		$this->addOption('status', null, InputOption::VALUE_NONE, 'list pending migrations (or the ones a rollback would undo), apply nothing');
		$this->addOption('rollback', null, InputOption::VALUE_NONE, 'undo the last applied migrations with their -- @down part');
		$this->addOption('step', null, InputOption::VALUE_REQUIRED, 'number of migrations undone by --rollback', '1');
		$this->setHelp("without --instance, every instance is migrated (the project database when there is no instance)\nrollback: migrations are undone in the reverse order of application, a file without -- @down stops it before any change");

	}

	protected function execute(InputInterface $input, OutputInterface $output):int
	{

		$status_only = (bool)$input->getOption('status');
		$instance = (string)$input->getOption('instance');
		$rollback = (bool)$input->getOption('rollback');
		$step = (int)$input->getOption('step');

		if($rollback && $step < 1)
		{

			$output->writeln('--step must be a number greater than 0');
			return Command::INVALID;

		}

		if($instance !== '')
			return $this->migrateDatabase($instance, $status_only, $rollback, $step, $output);

		$instances = [];
		foreach(glob(APP_PATH.'/instances/*/config/app.php') as $instance_config)
		{

			$instances[] = basename(dirname($instance_config, 2));

		}

		// project without instances: migrate the project database
		if(!count($instances))
			return $this->migrateDatabase('', $status_only, $rollback, $step, $output);

		// one process per instance: APP_DIR and the config are defined once per process
		$failed = 0;
		foreach($instances as $name)
		{

			$cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(APP_PATH.'/vendor/bin/unee').' migrate --instance='.escapeshellarg($name);
			if($status_only)$cmd .= ' --status';
			if($rollback)$cmd .= ' --rollback --step='.$step;

			passthru($cmd, $exit_code);
			if($exit_code !== 0)$failed++;

		}

		return ($failed) ? Command::FAILURE : Command::SUCCESS;

	}

	/**
	 * up and down parts of a migration file
	 *
	 * @param string $file
	 *
	 * @return array [up, down], down = null when the file has no -- @down line
	 */
	public static function parts(string $file):array
	{

		$parts = preg_split(self::DOWN_MARK, (string)file_get_contents($file), 2);
		return [trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : null];

	}

	/**
	 * run every statement of a sql text, nextRowset() raises the error of a failing statement
	 *
	 * @param \PDO $pdo
	 * @param string $sql
	 *
	 * @return void
	 */
	private function runSql(\PDO $pdo, string $sql):void
	{

		// comments only: mysql refuses an empty query
		if(trim(preg_replace('/^[ \t]*--.*$/m', '', $sql)) === '')return;

		$stmt = $pdo->query($sql);
		while($stmt->nextRowset());
		$stmt->closeCursor();

	}

	/**
	 * migrate the database of the current process (APP_DIR)
	 */
	private function migrateDatabase(string $instance, bool $status_only, bool $rollback, int $step, OutputInterface $output):int
	{

		$label = ($instance === '') ? '[project]' : "[{$instance}]";
		$error_output = ($output instanceof ConsoleOutputInterface) ? $output->getErrorOutput() : $output;

		$db = \Core\DB::connectFromConfig();
		$pdo = $db->getConnectionLink();

		// migration table (also part of the core schema)
		$pdo->exec("CREATE TABLE IF NOT EXISTS `xcore_migration` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`version` varchar(255) NOT NULL DEFAULT '0',
			`deleted` enum('yes','no') NOT NULL DEFAULT 'no',
			`deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
			`created_at` datetime DEFAULT NULL,
			`created_by` varchar(255) DEFAULT NULL,
			`updated_at` datetime DEFAULT NULL,
			`updated_by` varchar(255) DEFAULT NULL,
			`deleted_at` datetime DEFAULT NULL,
			`deleted_by` varchar(255) DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `deleted` (`deleted`),
			UNIQUE KEY `version` (`version`, `deleted_key`)
		) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		// files: core, then project, then instance
		$scopes = ['core' => CORE_PATH.'/migrations', 'project' => APP_PATH.'/migrations'];
		if($instance !== '')$scopes['instance'] = APP_PATH."/instances/{$instance}/migrations";

		if($rollback)
			return $this->rollbackDatabase($pdo, $scopes, $label, $status_only, $step, $output, $error_output);

		$applied = $pdo->query("SELECT version FROM xcore_migration WHERE deleted = 'no'")->fetchAll(\PDO::FETCH_COLUMN);

		$pending = [];
		foreach($scopes as $scope => $dir)
		{

			foreach(glob("{$dir}/*.sql") as $file)
			{

				$name = basename($file, '.sql');
				if(!preg_match('/^\d{14}_[a-z0-9_]+$/', $name))
				{

					$error_output->writeln("{$label} invalid migration name: {$scope}/{$name}.sql (expected YYYYMMDDHHMMSS_description.sql)");
					return Command::FAILURE;

				}

				if(!in_array("{$scope}/{$name}", $applied))$pending["{$scope}/{$name}"] = $file;

			}

		}

		if(!count($pending))
		{

			$output->writeln("{$label} up to date");
			return Command::SUCCESS;

		}

		foreach($pending as $version => $file)
		{

			if($status_only)
			{

				$output->writeln("{$label} pending {$version}");
				continue;

			}

			try
			{

				$this->runSql($pdo, self::parts($file)[0]);

			}
			catch(\PDOException $e)
			{

				// mysql ddl statements are not transactional: statements before the error stay applied
				$error_output->writeln("{$label} FAILED {$version}: {$e->getMessage()}");
				return Command::FAILURE;

			}

			$db->table('xcore_migration')->insert(['version' => $version], 'unee-migrate');
			$output->writeln("{$label} applied {$version}");

		}

		return Command::SUCCESS;

	}

	/**
	 * undo the $step last applied migrations, last applied first
	 */
	private function rollbackDatabase(\PDO $pdo, array $scopes, string $label, bool $status_only, int $step, OutputInterface $output, OutputInterface $error_output):int
	{

		$recs = $pdo->query("SELECT id, version FROM xcore_migration WHERE deleted = 'no' ORDER BY id DESC LIMIT {$step}")->fetchAll(\PDO::FETCH_ASSOC);
		if(!count($recs))
		{

			$output->writeln("{$label} nothing to roll back");
			return Command::SUCCESS;

		}

		// every down part is checked before the first change
		$downs = [];
		foreach($recs as $rec)
		{

			[$scope, $name] = explode('/', $rec['version'], 2) + ['', ''];
			$file = isset($scopes[$scope]) ? "{$scopes[$scope]}/{$name}.sql" : '';
			if($file === '' || !is_file($file))
			{

				$error_output->writeln("{$label} rollback stopped, file not found: {$rec['version']}.sql");
				return Command::FAILURE;

			}

			$down = self::parts($file)[1];
			if($down === null)
			{

				$error_output->writeln("{$label} rollback stopped, no -- @down part in {$rec['version']}.sql");
				return Command::FAILURE;

			}

			$downs[] = ['id' => (int)$rec['id'], 'version' => $rec['version'], 'sql' => $down];

		}

		foreach($downs as $down)
		{

			if($status_only)
			{

				$output->writeln("{$label} to roll back {$down['version']}");
				continue;

			}

			try
			{

				$this->runSql($pdo, $down['sql']);

			}
			catch(\PDOException $e)
			{

				// mysql ddl statements are not transactional: statements before the error stay applied
				$error_output->writeln("{$label} ROLLBACK FAILED {$down['version']}: {$e->getMessage()}");
				return Command::FAILURE;

			}

			$pdo->prepare("UPDATE xcore_migration SET deleted = 'yes', deleted_at = NOW(), deleted_by = 'unee-migrate' WHERE id = :id")->execute(['id' => $down['id']]);
			$output->writeln("{$label} rolled back {$down['version']}");

		}

		return Command::SUCCESS;

	}

}
