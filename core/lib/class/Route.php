<?php

namespace Core;

/**
 * route of a controller method, read by core/run.php
 * placeholders in path and name: @backend (config backend.dirname), @module (folder of the module, ex: globals)
 * a route declared on a parent method is inherited by every module controller (ex: \Core\CrudController)
 *
 * ex: #[\Core\Route('/api/@module/{id}/', methods: 'PUT', name: 'api.@module.update')]
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Route
{

	public array $methods;

	/**
	 * @param string $path ends with a slash (rule), {id} and {page} are digits only
	 * @param string|array $methods GET, POST, PUT, PATCH, DELETE or ANY
	 * @param string $name route name, empty = generated from the path and the method
	 * @param string $controller method called instead of the decorated one, empty = the decorated method
	 */
	public function __construct(public string $path, string|array $methods='GET', public string $name='', public string $controller='')
	{

		$this->methods = array_map('strtoupper', (array)$methods);

	}

}
