<?php

namespace Core;

use Symfony\Component\HttpFoundation\Request;

/**
 * rules shared by the statistics (trackers today, newsletters and frontend statistics later), see the project doc link-tracker.md
 * - exclusions: globals stats.exclude.robots (user agent fragments) and stats.exclude.ips (ip or cidr), an excluded request is never counted
 * - visitor: hash of the salt of the day + domain + ip + user agent (xcore_stats_salt, a new salt every 24 h, the old ones deleted):
 *   no cookie, the ip and the user agent are never stored, a visitor counts again the next day
 * - device, browser and os from the user agent, country from the DB-IP lite database (cron task Statistics GeoIP, package maxmind-db/reader)
 */
class Stats
{

	// user agent fragments of the robots, default of the globals stats.exclude.robots
	public const array ROBOTS = ['bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'preview', 'curl', 'wget', 'python', 'headless', 'lighthouse', 'pingdom', 'uptime'];

	public const array DEVICES = ['mobile' => 'Mobile', 'desktop' => 'Desktop', 'tablet' => 'Tablet'];

	private static array $salts = [];
	private static mixed $reader = null;

	/**
	 * registers the shared globals with their default values (shown by the button Configuration of the modules of statistics)
	 *
	 * @return void
	 */
	public static function register():void
	{

		self::robots();
		self::ips();

	}

	/**
	 * user agent fragments of the robots, lower case
	 *
	 * @return array
	 */
	public static function robots():array
	{

		$robots = Globals::get('stats.exclude.robots', self::ROBOTS, true, 'list', 'statistics: user agent fragments of the robots never counted, one per line (case ignored), an empty user agent is a robot');
		return self::lines($robots, true);

	}

	/**
	 * ips and ranges never counted
	 *
	 * @return array
	 */
	public static function ips():array
	{

		$ips = Globals::get('stats.exclude.ips', [], true, 'list', 'statistics: ips never counted, one ip or range (cidr) per line, v4 or v6, ex: the office, the developer');
		return self::lines($ips);

	}

	/**
	 * clean lines of a globals of type list: trimmed, empty lines and comments (#) left out
	 *
	 * @param mixed $value
	 * @param bool $lower
	 *
	 * @return array
	 */
	public static function lines(mixed $value, bool $lower=false):array
	{

		if(is_string($value))$value = explode("\n", $value);
		if(!is_array($value))return [];

		$lines = [];
		foreach($value as $line)
		{

			$line = trim((string)$line);
			if($line === '' || $line[0] === '#')continue;
			$lines[] = ($lower) ? strtolower($line) : $line;

		}

		return $lines;

	}

	/**
	 * true when the user agent is empty or holds a fragment of the robots list
	 *
	 * @param string $user_agent
	 * @param array|null $robots null = the globals
	 *
	 * @return bool
	 */
	public static function isRobot(string $user_agent, ?array $robots=null):bool
	{

		$user_agent = strtolower(trim($user_agent));
		if($user_agent === '')return true;

		foreach($robots ?? self::robots() as $fragment)
		{

			if($fragment !== '' && str_contains($user_agent, $fragment))return true;

		}

		return false;

	}

	/**
	 * true when a request must not be counted: head, browser prefetch, robot, excluded ip
	 * the answer of the caller stays the same (redirect, 204): only the counting is skipped
	 *
	 * @param Request $request
	 * @param array $extra_ips ips of the module added to the shared ones
	 *
	 * @return bool
	 */
	public static function excluded(Request $request, array $extra_ips=[]):bool
	{

		if($request->isMethod('HEAD'))return true;

		// pages fetched ahead by the browser (chromium: sec-purpose, older: purpose, firefox: x-moz)
		foreach(['Sec-Purpose', 'Purpose', 'X-Moz'] as $header)
		{

			$value = strtolower((string)$request->headers->get($header, ''));
			if(str_contains($value, 'prefetch') || str_contains($value, 'prerender'))return true;

		}

		if(self::isRobot((string)$request->headers->get('User-Agent', '')))return true;

		return isTrustedProxy(getVisitorIp(), array_merge(self::ips(), $extra_ips));

	}

	/**
	 * salt of the day, created by the first request of the day (insert ignore: two requests at midnight read the same one)
	 * the salts of the past days are deleted then: yesterday's hashes cannot be computed again
	 *
	 * @return string
	 */
	public static function salt():string
	{

		$day = now('', false);
		if(isset(self::$salts[$day]))return self::$salts[$day];

		$created = DB()->query("INSERT IGNORE INTO xcore_stats_salt (`day`, `salt`) VALUES (:day, :salt)", ['day' => $day, 'salt' => bin2hex(random_bytes(32))])->rowCount();
		if($created)DB()->query("DELETE FROM xcore_stats_salt WHERE `day` < :day", ['day' => $day]);

		return self::$salts[$day] = (string)DB()->query("SELECT `salt` FROM xcore_stats_salt WHERE `day` = :day", ['day' => $day])->fetchOne();

	}

	/**
	 * visitor of the day: 32 hex characters, the same for a domain + ip + user agent until midnight
	 *
	 * @param string $domain
	 * @param string $ip
	 * @param string $user_agent
	 *
	 * @return string
	 */
	public static function visitor(string $domain, string $ip, string $user_agent):string
	{

		return substr(hash('sha256', self::salt().'|'.strtolower($domain).'|'.$ip.'|'.$user_agent), 0, 32);

	}

	/**
	 * mobile, desktop or tablet (empty without user agent)
	 * known limit: an ipad shows as a desktop mac since ipados 13, so tablet counts mostly the android tablets
	 *
	 * @param string $user_agent
	 * @param string $ch_mobile header Sec-CH-UA-Mobile (chromium): ?1 = mobile
	 *
	 * @return string
	 */
	public static function device(string $user_agent, string $ch_mobile=''):string
	{

		if($user_agent === '')return '';

		// android without "mobile" = tablet (rule of the android user agents)
		if(preg_match('/iPad|Tablet|PlayBook|Silk|Kindle/i', $user_agent) || (stripos($user_agent, 'Android') !== false && stripos($user_agent, 'Mobi') === false))return 'tablet';
		if(trim($ch_mobile) === '?1' || preg_match('/Mobi|iPhone|iPod|Windows Phone/i', $user_agent))return 'mobile';

		return 'desktop';

	}

	/**
	 * browser of a user agent, Other when unknown
	 *
	 * @param string $user_agent
	 *
	 * @return string
	 */
	public static function browser(string $user_agent):string
	{

		if($user_agent === '')return '';

		// chromium browsers before chrome, chrome before safari: their agents hold these words too
		return match(true)
		{

			str_contains($user_agent, 'Edg/') || str_contains($user_agent, 'EdgA/') || str_contains($user_agent, 'EdgiOS/') => 'Edge',
			str_contains($user_agent, 'OPR/') || str_contains($user_agent, 'Opera') => 'Opera',
			str_contains($user_agent, 'SamsungBrowser/') => 'Samsung Internet',
			str_contains($user_agent, 'Firefox/') || str_contains($user_agent, 'FxiOS/') => 'Firefox',
			str_contains($user_agent, 'Chrome/') || str_contains($user_agent, 'CriOS/') => 'Chrome',
			str_contains($user_agent, 'Safari/') => 'Safari',
			default => 'Other',

		};

	}

	/**
	 * system of a user agent, Other when unknown
	 *
	 * @param string $user_agent
	 *
	 * @return string
	 */
	public static function os(string $user_agent):string
	{

		if($user_agent === '')return '';

		// android and ios before linux and macos: their agents hold these words too
		return match(true)
		{

			str_contains($user_agent, 'Windows') => 'Windows',
			str_contains($user_agent, 'Android') => 'Android',
			(bool)preg_match('/iPhone|iPad|iPod/', $user_agent) => 'iOS',
			str_contains($user_agent, 'Mac OS X') || str_contains($user_agent, 'Macintosh') => 'macOS',
			str_contains($user_agent, 'CrOS') => 'ChromeOS',
			str_contains($user_agent, 'Linux') => 'Linux',
			default => 'Other',

		};

	}

	/**
	 * file of the country database, shared by every instance of the project (config stats/geoip_file to move it)
	 *
	 * @return string
	 */
	public static function geoipFile():string
	{

		return (string)(Config::get('stats/geoip_file', '') ?: APP_PATH.'/private/geoip/dbip-country-lite.mmdb');

	}

	/**
	 * iso code of the country of an ip (FR, BE...), empty when unknown: no database yet, package maxmind-db/reader missing, private ip
	 * the ip is read here and never stored
	 *
	 * @param string $ip
	 *
	 * @return string
	 */
	public static function country(string $ip):string
	{

		if($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP) || !class_exists(\MaxMind\Db\Reader::class))return '';

		try
		{

			if(self::$reader === null)
			{

				$file = self::geoipFile();
				if(!is_file($file))return '';
				self::$reader = new \MaxMind\Db\Reader($file);

			}

			$record = self::$reader->get($ip);

		}
		catch(\Throwable $e)
		{

			return '';

		}

		// db-ip and geolite2 files: country.iso_code
		$code = (is_array($record)) ? ($record['country']['iso_code'] ?? $record['country_code'] ?? '') : '';
		return (is_string($code) && preg_match('/^[A-Z]{2}$/', $code)) ? $code : '';

	}

}
