<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/email_helpers.php";

session_start();
header("Content-Type: application/json; charset=utf-8");

if (empty($_SESSION["fenix_access_token"]) || empty($_SESSION["person"])) {
  respond(401, ["ok" => false, "error" => "not_authenticated"]);
}

$body = null;

$contentType = $_SERVER["CONTENT_TYPE"] ?? $_SERVER["HTTP_CONTENT_TYPE"] ?? "";
$contentType = strtolower((string)$contentType);

if (strpos($contentType, "application/json") !== false) {
  $raw = file_get_contents("php://input");
  $decoded = json_decode((string)$raw, true);
  if (is_array($decoded)) $body = $decoded;
} else {
  if (!empty($_POST) && is_array($_POST)) $body = $_POST;
}

if (!is_array($body)) {
  respond(400, [
    "ok" => false,
    "error" => "invalid_json",
    "content_type" => $contentType
  ]);
}

$pid = (int)($body["pid"] ?? 0);
$studentEmail = safe_string($body["student_email"] ?? "");
$studentName = safe_string($body["student_name"] ?? "");
$comment = safe_string($body["global_comment"] ?? "");

if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_process_id"]);
if ($studentEmail === "") respond(400, ["ok" => false, "error" => "missing_student_email"]);

try {
  $pdo = pdo_connect();

  $person = $_SESSION["person"];
  $istId = $person["username"] ?? null;
  if (!$istId) respond(400, ["ok" => false, "error" => "missing_ist_id"]);

  $u = $pdo->prepare("SELECT id, role, ist_id FROM users WHERE ist_id = :ist_id LIMIT 1");
  $u->execute([":ist_id" => $istId]);
  $user = $u->fetch();

  if (!$user) respond(403, ["ok" => false, "error" => "user_not_in_db"]);

  $role = (string)$user["role"];
  if ($role !== "COORDINATOR" && $role !== "ADMIN") {
    respond(403, ["ok" => false, "error" => "forbidden"]);
  }

  $chk = $pdo->prepare("SELECT id FROM processes WHERE id = :pid LIMIT 1");
  $chk->execute([":pid" => $pid]);
  if (!$chk->fetch()) {
    respond(404, ["ok" => false, "error" => "no_process"]);
  }

  // base path
  $base = realpath(dirname(__DIR__, 2));
  if ($base === false) {
    respond(500, ["ok" => false, "error" => "bad_base"]);
  }

  $dir = $base . "/_private/generated_docs";

  if (!is_dir($dir)) {
    respond(404, ["ok" => false, "error" => "zip_not_found"]);
  }

  $pattern = $dir . "/" . $pid . "_*.zip";
  $files = glob($pattern);

  if (!$files || count($files) === 0) {
    respond(404, ["ok" => false, "error" => "zip_not_found"]);
  }

  usort($files, function ($a, $b) {
    return strcmp(basename($b), basename($a));
  });

  $latestFile = $files[0];

  if (!is_file($latestFile) || !is_readable($latestFile)) {
    respond(500, ["ok" => false, "error" => "file_not_readable"]);
  }

  $customZipName = safe_string($body["zip_name"] ?? "");

  $originalZipName = basename($latestFile);
  $zipFileName = $customZipName !== "" 
    ? $customZipName 
    : $originalZipName;
    
  $zipMime = "application/zip";
  $zipBytes = file_get_contents($latestFile);

  if ($zipBytes === false || $zipBytes === "") {
    respond(500, ["ok" => false, "error" => "cannot_read_file"]);
  }

  $config = load_mail_config();

  $subject = "Initial documents generated";
  
  $greeting = $studentName !== "" ? ("Hello " . $studentName . ",") : "Hello,";

  $commentText = "";
  $commentHtml = "";

  if ($comment !== "") {
    $safeComment = htmlspecialchars($comment, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");

    $commentText =
      "\nComment from coordinator:\n" .
      $comment . "\n";

    $commentHtml =
      "<p><strong>Comment from coordinator:</strong><br>" .
      nl2br($safeComment) .
      "</p>";
  }
  
  $text =
    $greeting . "\n\n" .
    "The initial documents for process " . $pid . " have been generated.\n" .
    "The files are attached to this email.\n\n" .
    $commentText .
    "Please do not reply to this e-mail" .
    "Best regards,\n" .
    "Mobility Website";
  
  $html =
    "<p>" . htmlspecialchars($greeting, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . "</p>" .
    "<p>The initial documents for process <strong>" . htmlspecialchars((string)$pid, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . "</strong> have been generated.<br>" .
    "The files are attached to this email.</p>" .
    $commentHtml .
    "<p>Please do not reply to this e-mail</p>" .
    "<p>Best regards,<br>Mobility Website</p>";
  
  $mail = make_mailer($config);
  $mail->addAddress($studentEmail, $studentName);

  $mail->Subject = $subject;
  $mail->isHTML(true);
  $mail->Body = $html;
  $mail->AltBody = $text;

  $mail->addStringAttachment((string)$zipBytes, $zipFileName, "base64", $zipMime);

  $mail->send();

  respond(200, ["ok" => true, "message" => "Email successfully sent"]);

} catch (Exception $e) {
  respond(500, ["ok" => false, "error" => "mailer_error", "message" => $e->getMessage()]);
} catch (Throwable $e) {
  respond(500, ["ok" => false, "error" => "server_error"]);
}
