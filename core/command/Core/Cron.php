<?php

namespace Command\Core;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * runs the due scheduled tasks, to put in the crontab every minute:
 * * * * * * cd /path/to/project && php vendor/bin/unee cron --instance=name > /dev/null 2>&1
 */
#[AsCommand(name: 'cron', description: 'Run the due scheduled tasks (crontab: every minute)')]
class Cron extends Command
{

	protected function configure():void
	{

		$this->addOption('id', null, InputOption::VALUE_REQUIRED, 'run this task now, due or not');

	}

	protected function execute(InputInterface $input, OutputInterface $output):int
	{

		\Core\Kernel::boot();
		$result = \Core\Cron::run((int)$input->getOption('id'), fn(string $line) => $output->writeln($line));
		$output->writeln("{$result['executed']} task(s) executed, {$result['errors']} error(s)");

		return ($result['errors']) ? Command::FAILURE : Command::SUCCESS;

	}

}
