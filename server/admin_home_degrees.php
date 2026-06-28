<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/helpers.php";

session_start();
header("Content-Type: application/json; charset=utf-8");

function normalize_url(string $u): string {
  $u = trim($u);
  if ($u === "") return "";
  if (stripos($u, "www.") === 0) return "https://" . $u;
  return $u;
}

function validate_degree(array $row): array {
  $course = safe_string($row["course"] ?? "");
  $name = safe_string($row["name"] ?? "");
  $link = normalize_url(safe_string($row["link"] ?? ""));
  $ects = array_key_exists("ects", $row) ? (int)$row["ects"] : null;
  $semester = array_key_exists("semester", $row) ? (int)$row["semester"] : null;

  if ($course === "") return [false, "course_required", null];
  if ($name === "") return [false, "name_required", null];
  if ($ects === null || $ects < 0) return [false, "ects_invalid", null];
  if ($semester === null || $semester < 1) return [false, "semester_invalid", null];
  if (strlen($link) > 500) return [false, "link_too_long", null];

  return [true, "", ["course" => $course, "name" => $name, "ects" => $ects, "semester" => $semester, "link" => $link]];
}

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
  if ($role !== "ADMIN" && $role !== "STAFF" && $role !== "COORDINATOR") {
    respond(403, ["ok" => false, "error" => "forbidden"]);
  }

  if ($action === "list") {
    $q = $pdo->query("
      SELECT
        id,
        course,
        name,
        ects,
        semester,
        link,
        created_at,
        updated_at
      FROM home_degrees
      ORDER BY updated_at DESC, id DESC
    ");
    $rows = $q->fetchAll();
    respond(200, ["ok" => true, "rows" => $rows]);
  }

  if ($action === "create") {
    $row = is_array($body["row"] ?? null) ? $body["row"] : [];
    [$ok, $err, $clean] = validate_degree($row);
    if (!$ok) respond(400, ["ok" => false, "error" => $err]);

    $ins = $pdo->prepare("
      INSERT INTO home_degrees (course, name, ects, semester, link)
      VALUES (:course, :name, :ects, :semester, :link)
    ");
    $ins->execute([
      ":course" => $clean["course"],
      ":name" => $clean["name"],
      ":ects" => $clean["ects"],
      ":semester" => $clean["semester"],
      ":link" => $clean["link"]
    ]);

    respond(200, ["ok" => true, "id" => (int)$pdo->lastInsertId()]);
  }

  if ($action === "update") {
    $id = isset($body["id"]) ? (int)$body["id"] : 0;
    if ($id <= 0) respond(400, ["ok" => false, "error" => "invalid_id"]);

    $patch = is_array($body["patch"] ?? null) ? $body["patch"] : [];
    [$ok, $err, $clean] = validate_degree($patch);
    if (!$ok) respond(400, ["ok" => false, "error" => $err]);

    $up = $pdo->prepare("
      UPDATE home_degrees
      SET course = :course, name = :name, ects = :ects, semester = :semester, link = :link
      WHERE id = :id
      LIMIT 1
    ");
    $up->execute([
      ":course" => $clean["course"],
      ":name" => $clean["name"],
      ":ects" => $clean["ects"],
      ":semester" => $clean["semester"],
      ":link" => $clean["link"],
      ":id" => $id
    ]);

    respond(200, ["ok" => true, "updated" => $up->rowCount()]);
  }

  if ($action === "delete") {
    $id = isset($body["id"]) ? (int)$body["id"] : 0;
    if ($id <= 0) respond(400, ["ok" => false, "error" => "invalid_id"]);

    $d = $pdo->prepare("DELETE FROM home_degrees WHERE id = :id LIMIT 1");
    $d->execute([":id" => $id]);

    respond(200, ["ok" => true, "deleted" => $d->rowCount()]);
  }

  respond(400, ["ok" => false, "error" => "unknown_action"]);
} catch (Throwable $e) {
  respond(500, ["ok" => false, "error" => "server_error"]);
}