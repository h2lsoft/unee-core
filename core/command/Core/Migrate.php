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
 * an applied migration is never modified: a change is a new file
 */
#[AsCommand(name: 'migrate', description: 'Apply pending sql migrations (core, project, instance)')]
class Migrate extends Command
{
	protected function configure():void
	{
		$this->addOption('status', null, InputOption::VALUE_NONE, 'list pending migrations, apply nothing');
		$this->setHelp("without --instance, every instance is migrated (the project database when there is no instance)");
	}

	protected function execute(InputInterface $input, OutputInterface $output):int
	{
		$status_only = (bool)$input->getOption('status');
		$instance = (string)$input->getOption('instance');

		if($instance !== '')
			return $this->migrateDatabase($instance, $status_only, $output);

		$instances = [];
		foreach(glob(APP_PATH.'/instances/*/config/app.php') as $instance_config)
		{
			$instances[] = basename(dirname($instance_config, 2));
		}

		// project without instances: migrate the project database
		if(!count($instances))
			return $this->migrateDatabase('', $status_only, $output);

		// one process per instance: APP_DIR and the config are defined once per process
		$failed = 0;
		foreach($instances as $name)
		{
			$cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(APP_PATH.'/vendor/bin/unee').' migrate --instance='.escapeshellarg($name);
			if($status_only)$cmd .= ' --status';

			passthru($cmd, $exit_code);
			if($exit_code !== 0)$failed++;
		}

		return ($failed) ? Command::FAILURE : Command::SUCCESS;
	}

	/**
	 * migrate the database of the current process (APP_DIR)
	 */
	private function migrateDatabase(string $instance, bool $status_only, OutputInterface $output):int
	{
		$label = ($instance === '') ? '[project]' : "[{$instance}]";
		$error_output = ($output instanceof ConsoleOutputInterface) ? $output->getErrorOutput() : $output;

		// connection: same config and cli overrides as core/run.php
		$db_cfg = \Core\Config::get('db');
		foreach(['host', 'port', 'username', 'password', 'database', 'pdo_options', 'init_queries'] as $key)
		{
			$value = \Core\Config::get("cli/db/{$key}");
			if(!empty($value))$db_cfg[$key] = $value;
		}

		$db = new \Core\DB();
		$pdo = $db->connect($db_cfg['driver'], $db_cfg['host'], $db_cfg['username'], $db_cfg['password'], $db_cfg['database'], $db_cfg['port'], $db_cfg['pdo_options']);
		foreach($db_cfg['init_queries'] as $sql)
		{
			$pdo->exec($sql);
		}

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

		$applied = $pdo->query("SELECT version FROM xcore_migration WHERE deleted = 'no'")->fetchAll(\PDO::FETCH_COLUMN);

		// pending files: core, then project, then instance
		$scopes = ['core' => CORE_PATH.'/migrations', 'project' => APP_PATH.'/migrations'];
		if($instance !== '')$scopes['instance'] = APP_PATH."/instances/{$instance}/migrations";

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

			$sql = trim(file_get_contents($file));
			try
			{
				// every statement of the file, nextRowset() raises the error of a failing statement
				if($sql !== '')
				{
					$stmt = $pdo->query($sql);
					while($stmt->nextRowset());
					$stmt->closeCursor();
				}
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
}
