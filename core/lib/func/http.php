<?php

/**
 * redirect with php header
 *
 * @param string $url
 * @param int    $code
 *
 * @return void
 */
function http_redirect(string $url, int $code=302):void
{
	if (!headers_sent())
	{
		header("Location: {$url}", true, $code);
		exit;
	}
	else
	{
		// fallback JS redirection: only http(s) or relative urls (browsers drop spaces/control chars, so check without them)
		$scheme = strtolower((string)parse_url(preg_replace('/[\x00-\x20]/', '', $url), PHP_URL_SCHEME));
		if($scheme !== '' && $scheme !== 'http' && $scheme !== 'https')
		{
			$url = '/';
		}

		echo '<script>window.location.href = '.json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES).';</script>';
		exit;
	}


	header("Status: 302 Moved Temporarily", false, 302);
	header("Location: {$url}");
	exit();
}

/**
 * get visitor IP
 * forwarded header (config proxy.ip_header) is read only when the request comes from a trusted proxy (config proxy.trusted)
 * @return string
 */
function getVisitorIp():string
{
	if(APP_CLI_MODE)return "127.0.0.1";

	$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
	$proxy = $GLOBALS['config']['proxy'] ?? [];
	$trusted = $proxy['trusted'] ?? [];

	if(!isTrustedProxy($remote_ip, $trusted))return $remote_ip;

	$server_key = 'HTTP_'.strtoupper(str_replace('-', '_', $proxy['ip_header'] ?? ''));
	$chain = array_map('trim', explode(',', $_SERVER[$server_key] ?? ''));

	// walk from the closest hop: the first address that is not a trusted proxy is the visitor (left entries may be forged)
	for($i=count($chain) - 1; $i >= 0; $i--)
	{
		if(!filter_var($chain[$i], FILTER_VALIDATE_IP))return $remote_ip;
		if(!isTrustedProxy($chain[$i], $trusted))return $chain[$i];
	}

	return $remote_ip;
}

/**
 * check if an ip belongs to one of the ranges (ip or cidr, ipv4 and ipv6)
 * @param string $ip
 * @param array  $ranges ex: ['173.245.48.0/20', '2400:cb00::/32', '10.0.0.1']
 * @return bool
 */
function isTrustedProxy(string $ip, array $ranges):bool
{
	$ip_bin = @inet_pton($ip);
	if($ip_bin === false)return false;

	foreach($ranges as $range)
	{
		[$subnet, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
		$subnet_bin = @inet_pton($subnet);
		if($subnet_bin === false || strlen($subnet_bin) !== strlen($ip_bin))continue;

		$max_bits = strlen($ip_bin) * 8;
		if($bits === null)$bits = (string)$max_bits;
		if(!ctype_digit($bits) || (int)$bits > $max_bits)continue;

		$bits = (int)$bits;
		$bytes = intdiv($bits, 8);
		if(substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes))continue;

		$rest = $bits % 8;
		if($rest === 0)return true;

		$mask = chr((0xFF << (8 - $rest)) & 0xFF);
		if(($ip_bin[$bytes] & $mask) === ($subnet_bin[$bytes] & $mask))return true;
	}

	return false;
}

/**
 * return query parameter
 *
 * @param string $key
 * @param mixed  $default
 * @param array  $list force value in list
 *
 * @return mixed
 */
function get(string $key, mixed $default='', array $list=[]):mixed
{
	$v = (isset($_GET[$key])) ? $_GET[$key] : $default;

	if(count($list) && !in_array($v, $list))
		$v = $list[0];

	return $v;
}

/**
 * return post parameter
 *
 * @param string $key
 * @param mixed  $default
 * @param array  $list force value in list
 *
 * @return mixed
 */
function post(string $key, mixed $default='', array $list=[]):mixed
{
	$v = (isset($_POST[$key])) ? $_POST[$key] : $default;
	if(count($list) && !in_array($v, $list))
		$v = $list[0];
	return $v;
}

/**
 * return query parameter validated against a regex pattern
 * unlike get(), an invalid value returns $default and never the raw user input
 *
 * @param string $key
 * @param string $pattern regex applied to the value
 * @param mixed  $default returned when the parameter is missing or invalid
 *
 * @return mixed
 */
function get_pattern(string $key, string $pattern, mixed $default=''):mixed
{
	if(!isset($_GET[$key]) || !is_string($_GET[$key]))
		return $default;

	return (preg_match($pattern, $_GET[$key])) ? $_GET[$key] : $default;
}

/**
 * return query parameter restricted to an allowed list
 * unlike get($key, $default, $list), an invalid value returns $default and not $list[0]
 *
 * @param string $key
 * @param array  $list allowed values
 * @param mixed  $default returned when the parameter is missing or not allowed
 *
 * @return mixed
 */
function get_in(string $key, array $list, mixed $default=''):mixed
{
	if(!isset($_GET[$key]) || !is_string($_GET[$key]))
		return $default;

	return (in_array($_GET[$key], $list, true)) ? $_GET[$key] : $default;
}

/**
 * replace in url get parameters
 *
 * @param array  $params
 * @param string $url
 *
 * @return string
 */
function http_query_replace(array $params, string $url):string
{
	$new_url = parse_url($url);
	if(!isset($new_url['query']))$new_url['query'] = "";
	parse_str($new_url['query'], $q);

	foreach($params as $k => $v)
	{
		if(is_null($v))
			unset($q[$k]);
		else
			$q[$k] = $v;
	}

	$new_url = $new_url['path'] . '?' . http_build_query($q);

	for($i=0; $i<=20; $i++)
		$new_url = str_replace("%5B{$i}%5D", '[]', $new_url);
	$new_url = str_replace('%7C', '|', $new_url);

	return $new_url;
}

/**
 * get current request GET, POST, PUT, DELETE
 * @param string $type
 *
 * @return bool
 */
function requestIs(string $type):bool
{
	$type = strtoupper($type);
	return (App()->request->getMethod() == $type);
}
function requestIsGet():bool{return requestIs('get');}
function requestIsPost():bool{return requestIs('post');}
function requestIsPut():bool{return requestIs('put');}
function requestIsDelete():bool{return requestIs('delete');}

/**
 * return current mail provider for a domain (office365, gmail, orange, yahoo)
 * @param $domain
 * @return string
 */
function dns_mx_get_provider($domain):string
{
	$provider = '';


	$mx_records = @dns_get_record($domain, DNS_MX);
	if(!$mx_records)return $provider;

	foreach($mx_records as $record)
	{
		if(strpos($record['target'], 'outlook.com') !== false)
			return 'office365';

		if(strpos($record['target'], 'google.com') !== false || strpos($record['target'], 'googlemail.com') !== false)
			return 'google';

		if(strpos($record['target'], 'orange.') !== false || strpos($record['target'], 'wanadoo.fr') !== false)
			return 'orange';

		if(strpos($record['target'], 'yahoo.') !== false)
			return 'yahoo';
	}

	return $provider;
}


