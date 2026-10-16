<?php

namespace Core;

class JsonResponse extends \Symfony\Component\HttpFoundation\JsonResponse
{

	/**
	 * api error, same format everywhere: {"error": {"code": 422, "message": "...", ...extra}}
	 *
	 * @param int $code http status
	 * @param string $message english text, translated in the locale of the request
	 * @param array $extra added in error, ex: ['fields' => [...]]
	 * @param array $headers
	 *
	 * @return static
	 */
	public static function error(int $code, string $message, array $extra=[], array $headers=[]):static
	{

		return new static(['error' => ['code' => $code, 'message' => ts($message)] + $extra], $code, $headers);

	}

}
