<?php

namespace Plugin\Core;

use Symfony\Component\HttpFoundation\Request;

/**
 * api authentication for the react backend, session cookie (HttpOnly, SameSite=Lax)
 * errors: 401 invalid credentials, 422 missing fields, 429 throttled
 */
class AuthController extends \Core\Controller
{

	/**
	 * logon, json body: {"login": "...", "password": "..."}, login is the email or the login (config auth/backend/key)
	 *
	 * @route /api/auth/login/ {name: "api.auth.login", method: "POST"}
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"user": {...}}
	 */
	public function login(Request $request):\Core\JsonResponse
	{

		$this->validator->input('login')->required();
		$this->validator->input('password')->required();

		if($this->validator->fails())
			return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $this->validator->result()['error_stack_deep']]);

		$error = \Model\User::logon((string)$request->request->get('login'), (string)$request->request->get('password'));

		if($error === 'throttled')
		{

			$retry_after = \Core\Config::get('auth/logon_throttle/window_seconds');
			return \Core\JsonResponse::error(429, 'too many failed attempts, retry later', [], ['Retry-After' => $retry_after]);

		}

		if($error !== '')
			return \Core\JsonResponse::error(401, 'invalid credentials');

		return new \Core\JsonResponse(['user' => \Model\User::getCurrent()]);

	}

	/**
	 * logout
	 *
	 * @route /api/auth/logout/ {name: "api.auth.logout", method: "POST"}
	 *
	 * @return \Core\JsonResponse 204 no content
	 */
	public function logout():\Core\JsonResponse
	{

		\Model\User::logout();
		return new \Core\JsonResponse(null, 204);

	}

	/**
	 * public settings of the login page: whitelist of globals, the other globals are never exposed
	 *
	 * @route /api/auth/config/ {name: "api.auth.config"}
	 *
	 * @return \Core\JsonResponse {"login_key", "logo", "login": {"image", "title", "subtitle", "notice", "notice_severity"}}, null = default of the backend
	 */
	public function config():\Core\JsonResponse
	{

		$globals = App()->globals;
		$get = fn(string $name) => (isset($globals[$name]) && trim((string)$globals[$name]) !== '') ? (string)$globals[$name] : null;
		$severity = $get('backend.login.notice_severity');

		return new \Core\JsonResponse([
			'login_key' => (\Core\Config::get('auth/backend/key') === 'login') ? 'login' : 'email',
			'logo' => $get('backend.logo'),
			'login' => [
				'image' => $get('backend.login.image'),
				'title' => $get('backend.login.title'),
				'subtitle' => $get('backend.login.subtitle'),
				'notice' => $get('backend.login.notice'),
				'notice_severity' => in_array($severity, ['info', 'warning', 'error', 'success'], true) ? $severity : 'info',
			],
		]);

	}

	/**
	 * logged user, called by the react backend at startup (401 from the auth.api middleware when nobody is logged)
	 *
	 * @route /api/auth/me/ {name: "api.auth.me"}
	 *
	 * @return \Core\JsonResponse {"user": {...}}
	 */
	public function me():\Core\JsonResponse
	{

		return new \Core\JsonResponse(['user' => \Model\User::getCurrent()]);

	}

}
