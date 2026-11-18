<?php

namespace Plugin\Communication;

use Core\SmartLink;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * public url of the smart links: /go/<code>/ (\Core\SmartLink), out of /api/ so no session and no csrf header are needed
 * in its own module folder (go): the class autoloader finds a controller by the folder named like it
 * GET = counted then redirected to the target (302, never cached), an action smart link answers 204
 * POST = counted, 204 (sent by the page on a click with fetch keepalive, ex: tel: or mailto: button)
 * the target comes from the smart link only: no url in the query string, so no open redirect
 * an amount is never read here: it comes from \Core\SmartLink::hit() only
 */
#[Route('/go', name: 'go.')]
class GoController extends \Core\Controller
{

	/**
	 * hit of a smart link
	 *
	 * @param Request $request
	 * @param string $code
	 *
	 * @return Response 302 to the target, 204, or 404 for an unknown code
	 */
	#[Route('/{code}/', name: 'hit', methods: ['GET', 'POST'], defaults: ['_public' => true, '_csrf' => false], requirements: ['code' => SmartLink::CODE_PATTERN])]
	public function hit(Request $request, string $code):Response
	{

		$headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow'];

		if(!$smart_link = SmartLink::find($code))return new Response('not found', 404, $headers + ['Content-Type' => 'text/plain']);

		// excluded requests (robot, ip, head, prefetch) and inactive smart links get the same answer, without the counting
		SmartLink::record($smart_link, $request);

		$url = trim((string)$smart_link['url']);
		if($request->isMethod('POST') || $smart_link['type'] !== 'redirect' || !preg_match('#^https?://#i', $url))return new Response('', 204, $headers);

		return new Response('', 302, $headers + ['Location' => $url]);

	}

}
