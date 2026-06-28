<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/helpers.php";

session_start();
header("Content-Type: application/json; charset=utf-8");

if (empty($_SESSION["fenix_access_token"]) || empty($_SESSION["person"])) {
  respond(401, ["ok" => false, "error" => "not_authenticated"]);
}

$person = $_SESSION["person"];
$istId  = $person["username"] ?? null;
if (!$istId) respond(400, ["ok" => false, "error" => "missing_ist_id"]);

try {
  $pdo = pdo_connect();

  $u = $pdo->prepare("SELECT id, role, ist_id FROM users WHERE ist_id = :ist_id LIMIT 1");
  $u->execute([":ist_id" => $istId]);
  $user = $u->fetch();
  if (!$user) respond(403, ["ok" => false, "error" => "user_not_in_db"]);

  $role = (string)$user["role"];
  $method = $_SERVER["REQUEST_METHOD"];

  if ($method === "GET") {
    $action = safe_string($_GET["action"] ?? "");
    if ($action !== "download") respond(400, ["ok" => false, "error" => "unknown_action"]);

    $pid = isset($_GET["pid"]) ? (int)$_GET["pid"] : 0;
    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_process_id"]);

    $base = realpath(dirname(__DIR__, 2));
    if ($base === false) respond(500, ["ok" => false, "error" => "bad_base"]);

    $dir     = $base . "/_private/final_generated_docs";
    $pattern = $dir . "/" . $pid . "_*.zip";
    $files   = glob($pattern);

    if (!$files || count($files) === 0) respond(404, ["ok" => false, "error" => "no_generated_docs"]);

    usort($files, fn($a, $b) => strcmp(basename($b), basename($a)));
    $latestFile = $files[0];

    $pq = $pdo->prepare("SELECT student_id FROM final_processes WHERE id = :pid LIMIT 1");
    $pq->execute([":pid" => $pid]);
    $processRow = $pq->fetch();
    if (!$processRow) respond(404, ["ok" => false, "error" => "process_not_found"]);

    $uq = $pdo->prepare("SELECT ist_id, name FROM users WHERE id = :uid LIMIT 1");
    $uq->execute([":uid" => $processRow["student_id"]]);
    $userRow = $uq->fetch();
    if (!$userRow) respond(404, ["ok" => false, "error" => "student_not_found"]);

    $istRaw      = $userRow["ist_id"] ?? "unknown";
    $ist         = strlen($istRaw) > 4 ? substr($istRaw, 4) : $istRaw;
    $name        = preg_replace('/[^a-zA-Z0-9 _-]/', '', $userRow["name"] ?? "user");
    $downloadName = $ist . "_" . $name . "_PEF.zip";

    header("Content-Type: application/zip");
    header("Content-Disposition: attachment; filename=\"" . $downloadName . "\"");
    header("Content-Length: " . filesize($latestFile));
    header("X-Content-Type-Options: nosniff");
    readfile($latestFile);
    exit;
  }

  if ($method === "POST") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($_POST["pid"]) ? (int)$_POST["pid"] : 0;
    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_process_id"]);

    if (!isset($_FILES["file"]) || !is_array($_FILES["file"])) {
      respond(400, ["ok" => false, "error" => "missing_file"]);
    }

    $f = $_FILES["file"];
    if (!isset($f["error"]) || $f["error"] !== UPLOAD_ERR_OK) {
      respond(400, ["ok" => false, "error" => "upload_error", "code" => $f["error"] ?? -1]);
    }

    $tmp  = $f["tmp_name"] ?? "";
    if ($tmp === "" || !is_uploaded_file($tmp)) respond(400, ["ok" => false, "error" => "invalid_file"]);

    $originalName = safe_string($f["name"] ?? "pef.zip");
    $sizeBytes    = isset($f["size"]) ? (int)$f["size"] : 0;
    if ($sizeBytes <= 0) respond(400, ["ok" => false, "error" => "empty_file"]);

    $chk = $pdo->prepare("SELECT id, payload_json FROM final_processes WHERE id = :pid LIMIT 1");
    $chk->execute([":pid" => $pid]);
    $finalProc = $chk->fetch();
    if (!$finalProc) respond(404, ["ok" => false, "error" => "no_process"]);

    $base = realpath(dirname(__DIR__, 2));
    if ($base === false) respond(500, ["ok" => false, "error" => "bad_base"]);

    $dir = $base . "/_private/final_generated_docs";
    if (!is_dir($dir)) {
      if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        respond(500, ["ok" => false, "error" => "cannot_create_directory"]);
      }
    }

    $fileName = $pid . "_" . date("Ymd_His") . ".zip";
    $path     = $dir . "/" . $fileName;

    if (!@move_uploaded_file($tmp, $path)) {
      respond(500, ["ok" => false, "error" => "cannot_save_file"]);
    }

    if (!empty($finalProc["payload_json"])) {
      $decoded = json_decode($finalProc["payload_json"], true);
      if (is_array($decoded) && isset($decoded["personal"]["signature_png_base64"])) {
        unset($decoded["personal"]["signature_png_base64"]);
        $upd = $pdo->prepare("UPDATE final_processes SET payload_json = :payload_json WHERE id = :pid LIMIT 1");
        $upd->execute([":payload_json" => json_encode($decoded, JSON_UNESCAPED_UNICODE), ":pid" => $pid]);
      }
    }

    respond(200, ["ok" => true, "pid" => $pid, "filename" => $fileName, "size_bytes" => $sizeBytes]);
  }

  respond(405, ["ok" => false, "error" => "method_not_allowed"]);

} catch (Throwable $e) {
  respond(500, ["ok" => false, "error" => "server_error"]);
}
