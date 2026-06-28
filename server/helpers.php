<?php
declare(strict_types=1);

require_once __DIR__ . "/configdb.php";

function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function safe_string($v): string {
  if ($v === null) return "";
  return trim((string)$v);
}

function pdo_connect(): PDO {
  return new PDO(
    "mysql:host=" . host . ";dbname=" . dbname . ";charset=utf8mb4",
    user,
    pass,
    [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false
    ]
  );
}

function is_valid_role(string $role): bool {
  return in_array($role, ["STUDENT", "COORDINATOR", "ADMIN", "STAFF"], true);
}
