<?php

namespace Core;

use Symfony\Component\HttpFoundation\Request;

/**
 * smart links (module Communication > Smart links, xcore_smart_link, xcore_smart_link_hit), see the project doc smart-links.md
 * a smart link counts an action: a click sent to the public url /go/<code>/ (GET = counted then redirected, POST = counted, 204) or an action validated by php:
 *   \Core\SmartLink::hit('contact-form-sent');
 *   \Core\SmartLink::hit('donate-done', amount: 10, currency: 'EUR', ref: $payment_id);
 * - one row per hit, insert only: source (?s=), referrer (domain, or page of the site for an action), country, device, browser, os, visitor hash of the day (\Core\Stats)
 * - never counted: robots, excluded ips (globals stats.exclude.* and smart-link.exclude.ips), head and prefetch requests, inactive smart links
 * - an amount comes from php only (never from the public url) and needs a ref: the same ref is counted once by smart link (reload of a return page)
 * - currencies are never added together
 */
class SmartLink
{

	public const array TYPES = ['redirect' => 'Redirect', 'action' => 'Action'];
	public const array TYPE_COLORS = ['redirect' => 'blue', 'action' => 'orange'];

	public const string CODE_PATTERN = '[A-Za-z0-9_-]{1,64}';

	// columns of a hit that can be grouped in the statistics
	private const array BREAKDOWNS = ['source', 'referrer', 'country', 'device', 'browser', 'os'];

	/**
	 * registers the globals of the module with their default values (button Configuration of the grid: categories stats and smart-link)
	 *
	 * @return void
	 */
	public static function register():void
	{

		Stats::register();
		self::excludedIps();
		self::retentionMonths();

	}

	/**
	 * ips never counted by the smart links, added to the shared ones (globals stats.exclude.ips)
	 *
	 * @return array
	 */
	public static function excludedIps():array
	{

		$ips = Globals::get('smart-link.exclude.ips', [], true, 'list', 'smart links: more ips never counted, added to stats.exclude.ips, one ip or range (cidr) per line');
		return Stats::lines($ips);

	}

	/**
	 * months the hits are kept, 0 = never purged
	 *
	 * @return int
	 */
	public static function retentionMonths():int
	{

		return max(0, (int)Globals::get('smart-link.retention.months', '24', true, 'int', 'smart links: months the hits are kept, 0 = never purged (cron task Smart links purge)'));

	}

	/**
	 * smart link of a code (active or not), null when unknown
	 *
	 * @param string $code
	 *
	 * @return array|null
	 */
	public static function find(string $code):?array
	{

		if(!preg_match('/^'.self::CODE_PATTERN.'$/', $code))return null;

		$smart_link = DB()->query("SELECT id, code, type, url, active FROM xcore_smart_link WHERE code = :code AND deleted = 'no' LIMIT 1", ['code' => $code])->fetch();
		return ($smart_link) ?: null;

	}

	/**
	 * public url of a smart link
	 *
	 * @param string $code
	 *
	 * @return string
	 */
	public static function url(string $code):string
	{

		return rtrim((string)Config::get('url'), '/')."/go/{$code}/";

	}

	/**
	 * counts an action validated by the server, in the request of the visitor (country, device and source are his)
	 * an unknown or inactive smart link and an excluded visitor are not counted (false), wrong arguments throw
	 *
	 * @param string $code code of the smart link
	 * @param float|null $amount amount of the action (donation, order), null = none
	 * @param string $currency iso 4217 code, required with an amount
	 * @param string $ref reference of the caller (ex: payment id), counted once by smart link, required with an amount
	 * @param string $source same as ?s= of the public url, empty = the one of the request
	 *
	 * @return bool true when the hit is counted
	 */
	public static function hit(string $code, ?float $amount=null, string $currency='', string $ref='', string $source=''):bool
	{

		$currency = strtoupper(trim($currency));
		$ref = trim($ref);

		if($amount !== null)
		{

			if($ref === '')throw new \InvalidArgumentException('a ref is required with an amount (counted once by smart link)');
			if($amount < 0)throw new \InvalidArgumentException('the amount must be positive');
			if(!preg_match('/^[A-Z]{3}$/', $currency))throw new \InvalidArgumentException('a currency (iso 4217 code, ex: EUR) is required with an amount');

		}

		if($ref !== '' && !preg_match('/^[\x21-\x7e]{1,64}$/', $ref))throw new \InvalidArgumentException('the ref holds 1 to 64 ascii characters without space');

		if(!$smart_link = self::find($code))return false;

		$request = $GLOBALS['request'] ?? null;
		return self::record($smart_link, ($request instanceof Request) ? $request : null, $amount, ($amount !== null) ? $currency : '', $ref, $source);

	}

	/**
	 * writes the hit of a smart link unless it is inactive or the request is excluded
	 * no request (cli): counted without visitor data
	 *
	 * @param array $smart_link row of find()
	 * @param Request|null $request
	 * @param float|null $amount
	 * @param string $currency
	 * @param string $ref
	 * @param string $source
	 *
	 * @return bool true when a row is written (false too for a ref already counted)
	 */
	public static function record(array $smart_link, ?Request $request, ?float $amount=null, string $currency='', string $ref='', string $source=''):bool
	{

		if($smart_link['active'] !== 'yes')return false;

		$row = ['xcore_smart_link_id' => (int)$smart_link['id'], 'date' => now(), 'source' => self::source($source), 'referrer' => '', 'country' => '', 'device' => '', 'browser' => '', 'os' => '', 'visitor' => '', 'amount' => ($amount === null) ? null : round($amount, 2), 'currency' => $currency, 'ref' => ($ref === '') ? null : $ref];

		if($request)
		{

			if(Stats::excluded($request, self::excludedIps()))return false;

			$ip = getVisitorIp();
			$user_agent = (string)$request->headers->get('User-Agent', '');

			if($row['source'] === '')$row['source'] = self::source((string)$request->query->get('s', ''));
			$row['referrer'] = self::referrer($request, (string)$smart_link['type']);
			$row['country'] = Stats::country($ip);
			$row['device'] = Stats::device($user_agent, (string)$request->headers->get('Sec-CH-UA-Mobile', ''));
			$row['browser'] = Stats::browser($user_agent);
			$row['os'] = Stats::os($user_agent);
			$row['visitor'] = Stats::visitor($request->getHost(), $ip, $user_agent);

		}

		$columns = array_keys($row);
		$sql = "INSERT IGNORE INTO xcore_smart_link_hit (`".join('`, `', $columns)."`) VALUES (:".join(', :', $columns).")";

		return DB()->query($sql, $row)->rowCount() > 0;

	}

	/**
	 * source of a hit: lower case letters, digits, - and _ (32 at most), anything else is dropped
	 *
	 * @param string $source
	 *
	 * @return string
	 */
	public static function source(string $source):string
	{

		$source = strtolower(trim($source));
		return (preg_match('/^[a-z0-9_-]{1,32}$/', $source)) ? $source : '';

	}

	/**
	 * referrer of a hit: the domain of the referer only (never the full url), the page of the site for an action sent from it
	 *
	 * @param Request $request
	 * @param string $type type of the smart link
	 *
	 * @return string
	 */
	public static function referrer(Request $request, string $type):string
	{

		$parts = parse_url((string)$request->headers->get('Referer', ''));
		if(!is_array($parts) || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true))return '';

		$host = strtolower($parts['host']);

		if($type === 'action' && $host === strtolower($request->getHost()))
		{

			$path = (string)($parts['path'] ?? '/');
			return mb_substr(($path === '') ? '/' : $path, 0, 190);

		}

		return mb_substr((str_starts_with($host, 'www.')) ? substr($host, 4) : $host, 0, 190);

	}

	/**
	 * deletes the hits older than the retention (globals smart-link.retention.months, 0 = never)
	 *
	 * @param int|null $months null = the globals
	 *
	 * @return int hits deleted
	 */
	public static function purge(?int $months=null):int
	{

		$months ??= self::retentionMonths();
		if($months <= 0)return 0;

		return (int)DB()->query("DELETE FROM xcore_smart_link_hit WHERE `date` < :limit", ['limit' => now("-{$months} months")])->rowCount();

	}

	/**
	 * first and last day of a period of the statistics
	 *
	 * @param string $period today, 7, 30 (days), 12 (months) or custom
	 * @param string $from Y-m-d, custom only
	 * @param string $to Y-m-d, custom only
	 *
	 * @return array [from, to] Y-m-d, to >= from, never before the retention nor after today
	 */
	public static function period(string $period, string $from='', string $to=''):array
	{

		$today = now('', false);
		$valid = fn(string $date):bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false;

		[$from, $to] = match($period)
		{

			'today' => [$today, $today],
			'7' => [now('-6 days', false), $today],
			'12' => [date('Y-m-01', strtotime('-11 months', strtotime(date('Y-m-01')))), $today],
			'custom' => [$valid($from) ? $from : $today, $valid($to) ? $to : $today],
			default => [now('-29 days', false), $today],

		};

		if($to > $today)$to = $today;
		if($from > $to)$from = $to;

		$months = self::retentionMonths();
		if($months > 0 && $from < ($limit = now("-{$months} months", false)))$from = min($limit, $to);

		return [$from, $to];

	}

	/**
	 * statistics of a smart link between two days (included)
	 * unique = visitors of each day added up (the visitor hash changes every day), currencies are never added together
	 *
	 * @param int $smart_link_id
	 * @param string $from Y-m-d
	 * @param string $to Y-m-d
	 *
	 * @return array {from, to, unit, hits, unique, countries, last_hit, trend: {hits, unique}, series: [{label, hits, unique}], source, referrer, country, device, browser, os: [{label, hits, unique, share}], currencies: [{currency, hits, total, average}], last: [...]}
	 */
	public static function stats(int $smart_link_id, string $from, string $to):array
	{

		$days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
		$unit = ($days <= 1) ? 'hour' : (($days <= 92) ? 'day' : 'month');
		$format = ['hour' => '%Y-%m-%d %H', 'day' => '%Y-%m-%d', 'month' => '%Y-%m'][$unit];

		$where = "xcore_smart_link_id = :id AND `date` >= :from AND `date` < :to";
		$binds = ['id' => $smart_link_id, 'from' => "{$from} 00:00:00", 'to' => date('Y-m-d 00:00:00', strtotime('+1 day', strtotime($to)))];

		$totals = DB()->query("SELECT COUNT(*) AS hits, COUNT(DISTINCT visitor) AS uniques, COUNT(DISTINCT NULLIF(country, '')) AS countries FROM xcore_smart_link_hit WHERE {$where}", $binds)->fetch();
		$hits = (int)$totals['hits'];

		// previous period of the same length, left out when it starts before the retention
		$previous_from = date('Y-m-d', strtotime("-{$days} days", strtotime($from)));
		$months = self::retentionMonths();
		$trend = ['hits' => null, 'unique' => null];

		if($months <= 0 || $previous_from >= now("-{$months} months", false))
		{

			$previous = DB()->query("SELECT COUNT(*) AS hits, COUNT(DISTINCT visitor) AS uniques FROM xcore_smart_link_hit WHERE xcore_smart_link_id = :id AND `date` >= :from AND `date` < :to", ['id' => $smart_link_id, 'from' => "{$previous_from} 00:00:00", 'to' => "{$from} 00:00:00"])->fetch();
			$percent = fn(int $now, int $before):?float => ($before > 0) ? round(($now - $before) * 100 / $before, 1) : null;
			$trend = ['hits' => $percent($hits, (int)$previous['hits']), 'unique' => $percent((int)$totals['uniques'], (int)$previous['uniques'])];

		}

		// series: every hour, day or month of the period, 0 when empty
		$counted = [];
		foreach(DB()->query("SELECT DATE_FORMAT(`date`, '{$format}') AS bucket, COUNT(*) AS hits, COUNT(DISTINCT visitor) AS uniques FROM xcore_smart_link_hit WHERE {$where} GROUP BY bucket", $binds)->fetchAll() as $rec)
			$counted[$rec['bucket']] = $rec;

		$series = [];
		foreach(self::buckets($unit, $from, $to) as $bucket)
			$series[] = ['label' => $bucket, 'hits' => (int)($counted[$bucket]['hits'] ?? 0), 'unique' => (int)($counted[$bucket]['uniques'] ?? 0)];

		$stats = [
			'from' => $from, 'to' => $to, 'unit' => $unit,
			'hits' => $hits, 'unique' => (int)$totals['uniques'], 'countries' => (int)$totals['countries'],
			'last_hit' => (string)DB()->query("SELECT MAX(`date`) FROM xcore_smart_link_hit WHERE xcore_smart_link_id = :id", ['id' => $smart_link_id])->fetchOne(),
			'trend' => $trend,
			'series' => $series,
		];

		foreach(self::BREAKDOWNS as $column)
		{

			$rows = [];
			foreach(DB()->query("SELECT `{$column}` AS label, COUNT(*) AS hits, COUNT(DISTINCT visitor) AS uniques FROM xcore_smart_link_hit WHERE {$where} GROUP BY `{$column}` ORDER BY hits DESC, label LIMIT 10", $binds)->fetchAll() as $rec)
				$rows[] = ['label' => (string)$rec['label'], 'hits' => (int)$rec['hits'], 'unique' => (int)$rec['uniques'], 'share' => ($hits > 0) ? round((int)$rec['hits'] * 100 / $hits, 1) : 0.0];

			$stats[$column] = $rows;

		}

		$stats['currencies'] = [];
		foreach(DB()->query("SELECT currency, COUNT(*) AS hits, SUM(amount) AS total, AVG(amount) AS average FROM xcore_smart_link_hit WHERE {$where} AND amount IS NOT NULL GROUP BY currency ORDER BY hits DESC, currency", $binds)->fetchAll() as $rec)
			$stats['currencies'][] = ['currency' => (string)$rec['currency'], 'hits' => (int)$rec['hits'], 'total' => round((float)$rec['total'], 2), 'average' => round((float)$rec['average'], 2)];

		$stats['last'] = [];
		foreach(DB()->query("SELECT `date`, source, referrer, country, device, browser, os, amount, currency FROM xcore_smart_link_hit WHERE {$where} ORDER BY `date` DESC, id DESC LIMIT 5", $binds)->fetchAll() as $rec)
		{

			$rec['amount'] = ($rec['amount'] === null) ? null : (float)$rec['amount'];
			$stats['last'][] = $rec;

		}

		return $stats;

	}

	/**
	 * labels of the series of a period, same format as the sql buckets
	 *
	 * @param string $unit hour, day or month
	 * @param string $from Y-m-d
	 * @param string $to Y-m-d
	 *
	 * @return array
	 */
	public static function buckets(string $unit, string $from, string $to):array
	{

		$buckets = [];

		if($unit === 'hour')
		{

			for($hour=0; $hour < 24; $hour++)
				$buckets[] = sprintf('%s %02d', $from, $hour);

			return $buckets;

		}

		$step = ($unit === 'month') ? '+1 month' : '+1 day';
		$format = ($unit === 'month') ? 'Y-m' : 'Y-m-d';
		$time = strtotime(($unit === 'month') ? date('Y-m-01', strtotime($from)) : $from);
		$end = strtotime($to);

		while($time <= $end && count($buckets) < 1000)
		{

			$buckets[] = date($format, $time);
			$time = strtotime($step, $time);

		}

		return $buckets;

	}

}
