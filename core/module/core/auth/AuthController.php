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

		$this->validator->input('login')->required('required');
		$this->validator->input('password')->required('required');

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
	 * password recovery, json body: {"login": "..."}, always the same answer whether the account exists or not
	 *
	 * @route /api/auth/password/forgot/ {name: "api.auth.password.forgot", method: "POST"}
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	public function passwordForgot(Request $request):\Core\JsonResponse
	{

		$this->validator->input('login')->required('required');

		if($this->validator->fails())
			return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $this->validator->result()['error_stack_deep']]);

		$login = (string)$request->request->get('login');

		if(\Model\User::isThrottled('password_forgot', $login))
		{

			$retry_after = \Core\Config::get('auth/logon_throttle/window_seconds');
			return \Core\JsonResponse::error(429, 'too many requests, retry later', [], ['Retry-After' => $retry_after]);

		}

		\Model\User::passwordForgot($login);

		return new \Core\JsonResponse(['message' => 'If an account exists for this email address, a recovery link has been sent.']);

	}

	/**
	 * password reset, json body: {"token": "...", "password": "...", "password_confirmation": "..."}
	 *
	 * @route /api/auth/password/reset/ {name: "api.auth.password.reset", method: "POST"}
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"message"}, 400 when the link is invalid or expired
	 */
	public function passwordReset(Request $request):\Core\JsonResponse
	{

		$password_cfg = \Core\Config::get('auth/security/password');

		// short messages: they are shown under the fields
		$this->validator->input('token')->required('required');
		$this->validator->input('password')->required('required')->regex($password_cfg['regex'], "{$password_cfg['min_length']} to {$password_cfg['max_length']} characters");
		$this->validator->input('password_confirmation')->required('required')->sameAs('password', 'passwords do not match');

		if($this->validator->fails())
			return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $this->validator->result()['error_stack_deep']]);

		if(!\Model\User::passwordReset((string)$request->request->get('token'), (string)$request->request->get('password')))
			return \Core\JsonResponse::error(400, 'this link is invalid or has expired, ask for a new one');

		return new \Core\JsonResponse(['message' => 'Your password has been changed, you can sign in.']);

	}

	/**
	 * public settings of the login page: whitelist of globals, the other globals are never exposed
	 *
	 * @route /api/auth/config/ {name: "api.auth.config"}
	 *
	 * @return \Core\JsonResponse {"login_key", "logo", "login": {"image", "title", "subtitle"}, "maintenance_message"}, null = default of the backend
	 */
	public function config():\Core\JsonResponse
	{

		$globals = App()->globals;
		$get = fn(string $name) => (isset($globals[$name]) && trim((string)$globals[$name]) !== '') ? (string)$globals[$name] : null;

		return new \Core\JsonResponse([
			'login_key' => (\Core\Config::get('auth/backend/key') === 'login') ? 'login' : 'email',
			'logo' => $get('backend.logo'),
			'login' => [
				'image' => $get('backend.login.image'),
				'title' => $get('backend.login.title'),
				'subtitle' => $get('backend.login.subtitle'),
			],
			'maintenance_message' => $get('backend.maintenance.message'),
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
