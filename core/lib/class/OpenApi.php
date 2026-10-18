<?php

namespace Core;

use Symfony\Component\Routing\RouteCollection;

/**
 * OpenAPI 3.1 document of the /api/ routes, built from the routes, the docblocks of the methods and the controllers
 * summary = first line of the docblock, description = the next lines (before the @ tags)
 * a controller can add details with a public apiDoc(string $method): array (parameters, requestBody, response, status, path), ex: \Core\CrudController
 */
class OpenApi
{

	/**
	 * openapi document
	 *
	 * @param RouteCollection $routes all the routes of the application
	 *
	 * @return array
	 */
	public static function build(RouteCollection $routes):array
	{

		$public = (array)\Core\Config::get('auth/api/public_routes', []);
		$paths = [];
		$controllers = [];

		foreach($routes->all() as $name => $route)
		{

			$path = $route->getPath();
			$callback = $route->getDefault('_controller');
			if(!str_starts_with($path, '/api/') || !is_string($callback) || !str_contains($callback, '::'))continue;
			if(in_array($name, ['api.openapi', 'api.docs'], true))continue;

			[$class, $method] = explode('::', $callback, 2);
			if(!method_exists($class, $method))continue;

			$reflection = new \ReflectionMethod($class, $method);
			[$summary, $description] = self::docblock((string)$reflection->getDocComment());

			// details of the controller (one instance per class)
			$extra = [];
			if(method_exists($class, 'apiDoc'))
			{

				$controllers[$class] ??= new $class();
				$extra = $controllers[$class]->apiDoc($method);

			}

			// route without use for this controller (ex: no batch action declared)
			if(!empty($extra['skip']))continue;

			$parameters = [];
			preg_match_all('/\{(\w+)\}/', $path, $placeholders);
			foreach($placeholders[1] as $placeholder)
			{

				$schema = $extra['path'][$placeholder] ?? (($route->getRequirement($placeholder) === '\d+') ? ['type' => 'integer'] : ['type' => 'string']);
				$parameters[] = ['name' => $placeholder, 'in' => 'path', 'required' => true, 'schema' => $schema];

			}
			$parameters = array_merge($parameters, $extra['parameters'] ?? []);

			$status = (string)($extra['status'] ?? 200);
			$responses = [$status => ['description' => ($status === '204') ? 'no content' : 'success']];
			if(isset($extra['response']))$responses[$status]['content'] = ['application/json' => ['schema' => $extra['response']]];

			$is_public = in_array($name, $public, true);
			if(!$is_public)
			{

				$responses['401'] = ['$ref' => '#/components/responses/Error'];
				$responses['403'] = ['$ref' => '#/components/responses/Error'];

			}
			if(isset($extra['requestBody']))$responses['422'] = ['$ref' => '#/components/responses/Error'];

			$operation = [
				'operationId' => $name,
				'tags' => [explode('.', $name)[1] ?? 'api'],
				'summary' => $summary,
			];
			if($description !== '')$operation['description'] = $description;
			if($parameters)$operation['parameters'] = $parameters;
			if(isset($extra['requestBody']))$operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $extra['requestBody']]]];
			$operation['responses'] = $responses;
			if($is_public)$operation['security'] = [];

			foreach(($route->getMethods() ?: ['GET']) as $verb)
				$paths[$path][strtolower($verb)] = $operation;

		}

		ksort($paths);

		return [
			'openapi' => '3.1.0',
			'info' => [
				'title' => \Core\Config::get('name').' API',
				'version' => '1.0.0',
				'description' => "authentication: session cookie (POST /api/auth/login/)\n\nunsafe methods (POST, PUT, PATCH, DELETE): header `X-Requested-With: XMLHttpRequest` required (csrf)\n\nerrors: {\"error\": {\"code\", \"message\", \"fields\"}}",
			],
			'servers' => [['url' => \Core\Config::get('url')]],
			'security' => [['session' => []]],
			'paths' => (object)$paths,
			'components' => [
				'securitySchemes' => ['session' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => (string)\Core\Config::get('session/name')]],
				'schemas' => ['Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'properties' => [
					'code' => ['type' => 'integer'],
					'message' => ['type' => 'string'],
					'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
				]]]]],
				'responses' => ['Error' => ['description' => 'error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]],
			],
		];

	}

	/**
	 * summary and description of a docblock
	 *
	 * @param string $doc
	 *
	 * @return array [summary, description]
	 */
	private static function docblock(string $doc):array
	{

		$lines = [];
		foreach(explode("\n", $doc) as $line)
		{

			$line = trim(preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line));
			if(str_starts_with($line, '@'))break;
			$lines[] = $line;

		}

		$text = trim(implode("\n", $lines));
		$parts = explode("\n", $text, 2);

		return [trim($parts[0]), trim($parts[1] ?? '')];

	}

}
