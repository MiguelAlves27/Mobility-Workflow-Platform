<?php
declare(strict_types=1);

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function load_mail_config(): array {
  $secretsPath = dirname(__DIR__, 2) . '/_private/gmail_aouth.php';
  if (!file_exists($secretsPath)) {
    respond(500, ["ok" => false, "error" => "secrets_file_not_found"]);
  }
  $config = require $secretsPath;
  foreach (["smtp_host", "smtp_port", "smtp_user", "smtp_pass", "from_email", "from_name"] as $key) {
    if (!isset($config[$key]) || $config[$key] === "") {
      respond(500, ["ok" => false, "error" => "missing_configuration_key", "key" => $key]);
    }
  }
  return $config;
}

function make_mailer(array $config): PHPMailer {
  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host      = (string)$config["smtp_host"];
  $mail->SMTPAuth  = true;
  $mail->Username  = (string)$config["smtp_user"];
  $mail->Password  = (string)$config["smtp_pass"];
  $port            = (int)$config["smtp_port"];
  $mail->Port      = $port;
  $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
  $mail->CharSet   = "UTF-8";
  $mail->setFrom((string)$config["from_email"], (string)$config["from_name"]);
  return $mail;
}
