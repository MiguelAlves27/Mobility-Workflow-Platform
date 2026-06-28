<?php
declare(strict_types=1);

session_start();

function fail(int $code, string $msg): void {
  http_response_code($code);
  header("Content-Type: text/plain; charset=utf-8");
  echo $msg;
  exit;
}

if (empty($_SESSION["fenix_access_token"]) || empty($_SESSION["person"])) {
  fail(401, "not_authenticated");
}

$person = $_SESSION["person"];
$istId = trim((string)($person["username"] ?? ""));
if ($istId === "") {
  fail(400, "missing_ist_id");
}

if (empty($_FILES["english_certificate_file"])) {
  fail(400, "missing_file");
}

$file = $_FILES["english_certificate_file"];

if (!isset($file["error"]) || is_array($file["error"])) {
  fail(400, "invalid_upload");
}

if ($file["error"] !== UPLOAD_ERR_OK) {
  fail(400, "upload_error");
}

if (!is_uploaded_file($file["tmp_name"])) {
  fail(400, "invalid_uploaded_file");
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file["tmp_name"]);

$allowed = [
  "application/pdf" => "pdf",
  "image/jpeg"      => "jpg",
  "image/png"       => "png"
];

if (!isset($allowed[$mime])) {
  fail(400, "invalid_file_type");
}

$maxSize = 5 * 1024 * 1024;
if ((int)$file["size"] > $maxSize) {
  fail(400, "file_too_large");
}

$ext      = $allowed[$mime];
$safeIstId = preg_replace("/[^a-zA-Z0-9_]/", "_", $istId);
$fileName  = $safeIstId . "_english_certificate." . $ext;

$base = realpath(dirname(__DIR__, 2));
if ($base === false) {
  fail(500, "bad_base");
}

$dir = $base . "/_private/certificates";

if (!is_dir($dir)) {
  if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
    fail(500, "cannot_create_directory");
  }
}

$path = $dir . "/" . $fileName;

if (!@move_uploaded_file($file["tmp_name"], $path)) {
  $last = error_get_last();
  fail(500, json_encode([
    "error"        => "cannot_save_file",
    "dest"         => $path,
    "dir_exists"   => is_dir($dir),
    "dir_writable" => is_writable($dir),
    "dir_perms"    => is_dir($dir) ? substr(sprintf("%o", fileperms($dir)), -4) : null,
    "open_basedir" => ini_get("open_basedir"),
    "last_error"   => $last,
  ]));
}

header("Content-Type: text/plain; charset=utf-8");
echo "ok";
