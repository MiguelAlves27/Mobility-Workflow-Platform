<?php
declare(strict_types=1);

require_once __DIR__ . "/helpers.php";

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS'])) {
  ini_set('session.cookie_secure', '1');
}

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
$sessionIstId = trim((string)($person["username"] ?? ""));
$role = "STUDENT";

try {
  $pdo = pdo_connect();
  $stmt = $pdo->prepare("SELECT role FROM users WHERE ist_id = :ist_id LIMIT 1");
  $stmt->execute([":ist_id" => $sessionIstId]);
  $row = $stmt->fetch();
  if ($row) $role = $row["role"];
} catch (Throwable $e) {
  fail(500, "server_error");
}

$torType = trim((string)($_GET["tor_type"] ?? ""));
if (!in_array($torType, ["origin", "destination"], true)) {
  fail(400, "invalid_tor_type");
}

$istId = trim((string)($_GET["ist_id"] ?? ""));
if ($istId === "") {
  fail(400, "missing_ist_id");
}

// Students can only access their own TOR files
if ($role === "STUDENT" && $istId !== $sessionIstId) {
  fail(403, "forbidden");
}

$safeIstId = preg_replace("/[^a-zA-Z0-9_]/", "_", $istId);
$fileName  = $safeIstId . "_tor_" . $torType . ".pdf";

$base = realpath(dirname(__DIR__, 2));
if ($base === false) {
  fail(500, "bad_base");
}

$path = $base . "/_private/tor/" . $fileName;

if (!file_exists($path)) {
  fail(404, "not_found");
}

$realPath = realpath($path);
$realDir  = realpath($base . "/_private/tor");

if ($realPath === false || $realDir === false || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
  fail(403, "forbidden");
}

header("Content-Type: application/pdf");
header("Content-Disposition: inline; filename=\"" . basename($fileName) . "\"");
header("Content-Length: " . filesize($realPath));
header("Cache-Control: private, no-store");
readfile($realPath);
exit;
