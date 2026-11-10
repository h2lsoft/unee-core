<?php

namespace Command\Core;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * second factor of a user from the console, the way out when nobody can sign in anymore (lost phone, no sms or email credits)
 * php vendor/bin/unee user:otp <login> --instance=x                       state of the user
 * php vendor/bin/unee user:otp <login> --method=none|authenticator|sms|email|default [--reset]
 * php vendor/bin/unee user:otp --global=none|authenticator|sms|email      method of the backend (globals auth.backend.two_factor.method)
 */
#[AsCommand(name: 'user:otp', description: 'Show or change the second factor (OTP) of a user or of the backend')]
class UserOtp extends Command
{

	protected function configure():void
	{

		$this->addArgument('login', InputArgument::OPTIONAL, 'email or login of the user (config auth/backend/key)');
		$this->addOption('method', null, InputOption::VALUE_REQUIRED, 'default, authenticator, sms, email or none (a change resets the OTP)');
		$this->addOption('reset', null, InputOption::VALUE_NONE, 'remove the secret of the authenticator app and the trusted devices');
		$this->addOption('global', null, InputOption::VALUE_REQUIRED, 'method of the backend for every user: authenticator, sms, email or none');

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

		\Core\Kernel::boot();

		$global = $input->getOption('global');
		if($global !== null)
		{

			if(!in_array($global, \Core\TwoFactor::METHODS, true))
			{

				$io->error("--global: authenticator, sms, email or none");
				return Command::FAILURE;

			}

			$before = \Core\TwoFactor::globalMethod('backend');
			\Core\Globals::set('auth.backend.two_factor.method', $global, 'text', 'second factor of the backend for every user: authenticator, sms, email or none (a user can have his own method)');
			\Core\Log::write('two_factor_cli', "OTP method of the backend: {$before} -> {$global}", [], 0, 'warning', 'user', 'unee-cli', 'cli');
			$io->success("OTP method of the backend: {$before} -> {$global}");

			if((string)$input->getArgument('login') === '')return Command::SUCCESS;

		}

		$login = (string)$input->getArgument('login');
		if($login === '')
		{

			$io->error("give the login of a user, or --global");
			return Command::FAILURE;

		}

		$key = (\Core\Config::get('auth/backend/key') === 'login') ? 'login' : 'email';
		$user = DB()->query("SELECT * FROM xcore_user WHERE deleted = 'no' AND {$key} = :login LIMIT 1", ['login' => $login])->fetch();
		if(!$user)
		{

			$io->error("user not found: {$login}");
			return Command::FAILURE;

		}

		$id = (int)$user['id'];
		$method = $input->getOption('method');
		if($method !== null)
		{

			if(!in_array($method, ['default', ...\Core\TwoFactor::METHODS], true))
			{

				$io->error("--method: default, authenticator, sms, email or none");
				return Command::FAILURE;

			}

			$value = ($method === 'default') ? '' : $method;
			if($value === 'sms' && \Core\Sms::normalize((string)$user['mobile']) === '')
			{

				$io->error("this user has no valid mobile: set it first in the users module");
				return Command::FAILURE;

			}

			if($value !== (string)$user['two_factor_method'])
			{

				DB('xcore_user')->update(['two_factor_method' => $value], $id, 1, 'unee-cli');
				\Core\TwoFactor::reset($id, 'unee-cli');
				\Core\Log::write('two_factor_cli', "OTP method of {$user['login']}: ".($user['two_factor_method'] ?: 'default')." -> {$method}", [], $id, 'warning', 'user', 'unee-cli', 'cli');
				$user['two_factor_method'] = $value;

			}

		}

		if($input->getOption('reset'))
		{

			\Core\TwoFactor::reset($id, 'unee-cli');
			\Core\Log::write('two_factor_cli', "OTP reset of {$user['login']}", [], $id, 'warning', 'user', 'unee-cli', 'cli');
			$user = DB()->query("SELECT * FROM xcore_user WHERE id = :id", ['id' => $id])->fetch();

		}

		$effective = \Core\TwoFactor::method($user, 'backend');
		$devices = (int)DB()->query("SELECT COUNT(*) FROM xcore_user_trusted_device WHERE deleted = 'no' AND xcore_user_id = :id AND expires_at > NOW()", ['id' => $id])->fetchOne();

		$io->definitionList(
			['user' => "#{$id} {$user['login']}"],
			['method' => $effective.(((string)$user['two_factor_method'] === '') ? ' (default of the backend)' : ' (own method)')],
			['mobile' => ((string)$user['mobile'] !== '') ? \Core\TwoFactor::mask((string)$user['mobile'], 'sms') : '-'],
			['email' => \Core\TwoFactor::mask((string)$user['email'], 'email')],
			['authenticator app' => ($user['two_factor_enabled_at']) ? "set up on {$user['two_factor_enabled_at']}" : 'not set up'],
			['trusted devices' => (string)$devices],
		);

		return Command::SUCCESS;

	}

}
