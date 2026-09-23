<?php
// lib/mailer.php — sends email through SMTP (Hostinger mailbox) or PHP mail().
// No extra libraries needed.

function send_mail($toEmail, $toName, $subject, $html, $text) {
    if (MAIL_DRIVER === 'smtp') {
        smtp_send($toEmail, $toName, $subject, $html, $text);
    } else {
        php_mail_send($toEmail, $toName, $subject, $html, $text);
    }
}

function mail_encode_header($s) {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function build_mime($toEmail, $toName, $subject, $html, $text) {
    $boundary = 'b' . bin2hex(random_bytes(12));
    $from = mail_encode_header(MAIL_FROM_NAME) . ' <' . MAIL_FROM . '>';
    $to   = $toName ? mail_encode_header($toName) . " <$toEmail>" : $toEmail;
    $h  = "From: $from\r\n";
    $h .= "To: $to\r\n";
    $h .= 'Subject: ' . mail_encode_header($subject) . "\r\n";
    $h .= 'Date: ' . date('r') . "\r\n";
    $h .= 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr(MAIL_FROM, '@'), 1) . ">\r\n";
    $h .= "MIME-Version: 1.0\r\n";
    $h .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
           . chunk_split(base64_encode($text)) . "\r\n";
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
           . chunk_split(base64_encode($html)) . "\r\n";
    $body .= "--$boundary--\r\n";
    return [$h, $body];
}

function php_mail_send($toEmail, $toName, $subject, $html, $text) {
    [$h, $body] = build_mime($toEmail, $toName, $subject, $html, $text);
    // mail() adds To and Subject itself
    $h = preg_replace('/^(To|Subject):.*\r\n/m', '', $h);
    if (!mail($toEmail, mail_encode_header($subject), $body, $h, '-f' . MAIL_FROM)) {
        throw new Exception('PHP mail() failed');
    }
}

function smtp_send($toEmail, $toName, $subject, $html, $text) {
    $secure = ((int)SMTP_PORT === 465) ? 'ssl://' : '';
    $fp = @stream_socket_client($secure . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 20);
    if (!$fp) throw new Exception("SMTP connect failed: $errstr ($errno)");
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($c, $expect) use ($fp, $read) {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $r = $read();
        if ((int)substr($r, 0, 3) !== $expect) {
            throw new Exception('SMTP error after "' . (strpos((string)$c, 'AUTH') === 0 ? 'AUTH' : substr((string)$c, 0, 20)) . '": ' . trim($r));
        }
        return $r;
    };

    try {
        $host = parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';
        $cmd(null, 220);
        $cmd("EHLO $host", 250);
        if ($secure === '') { // port 587: upgrade to TLS
            $cmd('STARTTLS', 220);
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd("EHLO $host", 250);
        }
        $cmd('AUTH LOGIN', 334);
        $cmd(base64_encode(SMTP_USER), 334);
        $cmd(base64_encode(SMTP_PASS), 235);
        $cmd('MAIL FROM:<' . MAIL_FROM . '>', 250);
        $cmd("RCPT TO:<$toEmail>", 250);
        $cmd('DATA', 354);
        [$h, $body] = build_mime($toEmail, $toName, $subject, $html, $text);
        $msg = $h . "\r\n" . $body;
        $msg = preg_replace('/^\./m', '..', $msg); // dot-stuffing
        fwrite($fp, $msg . "\r\n.\r\n");
        $cmd(null, 250);
        $cmd('QUIT', 221);
    } finally {
        fclose($fp);
    }
}
