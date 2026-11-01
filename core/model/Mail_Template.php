<?php

namespace Model;

/**
 * mail templates (subject, body by language, optional layout), sent by \Core\Mailer::sendTemplate()
 */
class Mail_Template extends \Core\Entity
{

	public static string $table = 'xcore_mail_template';

}
