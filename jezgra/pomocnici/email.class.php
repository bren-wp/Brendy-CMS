<?php defined('BRENDY') or die('Brendy CMS.');

class Email {

	// Returns TRUE if the mail was successfully accepted for delivery, FALSE otherwise.
	public static function send($args)
	{
		if (!is_array($args)) return false;
		foreach (array('fromName', 'from', 'to', 'subject', 'message') as $field) {
			if (!isset($args[$field]) || !is_string($args[$field])) return false;
		}
		if (!filter_var($args['from'], FILTER_VALIDATE_EMAIL) ||
			!filter_var($args['to'], FILTER_VALIDATE_EMAIL) ||
			preg_match('/[\r\n]/', $args['fromName'] . $args['from'] . $args['to'] . $args['subject']) ||
			strlen($args['fromName']) > 200 || strlen($args['subject']) > 998 ||
			strlen($args['message']) > 10485760) {
			return false;
		}
		// Current time in unixtimestamp
		$now = time();

		// Domain
		$domainParse = parse_url(DOMAIN);
		$host = is_array($domainParse) && isset($domainParse['host']) &&
			preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $domainParse['host']) === 1
			? $domainParse['host'] : 'localhost';

		$headers   = array();
		$headers[] = 'MIME-Version: 1.0';
		$headers[] = 'Content-type: text/html; charset=utf-8';
		$headers[] = 'Content-Transfer-Encoding: 8bit';

		$headers[] = 'From: =?UTF-8?B?'.base64_encode($args['fromName']).'?= <'.$args['from'].'>';
		$headers[] = 'Reply-To: '.$args['from'];
		$headers[] = 'Return-Path: '.$args['from'];
		$headers[] = 'Message-ID: <' . $now . '.' . bin2hex(random_bytes(8)) . '@' . $host . '>';
		$headers[] = 'X-Mailer: PHP/'.phpversion();

		$subject = '=?UTF-8?B?'.base64_encode($args['subject']).'?=';

		$message = '<html>
		<head>
			<meta charset="UTF-8">
			<title>'.htmlspecialchars(BRENDY_NAME, ENT_QUOTES | ENT_SUBSTITUTE, CHARSET).'</title>
		</head>
		<body>
		<div>
			'.$args['message'].'
		</div>
		</body>
		</html>';

		return mail($args['to'], $subject, $message, implode(PHP_EOL, $headers));
	}

}
