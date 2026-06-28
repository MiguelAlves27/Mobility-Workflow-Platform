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
  $u->execute([":ist_id" => $istId]);
  $userRow = $u->fetch();

  if (!$userRow) {
    respond(403, ["ok" => false, "error" => "user_not_in_db"]);
  }

  $role = (string)$userRow["role"];
  if ($role !== "ADMIN" && $role !== "STAFF") {
    respond(403, ["ok" => false, "error" => "forbidden"]);
  }

  $userId = (int)$userRow["id"];

  if ($action === "list") {
    $cc = $pdo->prepare("SELECT course_id FROM coordinator_courses WHERE coordinator_id = ?");
    $cc->execute([$userId]);
    $allowedCourses = $cc->fetchAll(PDO::FETCH_COLUMN);

    if (count($allowedCourses) === 0) {
      respond(200, ["ok" => true, "rows" => []]);
    }

    $in = implode(",", array_fill(0, count($allowedCourses), "?"));

    $q = $pdo->prepare("
      SELECT
        ae.id,
        ae.pid,
        ae.host_country,
        ae.host_university,
        ae.host_courses,
        ae.home_courses,
        ae.total_host_ects,
        ae.total_home_ects,
        ae.approved_at,
        ae.approved_by,
        ae.equivalence_id
      FROM approved_equivalences ae
      JOIN processes p ON p.id = ae.pid
      WHERE p.course_id IN ($in)
      ORDER BY ae.approved_at DESC, ae.id DESC
    ");
    $q->execute($allowedCourses);

    $rows = $q->fetchAll();
    respond(200, ["ok" => true, "rows" => $rows]);
  }

  if ($action === "delete") {
    $id = isset($body["id"]) ? (int)$body["id"] : 0;
    if ($id <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_id"]);
    }

    $d = $pdo->prepare("DELETE FROM approved_equivalences WHERE id = :id LIMIT 1");
    $d->execute([":id" => $id]);

    respond(200, ["ok" => true, "deleted" => $d->rowCount()]);
  }

  respond(400, ["ok" => false, "error" => "unknown_action"]);

} catch (Throwable $e) {
  respond(500, ["ok" => false, "error" => "server_error"]);
}
