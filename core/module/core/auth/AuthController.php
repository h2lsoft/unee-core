<?php

namespace Plugin\Core;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

/**
 * api authentication for the react backend, session cookie (HttpOnly, SameSite=Lax)
 * errors: 401 invalid credentials, 422 missing fields, 429 throttled
 * two-factor (config auth/security/backend/two_factor): login answers {"two_factor": "verify"|"setup"}, then POST /api/auth/2fa/ opens the session
 */
#[Route('/api/auth', name: 'api.auth.')]
class AuthController extends \Core\Controller
{

	/**
	 * logon, json body: {"login": "...", "password": "..."}, login is the email or the login (config auth/backend/key)
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"user": {...}} or {"two_factor": "verify", "trust_days"} or {"two_factor": "setup", "trust_days", "secret", "uri"}
	 */
	#[Route('/login/', name: 'login', methods: ['POST'])]
	public function login(Request $request):\Core\JsonResponse
	{

		$login_label = (\Core\Config::get('auth/backend/key') === 'login') ? ts('Login') : ts('Email address');
		$this->validator->input('login', $login_label)->required();
		$this->validator->input('password', ts('Password'))->required();

		if($this->validator->fails())
			return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $this->validator->result()['error_stack_deep']]);

		$error = \Model\User::logon((string)$request->request->get('login'), (string)$request->request->get('password'));

		if($error === 'throttled')
		{

			$retry_after = \Core\Config::get('auth/logon_throttle/window_seconds');
			return \Core\JsonResponse::error(429, 'too many failed attempts, retry later', [], ['Retry-After' => $retry_after]);

		}

		if($error === 'two_factor' || $error === 'two_factor_setup')
			return new \Core\JsonResponse($this->twoFactorStep());

		if($error !== '')
			return \Core\JsonResponse::error(401, 'invalid credentials');

		return new \Core\JsonResponse(['user' => \Model\User::getCurrent()]);

	}

	/**
	 * second factor of a pending logon, json body: {"code": "123456", "trust": true}
	 * the secret is saved on the first use (setup), each wrong code counts as a failed logon
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"user": {...}}, 401 invalid code, 440 pending logon expired (password to type again)
	 */
	#[Route('/2fa/', name: 'two_factor', methods: ['POST'])]
	public function twoFactor(Request $request):\Core\JsonResponse
	{

		$this->validator->input('code', ts('Code'))->required();

		if($this->validator->fails())
			return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $this->validator->result()['error_stack_deep']]);

		$error = \Model\User::twoFactorCheck((string)$request->request->get('code'), (bool)$request->request->get('trust', false));

		if($error === 'expired')
			return \Core\JsonResponse::error(440, 'verification expired, sign in again');

		if($error !== '')
			return \Core\JsonResponse::error(401, 'invalid code');

		return new \Core\JsonResponse(['user' => \Model\User::getCurrent()]);

	}

	/**
	 * end of a connect as: the session of the account that started it opens again (no password or code asked)
	 *
	 * @return \Core\JsonResponse {"redirect"}, 422 when not connected as, 403 when the account cannot sign in anymore
	 */
	#[Route('/impersonation/stop/', name: 'impersonation.stop', methods: ['POST'])]
	public function impersonationStop():\Core\JsonResponse
	{

		$impersonator = \Core\Session::get('auth.impersonator');
		if(!$impersonator)return \Core\JsonResponse::error(422, 'you are not connected as another user');

		$user = DB()->query("SELECT u.*, g.access_backend
							 FROM xcore_user u
							 INNER JOIN xcore_group g ON g.id = u.xcore_group_id AND g.deleted = 'no' AND g.active = 'yes'
							 WHERE u.deleted = 'no' AND u.id = :id
							 LIMIT 1", ['id' => (int)$impersonator['id']])->fetch();

		if(!$user || $user['active'] !== 'yes' || $user['access_backend'] !== 'yes')
		{

			\Core\Session::destroy();
			return \Core\JsonResponse::error(403, 'your account cannot sign in anymore');

		}

		\Core\Log::write('connect_as_back', "back to {$user['login']}", [], (int)\Model\User::getUID(), 'warning', 'auth', $user['login']);
		\Core\Session::destroy();
		\Model\User::openSession($user);

		return new \Core\JsonResponse(['redirect' => '/'.\Core\Backend::dirname().'/']);

	}

	/**
	 * answer of a logon waiting for its second factor, with the secret and its qr code uri on setup
	 *
	 * @return array
	 */
	private function twoFactorStep():array
	{

		$pending = \Model\User::twoFactorPending();
		$data = ['two_factor' => ($pending['secret'] !== '') ? 'setup' : 'verify', 'trust_days' => (int)\Core\Config::get('auth/security/backend/two_factor/trust_days', 0)];

		if($pending['secret'] !== '')
		{

			$account = (string)$pending['user'][(\Core\Config::get('auth/backend/key') === 'login') ? 'login' : 'email'];
			$data += ['secret' => $pending['secret'], 'uri' => \Core\TwoFactor::uri($pending['secret'], $account)];

		}

		return $data;

	}

	/**
	 * logout
	 *
	 * @return \Core\JsonResponse 204 no content
	 */
	#[Route('/logout/', name: 'logout', methods: ['POST'])]
	public function logout():\Core\JsonResponse
	{

		\Model\User::logout();
		return new \Core\JsonResponse(null, 204);

	}

	/**
	 * password recovery, json body: {"login": "..."}, always the same answer whether the account exists or not
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"message"}
	 */
	#[Route('/password/forgot/', name: 'password.forgot', methods: ['POST'])]
	public function passwordForgot(Request $request):\Core\JsonResponse
	{

		$login_label = (\Core\Config::get('auth/backend/key') === 'login') ? ts('Login') : ts('Email address');
		$this->validator->input('login', $login_label)->required();

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
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"message"}, 400 when the link is invalid or expired
	 */
	#[Route('/password/reset/', name: 'password.reset', methods: ['POST'])]
	public function passwordReset(Request $request):\Core\JsonResponse
	{

		$password_cfg = \Core\Config::get('auth/security/password');

		$this->validator->input('token')->required();
		$this->validator->input('password', ts('New password'))->required()->regex($password_cfg['regex'], ts('`[FIELD]` must contain [MIN] to [MAX] characters', ['MIN' => $password_cfg['min_length'], 'MAX' => $password_cfg['max_length']]));
		$this->validator->input('password_confirmation', ts('Confirm password'))->required()->sameAs('password');

		if($this->validator->fails())
			return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $this->validator->result()['error_stack_deep']]);

		if(!\Model\User::passwordReset((string)$request->request->get('token'), (string)$request->request->get('password')))
			return \Core\JsonResponse::error(400, 'this link is invalid or has expired, ask for a new one');

		return new \Core\JsonResponse(['message' => 'Your password has been changed, you can sign in.']);

	}

	/**
	 * public settings of the login page: whitelist of globals, the other globals are never exposed
	 *
	 * @return \Core\JsonResponse {"name", "login_key", "logo", "header_background", "stylesheets": [url], "login": {"image", "title", "subtitle"}, "maintenance_message", "languages": {"en": "English"}}, null = default of the backend
	 */
	#[Route('/config/', name: 'config', methods: ['GET'])]
	public function config():\Core\JsonResponse
	{

		$globals = App()->globals;
		$get = fn(string $name) => (isset($globals[$name]) && trim((string)$globals[$name]) !== '') ? (string)$globals[$name] : null;

		// css color only: the value goes into a stylesheet
		$header_background = $get('backend.header.background');
		if($header_background !== null && !preg_match('/^(#[0-9a-f]{3,8}|[a-z]{3,30}|(rgb|hsl)a?\([0-9a-z\s.,%\/-]{1,60}\))$/i', trim($header_background)))$header_background = null;

		// stylesheets overriding the backend: absolute path or http(s) url only
		$stylesheets = array_values(array_filter(array_map('trim', (array)($globals['backend.stylesheets'] ?? [])), fn($url) => preg_match('#^(/[^/]|https?://)#i', $url)));

		return new \Core\JsonResponse([
			'name' => (string)\Core\Config::get('name'),
			'login_key' => (\Core\Config::get('auth/backend/key') === 'login') ? 'login' : 'email',
			'logo' => $get('backend.logo'),
			'header_background' => ($header_background !== null) ? trim($header_background) : null,
			'stylesheets' => $stylesheets,
			'login' => [
				'image' => $get('backend.login.image'),
				'title' => $get('backend.login.title'),
				'subtitle' => $get('backend.login.subtitle'),
			],
			'maintenance_message' => $get('backend.maintenance.message'),
			'languages' => \Core\Config::get('backend.languages'),
		]);

	}

	/**
	 * logged user, called by the react backend at startup (401 from the auth.api middleware when nobody is logged)
	 *
	 * @return \Core\JsonResponse {"user": {...}}
	 */
	#[Route('/me/', name: 'me', methods: ['GET'])]
	public function me():\Core\JsonResponse
	{

		return new \Core\JsonResponse(['user' => \Model\User::getCurrent()]);

	}

	/**
	 * preferences of the logged user, json body: {"language": "fr"} (key of config backend.languages)
	 *
	 * @param Request $request
	 *
	 * @return \Core\JsonResponse {"user": {...}}
	 */
	#[Route('/me/', name: 'me.update', methods: ['PATCH'])]
	public function meUpdate(Request $request):\Core\JsonResponse
	{

		$this->validator->input('language', ts('Language'))->required()->in(array_keys(\Core\Config::get('backend.languages')));

		if($this->validator->fails())
			return \Core\JsonResponse::error(422, 'validation failed', ['fields' => $this->validator->result()['error_stack_deep']]);

		$language = (string)$request->request->get('language');
		\Model\User::update(['language' => $language], \Model\User::getUID(), 1, (string)\Core\Session::get('auth.login'));
		\Core\Session::set('auth.language', $language);

		return new \Core\JsonResponse(['user' => \Model\User::getCurrent()]);

	}

}
