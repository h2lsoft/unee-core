<?php

namespace Core;

class Response extends \Symfony\Component\HttpFoundation\Response {


	public static function error(string $message, int $code=404):\Core\Response
	{
		$message = "<div class=\"error text-danger\">{$message}</div>";
		return new \Core\Response($message);
	}

}