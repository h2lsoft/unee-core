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
	 * @return \Core\JsonResponse {"server": {"stats", "variables"}, "php": {"stats", "ini", "extensions", "env"}, "database": {"version", "variables"}}
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
			'server' => [
				'stats' => $this->serverStats(),
				'variables' => $this->pairs(array_filter($server, fn($v) => is_scalar($v) || $v === null)),
			],
			'database' => [
				'version' => (string)DB()->query("SELECT VERSION()")->fetchOne(),
				'variables' => $this->pairs(array_column(DB()->query("SHOW VARIABLES")->fetchAll(), 'Value', 'Variable_name')),
			],
		]);

	}

	/**
	 * machine: host, system, cpu, load, memory, swap, disk, uptime (linux /proc, a value not readable is left out, ex: windows, open_basedir)
	 *
	 * @return array [{"label", "value"}]
	 */
	private function serverStats():array
	{

		$stats = [];
		$add = function(string $label, ?string $value) use (&$stats):void{if($value !== null && $value !== '')$stats[] = ['label' => ts($label), 'value' => $value];};

		$add('Host', gethostname() ?: null);
		$add('System', php_uname('s').' '.php_uname('r').' ('.php_uname('m').')');
		$add('Web server', $_SERVER['SERVER_SOFTWARE'] ?? null);

		// cpu: model and cores
		$cpuinfo = (string)@file_get_contents('/proc/cpuinfo');
		if($cpuinfo !== '')
		{

			preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $model);
			$cores = preg_match_all('/^processor\s*:/m', $cpuinfo);
			$add('CPU', trim(($model[1] ?? '').' - '.ts('[COUNT] cores', ['COUNT' => $cores]), ' -'));

		}

		$load = (function_exists('sys_getloadavg')) ? @sys_getloadavg() : false;
		if($load)$add('Load average', implode(' / ', array_map(fn($l) => number_format($l, 2), $load)));

		// memory and swap from /proc/meminfo (kB)
		$meminfo = [];
		foreach(explode("\n", (string)@file_get_contents('/proc/meminfo')) as $line)
			if(preg_match('/^(\w+):\s+(\d+)/', $line, $m))$meminfo[$m[1]] = (int)$m[2] * 1024;

		if(isset($meminfo['MemTotal'], $meminfo['MemAvailable']))$add('Memory', $this->usage($meminfo['MemTotal'] - $meminfo['MemAvailable'], $meminfo['MemTotal']));
		if(isset($meminfo['SwapTotal'], $meminfo['SwapFree']))$add('Swap', ($meminfo['SwapTotal']) ? $this->usage($meminfo['SwapTotal'] - $meminfo['SwapFree'], $meminfo['SwapTotal']) : ts('none'));

		// disk of the application
		$total = @disk_total_space(APP_PATH);
		$free = @disk_free_space(APP_PATH);
		if($total && $free !== false)$add('Disk', $this->usage($total - $free, $total));

		$uptime = (float)@file_get_contents('/proc/uptime');
		if($uptime > 0)$add('Uptime', ts('[DAYS] d [HOURS] h', ['DAYS' => intdiv((int)$uptime, 86400), 'HOURS' => intdiv((int)$uptime % 86400, 3600)]));

		$add('Server time', date('Y-m-d H:i:s T'));

		return $stats;

	}

	/**
	 * used / total in GB with the percentage
	 *
	 * @param float $used bytes
	 * @param float $total bytes
	 *
	 * @return string ex: 5.2 / 16 GB (32%)
	 */
	private function usage(float $used, float $total):string
	{

		$gb = fn($bytes) => rtrim(rtrim(number_format($bytes / 1073741824, 1, '.', ''), '0'), '.');
		return $gb($used).' / '.$gb($total).' GB ('.round($used / $total * 100).'%)';

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
