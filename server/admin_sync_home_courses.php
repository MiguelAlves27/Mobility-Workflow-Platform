<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/helpers.php";

session_start();
header("Content-Type: application/json; charset=utf-8");

function fenixGet(string $url) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ["Accept: application/json"]);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($response === false || $httpCode !== 200) {
    return null;
  }

  $data = json_decode($response, true);
  if (json_last_error() !== JSON_ERROR_NONE) return null;

  return $data;
}

function buildUrl(string $base, array $params = []): string {
  $query = http_build_query($params);
  return $query ? $base . "?" . $query : $base;
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
$apiBase = "https://fenix.tecnico.ulisboa.pt/api/fenix/v1";
$lang = "en-GB";

$pdo = null;
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

  if ($action === "degrees") {
    $degreesUrl = buildUrl($apiBase . "/degrees", ["lang" => $lang]);
    $degrees = fenixGet($degreesUrl);

    if (!is_array($degrees)) {
      respond(502, ["ok" => false, "error" => "fenix_fetch_failed"]);
    }

    $result = [];
    foreach ($degrees as $d) {
      $id = $d["id"] ?? null;
      $acronym = $d["acronym"] ?? null;
      $name = $d["name"] ?? null;
      $type = $d["type"] ?? null;

      if (!$id || !$acronym || !$name) continue;

      $result[] = [
        "id" => $id,
        "acronym" => $acronym,
        "name" => $name,
        "type" => $type
      ];
    }

    usort($result, function ($a, $b) {
      return strcmp($a["name"] . " " . $a["type"], $b["name"] . " " . $b["type"]);
    });

    respond(200, ["ok" => true, "degrees" => $result]);
  }

  if ($action === "preview") {
    $degreeId = safe_string($body["degreeId"] ?? "");
    $acronym = safe_string($body["acronym"] ?? "");
    $year = safe_string($body["year"] ?? "");

    if ($degreeId === "" || $acronym === "" || $year === "") {
      respond(400, ["ok" => false, "error" => "missing_params"]);
    }

    $terms = [
      1 => "1º Semestre " . $year,
      2 => "2º Semestre " . $year
    ];

    $fetched = [];
    $seen = [];

    foreach ($terms as $semester => $term) {
      $coursesUrl = buildUrl(
        $apiBase . "/degrees/" . urlencode($degreeId) . "/courses",
        ["lang" => $lang, "academicTerm" => $term]
      );

      $termCourses = fenixGet($coursesUrl);
      if (!is_array($termCourses)) continue;

      foreach ($termCourses as $course) {
        $courseId = $course["id"] ?? null;
        if (!$courseId || isset($seen[$courseId])) continue;
        $seen[$courseId] = true;

        $detailUrl = buildUrl($apiBase . "/courses/" . urlencode($courseId), ["lang" => $lang]);
        $detail = fenixGet($detailUrl);
        $link = is_array($detail) ? ($detail["url"] ?? "") : "";

        $fetched[] = [
          "name" => $course["name"] ?? "",
          "ects" => isset($course["credits"]) ? (float)$course["credits"] : 0,
          "semester" => $semester,
          "link" => $link ?? ""
        ];
      }
    }

    $e = $pdo->prepare("
      SELECT id, course, name, ects, semester, link
      FROM home_degrees
      WHERE course = :course
      ORDER BY semester ASC, name ASC
    ");
    $e->execute([":course" => $acronym]);
    $existing = $e->fetchAll();

    respond(200, ["ok" => true, "fetched" => $fetched, "existing" => $existing]);
  }

  if ($action === "apply") {
    $acronym = safe_string($body["acronym"] ?? "");
    $adds = is_array($body["adds"] ?? null) ? $body["adds"] : [];
    $updates = is_array($body["updates"] ?? null) ? $body["updates"] : [];
    $removes = is_array($body["removes"] ?? null) ? $body["removes"] : [];

    if ($acronym === "") {
      respond(400, ["ok" => false, "error" => "missing_acronym"]);
    }

    $pdo->beginTransaction();

    $ins = $pdo->prepare("
      INSERT INTO home_degrees (course, name, ects, semester, link)
      VALUES (:course, :name, :ects, :semester, :link)
    ");

    $upd = $pdo->prepare("
      UPDATE home_degrees
      SET ects = :ects, link = :link
      WHERE id = :id AND course = :course
      LIMIT 1
    ");

    $del = $pdo->prepare("DELETE FROM home_degrees WHERE id = :id AND course = :course LIMIT 1");

    $inserted = 0;
    foreach ($adds as $row) {
      $name = safe_string($row["name"] ?? "");
      $link = safe_string($row["link"] ?? "");
      $ects = isset($row["ects"]) ? (int)round((float)$row["ects"]) : 0;
      $semester = isset($row["semester"]) ? (int)$row["semester"] : 0;

      if ($name === "" || $semester < 1 || $ects < 0) continue;

      $ins->execute([
        ":course" => $acronym,
        ":name" => $name,
        ":ects" => $ects,
        ":semester" => $semester,
        ":link" => $link
      ]);
      $inserted++;
    }

    $updated = 0;
    foreach ($updates as $row) {
      $id = isset($row["id"]) ? (int)$row["id"] : 0;
      $link = safe_string($row["link"] ?? "");
      $ects = isset($row["ects"]) ? (int)round((float)$row["ects"]) : 0;

      if ($id <= 0 || $ects < 0) continue;

      $upd->execute([
        ":ects" => $ects,
        ":link" => $link,
        ":id" => $id,
        ":course" => $acronym
      ]);
      $updated += $upd->rowCount();
    }

    $deleted = 0;
    foreach ($removes as $id) {
      $id = (int)$id;
      if ($id <= 0) continue;

      $del->execute([":id" => $id, ":course" => $acronym]);
      $deleted += $del->rowCount();
    }

    $pdo->commit();

    respond(200, ["ok" => true, "inserted" => $inserted, "updated" => $updated, "deleted" => $deleted]);
  }

  respond(400, ["ok" => false, "error" => "unknown_action"]);
} catch (Throwable $e) {
  if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  respond(500, ["ok" => false, "error" => "server_error"]);
}
