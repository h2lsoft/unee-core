<?php

namespace Plugin\Communication;

use Core\Tracker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * public url of the trackers: /go/<code>/ (\Core\Tracker), out of /api/ so no session and no csrf header are needed
 * in its own module folder (go): the class autoloader finds a controller by the folder named like it
 * GET = counted then redirected to the target (302, never cached), an action tracker answers 204
 * POST = counted, 204 (sent by the page on a click with fetch keepalive, ex: tel: or mailto: button)
 * the target comes from the tracker only: no url in the query string, so no open redirect
 * an amount is never read here: it comes from \Core\Tracker::hit() only
 */
#[Route('/go', name: 'go.')]
class GoController extends \Core\Controller
{

	/**
	 * hit of a tracker
	 *
	 * @param Request $request
	 * @param string $code
	 *
	 * @return Response 302 to the target, 204, or 404 for an unknown code
	 */
	#[Route('/{code}/', name: 'hit', methods: ['GET', 'POST'], defaults: ['_public' => true, '_csrf' => false], requirements: ['code' => Tracker::CODE_PATTERN])]
	public function hit(Request $request, string $code):Response
	{

		$headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow'];

		if(!$tracker = Tracker::find($code))return new Response('not found', 404, $headers + ['Content-Type' => 'text/plain']);

		// excluded requests (robot, ip, head, prefetch) and inactive trackers get the same answer, without the counting
		Tracker::record($tracker, $request);

		$url = trim((string)$tracker['url']);
		if($request->isMethod('POST') || $tracker['type'] !== 'redirect' || !preg_match('#^https?://#i', $url))return new Response('', 204, $headers);

		return new Response('', 302, $headers + ['Location' => $url]);

	}

}
