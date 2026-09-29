<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// Sends a plain-text email over SMTP with STARTTLS + AUTH LOGIN — enough
// to talk to Gmail SMTP (smtp.gmail.com:587) without any external library.
// Gmail requires an App Password here, not a normal account password —
// generate one at https://myaccount.google.com/apppasswords (requires
// 2-Step Verification to be enabled on that Google account).
function send_email(string $to, string $subject, string $body): bool
{
    $host = getenv('SMTP_HOST') ?: '';
    $port = (int)(getenv('SMTP_PORT') ?: 587);
    $username = getenv('SMTP_USERNAME') ?: '';
    $password = getenv('SMTP_PASSWORD') ?: '';
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: $username;
    $fromName = getenv('SMTP_FROM_NAME') ?: 'Links Directory';

    if ($host === '' || $username === '' || $password === '') {
        error_log('send_email: SMTP is not configured (missing SMTP_HOST/SMTP_USERNAME/SMTP_PASSWORD).');
        return false;
    }

    $timeout = 10;
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    if (!$socket) {
        error_log("send_email: could not connect to {$host}:{$port} — {$errstr}");
        return false;
    }
    stream_set_timeout($socket, $timeout);

    $readResponse = function () use ($socket): string {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $sendCommand = function (string $cmd) use ($socket): void {
        fwrite($socket, $cmd . "\r\n");
    };
    $expect = function (string $response, string $code) use ($socket): bool {
        return strpos($response, $code) === 0;
    };

    $readResponse(); // server greeting

    $sendCommand('EHLO localhost');
    $readResponse();

    $sendCommand('STARTTLS');
    if (!$expect($readResponse(), '220')) {
        fclose($socket);
        error_log('send_email: STARTTLS was refused.');
        return false;
    }

    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($socket);
        error_log('send_email: TLS handshake failed.');
        return false;
    }

    $sendCommand('EHLO localhost');
    $readResponse();

    $sendCommand('AUTH LOGIN');
    $readResponse();
    $sendCommand(base64_encode($username));
    $readResponse();
    $sendCommand(base64_encode($password));
    if (!$expect($readResponse(), '235')) {
        fclose($socket);
        error_log('send_email: SMTP authentication failed — check SMTP_USERNAME/SMTP_PASSWORD (Gmail needs an App Password, not the account password).');
        return false;
    }

    $sendCommand("MAIL FROM:<{$fromEmail}>");
    $readResponse();
    $sendCommand("RCPT TO:<{$to}>");
    if (!$expect($readResponse(), '250')) {
        fclose($socket);
        error_log("send_email: recipient {$to} was rejected.");
        return false;
    }

    $sendCommand('DATA');
    $readResponse();

    $headers = "From: {$fromName} <{$fromEmail}>\r\n"
        . "To: <{$to}>\r\n"
        . "Subject: {$subject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "\r\n";

    // Per SMTP DATA rules, a line consisting of just "." ends the message,
    // so any line in the body that starts with "." must be escaped.
    $escapedBody = preg_replace('/^\./m', '..', $body);

    $sendCommand($headers . $escapedBody . "\r\n.");
    $success = $expect($readResponse(), '250');

    $sendCommand('QUIT');
    fclose($socket);

    if (!$success) {
        error_log('send_email: server did not accept the message after DATA.');
    }
    return $success;
}
