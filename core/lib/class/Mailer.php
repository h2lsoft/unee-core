<?php

namespace Core;

use Core\Config;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;


class Mailer
{
	public static string $last_error = "";

	public static function getLastError():string {return self::$last_error;}


	/**
	 * Send email by module email template
	 *
	 * @param string|int $template_name
	 * @param string     $to
	 * @param array      $vars
	 * @param string     $locale
	 * @param array      $attachments
	 * @param array      $options
	 *
	 * @return bool
	 */
	public static function sendTemplate(string|int $template_name, string $to, array $vars=[], string $locale='', array $attachments=[], array $options=[]):bool
	{
		if(empty($locale))$locale = App()->locale;
		$locale = strtolower($locale);

		$column = (is_int($template_name)) ? 'id' : 'name';
		$template = \Model\Mail_Template::findOne("{$column} = :value", [':value' => $template_name], '*');
		if(!$template)
		{

			self::$last_error = "mail template not found: {$template_name}";
			return false;

		}

		// templates hold en and fr columns: other languages fall back to en
		if(!array_key_exists("body_{$locale}", $template))$locale = 'en';

		$from = $template["sender_{$locale}"];

		$subject = $template["subject_{$locale}"];
		$body = $template["body_{$locale}"];
		$body = str_replace(['[[ ', ' ]]'], ['[[', ']]'], $body);

		// vars are escaped in an html body (user data), the subject is plain text on one line
		$html_mode = (isset($options['html'])) ? $options['html'] : true;
		foreach($vars as $var => $val)
		{

			$body = str_replace("[[{$var}]]", ($html_mode) ? htmlspecialchars((string)$val, ENT_QUOTES) : (string)$val, $body);
			$subject = str_replace("[[{$var}]]", str_replace(["\r", "\n"], ' ', (string)$val), $subject);

		}

		// add layout
		if($template['xcore_layout_id'])
		{
			$layout = \Model\Layout::findById($template['xcore_layout_id']);
			if($layout)
			{
				$body = str_replace(['[CONTENT]'], $body, $layout['content']);
			}
		}




		return \Core\Mailer::send($from, $to, $subject, $body, $attachments, $options);

	}



	/**
	 * transport from globals: smtp when <prefix>.host is set, otherwise php mail()
	 *
	 * @param PHPMailer $mail
	 * @param string $prefix globals prefix, ex: newsletter.smtp for a dedicated server
	 *
	 * @return void
	 */
	public static function transport(PHPMailer $mail, string $prefix='mailer.smtp'):void
	{

		$smtp_host = trim((string)\Core\Globals::get("{$prefix}.host", ($prefix === 'mailer.smtp') ? 'localhost' : '', true, 'text', 'smtp host, empty = php mail()'));
		if($smtp_host === '')return;

		$mail->isSMTP();
		$mail->Host = $smtp_host;
		$mail->Port = (int)\Core\Globals::get("{$prefix}.port", '25', true, 'int', 'smtp port');

		$security = (string)\Core\Globals::get("{$prefix}.security", '', true, 'text', 'smtp encryption: empty, tls or ssl');
		$mail->SMTPSecure = (in_array($security, ['tls', 'ssl'], true)) ? $security : '';

		$username = (string)\Core\Globals::get("{$prefix}.username", '', true, 'text', 'smtp user, empty = no authentication');
		if($username !== '')
		{

			$mail->SMTPAuth = true;
			$mail->Username = $username;
			$mail->Password = (string)\Core\Globals::get("{$prefix}.password", '', true, 'text', 'smtp password');

		}

	}

	/**
	 * @param string|array $from array(author|email) or string with | as separator
	 * @param string|array $to  if string separated by ;
	 * @param string       $subject
	 * @param string       $body
	 * @param array        $attachments array name and path
	 * @param array        $options (like charset : utf-8 by default or html : true by default or reply-to, bcc, cc)
	 * @param array        $data append value for data in body
	 *
	 * @return bool
	 */
	public static function send(string|array $from, string $to, string $subject, string $body, array $attachments=[], array $options=[], array $data=[], array $headers=[], $return_path=''):bool
	{
		global $request;

		$mail = new PHPMailer(true);

		self::transport($mail);

		if(!isset($options['charset']))$options['charset'] = 'utf-8';
		$mail->CharSet = $options['charset'];

		if(!isset($options['X-Mailer']) && !isset($options['XMailer']) && !isset($options['x-mailer']))
			$mail->XMailer = null;

		// add headers
		foreach($headers as $header_name => $header_value)
		{
			$mail->addCustomHeader($header_name, $header_value);
		}

		// html
		$html_mode = (isset($options['html'])) ? $options['html'] : true;
		$mail->isHTML($html_mode);

		// from
		if(empty($from))$from = \Core\Config::get('noreply_email');
		$from_email = $from;
		$from_author = '';
		if(is_array($from))
		{
			$from_author = $from[0];
			$from_email = $from[1];
		}
		elseif(count(explode('|', $from)) == 2)
		{
			list($from_author, $from_email) = explode('|', $from);
		}

		$mail->setFrom(trim($from_email), trim($from_author), true);

		// to
		if(!is_array($to))
		{
			$to = str_erase(' ', str_replace(',', ';', trim($to)));
			$to = explode(';', $to);
			$to = array_map('trim', $to);
		}

		foreach($to as $email)
		{
			$mail->addAddress($email);
		}

		// attachments
		foreach($attachments as $file)
		{
			if(empty($file['name']))$file['name'] = basename($file['path']);
			$mail->addAttachment($file['path'], $file['name']);
		}

		// reply-to
		if(isset($options['reply-to']) && !empty($options['reply-to']))
			$mail->addReplyTo($options['reply-to']);

		// cc
		if(isset($options['cc']))
		{
			$to = $options['cc'];
			if(!is_array($to))
			{
				$to = str_erase(' ', str_replace(',', ';', trim($options['cc'])));
				$to = explode(';', $to);
				$to = array_map('trim', $to);
			}

			foreach($to as $email)
				$mail->addCC($email);
		}

		// bcc
		if(isset($options['bcc']))
		{
			$to = $options['bcc'];
			if(!is_array($to))
			{
				$to = str_erase(' ', str_replace(',', ';', trim($options['bcc'])));
				$to = explode(';', $to);
				$to = array_map('trim', $to);
			}

			foreach($to as $email)
				$mail->addBCC($email);
		}

		// parsing subject, body
		foreach($data as $key => $val)
		{
			$subject = str_replace("[[{$key}]]", $val, $subject);
			$body = str_replace("[[{$key}]]", $val, $body);
		}

		$mail->Subject = $subject;

		// add envelop
		// $data['IP'] = $request->getClientIp();
		// $data['contents'] = $body;
		// $data['vars'] = $cfg['template']['vars'];

		/*if($wrap_email)
		{
			$body = View($cfg['template']['path'], $data, false);
		}*/

		$uri = \Core\Config::get('url');
		// @backend: folder of the backend (config backend.dirname), ex: <img src="/@backend/logo-mail.png">
		$body = str_replace('/@backend/', '/'.\Core\Backend::dirname().'/', $body);
		$body = str_replace(' src="/',  " src=\"{$uri}/", $body);
		$body = str_replace(' href="/',  " href=\"{$uri}/", $body);

		$mail->Body = $body;

		if(!empty($return_path))
			$mail->Sender = $return_path;

		try {
			$mail->send();
			return true;
		} catch (Exception $e) {
			self::$last_error = "Mailer Error: {$mail->ErrorInfo}";
			return false;
		}

	}
}