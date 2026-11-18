<?php

// cron task "Statistics GeoIP": downloads the country database of the statistics (\Core\Stats::country), once a month
// DB-IP IP to Country Lite, mmdb, CC BY 4.0: the screens showing countries credit it with a link "IP Geolocation by DB-IP" (https://db-ip.com)
// the file is shared by every instance of the project: an instance that finds the file of the month does nothing
// parameters: url (other source of a gzipped mmdb), force=1 (downloads again)

$file = \Core\Stats::geoipFile();
$dir = dirname($file);

if(!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir))throw new \RuntimeException("folder {$dir} cannot be created");
if(!is_file("{$dir}/.htaccess"))file_put_contents("{$dir}/.htaccess", "Require all denied\n");

if(empty($params['force']) && is_file($file) && date('Y-m', (int)filemtime($file)) === date('Y-m'))
{

	echo "database of the month already there (".date('Y-m-d', (int)filemtime($file)).")".CR;
	return;

}

// release of the month, the previous one while it is not published yet
$urls = (!empty($params['url'])) ? [(string)$params['url']] : ['https://download.db-ip.com/free/dbip-country-lite-'.date('Y-m').'.mmdb.gz', 'https://download.db-ip.com/free/dbip-country-lite-'.date('Y-m', strtotime('first day of last month')).'.mmdb.gz'];

$archive = "{$file}.gz.tmp";
$target = "{$file}.tmp";
$context = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'user_agent' => 'Unee/1.0 (+'.\Core\Config::get('url').')']]);
$downloaded = '';

foreach($urls as $url)
{

	if(!preg_match('#^https://#i', $url))continue;

	$in = @fopen($url, 'rb', false, $context);
	if(!$in)continue;

	$out = fopen($archive, 'wb');
	$bytes = stream_copy_to_stream($in, $out);
	fclose($in);
	fclose($out);

	if($bytes > 100000)
	{

		$downloaded = $url;
		break;

	}

}

if($downloaded === '')
{

	@unlink($archive);
	throw new \RuntimeException('country database not downloaded: '.join(', ', $urls));

}

// gunzip by blocks: the file never goes whole in memory
$gz = gzopen($archive, 'rb');
$out = fopen($target, 'wb');
while($gz && !gzeof($gz))
	fwrite($out, (string)gzread($gz, 1048576));

if($gz)gzclose($gz);
fclose($out);
@unlink($archive);

// a file that cannot be read never replaces the current one
$size = (int)filesize($target);
$country = '';

try
{

	if($size < 1000000)throw new \RuntimeException("file too small ({$size} bytes)");

	if(class_exists(\MaxMind\Db\Reader::class))
	{

		$reader = new \MaxMind\Db\Reader($target);
		$record = $reader->get('8.8.8.8');
		$reader->close();
		$country = (string)($record['country']['iso_code'] ?? '');
		if($country === '')throw new \RuntimeException('no country found for the test ip');

	}

}
catch(\Throwable $e)
{

	@unlink($target);
	throw new \RuntimeException('country database refused: '.$e->getMessage());

}

if(!rename($target, $file))throw new \RuntimeException("file {$file} cannot be written");

echo "country database updated from {$downloaded} (".round($size / 1048576, 1)." MB)".CR;
if($country === '')echo "package maxmind-db/reader not installed: countries stay empty until it is".CR;
