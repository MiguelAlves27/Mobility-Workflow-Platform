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

$contentType = strtolower((string)($_SERVER["CONTENT_TYPE"] ?? $_SERVER["HTTP_CONTENT_TYPE"] ?? ""));
$body = null;

if (strpos($contentType, "application/json") !== false) {
  $raw = file_get_contents("php://input");
  $decoded = json_decode((string)$raw, true);
  if (is_array($decoded)) $body = $decoded;
} else {
  if (!empty($_POST) && is_array($_POST)) $body = $_POST;
}

if (!is_array($body)) {
  respond(400, ["ok" => false, "error" => "invalid_json"]);
}

$pid          = (int)($body["pid"] ?? 0);
$studentEmail = safe_string($body["student_email"] ?? "");
$studentName  = safe_string($body["student_name"] ?? "");
$comment      = safe_string($body["global_comment"] ?? "");
$customZipName = safe_string($body["zip_name"] ?? "");

if ($pid <= 0)           respond(400, ["ok" => false, "error" => "invalid_process_id"]);
if ($studentEmail === "") respond(400, ["ok" => false, "error" => "missing_student_email"]);

try {
  $pdo = pdo_connect();

  $person = $_SESSION["person"];
  $istId  = $person["username"] ?? null;
  if (!$istId) respond(400, ["ok" => false, "error" => "missing_ist_id"]);

  $u = $pdo->prepare("SELECT id, role FROM users WHERE ist_id = :ist_id LIMIT 1");
  $u->execute([":ist_id" => $istId]);
  $user = $u->fetch();
  if (!$user) respond(403, ["ok" => false, "error" => "user_not_in_db"]);

  $role = (string)$user["role"];
  if ($role !== "COORDINATOR" && $role !== "ADMIN") {
    respond(403, ["ok" => false, "error" => "forbidden"]);
  }

  $chk = $pdo->prepare("SELECT id FROM final_processes WHERE id = :pid LIMIT 1");
  $chk->execute([":pid" => $pid]);
  if (!$chk->fetch()) respond(404, ["ok" => false, "error" => "no_process"]);

  // Locate the generated PEF ZIP
  $base = realpath(dirname(__DIR__, 2));
  if ($base === false) respond(500, ["ok" => false, "error" => "bad_base"]);

  $dir     = $base . "/_private/final_generated_docs";
  $pattern = $dir . "/" . $pid . "_*.zip";
  $files   = glob($pattern);

  $zipBytes    = null;
  $zipFileName = $customZipName !== "" ? $customZipName : ("pef_" . $pid . ".zip");

  if ($files && count($files) > 0) {
    usort($files, fn($a, $b) => strcmp(basename($b), basename($a)));
    $latestFile = $files[0];

    if (is_file($latestFile) && is_readable($latestFile)) {
      $zipBytes    = file_get_contents($latestFile);
      $zipFileName = $customZipName !== "" ? $customZipName : basename($latestFile);
    }
  }

  $config = load_mail_config();

  $subject  = "Your final mobility process (PEF) has been approved";
  $greeting = $studentName !== "" ? ("Hello " . $studentName . ",") : "Hello,";

  $commentText = "";
  $commentHtml = "";
  if ($comment !== "") {
    $safeComment = htmlspecialchars($comment, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    $commentText = "\nComment from coordinator:\n" . $comment . "\n";
    $commentHtml = "<p><strong>Comment from coordinator:</strong><br>" . nl2br($safeComment) . "</p>";
  }

  $attachNote      = $zipBytes ? "The PEF document is attached to this email." : "The documents will be available on the platform.";
  $attachNoteHtml  = $zipBytes ? "The PEF document is attached to this email." : "The documents will be available on the platform.";

  $text =
    $greeting . "\n\n" .
    "Your final mobility process (PEF) " . $pid . " has been approved by the coordinator.\n" .
    $attachNote . "\n\n" .
    $commentText .
    "Best regards,\nMobility Website";

  $html =
    "<p>" . htmlspecialchars($greeting, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . "</p>" .
    "<p>Your final mobility process (PEF) <strong>" .
    htmlspecialchars((string)$pid, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
    "</strong> has been approved by the coordinator.<br>" .
    htmlspecialchars($attachNoteHtml, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") . "</p>" .
    $commentHtml .
    "<p>Best regards,<br>Mobility Website</p>";

  $mail = make_mailer($config);
  $mail->addAddress($studentEmail, $studentName);
  $mail->Subject = $subject;
  $mail->isHTML(true);
  $mail->Body    = $html;
  $mail->AltBody = $text;

  if ($zipBytes && $zipBytes !== "") {
    $mail->addStringAttachment((string)$zipBytes, $zipFileName, "base64", "application/zip");
  }

  $mail->send();

  respond(200, ["ok" => true, "message" => "Email successfully sent"]);

} catch (Exception $e) {
  respond(500, ["ok" => false, "error" => "mailer_error", "message" => $e->getMessage()]);
} catch (Throwable $e) {
  respond(500, ["ok" => false, "error" => "server_error"]);
}
