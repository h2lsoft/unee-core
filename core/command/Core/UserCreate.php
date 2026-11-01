<?php

namespace Command\Core;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * creates a user, superadmin by default
 * the password is always asked hidden, never as an option (shell history, process list)
 */
#[AsCommand(name: 'user:create', description: 'Create a user (superadmin by default)')]
class UserCreate extends Command
{

	protected function configure():void
	{

		$this->addOption('email', null, InputOption::VALUE_REQUIRED, 'email');
		$this->addOption('login', null, InputOption::VALUE_REQUIRED, 'login');
		$this->addOption('firstname', null, InputOption::VALUE_REQUIRED, 'firstname');
		$this->addOption('lastname', null, InputOption::VALUE_REQUIRED, 'lastname');
		$this->addOption('group', null, InputOption::VALUE_REQUIRED, 'xcore_group id', 1);

	}

	protected function execute(InputInterface $input, OutputInterface $output):int
	{

		$io = new SymfonyStyle($input, $output);

		// one database per instance: the target must be explicit
		if((string)$input->getOption('instance') === '' && count(glob(APP_PATH.'/instances/*/config/app.php')))
		{

			$io->error("--instance is required");
			return Command::FAILURE;

		}

		$security = \Core\Config::get('auth/security');
		$login_cfg = $security['login'];
		$password_cfg = $security['password'];

		$checks = [
			'email' => fn($v) => (filter_var($v, FILTER_VALIDATE_EMAIL) && strlen($v) <= $security['email']['max_length']) ? $v : throw new \RuntimeException("invalid email"),
			'login' => fn($v) => (strlen((string)$v) >= $login_cfg['min_length'] && strlen($v) <= $login_cfg['max_length'] && preg_match($login_cfg['regex'], $v)) ? $v : throw new \RuntimeException("invalid login: {$login_cfg['min_length']} to {$login_cfg['max_length']} characters, alphanumeric, '-', '_'"),
			'firstname' => fn($v) => (trim((string)$v) !== '' && mb_strlen($v) <= 50) ? trim($v) : throw new \RuntimeException("firstname: 1 to 50 characters"),
			'lastname' => fn($v) => (trim((string)$v) !== '' && mb_strlen($v) <= 50) ? trim($v) : throw new \RuntimeException("lastname: 1 to 50 characters"),
		];

		$password_check = fn($v) => preg_match($password_cfg['regex'], (string)$v) ? $v : throw new \RuntimeException("invalid password: {$password_cfg['min_length']} to {$password_cfg['max_length']} characters");

		// option given or asked, always checked: in non interactive mode (-n) ask() returns null without running the validator
		$row = [];
		try
		{

			foreach($checks as $field => $check)
			{

				$value = $input->getOption($field);
				$row[$field] = $check($value ?? $io->ask($field, null, $check));

			}

			$password = $password_check($io->askHidden('password', $password_check));
			if($io->askHidden('password confirmation') !== $password)throw new \RuntimeException("passwords do not match");

		}
		catch(\RuntimeException $e)
		{

			$io->error($e->getMessage());
			return Command::FAILURE;

		}

		$db = \Core\DB::connectFromConfig();
		$group_id = (int)$input->getOption('group');

		if(!$db->query("SELECT COUNT(*) FROM xcore_group WHERE id = :id AND deleted = 'no'", ['id' => $group_id])->fetchOne())
		{

			$io->error("group not found: {$group_id}");
			return Command::FAILURE;

		}

		if($db->query("SELECT COUNT(*) FROM xcore_user WHERE deleted = 'no' AND (email = :email OR login = :login)", ['email' => $row['email'], 'login' => $row['login']])->fetchOne())
		{

			$io->error("email or login already used");
			return Command::FAILURE;

		}

		$row += [
			'xcore_group_id' => $group_id,
			'password' => password_hash($password, $password_cfg['algo'], $password_cfg['algo_options']),
			'avatar' => '',
			'company' => '',
			'bio' => '',
			'note' => '',
		];

		$id = $db->table('xcore_user')->insert($row, 'system');
		$io->success("user #{$id} created ({$row['login']}, group {$group_id})");

		return Command::SUCCESS;

	}

}
