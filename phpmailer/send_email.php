<?php
// Start the session at the very beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// For debugging purposes, you can enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/src/Exception.php';
require_once __DIR__ . '/src/PHPMailer.php';
require_once __DIR__ . '/src/SMTP.php';

// Include configuration file
require_once __DIR__ . '/../config.php';

/**
 * Sends an email using PHPMailer and SMTP.
 *
 * @param string $to The recipient's email address.
 * @param string $subject The subject of the email.
 * @param string $body The HTML body of the email.
 * @return bool True on success, false on failure.
 */
function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        // SMTP configuration from config.php
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE_METHOD;
        $mail->Port       = SMTP_PORT;

        // Sender information
        $mail->setFrom(APP_EMAIL_FROM, APP_NAME);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the error for debugging
        error_log("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Sends a welcome email after a user's account is verified.
 * @param string $recipientEmail The recipient's email address.
 * @param string $recipientName The recipient's display name.
 * @return bool True on success, false on failure.
 */
function sendWelcomeEmail($recipientEmail, $recipientName) {
    if (!defined('APP_NAME') || !defined('APP_URL') || !defined('APP_EMAIL_FROM')) {
        error_log("Cannot send welcome email: missing required constants.");
        return false;
    }

    $subject = "Welcome to " . APP_NAME . "!";
    $link = APP_URL . "/login.php";
    
    $templatePath = __DIR__ . '/../emails/welcome_email.html';
    if (!file_exists($templatePath) || filesize($templatePath) === 0) {
        error_log("Welcome email template not found or is empty at: " . $templatePath);
        return false;
    }

    $template = file_get_contents($templatePath);
    $body = str_replace(
        ['{{app_name}}', '{{name}}', '{{login_link}}'], 
        [htmlspecialchars(APP_NAME), htmlspecialchars($recipientName), htmlspecialchars($link)],
        $template
    );
    
    return sendEmail($recipientEmail, $subject, $body);
}

/**
 * Sends a business welcome email after a business user's account is verified.
 * @param string $recipientEmail The recipient's email address.
 * @param string $recipientName The recipient's display name.
 * @param string $businessName The name of the registered business.
 * @return bool True on success, false on failure.
 */
function sendBusinessWelcomeEmail($recipientEmail, $recipientName, $businessName) {
    if (!defined('APP_NAME') || !defined('APP_URL') || !defined('APP_EMAIL_FROM')) {
        error_log("Cannot send business welcome email: missing required constants.");
        return false;
    }

    $subject = "Welcome to " . APP_NAME . " Business! 🎉";
    $loginLink = APP_URL . "/login.php";
    $kycLink = APP_URL . "/kyc.php";
    $dashboardLink = "https://shop.naijabased.fun";
    
    $templatePath = __DIR__ . '/../emails/business_welcome_email.html';
    if (!file_exists($templatePath) || filesize($templatePath) === 0) {
        error_log("Business welcome email template not found or is empty at: " . $templatePath);
        return false;
    }

    $template = file_get_contents($templatePath);
    $body = str_replace(
        ['{{app_name}}', '{{name}}', '{{business_name}}', '{{login_link}}', '{{kyc_link}}', '{{dashboard_link}}'], 
        [
            htmlspecialchars(APP_NAME), 
            htmlspecialchars($recipientName), 
            htmlspecialchars($businessName),
            htmlspecialchars($loginLink),
            htmlspecialchars($kycLink),
            htmlspecialchars($dashboardLink)
        ],
        $template
    );
    
    return sendEmail($recipientEmail, $subject, $body);
}

/**
 * Sends a verification email to a newly registered user.
 * @param string $recipientEmail The user's email address.
 * @param string $recipientName The user's display name.
 * @param string $token The unique verification token.
 * @return bool True if the email was sent successfully, false otherwise.
 */
function sendVerificationEmail($recipientEmail, $recipientName, $token) {
    if (!defined('APP_NAME') || !defined('APP_URL') || !defined('APP_EMAIL_FROM')) {
        error_log("Cannot send verification email: missing required constants.");
        return false;
    }

    $subject = "Verify Your NaijaBased Account!";
    $link = APP_URL . "/verify.php?token=" . urlencode($token);

    $templatePath = __DIR__ . '/../emails/verification_email.html';
    if (!file_exists($templatePath) || filesize($templatePath) === 0) {
        error_log("Verification email template not found or is empty at: " . $templatePath);
        return false;
    }

    $template = file_get_contents($templatePath);
    $body = str_replace(
        ['{{name}}', '{{verification_link}}'], 
        [htmlspecialchars($recipientName), htmlspecialchars($link)],
        $template
    );

    return sendEmail($recipientEmail, $subject, $body);
}

/**
 * Sends a business verification email to a newly registered business user.
 * @param string $recipientEmail The user's email address.
 * @param string $recipientName The user's display name.
 * @param string $token The unique verification token.
 * @param string $businessName The name of the registered business.
 * @return bool True if the email was sent successfully, false otherwise.
 */
function sendBusinessVerificationEmail($recipientEmail, $recipientName, $token, $businessName) {
    if (!defined('APP_NAME') || !defined('APP_URL') || !defined('APP_EMAIL_FROM')) {
        error_log("Cannot send business verification email: missing required constants.");
        return false;
    }

    $subject = "Verify Your Email - Welcome to NaijaBased Business!";
    $verificationLink = APP_URL . "/verify.php?token=" . urlencode($token);
    $kycLink = APP_URL . "/kyc.php";
    $dashboardLink = "https://shop.naijabased.fun";

    $templatePath = __DIR__ . '/../emails/business_verification_email.html';
    if (!file_exists($templatePath) || filesize($templatePath) === 0) {
        error_log("Business verification email template not found or is empty at: " . $templatePath);
        return false;
    }

    $template = file_get_contents($templatePath);
    $body = str_replace(
        ['{{name}}', '{{business_name}}', '{{verification_link}}', '{{kyc_link}}', '{{dashboard_link}}'], 
        [
            htmlspecialchars($recipientName), 
            htmlspecialchars($businessName),
            htmlspecialchars($verificationLink),
            htmlspecialchars($kycLink),
            htmlspecialchars($dashboardLink)
        ],
        $template
    );

    return sendEmail($recipientEmail, $subject, $body);
}