<?php

namespace Core;

/**
 * weather forecast of the dashboard widget (DashboardController::widgetWeather): MET Norway Locationforecast 2.0 compact, CC BY 4.0
 * place: city and country of the settings of the placed widget, else the global widget.weather.city (Paris, France), found by locate()
 * terms of api.met.no: identifying user agent, coordinates with 4 decimals at most, responses cached until their Expires header (at least 30 minutes), If-Modified-Since
 * cache: private_dir/tmp/weather/<lat>_<lon>.json, served stale when the api fails; places found in private_dir/tmp/weather/places.json
 */
class Weather
{

	public const string API = 'https://api.met.no/weatherapi/locationforecast/2.0/compact';
	public const string CREDIT = 'MET Norway';
	public const string CREDIT_URL = 'https://api.met.no/doc/License';
	private const int MIN_CACHE = 1800;
	private const int TIMEOUT = 6;
	private const int DAYS = 4;

	// last network error (geocoding or forecast), shown by the widget: ex SSL certificate problem (curl.cainfo missing in php.ini)
	public static string $error = '';

	// countries of the setting country (ISO 3166-1 alpha-2 => english name, translated)
	public const array COUNTRIES = [
		'FR' => 'France',
		'BE' => 'Belgium',
		'CH' => 'Switzerland',
		'LU' => 'Luxembourg',
		'MC' => 'Monaco',
		'DE' => 'Germany',
		'ES' => 'Spain',
		'IT' => 'Italy',
		'PT' => 'Portugal',
		'NL' => 'Netherlands',
		'GB' => 'United Kingdom',
		'IE' => 'Ireland',
		'AT' => 'Austria',
		'PL' => 'Poland',
		'GR' => 'Greece',
		'CA' => 'Canada',
		'US' => 'United States',
		'MX' => 'Mexico',
		'BR' => 'Brazil',
		'MA' => 'Morocco',
		'DZ' => 'Algeria',
		'TN' => 'Tunisia',
		'SN' => 'Senegal',
		'CI' => 'Ivory Coast',
		'IL' => 'Israel',
		'AE' => 'United Arab Emirates',
		'TR' => 'Turkey',
		'JP' => 'Japan',
		'CN' => 'China',
		'AU' => 'Australia',
	];

	// symbol_code of MET Norway without _day / _night / _polartwilight => [label, material symbol of the day, of the night]
	private const array SYMBOLS = [
		'clearsky' => ['Clear sky', 'clear_day', 'clear_night'],
		'fair' => ['Fair', 'partly_cloudy_day', 'partly_cloudy_night'],
		'partlycloudy' => ['Partly cloudy', 'partly_cloudy_day', 'partly_cloudy_night'],
		'cloudy' => ['Cloudy', 'cloud', 'cloud'],
		'fog' => ['Fog', 'foggy', 'foggy'],
	];

	/**
	 * data of the widget: {"type": "weather", "city", "country" (name), "now": {"temp", "icon", "label"}, "min", "max", "wind" (km/h), "rain" (mm next hour), "days": [{"day" (Y-m-d), "min", "max", "icon", "label"}], "credit": {"text", "url"}}
	 *
	 * @param array $config settings of the placed widget: city (empty = globals), country (ISO code, default FR)
	 *
	 * @return array type error when the forecast cannot be read
	 */
	public static function widget(array $config=[]):array
	{

		$city = trim((string)($config['city'] ?? ''));
		if($city === '')$city = trim((string)Globals::get('widget.weather.city', 'Paris', true, 'text', 'city of the weather widget by default (France)'));

		$country = strtoupper(trim((string)($config['country'] ?? ''))) ?: 'FR';
		self::$error = '';
		$place = self::locate($city, $country);
		if(!$place && self::$error !== '')return ['type' => 'error', 'message' => ts('geocoding service unreachable: [ERROR]', ['ERROR' => self::$error])];
		if(!$place)return ['type' => 'error', 'message' => ($country === 'FR') ? ts('city `[CITY]` not found', ['CITY' => $city]) : ts('city `[CITY]` not found in [COUNTRY]', ['CITY' => $city, 'COUNTRY' => $country])];

		[$lat, $lon, $city] = [$place['lat'], $place['lon'], $place['name']];

		$series = self::forecast($lat, $lon);
		if(!$series)return ['type' => 'error', 'message' => (self::$error !== '') ? ts('weather service unreachable: [ERROR]', ['ERROR' => self::$error]) : ts('weather forecast unavailable')];

		// first step not in the past: current values
		$now = time();
		$current = $series[0];
		foreach($series as $step)
		{

			if(strtotime($step['time']) > $now - 3600)
			{

				$current = $step;
				break;

			}

		}

		$details = $current['data']['instant']['details'] ?? [];
		$symbol = (string)($current['data']['next_1_hours']['summary']['symbol_code'] ?? $current['data']['next_6_hours']['summary']['symbol_code'] ?? '');

		// days in the time zone of the server: min / max of the temperatures, symbol of the step nearest to 12:00
		$days = [];
		foreach($series as $step)
		{

			$time = strtotime($step['time']);
			if($time < $now - 3600)continue;

			$day = date('Y-m-d', $time);
			$temp = $step['data']['instant']['details']['air_temperature'] ?? null;
			if(!is_numeric($temp))continue;

			$days[$day] ??= ['day' => $day, 'min' => (float)$temp, 'max' => (float)$temp, 'symbol' => '', 'gap' => 99];
			$days[$day]['min'] = min($days[$day]['min'], (float)$temp);
			$days[$day]['max'] = max($days[$day]['max'], (float)$temp);

			$code = (string)($step['data']['next_6_hours']['summary']['symbol_code'] ?? $step['data']['next_1_hours']['summary']['symbol_code'] ?? '');
			$gap = abs((int)date('G', $time) - 12);
			if($code !== '' && $gap < $days[$day]['gap'])
			{

				$days[$day]['symbol'] = $code;
				$days[$day]['gap'] = $gap;

			}

		}

		$today = $days[date('Y-m-d', $now)] ?? reset($days) ?: ['min' => null, 'max' => null];
		$next = [];
		foreach($days as $day => $d)
		{

			if($day <= date('Y-m-d', $now))continue;
			// icon of the day for the next days
			[$label, $icon] = self::symbol(preg_replace('/_night$/', '_day', $d['symbol']));
			$next[] = ['day' => $day, 'min' => self::round($d['min']), 'max' => self::round($d['max']), 'icon' => $icon, 'label' => $label];
			if(count($next) >= self::DAYS)break;

		}

		[$label, $icon] = self::symbol($symbol);

		return [
			'type' => 'weather',
			'city' => $city,
			'country' => isset(self::COUNTRIES[$country]) ? ts(self::COUNTRIES[$country]) : $country,
			'now' => ['temp' => self::round($details['air_temperature'] ?? null), 'icon' => $icon, 'label' => $label],
			'min' => self::round($today['min']),
			'max' => self::round($today['max']),
			'wind' => is_numeric($details['wind_speed'] ?? null) ? (int)round((float)$details['wind_speed'] * 3.6) : null,
			'rain' => is_numeric($current['data']['next_1_hours']['details']['precipitation_amount'] ?? null) ? (float)$current['data']['next_1_hours']['details']['precipitation_amount'] : null,
			'days' => $next,
			// openstreetmap credited when it found the city (ODbL)
			'credit' => ['text' => self::CREDIT.((($place['source'] ?? '') === 'osm') ? ', © OpenStreetMap' : ''), 'url' => self::CREDIT_URL],
		];

	}

	/**
	 * coordinates of a city, kept in the cache: France by the geocoder of the config (IGN, municipalities, ex: Saint-Denis 93),
	 * other countries by Nominatim of OpenStreetMap (config weather/nominatim_url, usage policy: identifying user agent, 1 request per second at most, results cached)
	 *
	 * @param string $city ex: Lyon, Bruxelles
	 * @param string $country ISO 3166-1 alpha-2, ex: FR, BE
	 *
	 * @return array|null {"lat", "lon", "name", "source" (ign or osm)}
	 */
	public static function locate(string $city, string $country='FR'):?array
	{

		$city = trim(preg_replace('/\s+/u', ' ', $city));
		$country = strtoupper(trim($country)) ?: 'FR';
		if(mb_strlen($city) < 2 || mb_strlen($city) > 100 || !preg_match('/^[A-Z]{2}$/', $country))return null;

		$file = File::privateDir('tmp/weather').'/places.json';
		$places = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
		if(!is_array($places))$places = [];

		$key = $country.'|'.mb_strtolower($city);
		if(isset($places[$key]['lat'], $places[$key]['lon']))return $places[$key];

		$place = ($country === 'FR') ? self::locateIgn($city) : self::locateOsm($city, $country);
		if(!$place)return null;

		$places[$key] = $place;
		self::write($file, array_slice($places, -500, null, true));

		return $place;

	}

	// municipality of France (IGN Geoplateforme)
	private static function locateIgn(string $city):?array
	{

		$response = self::get(Geocoder::url().'?'.http_build_query(['q' => $city, 'index' => 'address', 'type' => 'municipality', 'limit' => 1]));
		$feature = json_decode((string)$response, true)['features'][0] ?? null;
		[$lon, $lat] = array_pad((array)($feature['geometry']['coordinates'] ?? []), 2, null);
		if(!is_numeric($lat) || !is_numeric($lon) || (float)($feature['properties']['score'] ?? 0) < 0.5)return null;

		return ['lat' => round((float)$lat, 4), 'lon' => round((float)$lon, 4), 'name' => (string)($feature['properties']['name'] ?? $city), 'source' => 'ign'];

	}

	// city of another country (Nominatim, OpenStreetMap)
	private static function locateOsm(string $city, string $country):?array
	{

		$url = (string)Config::get('weather/nominatim_url', 'https://nominatim.openstreetmap.org/search');
		$response = self::get($url.'?'.http_build_query(['city' => $city, 'countrycodes' => strtolower($country), 'format' => 'jsonv2', 'limit' => 1]));
		$place = json_decode((string)$response, true)[0] ?? null;
		if(!is_array($place) || !is_numeric($place['lat'] ?? null) || !is_numeric($place['lon'] ?? null))return null;

		return ['lat' => round((float)$place['lat'], 4), 'lon' => round((float)$place['lon'], 4), 'name' => (string)($place['name'] ?? $city), 'source' => 'osm'];

	}

	// body of a json GET answered 200, null otherwise (logged)
	private static function get(string $url):?string
	{

		$timeout = max(1, (int)Config::get('geocoder/timeout', 5));
		$ch = curl_init($url);
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => self::userAgent(), CURLOPT_HTTPHEADER => ['Accept: application/json']] + self::nativeCa());
		$body = curl_exec($ch);
		$code = ($body === false) ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if($code === 200)return (string)$body;

		self::$error = ($body === false) ? curl_error($ch) : "HTTP {$code}";
		Log::write('weather', 'city geocoding failed', ['url' => preg_replace('/\?.*/', '', $url), 'code' => $code, 'error' => self::$error], 0, 'warning');
		return null;

	}

	/**
	 * timeseries of the place, from the cache while it is valid
	 *
	 * @param float $lat
	 * @param float $lon
	 *
	 * @return array [] when unavailable
	 */
	public static function forecast(float $lat, float $lon):array
	{

		// 4 decimals at most (terms of api.met.no)
		$lat = round($lat, 4);
		$lon = round($lon, 4);

		$file = File::privateDir('tmp/weather').'/'.str_replace(['-', '.'], ['m', 'd'], "{$lat}_{$lon}").'.json';
		$cache = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
		if(!is_array($cache))$cache = null;

		if($cache && (int)($cache['expires'] ?? 0) > time())return (array)($cache['series'] ?? []);

		$headers = ['Accept: application/json'];
		if(!empty($cache['last_modified']))$headers[] = 'If-Modified-Since: '.$cache['last_modified'];

		$response_headers = [];
		$ch = curl_init(self::API.'?'.http_build_query(['lat' => $lat, 'lon' => $lon]));
		curl_setopt_array($ch, self::nativeCa() + [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
			CURLOPT_TIMEOUT => self::TIMEOUT,
			CURLOPT_ENCODING => '',
			CURLOPT_USERAGENT => self::userAgent(),
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_HEADERFUNCTION => function($ch, string $line) use (&$response_headers):int {

				$parts = explode(':', $line, 2);
				if(count($parts) === 2)$response_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
				return strlen($line);

			},
		]);
		$body = curl_exec($ch);
		$code = ($body === false) ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

		$expires = max(time() + self::MIN_CACHE, (int)strtotime($response_headers['expires'] ?? ''));

		// not modified: same forecast, new expiry
		if($code === 304 && $cache)
		{

			$cache['expires'] = $expires;
			self::write($file, $cache);
			return (array)($cache['series'] ?? []);

		}

		$series = ($code === 200) ? (json_decode((string)$body, true)['properties']['timeseries'] ?? null) : null;
		if(!is_array($series) || !$series)
		{

			self::$error = ($body === false) ? curl_error($ch) : "HTTP {$code}";
			Log::write('weather', 'forecast failed', ['code' => $code, 'error' => self::$error], 0, 'warning');

			// stale cache rather than nothing, retried in 10 minutes
			if($cache)
			{

				$cache['expires'] = time() + 600;
				self::write($file, $cache);
				return (array)($cache['series'] ?? []);

			}

			return [];

		}

		self::write($file, ['expires' => $expires, 'last_modified' => $response_headers['last-modified'] ?? '', 'series' => $series]);

		return $series;

	}

	/**
	 * certificates of the system store too (windows: the store of the browsers, ex: root of an antivirus that scans https), ssl still verified; no effect on linux
	 *
	 * @return array curl options
	 */
	public static function nativeCa():array
	{

		return defined('CURLSSLOPT_NATIVE_CA') ? [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA] : [];

	}

	/**
	 * identifying user agent required by api.met.no: application and site of the instance
	 *
	 * @return string
	 */
	private static function userAgent():string
	{

		$url = (string)Config::get('url', '');
		return 'Unee/1.0'.(preg_match('#^https?://[a-z0-9.-]+#i', $url, $m) ? " (+{$m[0]})" : '');

	}

	/**
	 * label (translated) and material symbol of a MET Norway symbol code
	 *
	 * @param string $code ex: lightrainshowers_day
	 *
	 * @return array [label, icon]
	 */
	private static function symbol(string $code):array
	{

		$night = str_ends_with($code, '_night');
		$base = preg_replace('/_(day|night|polartwilight)$/', '', $code);

		if(isset(self::SYMBOLS[$base]))
		{

			[$label, $day_icon, $night_icon] = self::SYMBOLS[$base];
			return [ts($label), $night ? $night_icon : $day_icon];

		}

		$thunder = str_contains($base, 'thunder');
		return match(true)
		{
			$base === '' => ['', 'thermostat'],
			str_contains($base, 'snow') => [ts($thunder ? 'Snow and thunder' : (str_starts_with($base, 'heavy') ? 'Heavy snow' : (str_starts_with($base, 'light') ? 'Light snow' : 'Snow'))), $thunder ? 'thunderstorm' : 'weather_snowy'],
			str_contains($base, 'sleet') => [ts($thunder ? 'Sleet and thunder' : 'Sleet'), $thunder ? 'thunderstorm' : 'weather_mix'],
			str_contains($base, 'rain') => [ts($thunder ? 'Rain and thunder' : (str_starts_with($base, 'heavy') ? 'Heavy rain' : (str_starts_with($base, 'light') ? 'Light rain' : 'Rain'))), $thunder ? 'thunderstorm' : (str_starts_with($base, 'heavy') ? 'rainy_heavy' : (str_starts_with($base, 'light') ? 'rainy_light' : 'rainy'))],
			default => [ts('Unknown'), 'thermostat'],
		};

	}

	// temperature rounded to the degree, null when missing
	private static function round(mixed $value):?int
	{

		return is_numeric($value) ? (int)round((float)$value) : null;

	}

	// cache written atomically (temp file then rename)
	private static function write(string $file, array $data):void
	{

		$tmp = $file.'.'.bin2hex(random_bytes(4));
		if(@file_put_contents($tmp, json_encode($data)) !== false)@rename($tmp, $file);

	}

}
