<?php

namespace Plugin\Core;

use Symfony\Component\Routing\Attribute\Route;

/**
 * documentation of the api (OpenAPI, Swagger UI), superadmin only
 */
#[Route('/api', name: 'api.')]
class ApiController extends \Core\Controller
{

	/**
	 * OpenAPI 3.1 document of the api
	 *
	 * @return \Core\JsonResponse
	 */
	#[Route('/openapi.json', name: 'openapi', methods: ['GET'])]
	public function openapi():\Core\JsonResponse
	{

		global $routes;
		if(!\Model\User::isSuperAdmin())return \Core\JsonResponse::error(403, 'access denied');

		$response = new \Core\JsonResponse(\Core\OpenApi::build($routes));
		$response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

		return $response;

	}

	/**
	 * Swagger UI page of the api, "try it out" uses the session of the backend
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	#[Route('/docs/', name: 'docs', methods: ['GET'])]
	public function docs():\Symfony\Component\HttpFoundation\Response|\Core\JsonResponse
	{

		if(!\Model\User::isSuperAdmin())return \Core\JsonResponse::error(403, 'access denied');

		$title = htmlspecialchars(\Core\Config::get('name').' API', ENT_QUOTES);

		// swagger-ui-dist pinned version from the npm cdn (the page is for the superadmin only)
		$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$title}</title>
<link rel="icon" type="image/png" href="/backend/favicon-32.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css">
<style>body{margin:0} .swagger-ui .topbar{display:none}</style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
<script>
SwaggerUIBundle({
	url: '/api/openapi.json',
	dom_id: '#swagger-ui',
	deepLinking: true,
	persistAuthorization: false,
	// csrf header of the api, the session cookie is sent by the browser (same origin)
	requestInterceptor: (request) => { request.headers['X-Requested-With'] = 'XMLHttpRequest'; return request; },
});
</script>
</body>
</html>
HTML;

		return new \Symfony\Component\HttpFoundation\Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex']);

	}

}
