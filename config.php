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
function send_email_smtp($to, $subject, $message, $headers = "") {
    // This is a stub for the actual SMTP integration
    // Do not call mail() because sendmail is not installed and it causes delays/errors in test environment.
    error_log("Dummy Email Sent to: $to | Subject: $subject");
    return true;
}

?>
