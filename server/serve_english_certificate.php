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

$istId = trim((string)($_GET["ist_id"] ?? ""));
if ($istId === "") {
  fail(400, "missing_ist_id");
}

// Students can only access their own certificate
if ($role === "STUDENT" && $istId !== $sessionIstId) {
  fail(403, "forbidden");
}

$safeIstId = preg_replace("/[^a-zA-Z0-9_]/", "_", $istId);

$base = realpath(dirname(__DIR__, 2));
if ($base === false) {
  fail(500, "bad_base");
}

$dir = $base . "/_private/certificates";

$extensions = ["pdf", "jpg", "png"];
$filePath = null;
$mimeType = null;

$mimeMap = [
  "pdf" => "application/pdf",
  "jpg" => "image/jpeg",
  "png" => "image/png",
];

foreach ($extensions as $ext) {
  $candidate = $dir . "/" . $safeIstId . "_english_certificate." . $ext;
  if (file_exists($candidate)) {
    $filePath = $candidate;
    $mimeType = $mimeMap[$ext];
    break;
  }
}

if ($filePath === null) {
  fail(404, "certificate_not_found");
}

$realPath = realpath($filePath);
$realDir  = realpath($dir);
if ($realPath === false || $realDir === false || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
  fail(403, "forbidden");
}

header("Content-Type: " . $mimeType);
header("Content-Length: " . filesize($realPath));
header("Content-Disposition: inline; filename=\"" . basename($realPath) . "\"");
header("Cache-Control: private, no-store");
readfile($realPath);
exit;
