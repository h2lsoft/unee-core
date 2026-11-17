<?php

namespace Core;

/**
 * coordinates of french addresses by the ign geoplateforme (config geocoder/url), used at the save of the address fields
 * the backend autocomplete calls the same service from the browser
 *
 * ex: \Core\Geocoder::locate('20 avenue de Ségur', '75007', 'Paris') gives ['lat' => 48.85, 'lng' => 2.30] or null
 */
class Geocoder
{

	// best result kept only above this score (0 to 1)
	public const float MIN_SCORE = 0.4;

	/**
	 * coordinates of an address, null when it is not found or the service does not answer
	 *
	 * @param string $street
	 * @param string $zip
	 * @param string $city
	 *
	 * @return array{lat: float, lng: float}|null
	 */
	public static function locate(string $street, string $zip, string $city):?array
	{

		$q = trim(preg_replace('/\s+/u', ' ', "{$street} {$zip} {$city}"));
		if(mb_strlen($q) < 3)return null;

		$url = self::url().'?'.http_build_query(['q' => mb_substr($q, 0, 200), 'index' => 'address', 'limit' => 1]);
		$timeout = max(1, (int)Config::get('geocoder/timeout', 5));

		$ch = curl_init($url);
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'Unee', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
		$response = curl_exec($ch);
		$code = ($response === false) ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

		if($code !== 200)
		{

			Log::write('geocoder', 'geocoding failed', ['code' => $code, 'error' => ($response === false) ? curl_error($ch) : ''], 0, 'warning');
			return null;

		}

		$feature = json_decode((string)$response, true)['features'][0] ?? null;
		if(!is_array($feature) || (float)($feature['properties']['score'] ?? 0) < self::MIN_SCORE)return null;

		[$lng, $lat] = array_pad((array)($feature['geometry']['coordinates'] ?? []), 2, null);
		if(!is_numeric($lat) || !is_numeric($lng))return null;

		return ['lat' => round((float)$lat, 7), 'lng' => round((float)$lng, 7)];

	}

	/**
	 * country handled by the geocoder: empty (default) or France
	 *
	 * @param string $country
	 *
	 * @return bool
	 */
	public static function france(string $country):bool
	{

		return in_array(mb_strtolower(trim($country)), ['', 'fr', 'fra', 'france'], true);

	}

	/**
	 * search url of the service, also given to the address fields of the backend
	 *
	 * @return string
	 */
	public static function url():string
	{

		return rtrim((string)Config::get('geocoder/url', 'https://data.geopf.fr/geocodage/search'), '/?');

	}

}
