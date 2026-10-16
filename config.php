<?php
// config.php

// API Keys
$API_KEY_GOOGLE_MAPS = "";
$API_KEY_MERCADO_PAGO = "";
$KEY_mp_php_Test = "";

// Admin users
$admin_emails = [
    'nanachesco@gmail.com',
    'franchesco@gmail.com'
];

// SMTP Email Sender (Dummy implementation as requested, wrap around actual mail server later if needed)
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_email_smtp($to, $subject, $message, $headers = "") {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.example.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER') ?: 'user@example.com';
        $mail->Password   = getenv('SMTP_PASS') ?: 'secret';
        $mail->SMTPSecure = getenv('SMTP_SECURE') ?: PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = getenv('SMTP_PORT') ?: 587;

        // Default Sender
        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: 'info@perchero.store';
        $fromName = getenv('SMTP_FROM_NAME') ?: 'Perchero';
        $mail->setFrom($fromEmail, $fromName);

        // Helper to extract email
        $extractEmail = function($val) {
            if (preg_match('/<([^>]+)>/', $val, $matches)) {
                return trim($matches[1]);
            }
            return trim($val);
        };

        // Parse headers
        $isHtml = false;
        if (!empty($headers)) {
            if (is_array($headers)) {
                $headerLines = [];
                foreach ($headers as $key => $val) {
                    $headerLines[] = is_int($key) ? $val : "$key: $val";
                }
            } else {
                $headerLines = preg_split("/\r\n|\n|\r/", $headers);
            }

            foreach ($headerLines as $line) {
                if (stripos($line, 'From:') === 0) {
                    $fromVal = trim(substr($line, 5));
                    $mail->setFrom($extractEmail($fromVal));
                } elseif (stripos($line, 'Cc:') === 0) {
                    $ccVal = trim(substr($line, 3));
                    $mail->addCC($extractEmail($ccVal));
                } elseif (stripos($line, 'Bcc:') === 0) {
                    $bccVal = trim(substr($line, 4));
                    $mail->addBCC($extractEmail($bccVal));
                } elseif (stripos($line, 'Content-Type:') === 0 && stripos($line, 'text/html') !== false) {
                    $isHtml = true;
                }
            }
        }

        // Recipients
        $mail->addAddress($to);

        // Content
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->AltBody = strip_tags($message);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

?>
