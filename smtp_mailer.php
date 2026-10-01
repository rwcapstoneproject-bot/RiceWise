<?php
/**
 * smtp_mailer.php
 * ---------------------------------------------------------------
 * A small, dependency-free SMTP client — no PHPMailer, no Composer,
 * nothing to upload beyond this one file. Handles exactly what
 * RiceWise needs: connect, STARTTLS, AUTH LOGIN, send one HTML
 * email to one recipient, quit.
 *
 * Requires mail_config.php (SMTP_HOST, SMTP_PORT, SMTP_USER,
 * SMTP_PASS, SMTP_FROM_NAME) to be filled in with real credentials.
 * ---------------------------------------------------------------
 */
require_once __DIR__ . '/mail_config.php';


/**
 * Reads one full SMTP response, following the multi-line convention
 * (lines continue with "250-", the final line uses "250 ").
 */
function rwSmtpReadResponse($socket)
{
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

/**
 * Sends one command (a CRLF is appended automatically) and returns
 * the server's full response.
 */
function rwSmtpCommand($socket, $cmd)
{
    fwrite($socket, $cmd . "\r\n");
    return rwSmtpReadResponse($socket);
}

/**
 * Sends a single HTML email via SMTP using the credentials in
 * mail_config.php.
 *
 * @return array{success: bool, error: string|null}
 */
function sendSmtpEmail(string $toEmail, string $toName, string $subject, string $htmlBody): array
{
    $timeout = 15;
    $errno = 0;
    $errstr = '';

    if (!function_exists('fsockopen')) {
        return ['success' => false, 'error' => 'fsockopen() is not available on this server (disabled by host).'];
    }

    $host = ((int)SMTP_PORT === 465) ? 'ssl://' . SMTP_HOST : SMTP_HOST;

    $socket = @fsockopen($host, (int)SMTP_PORT, $errno, $errstr, $timeout);
    if (!$socket) {
        return ['success' => false, 'error' => "Could not connect to SMTP server: $errstr ($errno)"];
    }
    stream_set_timeout($socket, $timeout);

    $greeting = rwSmtpReadResponse($socket);
    if (substr($greeting, 0, 3) !== '220') {
        fclose($socket);
        return ['success' => false, 'error' => "Unexpected greeting: $greeting"];
    }

    $heloDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $resp = rwSmtpCommand($socket, "EHLO $heloDomain");
    if (substr($resp, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'error' => "EHLO failed: $resp"];
    }

    if ((int)SMTP_PORT === 587) {
        $resp = rwSmtpCommand($socket, 'STARTTLS');
        if (substr($resp, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'error' => "STARTTLS failed: $resp"];
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['success' => false, 'error' => 'TLS negotiation failed.'];
        }
        $resp = rwSmtpCommand($socket, "EHLO $heloDomain");
        if (substr($resp, 0, 3) !== '250') {
            fclose($socket);
            return ['success' => false, 'error' => "EHLO after STARTTLS failed: $resp"];
        }
    }

    $resp = rwSmtpCommand($socket, 'AUTH LOGIN');
    if (substr($resp, 0, 3) !== '334') {
        fclose($socket);
        return ['success' => false, 'error' => "AUTH LOGIN not accepted: $resp"];
    }

    $resp = rwSmtpCommand($socket, base64_encode(SMTP_USER));
    if (substr($resp, 0, 3) !== '334') {
        fclose($socket);
        return ['success' => false, 'error' => "Username not accepted: $resp"];
    }

    $resp = rwSmtpCommand($socket, base64_encode(SMTP_PASS));
    if (substr($resp, 0, 3) !== '235') {
        fclose($socket);
        return ['success' => false, 'error' => "Authentication failed — check SMTP_USER/SMTP_PASS in mail_config.php: $resp"];
    }

    $resp = rwSmtpCommand($socket, 'MAIL FROM:<' . SMTP_USER . '>');
    if (substr($resp, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'error' => "MAIL FROM rejected: $resp"];
    }

    $resp = rwSmtpCommand($socket, "RCPT TO:<$toEmail>");
    if (substr($resp, 0, 3) !== '250' && substr($resp, 0, 3) !== '251') {
        fclose($socket);
        return ['success' => false, 'error' => "RCPT TO rejected: $resp"];
    }

    $resp = rwSmtpCommand($socket, 'DATA');
    if (substr($resp, 0, 3) !== '354') {
        fclose($socket);
        return ['success' => false, 'error' => "DATA not accepted: $resp"];
    }

    $body = str_replace(["\r\n", "\r", "\n"], "\n", $htmlBody);
    $body = str_replace("\n", "\r\n", $body);
    $body = preg_replace('/(^|\r\n)\./', '$1..', $body);

    $fromHeader = SMTP_FROM_NAME . ' <' . SMTP_USER . '>';
    $toHeader = $toName !== '' ? "$toName <$toEmail>" : $toEmail;
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $message = "From: $fromHeader\r\n" .
        "To: $toHeader\r\n" .
        "Subject: $encodedSubject\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/html; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: 8bit\r\n" .
        "\r\n" .
        $body . "\r\n.";

    $resp = rwSmtpCommand($socket, $message);
    if (substr($resp, 0, 3) !== '250') {
        fclose($socket);
        return ['success' => false, 'error' => "Message not accepted by server: $resp"];
    }

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return ['success' => true, 'error' => null];
}

/**
 * Builds and sends the actual password-reset email. Kept separate
 * from sendSmtpEmail() so forgot_password_process.php only needs to
 * call this one function with the data it already has.
 */
function sendPasswordResetEmail(string $toEmail, string $toName, string $resetLink): array
{
    $subject = 'Reset your RiceWise password';
    $safeName = htmlspecialchars($toName ?: 'there', ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#1e2a1a;">
  <h2 style="color:#3d5c38;margin-bottom:4px;">🌾 RiceWise</h2>
  <p>Hi {$safeName},</p>
  <p>We received a request to reset your RiceWise password. Click the button below to choose a new one:</p>
  <p style="text-align:center;margin:28px 0;">
    <a href="{$safeLink}" style="background:#5a7a52;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;display:inline-block;">Reset Password</a>
  </p>
  <p style="font-size:13px;color:#5a6655;">Or copy and paste this link into your browser:<br>
    <a href="{$safeLink}" style="color:#5a7a52;word-break:break-all;">{$safeLink}</a>
  </p>
  <p style="font-size:12.5px;color:#94a18e;margin-top:24px;">This link expires in 15 minutes and can only be used once. If you didn't request this, you can safely ignore this email — your password won't be changed.</p>
</div>
HTML;

    return sendSmtpEmail($toEmail, $toName, $subject, $html);
}