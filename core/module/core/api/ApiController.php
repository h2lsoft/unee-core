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
		$backend = htmlspecialchars(\Core\Backend::dirname(), ENT_QUOTES);
		$icons = $this->tagIcons($backend);

		// swagger-ui-dist pinned version from the npm cdn (the page is for the superadmin only)
		$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$title}</title>
<link rel="icon" type="image/png" href="/{$backend}/favicon-32.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css">
<style>
body{margin:0}
.swagger-ui .topbar{display:none}
.swagger-ui .filter-container .operation-filter-input{width:100%;max-width:none;margin:0;padding:9px 12px;border:1px solid #d6d9e0;border-radius:6px;font-size:14px}
.swagger-ui .opblock .opblock-summary-path-description-wrapper{flex-direction:column;align-items:flex-start;gap:2px 0;padding:4px 10px}
.swagger-ui .opblock .opblock-summary-description{font-size:12px;color:#5b6272}
{$icons}
</style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
<script>
// live search on the tag, the method, the path and the description of the routes (the default filter only reads the tags)
const RouteSearch = () => ({
	fn: {
		opsFilter: (tagged, phrase) => {
			const q = String(phrase || '').trim().toLowerCase();
			if(!q)return tagged;
			return tagged
				.map((tag_ops, tag) => tag_ops.set('operations', tag_ops.get('operations').filter((op) => [tag, op.get('method'), op.get('path'), op.getIn(['operation', 'summary']) || '', op.getIn(['operation', 'description']) || ''].join(' ').toLowerCase().includes(q))))
				.filter((tag_ops) => tag_ops.get('operations').size > 0);
		},
	},
});

SwaggerUIBundle({
	url: '/api/openapi.json',
	dom_id: '#swagger-ui',
	deepLinking: true,
	persistAuthorization: false,
	// tags folded at the opening, a click opens one
	docExpansion: 'none',
	filter: true,
	plugins: [RouteSearch],
	// the search box is drawn after the spec: its placeholder is replaced once it exists
	onComplete: () => {
		let tries = 0;
		const relabel = () => {
			const input = document.querySelector('.operation-filter-input');
			if(input){ input.placeholder = 'Search a route: path, method, tag or description'; input.focus(); return; }
			if(++tries < 50)setTimeout(relabel, 100);
		};
		relabel();
	},
	// csrf header of the api, the session cookie is sent by the browser (same origin)
	requestInterceptor: (request) => { request.headers['X-Requested-With'] = 'XMLHttpRequest'; return request; },
});
</script>
</body>
</html>
HTML;

		return new \Symfony\Component\HttpFoundation\Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex']);

	}

	/**
	 * css of the icon of the module before each tag (tag = route name of the module, dashes as underscores, api for the routes without module), font Material Symbols of the backend build
	 *
	 * @param string $backend dirname of the backend
	 *
	 * @return string
	 */
	private function tagIcons(string $backend):string
	{

		$font = glob(dirname(__DIR__, 3).'/theme/core-admin/dist/assets/material-symbols-outlined-*.woff2')[0] ?? '';
		if($font === '')return '';

		$css = "@font-face{font-family:'Material Symbols Outlined';font-style:normal;font-weight:400;font-display:block;src:url(/{$backend}/assets/".basename($font).") format('woff2')}";
		$css .= ".swagger-ui .opblock-tag::before{content:\"api\";font-family:'Material Symbols Outlined';font-size:22px;font-weight:400;line-height:1;margin-right:10px;color:#5b6272;font-feature-settings:'liga'}";
		foreach(DB()->query("SELECT route_prefix_name, icon FROM xcore_plugin WHERE deleted = 'no' AND route_prefix_name != '' AND icon != ''")->fetchAll(\PDO::FETCH_KEY_PAIR) as $route => $icon)
			if(preg_match('/^[a-z0-9-]+$/', (string)$route) && preg_match('/^[a-z0-9_]+$/', (string)$icon))$css .= '.swagger-ui .opblock-tag[data-tag="'.str_replace('-', '_', $route).'"]::before{content:"'.$icon.'"}';

		return $css;

	}

}
