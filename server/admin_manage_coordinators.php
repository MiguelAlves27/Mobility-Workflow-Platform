<?php
declare(strict_types=1);

require __DIR__ . "/config.php";
require_once __DIR__ . "/helpers.php";

session_start();
header("Content-Type: application/json; charset=utf-8");

if (empty($_SESSION["fenix_access_token"]) || empty($_SESSION["person"])) {
  respond(401, ["ok" => false, "error" => "not_authenticated"]);
}

$person = $_SESSION["person"];
$istId = $person["username"] ?? null;
if (!$istId) {
  respond(400, ["ok" => false, "error" => "missing_ist_id"]);
}

$raw = file_get_contents("php://input");
$body = json_decode($raw ?: "{}", true);
if (!is_array($body)) {
  respond(400, ["ok" => false, "error" => "invalid_json"]);
}

$action = safe_string($body["action"] ?? "");

try {
  $pdo = pdo_connect();

  $u = $pdo->prepare("SELECT id, role, ist_id FROM users WHERE ist_id = :ist_id LIMIT 1");
  $u->execute([":ist_id" => (string)$istId]);
  $me = $u->fetch();

  if (!$me) {
    respond(403, ["ok" => false, "error" => "user_not_in_db"]);
  }

  if ((string)$me["role"] !== "ADMIN" && (string)$me["role"] !== "STAFF") {
    respond(403, ["ok" => false, "error" => "forbidden"]);
  }

  if ($action === "list_privileged") {
    $q = $pdo->query("
      SELECT id, ist_id, name, email, role, created_at, updated_at
      FROM users
      WHERE role IN ('ADMIN','COORDINATOR')
      ORDER BY role DESC, ist_id ASC
    ");
    $rows = $q->fetchAll();
    respond(200, ["ok" => true, "rows" => $rows]);
  }

  if ($action === "set_role") {
    $userId = isset($body["user_id"]) ? (int)$body["user_id"] : 0;
    $role = safe_string($body["role"] ?? "");

    if ($userId <= 0) respond(400, ["ok" => false, "error" => "invalid_user_id"]);
    if (!is_valid_role($role)) respond(400, ["ok" => false, "error" => "invalid_role"]);

    $upd = $pdo->prepare("
      UPDATE users
      SET role = :role, updated_at = CURRENT_TIMESTAMP
      WHERE id = :id
      LIMIT 1
    ");
    $upd->execute([":role" => $role, ":id" => $userId]);

    if ($upd->rowCount() === 0) {
      respond(404, ["ok" => false, "error" => "user_not_found"]);
    }

    respond(200, ["ok" => true]);
  }

  if ($action === "set_role_by_istid") {
    $targetIst = safe_string($body["ist_id"] ?? "");
    $role = safe_string($body["role"] ?? "");

    if ($targetIst === "") respond(400, ["ok" => false, "error" => "missing_ist_id"]);
    if (!is_valid_role($role)) respond(400, ["ok" => false, "error" => "invalid_role"]);

    $chk = $pdo->prepare("SELECT id FROM users WHERE ist_id = :ist_id LIMIT 1");
    $chk->execute([":ist_id" => $targetIst]);
    $row = $chk->fetch();

    if (!$row) {
      respond(404, ["ok" => false, "error" => "user_not_found"]);
    }

    $upd = $pdo->prepare("
      UPDATE users
      SET role = :role, updated_at = CURRENT_TIMESTAMP
      WHERE ist_id = :ist_id
      LIMIT 1
    ");
    $upd->execute([":role" => $role, ":ist_id" => $targetIst]);

    respond(200, ["ok" => true]);
  }

  respond(400, ["ok" => false, "error" => "unknown_action"]);

} catch (Throwable $e) {
  respond(500, ["ok" => false, "error" => "server_error"]);
}
