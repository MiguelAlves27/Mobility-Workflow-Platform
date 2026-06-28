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

$fileName = preg_replace("/[^a-zA-Z0-9_]/", "_", $istId) . ".png";

$base = realpath(__DIR__ . "/../..");
if ($base === false) {
  fail(500, "bad_base");
}

$path = $base . "/_private/signatures/" . $fileName;

if (!is_file($path)) {
  fail(404, "no_signature");
}

header("Content-Type: image/png");
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, max-age=300");
readfile($path);