<?php

namespace Job\Core;

/**
 * job send_email: payload {"template", "to", "vars", "locale"} (mail template) or {"from", "to", "subject", "body"} (raw html)
 */
class Send_Email extends \Core\JobHandler
{

	public function handle(array $payload):?array
	{

		if(empty($payload['to']))throw new \InvalidArgumentException('payload: to is required');

		if(isset($payload['template']))
		{
			$sent = \Core\Mailer::sendTemplate($payload['template'], (string)$payload['to'], (array)($payload['vars'] ?? []), (string)($payload['locale'] ?? ''), (array)($payload['attachments'] ?? []), (array)($payload['options'] ?? []));
		}
		else
		{

			foreach(['from', 'subject', 'body'] as $key)
				if(!isset($payload[$key]))throw new \InvalidArgumentException("payload: {$key} is required");

			$sent = \Core\Mailer::send($payload['from'], (string)$payload['to'], (string)$payload['subject'], (string)$payload['body'], (array)($payload['attachments'] ?? []), (array)($payload['options'] ?? []));

		}

		if(!$sent)throw new \RuntimeException('mailer: '.\Core\Mailer::getLastError());

		return ['to' => $payload['to']];

	}

}
