<?php

namespace Command\Core;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * encryption key of an instance (config auth/security/encryption_key, 32 bytes in base64), written in:
 * - instances/<name>/config/app.php with --instance
 * - config/app_<APP_ENV>.php otherwise (not versioned)
 * --rotate re-encrypts the secrets with the new key (authenticator apps of the users, secret globals of the sms providers),
 * the old key is the current one of the config, or the public key of the old core config when the config has none (--old-key to give it)
 */
#[AsCommand(name: 'key:generate', description: 'Write a new encryption key in the config of the instance (--rotate re-encrypts the secrets)')]
class KeyGenerate extends Command
{

	protected function configure():void
	{

		$this->addOption('rotate', null, InputOption::VALUE_NONE, 're-encrypt the secrets of the database with the new key');
		$this->addOption('old-key', null, InputOption::VALUE_REQUIRED, 'key the secrets are encrypted with (default: the key of the config, else the old public key of the core)');
		$this->addOption('force', null, InputOption::VALUE_NONE, 'replace a key already set without --rotate (the secrets become unreadable)');

	}

	protected function execute(InputInterface $input, OutputInterface $output):int
	{

		$io = new SymfonyStyle($input, $output);
		$instance = (string)$input->getOption('instance');

		// one database per instance: the target must be explicit
		if($instance === '' && count(glob(APP_PATH.'/instances/*/config/app.php')))
		{

			$io->error("--instance is required");
			return Command::FAILURE;

		}

		$file = ($instance !== '') ? APP_PATH."/instances/{$instance}/config/app.php" : APP_PATH.'/config/app_'.APP_ENV.'.php';
		$current = (string)\Core\Config::get('auth/security/encryption_key', '');
		$rotate = (bool)$input->getOption('rotate');

		if(\Core\TwoFactor::keyStatus() !== 'missing' && !$rotate && !$input->getOption('force'))
		{

			$io->error("a key is already set: --rotate to re-encrypt the secrets with a new key, --force to replace it (the secrets become unreadable)");
			return Command::FAILURE;

		}

		if(!$rotate)$io->note("an instance already used (authenticator apps set up, sms provider saved) needs --rotate: its secrets are encrypted with the old key");

		$old = (string)($input->getOption('old-key') ?? (($current !== '') ? $current : \Core\TwoFactor::LEGACY_KEY));
		$raw = base64_decode($old, true);
		if($rotate && ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES))
		{

			$io->error("--old-key must be 32 bytes in base64");
			return Command::FAILURE;

		}

		$key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

		// secrets re-encrypted first: the config is written only when the database is done
		if($rotate)
		{

			\Core\Kernel::boot();
			try
			{

				$count = self::rotate($old, $key);

			}
			catch(\Throwable $e)
			{

				$io->error("nothing changed: ".$e->getMessage());
				return Command::FAILURE;

			}

			$io->writeln("{$count['users']} authenticator secret(s) and {$count['globals']} secret global(s) re-encrypted, {$count['unreadable']} unreadable with the old key (left as they are)");

		}

		$error = self::write($file, $key);
		if($error !== '')
		{

			$io->error($error);
			if($rotate)$io->warning("the secrets are already encrypted with the new key, add it by hand in the config: 'auth' => ['security' => ['encryption_key' => '{$key}']]");
			return Command::FAILURE;

		}

		$io->success("encryption key written in ".str_replace(APP_PATH.'/', '', $file));
		return Command::SUCCESS;

	}

	/**
	 * re-encrypts the secrets in one transaction: xcore_user.two_factor_secret, globals of the sms providers marked secret
	 *
	 * @param string $old base64 key
	 * @param string $new base64 key
	 *
	 * @return array{users: int, globals: int, unreadable: int}
	 */
	private static function rotate(string $old, string $new):array
	{

		$count = ['users' => 0, 'globals' => 0, 'unreadable' => 0];

		DB()->beginTransaction();
		try
		{

			foreach(DB()->query("SELECT id, two_factor_secret FROM xcore_user WHERE two_factor_secret IS NOT NULL AND two_factor_secret != ''")->fetchAll() as $user)
			{

				$secret = \Core\TwoFactor::decrypt((string)$user['two_factor_secret'], $old);
				if($secret === '')
				{

					$count['unreadable']++;
					continue;

				}

				DB()->query("UPDATE xcore_user SET two_factor_secret = :secret WHERE id = :id", ['secret' => \Core\TwoFactor::encrypt($secret, $new), 'id' => (int)$user['id']]);
				$count['users']++;

			}

			foreach(DB()->query("SELECT id, name, value FROM xcore_globals WHERE deleted = 'no' AND name LIKE 'sms.%' AND value != ''")->fetchAll() as $global)
			{

				if(!\Core\Sms::isSecret((string)$global['name']))continue;

				// a secret typed in clear in the module Globals stays as it is (encrypted at its next save)
				$secret = \Core\TwoFactor::decrypt((string)$global['value'], $old);
				if($secret === '')
				{

					$count['unreadable']++;
					continue;

				}

				DB()->query("UPDATE xcore_globals SET value = :value WHERE id = :id", ['value' => \Core\TwoFactor::encrypt($secret, $new), 'id' => (int)$global['id']]);
				$count['globals']++;

			}

			DB()->commit();

		}
		catch(\Throwable $e)
		{

			DB()->rollBack();
			throw $e;

		}

		return $count;

	}

	/**
	 * writes the key in a config file: value replaced, or an auth block added (refused when the file has its own auth block, to never lose it)
	 *
	 * @param string $file
	 * @param string $key base64
	 *
	 * @return string '' when written, the error otherwise
	 */
	private static function write(string $file, string $key):string
	{

		$entry = "'encryption_key' => '{$key}'";

		if(!file_exists($file))
		{

			if(!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true))return "cannot create the folder of {$file}";
			$content = "<?php\n\n// config of this environment or instance, not versioned: never commit the encryption key\nreturn [\n\n\t'auth' => [\n\t\t'security' => [\n\t\t\t{$entry},\n\t\t],\n\t],\n\n];\n";
			return (file_put_contents($file, $content) !== false) ? '' : "cannot write {$file}";

		}

		$content = (string)file_get_contents($file);
		$eol = str_contains($content, "\r\n") ? "\r\n" : "\n";

		if(preg_match("/'encryption_key'\s*=>\s*'[^']*'/", $content))
		{

			$content = (string)preg_replace("/'encryption_key'\s*=>\s*'[^']*'/", $entry, $content, 1);

		}
		elseif(preg_match("/['\"]auth['\"]\s*=>/", $content))
		{

			return "{$file} has its own auth block: add 'encryption_key' => '{$key}' in its auth / security part";

		}
		else
		{

			$pos = strrpos($content, '];');
			if($pos === false)return "{$file} does not end with ]; : add the key by hand";

			$block = "\t'auth' => [{$eol}\t\t'security' => [{$eol}\t\t\t{$entry},{$eol}\t\t],{$eol}\t],{$eol}{$eol}";
			$content = substr($content, 0, $pos).$block.substr($content, $pos);

		}

		return (file_put_contents($file, $content) !== false) ? '' : "cannot write {$file}";

	}

}
