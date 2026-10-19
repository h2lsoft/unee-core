<?php

/**
 * relative and readable date (past or future), ex: just now, 5 minutes ago, yesterday at 14:30, in 3 days, 2 years ago (12 Mar 2024)
 * port of the summio date_humanizer, intl kept out (strftime is deprecated)
 *
 * @param string|null $date any format of strtotime
 * @param string $locale en or fr
 * @param string $format time added to yesterday / tomorrow, ex: H:i
 * @param string $now reference date
 *
 * @return string the date as given when it is invalid
 */
function date_humanizer(?string $date, string $locale='en', string $format='', string $now='now'):string
{

	$date = (string)$date;
	$time = strtotime($date);
	if($date === '' || $time === false)return $date;

	$minute = 60;
	$hour = 3600;
	$day = 86400;
	$week = 604800;
	$month = 2628000;
	$year = 31536000;

	$fr = ($locale === 'fr');
	$t = ($fr)
		? ['just_now' => "à l'instant", 'ago' => 'il y a %s', 'in' => 'dans %s', 'minute' => 'minute', 'hour' => 'heure', 'day' => 'jour', 'week' => 'semaine', 'month' => 'mois', 'year' => 'an', 'yesterday' => 'hier', 'tomorrow' => 'demain', 'at' => 'à']
		: ['just_now' => 'just now', 'ago' => '%s ago', 'in' => 'in %s', 'minute' => 'minute', 'hour' => 'hour', 'day' => 'day', 'week' => 'week', 'month' => 'month', 'year' => 'year', 'yesterday' => 'yesterday', 'tomorrow' => 'tomorrow', 'at' => 'at'];

	$ref = strtotime($now);
	$diff = $time - $ref;
	$abs = abs($diff);

	// "3 days": no plural s on mois
	$count = function(float $value, string $unit) use ($t, $fr):string
	{

		$n = (int)floor($value);
		$word = $t[$unit];
		if($n > 1 && !($fr && $unit === 'month'))$word .= 's';

		return "{$n} {$word}";

	};

	if($abs < $minute)return $t['just_now'];

	$side = ($diff > 0) ? 'in' : 'ago';
	$neighbour = date('Y-m-d', $ref + (($diff > 0) ? $day : -$day));
	if($abs >= $hour * 6 && $abs < $day * 2 && date('Y-m-d', $time) === $neighbour)
		return $t[($diff > 0) ? 'tomorrow' : 'yesterday'].(($format !== '') ? " {$t['at']} ".date($format, $time) : '');

	$text = match(true)
	{
		$abs < $hour => $count($abs / $minute, 'minute'),
		$abs < $day => $count($abs / $hour, 'hour'),
		$abs < $week => $count($abs / $day, 'day'),
		$abs < $month => $count($abs / $week, 'week'),
		$abs < $year => $count($abs / $month, 'month'),
		default => $count($abs / $year, 'year'),
	};

	$result = sprintf($t[$side], $text);
	if($abs >= $year)$result .= ' ('.date('j M Y', $time).')';

	return $result;

}
