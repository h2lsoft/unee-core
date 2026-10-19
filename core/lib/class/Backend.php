<?php

namespace Core;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * react backend (core/backend/dist, built with a relative base) served under /<backend.dirname>/
 * the hashed files of assets/ are served by apache (skeleton .htaccess), this class gives them when apache does not
 */
class Backend
{

	private const string DIST = CORE_PATH.'/core/backend/dist';

	/**
	 * folder of the backend in the urls (config backend.dirname), ex: backend
	 *
	 * @return string
	 */
	public static function dirname():string
	{

		return trim((string)Config::get('backend.dirname', 'backend'), '/');

	}

	/**
	 * true when the url is a page or a file of the backend
	 *
	 * @param string $uri path of the request, ex: /backend/user/
	 *
	 * @return bool
	 */
	public static function matches(string $uri):bool
	{

		return (bool)preg_match('#^/'.preg_quote(self::dirname(), '#').'/#i', $uri);

	}

	/**
	 * file of the build (logos, favicon, assets) or index.html of the react app with the url of the backend in it
	 *
	 * @param string $uri path of the request
	 *
	 * @return Response
	 */
	public static function response(string $uri):Response
	{

		$rest = substr($uri, strlen(self::dirname()) + 2);

		// a file of the build: root files (favicon, logos) and assets/<hashed name>, never a path out of dist
		if(preg_match('#^(assets/)?[a-z0-9._-]+\.(png|svg|ico|js|css|woff2?|ttf|webmanifest)$#i', $rest) && is_file(self::DIST."/{$rest}"))
		{

			$response = new BinaryFileResponse(self::DIST."/{$rest}");
			$response->headers->set('Content-Type', match(strtolower(pathinfo($rest, PATHINFO_EXTENSION)))
			{
				'svg' => 'image/svg+xml',
				'js' => 'text/javascript',
				'css' => 'text/css',
				'png' => 'image/png',
				'ico' => 'image/x-icon',
				'woff2' => 'font/woff2',
				'woff' => 'font/woff',
				'ttf' => 'font/ttf',
				default => 'application/octet-stream',
			});
			$response->headers->set('Cache-Control', str_starts_with($rest, 'assets/') ? 'max-age=31536000, public, immutable' : 'max-age=86400, public');

			return $response;

		}

		// any other url: the react app (client routing), never cached so a new build is seen at once
		$base = '/'.self::dirname().'/';
		$html = (string)@file_get_contents(self::DIST.'/index.html');
		if($html === '')return new Response('backend not built: run npm run build in backend/', 500, ['Content-Type' => 'text/plain']);

		$html = str_replace(['="./', "='./"], ['="'.$base, "='".$base], $html);
		$html = str_replace('<head>', '<head>'.PHP_EOL.'		<meta name="unee-base" content="'.htmlspecialchars($base).'" />', $html);

		return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache, must-revalidate']);

	}

}
