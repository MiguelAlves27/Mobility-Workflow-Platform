<?php
declare(strict_types=1);
require __DIR__ . "/config.php";
require_once __DIR__ . "/helpers.php";

session_start();
header("Content-Type: application/json; charset=utf-8");

if (empty($_SESSION["fenix_access_token"]) || empty($_SESSION["person"])) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "not_authenticated"]);
  exit;
}

$body = file_get_contents("php://input");
$payload = json_decode($body, true);
$_SESSION["confirmed"] = !empty($payload["confirmed"]);

$person = $_SESSION["person"];

$istId = $person["username"] ?? null;
$name = $person["name"] ?? null;

$email = $person["institutionalEmail"] ?? null;
if (!$email) $email = $person["email"] ?? null;

if (!$istId || !$name) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "missing_user_data"]);
  exit;
}

try {
  $pdo = pdo_connect();

  $sql = "
    INSERT INTO users (ist_id, name, email, role)
    VALUES (:ist_id, :name, :email, 'STUDENT')
    ON DUPLICATE KEY UPDATE
      name = VALUES(name),
      email = VALUES(email),
      updated_at = CURRENT_TIMESTAMP
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ":ist_id" => $istId,
    ":name" => $name,
    ":email" => $email
  ]);

  echo json_encode([
    "ok" => true,
    "confirmed" => $_SESSION["confirmed"],
    "user" => [
      "ist_id" => $istId,
      "name" => $name,
      "email" => $email,
      "role" => "STUDENT"
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "db_error"]);
}
