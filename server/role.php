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

try {
  $pdo = pdo_connect();

  $u = $pdo->prepare("SELECT role FROM users WHERE ist_id = :ist_id LIMIT 1");
  $u->execute([":ist_id" => $istId]);
  $row = $u->fetch();

  if (!$row) {
    respond(403, ["ok" => false, "error" => "user_not_in_db"]);
  }

  respond(200, [
    "ok" => true,
    "istId" => $istId,
    "role" => $row["role"]
  ]);

} catch (Throwable $e) {
  respond(500, ["ok" => false, "error" => "server_error"]);
}
