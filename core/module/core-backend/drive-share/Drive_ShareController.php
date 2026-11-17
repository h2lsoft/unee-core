<?php

namespace Plugin\Core_Backend;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Core\Drive;

/**
 * public page of a share link of the Drive: /s/<token>/ (no logon)
 * password checked once per session, a download = a ticket of Drive::LINK_TICKET_TTL seconds counted once (ranges and resumed downloads included)
 * expired, revoked, used up, unknown links and files gone answer the same page "link not available"
 */
#[Route('/s', name: 'drive.share.')]
class Drive_ShareController extends \Core\Controller
{

	// images previewed on the page (svg never)
	private const array PREVIEW_IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'];

	/**
	 * page of the link: password form, or the file (preview of the images and pdf, download button)
	 *
	 * @param Request $request
	 * @param string $token
	 *
	 * @return Response
	 */
	#[Route('/{token}/', name: 'page', methods: ['GET'], defaults: ['_public' => true], requirements: ['token' => '[a-f0-9]{32}'], priority: 10)]
	public function page(Request $request, string $token):Response
	{

		$this->language($request);
		$link = Drive::link($token);
		$state = ($link) ? Drive::linkState($link) : 'unknown';
		if($state === 'locked')return $this->render(ts('Too many wrong passwords, try again in [N] minutes.', ['N' => Drive::LINK_LOCK_MINUTES]), $link, 429);
		if($state !== 'ok')return $this->render(ts('This link is not available.'), null, 404);

		if(!$this->unlocked($link))return $this->render('', $link, 200, true, (string)$request->query->get('error', ''));
		return $this->render('', $link);

	}

	/**
	 * password of the link: 5 wrong ones in a row lock the link 15 minutes
	 *
	 * @param Request $request form field "password"
	 * @param string $token
	 *
	 * @return Response redirect to the page
	 */
	#[Route('/{token}/', name: 'password', methods: ['POST'], defaults: ['_public' => true], requirements: ['token' => '[a-f0-9]{32}'], priority: 10)]
	public function password(Request $request, string $token):Response
	{

		$this->language($request);
		$link = Drive::link($token);
		if(!$link || Drive::linkState($link) !== 'ok')return $this->redirect($request, $token);

		$password = (string)$request->request->get('password', '');
		if($link['password'] === '' || password_verify($password, (string)$link['password']))
		{

			DB()->query("UPDATE xcore_drive_link SET failures = 0 WHERE id = :id", ['id' => (int)$link['id']]);
			$unlocked = (array)\Core\Session::get('drive_links', []);
			$unlocked[(int)$link['id']] = $this->fingerprint($link);
			\Core\Session::set('drive_links', $unlocked);
			return $this->redirect($request, $token);

		}

		// wrong password: counted, the link locked after Drive::LINK_FAILURES
		$failures = (int)$link['failures'] + 1;
		$locked = ($failures >= Drive::LINK_FAILURES) ? date('Y-m-d H:i:s', time() + Drive::LINK_LOCK_MINUTES * 60) : null;
		DB()->query("UPDATE xcore_drive_link SET failures = :failures, locked_until = :locked WHERE id = :id", ['id' => (int)$link['id'], 'failures' => ($locked) ? 0 : $failures, 'locked' => $locked]);
		\Core\Log::write('link_password', 'file', ['link' => (int)$link['id'], 'locked' => (bool)$locked], (int)$link['xcore_drive_file_id'], 'warning', 'drive');

		return $this->redirect($request, $token, 'password');

	}

	/**
	 * download: one count, the creator notified when asked, then the file by a ticket of the session
	 *
	 * @param string $token
	 *
	 * @return Response
	 */
	#[Route('/{token}/download/', name: 'download', methods: ['GET'], defaults: ['_public' => true], requirements: ['token' => '[a-f0-9]{32}'], priority: 10)]
	public function download(Request $request, string $token):Response
	{

		$this->language($request);
		$link = Drive::link($token);
		if(!$link || Drive::linkState($link) !== 'ok' || !$this->unlocked($link))return $this->redirect($request, $token);
		if(!Drive::linkCount((int)$link['id']))return $this->redirect($request, $token);

		$this->notify($link);
		\Core\Log::write('link_download', 'file', ['link' => (int)$link['id'], 'name' => $link['file']['name']], (int)$link['xcore_drive_file_id'], 'info', 'drive');

		// ticket of the session: the file can be read again a few minutes (ranges, resumed download) without a new count
		$ticket = bin2hex(random_bytes(16));
		$tickets = array_filter((array)\Core\Session::get('drive_tickets', []), fn($t) => $t['until'] > time());
		$tickets[$ticket] = ['link' => (int)$link['id'], 'until' => time() + Drive::LINK_TICKET_TTL];
		\Core\Session::set('drive_tickets', $tickets);

		return new RedirectResponse($request->getBaseUrl()."/s/{$token}/file/{$ticket}/", 303);

	}

	/**
	 * content of the file for a ticket of the session (attachment, ranges)
	 *
	 * @param Request $request
	 * @param string $token
	 * @param string $ticket
	 *
	 * @return Response
	 */
	#[Route('/{token}/file/{ticket}/', name: 'file', methods: ['GET'], defaults: ['_public' => true], requirements: ['token' => '[a-f0-9]{32}', 'ticket' => '[a-f0-9]{32}'], priority: 10)]
	public function file(Request $request, string $token, string $ticket):Response
	{

		$this->language($request);
		$link = Drive::link($token);
		$tickets = (array)\Core\Session::get('drive_tickets', []);
		$valid = $link && isset($tickets[$ticket]) && $tickets[$ticket]['link'] === (int)$link['id'] && $tickets[$ticket]['until'] > time();

		// the ticket outlives the count (a used up link still serves its last download) but never a revoke or a file gone
		if(!$valid || $link['revoked_at'] || empty($link['file']))return $this->redirect($request, $token);

		return $this->serve($request, $link['file'], false);

	}

	/**
	 * preview of an image or a pdf on the page (inline, not counted)
	 *
	 * @param Request $request
	 * @param string $token
	 *
	 * @return Response
	 */
	#[Route('/{token}/preview/', name: 'preview', methods: ['GET'], defaults: ['_public' => true], requirements: ['token' => '[a-f0-9]{32}'], priority: 10)]
	public function preview(Request $request, string $token):Response
	{

		$this->language($request);
		$link = Drive::link($token);
		if(!$link || Drive::linkState($link) !== 'ok' || !$this->unlocked($link) || !$this->previewable($link['file']))return new Response('', 404);

		return $this->serve($request, $link['file'], true);

	}

	// ================================================================================================================
	// private
	// ================================================================================================================

	// the password was given in this session (and not changed since); a link without password is always open
	private function unlocked(array $link):bool
	{

		if((string)$link['password'] === '')return true;
		$unlocked = (array)\Core\Session::get('drive_links', []);
		return isset($unlocked[(int)$link['id']]) && hash_equals($unlocked[(int)$link['id']], $this->fingerprint($link));

	}

	// a change of the password closes the sessions opened with the previous one
	private function fingerprint(array $link):string
	{

		return hash('sha256', 'drive-link|'.$link['id'].'|'.$link['password']);

	}

	private function previewable(array $file):bool
	{

		return in_array(strtolower((string)$file['extension']), self::PREVIEW_IMAGES, true) || $file['mime'] === 'application/pdf';

	}

	// path of the request (same host as the visitor: his session cookie follows)
	private function redirect(Request $request, string $token, string $error=''):Response
	{

		return new RedirectResponse($request->getBaseUrl()."/s/{$token}/".(($error !== '') ? "?error={$error}" : ''), 303);

	}

	/**
	 * language of the page: the browser one if it is a backend language (the visitor is anonymous, /s/ is not under the backend)
	 *
	 * @param Request $request
	 *
	 * @return void
	 */
	private function language(Request $request):void
	{

		$languages = array_keys((array)\Core\Config::get('backend.languages'));
		$locale = strtolower(substr((string)$request->headers->get('Accept-Language'), 0, 2));
		App()->locale = (in_array($locale, $languages, true)) ? $locale : 'en';

	}

	/**
	 * file of the drive: inline (preview: sandboxed images, pdf without csp for the viewers of the browsers) or attachment
	 */
	private function serve(Request $request, array $file, bool $inline):Response
	{

		$path = \Core\File::privateDir().'/'.$file['disk'];
		if(!is_file($path))return new Response('', 404);

		$mime = (string)$file['mime'];
		$response = new BinaryFileResponse($path);
		$response->headers->set('Content-Type', $mime ?: 'application/octet-stream');
		$response->headers->set('X-Content-Type-Options', 'nosniff');
		$response->headers->set('X-Robots-Tag', 'noindex');
		if($mime !== 'application/pdf')$response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
		$response->setContentDisposition(($inline) ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string)$file['name'], 'file.'.$file['extension']);
		$response->setPrivate();
		$response->prepare($request);

		return $response;

	}

	/**
	 * creator of the link notified (in-app and email, 1 email credit) when he asked it, with the note of the link
	 */
	private function notify(array $link):void
	{

		if($link['notify'] !== 'yes')return;

		$user = DB()->query("SELECT id, email, firstname, language FROM xcore_user WHERE id = :id AND deleted = 'no' AND active = 'yes'", ['id' => (int)$link['xcore_user_id']])->fetch();
		if(!$user)return;

		$downloads = (int)$link['downloads'] + 1;
		$date = date('d/m/Y H:i');
		$name = (string)$link['file']['name'];

		// notification in the language of the creator, not of the visitor
		$locale = App()->locale;
		App()->locale = (string)$user['language'] ?: 'en';
		$note = trim((string)($link['note'] ?? ''));
		$message = ts('Shared link downloaded on [DATE] ([N] downloads)', ['DATE' => $date, 'N' => $downloads]).(($note !== '') ? " - {$note}" : '');
		\Core\Notification::send((int)$user['id'], ts('[FILE] downloaded', ['FILE' => $name]), $message, 'drive/', 'info', 'drive');
		App()->locale = $locale;

		if((string)$user['email'] === '')return;

		// a mail that cannot go never stops the download
		try
		{

			\Core\Mailer::sendTemplate('core.drive.link_download', (string)$user['email'], ['firstname' => $user['firstname'], 'file' => $name, 'note' => $note, 'date' => $date, 'downloads' => $downloads, 'url' => rtrim((string)\Core\Config::get('url', ''), '/').'/@backend/drive/'],
				(string)($user['language'] ?? ''), [], ['category' => 'transactional', 'module' => 'drive', 'action' => 'link_download', 'record_id' => (int)$link['file']['id'], 'xcore_user_id' => (int)$user['id']]);

		}
		catch(\Throwable $e)
		{

			\Core\Log::write('link_download_mail', 'file', ['link' => (int)$link['id'], 'error' => $e->getMessage()], (int)$link['xcore_drive_file_id'], 'error', 'drive');

		}

	}

	/**
	 * html page of the link (no script): logo of the instance, file, password form or preview and download button
	 *
	 * @param string $message shown instead of the file (link not available, locked)
	 * @param array|null $link
	 * @param int $code
	 * @param bool $ask_password
	 * @param string $error password (wrong password)
	 */
	private function render(string $message, ?array $link, int $code=200, bool $ask_password=false, string $error=''):Response
	{

		$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
		$globals = App()->globals;
		$name = (string)\Core\Config::get('name');

		// logo: global backend.logo, otherwise the icon of the backend and the name of the logo
		$logo_url = trim((string)($globals['backend.logo'] ?? ''));
		$logo_text = trim((string)($globals['backend.logo.text'] ?? '')) ?: 'Unee';
		$powered = trim((string)($globals['backend.powered_by'] ?? ''));
		$logo = ($logo_url !== '' && preg_match('#^(/[^/]|https://)#i', $logo_url))
			? '<img src="'.$e($logo_url).'" alt="'.$e($name).'" style="height:40px;width:auto">'
			: '<span style="display:inline-flex;align-items:center;gap:10px;font:600 26px/1 system-ui,sans-serif;color:#1f2335"><img src="/'.$e(\Core\Backend::dirname()).'/logo-icon.svg" alt="" style="height:32px;width:auto">'.$e($logo_text).'</span>';

		$body = '';
		if($message !== '')
		{

			$body = '<p style="margin:8px 0 0;font-size:16px;color:#3a3f55">'.$e($message).'</p>';

		}
		elseif($link)
		{

			$file = $link['file'];
			$size = $this->size((int)$file['size']);
			$body .= '<div style="font-weight:600;font-size:18px;word-break:break-word">'.$e($file['name']).'</div><div style="margin-top:4px;color:#6b7088;font-size:14px">'.$e($size).'</div>';

			if($ask_password)
			{

				$wrong = ($error === 'password') ? '<p style="margin:0 0 12px;color:#b3261e;font-size:14px">'.$e(ts('Wrong password')).'</p>' : '';
				$body .= '<form method="post" action="./" style="margin:24px 0 0">'.$wrong
					.'<label for="password" style="display:block;margin:0 0 8px;font-size:14px;color:#3a3f55">'.$e(ts('This file is protected by a password')).'</label>'
					.'<input id="password" name="password" type="password" required autofocus autocomplete="off" style="box-sizing:border-box;width:100%;height:42px;padding:0 12px;border:1px solid #d5d8e3;border-radius:6px;font:15px system-ui,sans-serif">'
					.'<button type="submit" style="margin-top:12px;width:100%;height:42px;border:0;border-radius:6px;background:#2c4fc4;color:#fff;font:600 15px system-ui,sans-serif;cursor:pointer">'.$e(ts('Open')).'</button></form>';

			}
			else
			{

				if($this->previewable($file))
				{

					$body .= ($file['mime'] === 'application/pdf')
						? '<iframe src="preview/" title="'.$e($file['name']).'" style="display:block;width:100%;height:60vh;margin:20px 0 0;border:1px solid #e6e8ee;border-radius:8px"></iframe>'
						: '<img src="preview/" alt="'.$e($file['name']).'" style="display:block;max-width:100%;max-height:60vh;margin:20px auto 0;border-radius:8px;border:1px solid #e6e8ee">';

				}

				$body .= '<a href="download/" rel="nofollow" style="display:inline-block;margin-top:24px;padding:0 28px;height:44px;border-radius:6px;background:#2c4fc4;color:#fff;font:600 15px/44px system-ui,sans-serif;text-decoration:none">'.$e(ts('Download')).'</a>';

				$limits = [];
				if($link['expires_at'])$limits[] = ts('Available until [DATE]', ['DATE' => date('d/m/Y', strtotime((string)$link['expires_at']))]);
				if((int)$link['max_downloads'] > 0)$limits[] = ts('[N] downloads left', ['N' => max(0, (int)$link['max_downloads'] - (int)$link['downloads'])]);
				if($limits)$body .= '<p style="margin:16px 0 0;font-size:13px;color:#6b7088">'.$e(implode(' - ', $limits)).'</p>';

			}

		}

		$wide = ($link && $message === '' && !$ask_password && $this->previewable($link['file']) && $link['file']['mime'] === 'application/pdf');
		$html = '<!doctype html><html lang="'.$e(App()->locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer"><title>'.$e(($link && $message === '') ? $link['file']['name'] : $name).'</title></head>'
			.'<body style="margin:0;background:#f7f8fb;font:15px/1.5 system-ui,sans-serif;color:#1f2335">'
			.'<div style="max-width:'.(($wide) ? 960 : 520).'px;margin:48px auto;padding:0 16px">'
			.'<div style="text-align:center;margin:0 0 24px">'.$logo.'</div>'
			.'<div style="padding:32px;background:#fff;border:1px solid #e6e8ee;border-radius:10px;text-align:center">'.$body.'</div>'
			.(($powered !== '') ? '<p style="margin:16px 0 0;text-align:center;font-size:12px;color:#9a9fb3">'.$e($powered).'</p>' : '')
			.'</div></body></html>';

		return new Response($html, $code, [
			'Content-Type' => 'text/html; charset=utf-8',
			'X-Robots-Tag' => 'noindex, nofollow',
			'Cache-Control' => 'no-store',
			'Referrer-Policy' => 'no-referrer',
			'Content-Security-Policy' => "default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
		]);

	}

	// 14 Ko, 2,5 Mo (units translated)
	private function size(int $bytes):string
	{

		if($bytes < 1024)return $bytes.' '.ts('B');
		if($bytes < 1048576)return round($bytes / 1024).' '.ts('KB');
		$comma = (App()->locale === 'fr') ? ',' : '.';
		if($bytes < 1073741824)return str_replace('.', $comma, (string)round($bytes / 1048576, 1)).' '.ts('MB');
		return str_replace('.', $comma, (string)round($bytes / 1073741824, 1)).' '.ts('GB');

	}

}
