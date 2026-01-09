<?php
// public/includes/mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Composer autoloader (vendor is one level above /public)
require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Load mail configuration array.
 *
 * Expected keys in config/mail.php:
 *  - host
 *  - port
 *  - username
 *  - password
 *  - from_email
 *  - from_name
 *  - encryption ('tls', 'ssl', or '')
 *
 * @return array
 */
function sjst_get_mail_config()
{
    $configFile = __DIR__ . '/../../config/mail.php';

    if (!file_exists($configFile)) {
        throw new RuntimeException('Mail config file not found: ' . $configFile);
    }

    $config = require $configFile;

    if (!is_array($config)) {
        throw new RuntimeException('Mail config file must return an array.');
    }

    return $config;
}

/**
 * Send verification email via SMTP (PHPMailer).
 *
 * @param string $toEmail
 * @param string $username
 * @param string $verifyLink
 * @param string $errorMessage (by ref) – error text on failure
 * @return bool
 */
function sendVerificationEmail($toEmail, $username, $verifyLink, &$errorMessage)
{
    $errorMessage = '';

    try {
        $config = sjst_get_mail_config();
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        error_log('[SJSharkTank] Mail config error: ' . $e->getMessage());
        return false;
    } catch (RuntimeException $e) {
        $errorMessage = $e->getMessage();
        error_log('[SJSharkTank] Mail config error: ' . $e->getMessage());
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        /* ---------- SMTP setup ---------- */

        $mail->isSMTP();
        $mail->Host       = isset($config['host']) ? $config['host'] : '';
        $mail->SMTPAuth   = true;
        $mail->Username   = isset($config['username']) ? $config['username'] : '';
        $mail->Password   = isset($config['password']) ? $config['password'] : '';

        if (!empty($config['encryption'])) {
            $mail->SMTPSecure = $config['encryption']; // 'tls' or 'ssl'
        }

        $mail->Port = isset($config['port']) ? (int)$config['port'] : 587;

        // Optional debug while testing:
        // $mail->SMTPDebug  = 2;
        // $mail->Debugoutput = 'error_log';

        /* ---------- From / To ---------- */

        $fromEmail = isset($config['from_email']) ? $config['from_email'] : $config['username'];
        $fromName  = isset($config['from_name']) ? $config['from_name'] : 'SJ Shark Tank';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $username);

        /* ---------- Content ---------- */

        $mail->isHTML(true);
        $mail->Subject = 'Confirm your SJ Shark Tank account';

        $safeUsername   = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safeVerifyLink = htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8');

        $mail->Body = '
          <p>Hi ' . $safeUsername . ',</p>
          <p>Thanks for registering at <strong>SJ Shark Tank</strong>.</p>
          <p>Please click the button below to verify your email address:</p>
          <p>
            <a href="' . $safeVerifyLink . '"
               style="
                 display:inline-block;
                 padding:8px 14px;
                 background:#007b8a;
                 color:#ffffff;
                 text-decoration:none;
                 border-radius:4px;
                 font-weight:600;
               ">
              Verify my account
            </a>
          </p>
          <p>If the button doesn\'t work, copy and paste this URL into your browser:</p>
          <p><code>' . $safeVerifyLink . '</code></p>
          <p>If you didn\'t sign up, you can ignore this email.</p>
        ';

        $mail->AltBody =
            "Hi $username,\n\n" .
            "Thanks for registering at SJ Shark Tank.\n\n" .
            "Please open this link to verify your email address:\n\n" .
            $verifyLink . "\n\n" .
            "If you didn't sign up, you can ignore this email.\n";

        $mail->send();
        return true;
    } catch (Exception $e) {
        $errorMessage = $mail->ErrorInfo;
        error_log('[SJSharkTank] SMTP send failed: ' . $mail->ErrorInfo);
        return false;
    }
}
