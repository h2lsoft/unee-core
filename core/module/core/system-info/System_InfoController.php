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
					['label' => ts('PHP version'), 'value' => PHP_VERSION, 'icon' => 'code'],
					['label' => ts('Interface'), 'value' => PHP_SAPI, 'icon' => 'settings_ethernet'],
					['label' => ts('Memory limit'), 'value' => (string)ini_get('memory_limit'), 'icon' => 'memory_alt'],
					['label' => ts('Max execution time'), 'value' => ini_get('max_execution_time').' s', 'icon' => 'timer'],
					['label' => ts('Upload max filesize'), 'value' => (string)ini_get('upload_max_filesize'), 'icon' => 'upload_file'],
					['label' => ts('Extensions'), 'value' => (string)count($extensions), 'icon' => 'extension'],
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
	 * machine: host, system, cpu, load, memory, swap, disk, uptime (linux /proc, windows: cpu from the environment, a value not readable is left out)
	 *
	 * @return array [{"label", "value", "icon", "percent"}], percent: used part of memory, swap and disk
	 */
	private function serverStats():array
	{

		$stats = [];
		$add = function(string $label, ?string $value, string $icon, ?int $percent=null) use (&$stats):void{

			if($value !== null && $value !== '')$stats[] = ['label' => ts($label), 'value' => $value, 'icon' => $icon] + (($percent !== null) ? ['percent' => $percent] : []);

		};

		$add('Host', gethostname() ?: null, 'dns');
		$add('System', php_uname('s').' '.php_uname('r').' ('.php_uname('m').')', 'computer');
		$add('Web server', $_SERVER['SERVER_SOFTWARE'] ?? null, 'public');

		// cpu: model and cores
		$cpuinfo = (string)@file_get_contents('/proc/cpuinfo');
		if($cpuinfo !== '')
		{

			preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $model);
			$cores = preg_match_all('/^processor\s*:/m', $cpuinfo);
			$add('CPU', trim(($model[1] ?? '').' - '.ts('[COUNT] cores', ['COUNT' => $cores]), ' -'), 'memory');

		}
		elseif(getenv('PROCESSOR_IDENTIFIER'))
		{

			$add('CPU', getenv('PROCESSOR_IDENTIFIER').' - '.ts('[COUNT] cores', ['COUNT' => (int)getenv('NUMBER_OF_PROCESSORS')]), 'memory');

		}

		$load = (function_exists('sys_getloadavg')) ? @sys_getloadavg() : false;
		if($load)$add('Load average', implode(' / ', array_map(fn($l) => number_format($l, 2), $load)), 'speed');

		// memory and swap from /proc/meminfo (kB), windows: memory and paging files of the system
		$meminfo = [];
		foreach(explode("\n", (string)@file_get_contents('/proc/meminfo')) as $line)
			if(preg_match('/^(\w+):\s+(\d+)/', $line, $m))$meminfo[$m[1]] = (int)$m[2] * 1024;

		if(!$meminfo && PHP_OS_FAMILY === 'Windows')$meminfo = $this->windowsMemory();

		if(isset($meminfo['MemTotal'], $meminfo['MemAvailable']))$add('Memory', $this->usage($meminfo['MemTotal'] - $meminfo['MemAvailable'], $meminfo['MemTotal']), 'memory_alt', $this->percent($meminfo['MemTotal'] - $meminfo['MemAvailable'], $meminfo['MemTotal']));
		if(isset($meminfo['SwapTotal'], $meminfo['SwapFree']))$add('Swap', ($meminfo['SwapTotal']) ? $this->usage($meminfo['SwapTotal'] - $meminfo['SwapFree'], $meminfo['SwapTotal']) : ts('none'), 'swap_horiz', ($meminfo['SwapTotal']) ? $this->percent($meminfo['SwapTotal'] - $meminfo['SwapFree'], $meminfo['SwapTotal']) : null);

		// disk of the application
		$total = @disk_total_space(APP_PATH);
		$free = @disk_free_space(APP_PATH);
		if($total && $free !== false)$add('Disk', $this->usage($total - $free, $total), 'hard_drive', $this->percent($total - $free, $total));

		$uptime = (float)@file_get_contents('/proc/uptime');
		if($uptime > 0)$add('Uptime', ts('[DAYS] d [HOURS] h', ['DAYS' => intdiv((int)$uptime, 86400), 'HOURS' => intdiv((int)$uptime % 86400, 3600)]), 'timer');

		$add('Server time', date('Y-m-d H:i:s T'), 'schedule');

		return $stats;

	}

	/**
	 * memory and paging files of windows (kB in Win32_OperatingSystem) as /proc/meminfo keys in bytes: com_dotnet, else wmic, else powershell
	 *
	 * @return array MemTotal, MemAvailable, SwapTotal, SwapFree, [] when nothing is readable
	 */
	private function windowsMemory():array
	{

		$fields = ['TotalVisibleMemorySize', 'FreePhysicalMemory', 'SizeStoredInPagingFiles', 'FreeSpaceInPagingFiles'];
		$values = [];

		try
		{

			if(class_exists('COM'))
			{

				$wmi = new \COM('winmgmts://./root/cimv2');
				foreach($wmi->ExecQuery('SELECT '.implode(', ', $fields).' FROM Win32_OperatingSystem') as $os)
					foreach($fields as $field)$values[$field] = (int)$os->$field;

			}

		}
		catch(\Throwable)
		{

			$values = [];

		}

		// fixed commands, no user input
		if(!$values && function_exists('shell_exec'))
		{

			$output = (string)@shell_exec('wmic OS get '.implode(',', $fields).' /Value 2>NUL');
			if(trim($output) === '')$output = (string)@shell_exec('powershell -NoProfile -Command "Get-CimInstance Win32_OperatingSystem | Format-List '.implode(',', $fields).'" 2>NUL');
			$values = $this->parseWindowsValues($output, $fields);

		}

		if(!isset($values['TotalVisibleMemorySize'], $values['FreePhysicalMemory']))return [];

		return [
			'MemTotal' => $values['TotalVisibleMemorySize'] * 1024,
			'MemAvailable' => $values['FreePhysicalMemory'] * 1024,
		] + ((isset($values['SizeStoredInPagingFiles'], $values['FreeSpaceInPagingFiles'])) ? ['SwapTotal' => $values['SizeStoredInPagingFiles'] * 1024, 'SwapFree' => $values['FreeSpaceInPagingFiles'] * 1024] : []);

	}

	/**
	 * values of a wmic (Name=123) or powershell format-list (Name : 123) output
	 *
	 * @param string $output
	 * @param array $fields
	 *
	 * @return array field => int
	 */
	private function parseWindowsValues(string $output, array $fields):array
	{

		$values = [];
		foreach($fields as $field)
			if(preg_match('/^\s*'.$field.'\s*[=:]\s*(\d+)\s*$/mi', $output, $m))$values[$field] = (int)$m[1];

		return $values;

	}

	/**
	 * used / total in GB with the percentage
	 *
	 * @param float $used bytes
	 * @param float $total bytes
	 *
	 * @return string ex: 5.2 / 16 GB
	 */
	private function usage(float $used, float $total):string
	{

		$gb = fn($bytes) => rtrim(rtrim(number_format($bytes / 1073741824, 1, '.', ''), '0'), '.');
		return $gb($used).' / '.$gb($total).' GB';

	}

	private function percent(float $used, float $total):int
	{

		return (int)round($used / $total * 100);

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
