<?php

namespace Command\Core;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * runs the pending jobs of the queue (also run every minute by the cron task "Jobber")
 */
#[AsCommand(name: 'jobber', description: 'Run the pending jobs of the queue')]
class Jobber extends Command
{

	protected function configure():void
	{

		$this->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'maximum number of jobs', 100);
		$this->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'maximum duration in seconds', 50);

	}

	protected function execute(InputInterface $input, OutputInterface $output):int
	{

		\Core\Kernel::boot();
		$result = \Core\Jobber::run((int)$input->getOption('max-jobs'), (int)$input->getOption('max-seconds'), fn(string $line) => $output->writeln($line));
		$output->writeln("{$result['completed']} job(s) completed, {$result['failed']} failed");

		return Command::SUCCESS;

	}

}
