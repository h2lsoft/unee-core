<?php

namespace Plugin\Core;

use Symfony\Component\Routing\Attribute\Route;

/**
 * php, server and database information (module system-info, menu tools), secrets masked
 */
#[Route('/api/system-info', name: 'api.system_info.')]
class System_InfoController extends \Core\Controller
{

	/**
	 * names whose value is masked: passwords, secrets, tokens, keys, authorization headers and cookies
	 */
	private const string SECRET = '/pass|pwd|_pw$|secret|token|(^|_)key$|api_?key|auth|cookie/i';

	/**
	 * information of the three tabs
	 *
	 * @return \Core\JsonResponse {"php": {"stats", "ini", "extensions", "env"}, "server": [{"name", "value"}], "database": {"version", "variables"}}
	 */
	#[Route('/', name: 'index', methods: ['GET'])]
	public function index():\Core\JsonResponse
	{

		if(!\Model\User::hasRight($this->module, 'list'))return \Core\JsonResponse::error(403, 'access denied');

		$extensions = get_loaded_extensions();
		natcasesort($extensions);

		$server = $_SERVER;
		ksort($server);
		$env = getenv();
		ksort($env);

		return new \Core\JsonResponse([
			'title' => ts('System info'),
			'php' => [
				'stats' => [
					['label' => ts('PHP version'), 'value' => PHP_VERSION],
					['label' => ts('Interface'), 'value' => PHP_SAPI],
					['label' => ts('Memory limit'), 'value' => (string)ini_get('memory_limit')],
					['label' => ts('Max execution time'), 'value' => ini_get('max_execution_time').' s'],
					['label' => ts('Upload max filesize'), 'value' => (string)ini_get('upload_max_filesize')],
					['label' => ts('Extensions'), 'value' => (string)count($extensions)],
				],
				'ini' => $this->pairs(array_map(fn($v) => (string)$v, ini_get_all(null, false))),
				'extensions' => array_values($extensions),
				'env' => $this->pairs($env),
			],
			'server' => $this->pairs(array_filter($server, fn($v) => is_scalar($v) || $v === null)),
			'database' => [
				'version' => (string)DB()->query("SELECT VERSION()")->fetchOne(),
				'variables' => $this->pairs(array_column(DB()->query("SHOW VARIABLES")->fetchAll(), 'Value', 'Variable_name')),
			],
		]);

	}

	/**
	 * name => value to a list, secrets masked
	 *
	 * @param array $values
	 *
	 * @return array [{"name", "value"}]
	 */
	private function pairs(array $values):array
	{

		$list = [];
		foreach($values as $name => $value)
			$list[] = ['name' => (string)$name, 'value' => (preg_match(self::SECRET, (string)$name) && (string)$value !== '') ? '********' : (string)$value];

		return $list;

	}

}
