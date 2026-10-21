<?php

// cron task "Newsletter bounces": reads the bounces of the imap mailbox receiving the return path of the newsletters, $params: max_messages
// globals newsletter.bounce.imap.*: host (empty = nothing done), port, security (ssl, tls or empty), username, password, folder
// a bounce is matched by the header X-Unee-Newsletter of the original message (token), else by its Final-Recipient on the last newsletter sent to that email
// hard bounce (status 5.x.x): action bounce + email blacklisted, soft bounce (4.x.x): action bounce, blacklisted after newsletter.bounce.soft_limit soft bounces
// matched messages are deleted, the others are marked read and left in the mailbox

$max_messages = max(1, (int)($params['max_messages'] ?? 500));

$host = trim((string)\Core\Globals::get('newsletter.bounce.imap.host', '', true, 'text', 'imap host of the bounce mailbox (return path of the newsletters), empty = bounces not read'));
$port = (int)\Core\Globals::get('newsletter.bounce.imap.port', '993', true, 'int', 'imap port of the bounce mailbox');
$security = (string)\Core\Globals::get('newsletter.bounce.imap.security', 'ssl', true, 'text', 'imap encryption of the bounce mailbox: ssl, tls or empty');
$username = (string)\Core\Globals::get('newsletter.bounce.imap.username', '', true, 'text', 'imap user of the bounce mailbox');
$password = (string)\Core\Globals::get('newsletter.bounce.imap.password', '', true, 'text', 'imap password of the bounce mailbox');
$folder = (string)\Core\Globals::get('newsletter.bounce.imap.folder', 'INBOX', true, 'text', 'imap folder of the bounces');
$soft_limit = max(1, (int)\Core\Globals::get('newsletter.bounce.soft_limit', '3', true, 'int', 'soft bounces (mailbox full...) before an email is blacklisted'));

if($host === '')
{

	echo "globals newsletter.bounce.imap.host is empty: nothing to read".CR;
	return;

}

// bounce of a raw message: [type hard|soft, email, token, diagnostic] or null when it is not a bounce
$parse = static function(string $raw):?array
{

	$raw = str_replace("\r\n", "\n", $raw);
	$token = (preg_match('/^X-Unee-Newsletter:\s*(\d+\.\d+\.[a-f0-9]{16})/mi', $raw, $m)) ? $m[1] : '';
	$email = (preg_match('/^Final-Recipient:\s*rfc822;\s*<?([^\s>]+@[^\s>]+)>?/mi', $raw, $m)) ? mb_strtolower($m[1]) : '';
	$status = (preg_match('/^Status:\s*([245])\.\d{1,3}\.\d{1,3}/mi', $raw, $m)) ? $m[1] : '';
	$diagnostic = (preg_match('/^Diagnostic-Code:\s*(.+)$/mi', $raw, $m)) ? trim($m[1]) : '';

	if($status === '2' || ($status === '' && $diagnostic === ''))return null;
	if($token === '' && $email === '')return null;

	// no status: the smtp code of the diagnostic
	if($status === '')$status = (preg_match('/\b([45])\d\d\b/', $diagnostic, $m)) ? $m[1] : '5';

	return [($status === '5') ? 'hard' : 'soft', $email, $token, mb_substr($diagnostic, 0, 200)];

};

// self-check of the parser
assert($parse("Final-Recipient: rfc822; John@X.fr\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 5.1.1 unknown\n") === ['hard', 'john@x.fr', '', 'smtp; 550 5.1.1 unknown']);
assert($parse("Subject: hello\n\nnot a bounce") === null);

// minimal imap client (no php imap extension since php 8.4): tagged commands, literals {n} read as data
$imap = new class($host, $port, $security)
{

	private $socket;
	private int $tag = 0;

	public function __construct(string $host, int $port, string $security)
	{

		$this->socket = @stream_socket_client((($security === 'ssl') ? 'ssl://' : 'tcp://')."{$host}:{$port}", $errno, $errstr, 30);
		if(!$this->socket)throw new \RuntimeException("imap connection failed: {$errstr}");
		stream_set_timeout($this->socket, 60);
		fgets($this->socket);

		if($security === 'tls')
		{

			$this->command('STARTTLS');
			if(!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new \RuntimeException('imap starttls failed');

		}

	}

	// untagged data of the command, exception when it is not OK
	public function command(string $command):string
	{

		$tag = 'A'.(++$this->tag);
		fwrite($this->socket, "{$tag} {$command}\r\n");

		$data = '';
		while(($line = fgets($this->socket)) !== false)
		{

			if(str_starts_with($line, "{$tag} "))
			{

				if(!preg_match('/^'.$tag.' OK/i', $line))throw new \RuntimeException('imap '.strtok($command, ' ').': '.trim($line));
				return $data;

			}

			$data .= $line;
			if(preg_match('/\{(\d+)\}\r\n$/', $line, $m))
			{

				$size = (int)$m[1];
				$literal = '';
				while(strlen($literal) < $size && !feof($this->socket))$literal .= fread($this->socket, $size - strlen($literal));
				$data .= $literal;

			}

		}

		throw new \RuntimeException('imap connection lost');

	}

	public static function quote(string $value):string
	{

		return '"'.addcslashes($value, '"\\').'"';

	}

};

$imap->command('LOGIN '.$imap::quote($username).' '.$imap::quote($password));
$imap->command('SELECT '.$imap::quote($folder));

preg_match_all('/\d+/', (string)preg_replace('/^\* SEARCH/m', '', $imap->command('UID SEARCH UNSEEN')), $m);
$uids = array_slice($m[0], 0, $max_messages);

$hard = 0;
$soft = 0;
$ignored = 0;
foreach($uids as $uid)
{

	$raw = $imap->command("UID FETCH {$uid} BODY.PEEK[]");
	$bounce = $parse($raw);

	// newsletter and subscriber: token of the original message, else last newsletter sent to the email
	$target = null;
	if($bounce)
	{

		[$type, $email, $token, $diagnostic] = $bounce;
		if($token !== '' && ($ids = \Core\Newsletter::parseToken($token)))
			$target = DB()->query("SELECT :nid AS xcore_newsletter_id, id, xcore_mailinglist_id, email FROM xcore_mailinglist_subscriber WHERE id = :sid", ['nid' => $ids[0], 'sid' => $ids[1]])->fetch();

		if(!$target && $email !== '')
			$target = DB()->query("SELECT xcore_newsletter_id, xcore_mailinglist_subscriber_id AS id, xcore_mailinglist_id, email FROM xcore_newsletter_data WHERE deleted = 'no' AND action = 'sent' AND email = :email ORDER BY id DESC LIMIT 1", ['email' => $email])->fetch();

	}

	if(!$target)
	{

		$imap->command("UID STORE {$uid} +FLAGS (\\Seen)");
		$ignored++;
		continue;

	}

	$email = mb_strtolower($target['email']);
	\Core\Newsletter::log((int)$target['xcore_newsletter_id'], $target, 'bounce', "{$type}: {$diagnostic}");

	$blacklist = ($type === 'hard');
	if(!$blacklist)
		$blacklist = ((int)DB()->query("SELECT COUNT(*) FROM xcore_newsletter_data WHERE deleted = 'no' AND action = 'bounce' AND email = :email AND error_message LIKE 'soft:%'", ['email' => $email])->fetchOne() >= $soft_limit);

	if($blacklist && !DB()->query("SELECT 1 FROM xcore_blacklist WHERE deleted = 'no' AND email = :email", ['email' => $email])->fetchOne())
		DB('xcore_blacklist')->insert(['date' => now(), 'email' => $email], 'newsletter');

	if($type === 'hard')$hard++;
	else $soft++;
	$imap->command("UID STORE {$uid} +FLAGS (\\Deleted)");

}

$imap->command('EXPUNGE');
try
{
	$imap->command('LOGOUT');
}
catch(\Throwable)
{
	// some servers close before the tagged answer
}

echo count($uids)." message(s): {$hard} hard bounce(s), {$soft} soft bounce(s), {$ignored} ignored".CR;
