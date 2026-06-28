<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/helpers.php";

session_start();
header("Content-Type: application/json; charset=utf-8");

function sum_credits($courses): float {
  if (!is_array($courses)) return 0.0;
  $acc = 0.0;

  foreach ($courses as $c) {
    if (!is_array($c)) continue;
    $raw = safe_string($c["credits"] ?? "");
    $raw = str_replace(",", ".", $raw);
    $n = is_numeric($raw) ? (float)$raw : 0.0;
    $acc += $n;
  }

  return $acc;
}

function compact_course_list($courses): array {
  if (!is_array($courses)) return [];

  $out = [];

  foreach ($courses as $c) {
    if (!is_array($c)) continue;

    $name = safe_string($c["name"] ?? "");
    $link = safe_string($c["link"] ?? "");

    if ($name === "" && $link === "") continue;

    $item = [];
    if ($name !== "") $item["name"] = $name;
    if ($link !== "") $item["link"] = $link;

    $out[] = $item;
  }

  return $out;
}

function is_assoc_array(array $arr): bool {
  $keys = array_keys($arr);
  return array_keys($keys) !== $keys;
}

function canonicalize_json_value($value) {
  if (is_array($value)) {
    $out = [];
    if (is_assoc_array($value)) {
      $keys = array_keys($value);
      sort($keys, SORT_STRING);
      foreach ($keys as $k) {
        $out[$k] = canonicalize_json_value($value[$k]);
      }
      return $out;
    }
    foreach ($value as $item) {
      $out[] = canonicalize_json_value($item);
    }
    return $out;
  }
  return $value;
}

function is_uuid_v4_or_any_uuid(string $s): bool {
  return (bool)preg_match('/^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}$/', $s);
}

if (empty($_SESSION["fenix_access_token"]) || empty($_SESSION["person"])) {
  respond(401, ["ok" => false, "error" => "not_authenticated"]);
}

$person = $_SESSION["person"];
$istId = $person["username"] ?? null;

if (!$istId) {
  respond(400, ["ok" => false, "error" => "missing_ist_id"]);
}

function fenix_api_base(): string {
  return "https://fenix.tecnico.ulisboa.pt/api/fenix/v1";
}

function fenix_get_json(string $path, string $accessToken): array {
  $url = fenix_api_base() . $path;

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $accessToken,
    "Accept: application/json"
  ]);

  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false) {
    return ["ok" => false, "code" => 0, "error" => $err ?: "curl_error", "data" => null];
  }

  $decoded = json_decode($body, true);
  if ($code >= 200 && $code < 300) {
    return ["ok" => true, "code" => $code, "error" => "", "data" => $decoded];
  }

  return ["ok" => false, "code" => $code, "error" => "fenix_http_" . $code, "data" => $decoded];
}

function get_active_degree_entry(array $curr): ?array {
  if (count($curr) === 0) return null;

  $active = array_values(array_filter($curr, function ($it) {
    return is_array($it)
      && isset($it["acronimo"]) && safe_string($it["acronimo"]) !== ""
      && (isset($it["isFinished"]) ? ($it["isFinished"] === false) : false);
  }));

  if (count($active) === 0) {
    $active = array_values(array_filter($curr, function ($it) {
      if (!is_array($it)) return false;

      if (!isset($it["acronimo"]) || safe_string($it["acronimo"]) === "") return false;

      $end = $it["end"] ?? null;
      return $end === null || safe_string($end) === "";
    }));
  }

  if (count($active) === 0) {
    return isset($curr[0]) && is_array($curr[0]) ? $curr[0] : null;
  }

  foreach ($active as $it) {
    $end = $it["end"] ?? null;
    if ($end === null || safe_string($end) === "") return $it;
  }

  return $active[0];
}

function build_fenix_course_base_url(string $acronym): string {
  $acr = safe_string($acronym);
  if ($acr === "") return "";
  return "https://fenix.tecnico.ulisboa.pt/disciplinas/" . rawurlencode($acr);
}

function filter_home_courses(array $courses): array {
  return array_values(array_filter($courses, function ($course) {
    if (!is_array($course)) return false;

    $name = safe_string($course["name"] ?? "");

    if (preg_match('/free option/i', $name)) return false;
    if (preg_match('/extra-curricular courses/i', $name)) return false;

    return true;
  }));
}

function fetch_processes_by_status(PDO $pdo, int $userId, string $status, string $orderBy): array {
  $stmt = $pdo->prepare("SELECT course_id FROM coordinator_courses WHERE coordinator_id = ?");
  $stmt->execute([$userId]);
  $allowedCourses = $stmt->fetchAll(PDO::FETCH_COLUMN);

  if (count($allowedCourses) === 0) return [];

  $in  = implode(",", array_fill(0, count($allowedCourses), "?"));
  $sql = "
    SELECT p.id, p.student_id, u.ist_id, p.mobility_type, p.status,
           p.payload_json, p.submitted_at, p.created_at, p.updated_at
    FROM processes p
    JOIN users u ON u.id = p.student_id
    WHERE p.status = ? AND p.course_id IN ($in)
    ORDER BY $orderBy ASC
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(array_merge([$status], $allowedCourses));

  $out = [];
  foreach ($stmt->fetchAll() as $r) {
    $out[] = [
      "id"          => (int)$r["id"],
      "studentId"   => (int)$r["student_id"],
      "istId"       => $r["ist_id"],
      "mobilityType"=> $r["mobility_type"],
      "status"      => $r["status"],
      "submittedAt" => $r["submitted_at"],
      "createdAt"   => $r["created_at"],
      "updatedAt"   => $r["updated_at"],
      "payload"     => !empty($r["payload_json"]) ? json_decode($r["payload_json"], true) : null
    ];
  }
  return $out;
}

try {
  $pdo = pdo_connect();

  $u = $pdo->prepare("SELECT id, role, ist_id FROM users WHERE ist_id = :ist_id LIMIT 1");
  $u->execute([":ist_id" => $istId]);
  $user = $u->fetch();

  if (!$user) {
    respond(403, ["ok" => false, "error" => "user_not_in_db"]);
  }

  $userId = (int)$user["id"];
  $role = (string)$user["role"];

  if ($_SERVER["REQUEST_METHOD"] === "GET") {
    if ($role !== "STUDENT" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $p = $pdo->prepare("
      SELECT id, student_id, mobility_type, status, payload_json, submitted_at, created_at, updated_at
      FROM processes
      WHERE student_id = :sid
      LIMIT 1
    ");
    $p->execute([":sid" => $userId]);
    $proc = $p->fetch();

    if (!$proc) {
      respond(200, ["ok" => true, "process" => null]);
    }

    $payload = null;
    if (!empty($proc["payload_json"])) {
      $payload = json_decode($proc["payload_json"], true);
    }

    respond(200, [
      "ok" => true,
      "process" => [
        "id" => (int)$proc["id"],
        "studentId" => (int)$proc["student_id"],
        "mobilityType" => $proc["mobility_type"],
        "status" => $proc["status"],
        "submittedAt" => $proc["submitted_at"],
        "createdAt" => $proc["created_at"],
        "updatedAt" => $proc["updated_at"],
        "payload" => $payload
      ]
    ]);
  }

  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(405, ["ok" => false, "error" => "method_not_allowed"]);
  }

  if (isset($_POST["action"]) && safe_string($_POST["action"]) === "upload_generated_docs_zip_chunk") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid         = isset($_POST["pid"]) ? (int)$_POST["pid"] : 0;
    $uploadId    = preg_replace('/[^a-zA-Z0-9_]/', '', safe_string($_POST["upload_id"] ?? ""));
    $chunkIndex  = isset($_POST["chunk_index"]) ? (int)$_POST["chunk_index"] : -1;
    $totalChunks = isset($_POST["total_chunks"]) ? (int)$_POST["total_chunks"] : 0;
    $filename    = safe_string($_POST["filename"] ?? "documents.zip");

    if ($pid <= 0 || $uploadId === "" || $chunkIndex < 0 || $totalChunks <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_chunk_params"]);
    }

    if (!isset($_FILES["file"]) || $_FILES["file"]["error"] !== UPLOAD_ERR_OK) {
      respond(400, ["ok" => false, "error" => "upload_error", "code" => $_FILES["file"]["error"] ?? -1]);
    }

    $base = realpath(dirname(__DIR__, 2));
    if ($base === false) respond(500, ["ok" => false, "error" => "bad_base"]);

    $chunksDir = $base . "/_private/upload_chunks";

    $chunkPath = $chunksDir . "/" . $uploadId . "_" . $chunkIndex;
    if (!@move_uploaded_file($_FILES["file"]["tmp_name"], $chunkPath)) {
      respond(500, ["ok" => false, "error" => "cannot_save_chunk"]);
    }

    $saved = count(glob($chunksDir . "/" . $uploadId . "_*"));
    if ($saved < $totalChunks) {
      respond(200, ["ok" => true, "done" => false, "chunk" => $chunkIndex]);
    }

    // All chunks received - assemble
    $chk = $pdo->prepare("SELECT id FROM processes WHERE id = :pid LIMIT 1");
    $chk->execute([":pid" => $pid]);
    if (!$chk->fetch()) respond(404, ["ok" => false, "error" => "no_process"]);

    $dir = $base . "/_private/generated_docs";
    if (!is_dir($dir)) {
      if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        respond(500, ["ok" => false, "error" => "cannot_create_directory"]);
      }
    }

    $fileName = $pid . "_" . date("Ymd_His") . ".zip";
    $finalPath = $dir . "/" . $fileName;
    $out = fopen($finalPath, "wb");
    if (!$out) respond(500, ["ok" => false, "error" => "cannot_open_final_file"]);

    for ($i = 0; $i < $totalChunks; $i++) {
      $cp = $chunksDir . "/" . $uploadId . "_" . $i;
      $in = fopen($cp, "rb");
      if (!$in) { fclose($out); respond(500, ["ok" => false, "error" => "cannot_read_chunk", "chunk" => $i]); }
      stream_copy_to_stream($in, $out);
      fclose($in);
    }
    fclose($out);

    // Cleanup temp chunks
    foreach (glob($chunksDir . "/" . $uploadId . "_*") as $f) @unlink($f);

    $sizeBytes = filesize($finalPath);
    respond(200, ["ok" => true, "done" => true, "pid" => $pid, "filename" => $fileName, "size_bytes" => $sizeBytes]);
  }

  if (isset($_POST["action"]) && safe_string($_POST["action"]) === "upload_generated_docs_zip") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($_POST["pid"]) ? (int)$_POST["pid"] : 0;
    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    if (!isset($_FILES["file"]) || !is_array($_FILES["file"])) {
      respond(400, ["ok" => false, "error" => "missing_file"]);
    }

    $f = $_FILES["file"];

    if (!isset($f["error"]) || $f["error"] !== UPLOAD_ERR_OK) {
      $code = isset($f["error"]) ? (int)$f["error"] : -1;
      respond(400, ["ok" => false, "error" => "upload_error", "code" => $code]);
    }

    $tmp = $f["tmp_name"] ?? "";
    if ($tmp === "" || !is_uploaded_file($tmp)) {
      respond(400, ["ok" => false, "error" => "invalid_uploaded_file"]);
    }

    $originalName = safe_string($f["name"] ?? "documents.zip");
    $sizeBytes = isset($f["size"]) ? (int)$f["size"] : 0;

    if ($sizeBytes <= 0) {
      respond(400, ["ok" => false, "error" => "empty_file"]);
    }

    $mime = "application/octet-stream";
    $fi = @finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
      $detected = @finfo_file($fi, $tmp);
      if (is_string($detected) && $detected !== "") {
        $mime = $detected;
      }
      @finfo_close($fi);
    }

    $mimeOk = in_array($mime, ["application/zip", "application/x-zip-compressed", "application/octet-stream"], true);
    $nameOk = (bool)preg_match('/\.zip$/i', $originalName);

    if (!$mimeOk && !$nameOk) {
      respond(415, ["ok" => false, "error" => "not_a_zip", "mime" => $mime]);
    }

    $chk = $pdo->prepare("SELECT id FROM processes WHERE id = :pid LIMIT 1");
    $chk->execute([":pid" => $pid]);
    if (!$chk->fetch()) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $base = realpath(dirname(__DIR__, 2));
    if ($base === false) {
      respond(500, ["ok" => false, "error" => "bad_base"]);
    }

    $dir = $base . "/_private/generated_docs";

    if (!is_dir($dir)) {
      if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        respond(500, ["ok" => false, "error" => "cannot_create_directory"]);
      }
    }

    $date = date("Ymd_His");
    $fileName = $pid . "_" . $date . ".zip";

    $path = $dir . "/" . $fileName;

    if (!@move_uploaded_file($tmp, $path)) {
      respond(500, [
        "ok" => false,
        "error" => "cannot_save_file",
        "path" => $path,
        "dir_writable" => is_writable($dir)
      ]);
    }

    respond(200, [
      "ok" => true,
      "pid" => $pid,
      "filename" => $fileName,
      "size_bytes" => $sizeBytes
    ]);
  }

  $raw = file_get_contents("php://input");
  $body = json_decode($raw, true);
  if (!is_array($body)) {
    respond(400, ["ok" => false, "error" => "invalid_json"]);
  }

  $action = safe_string($body["action"] ?? "");

  if ($action === "create") {
    if ($role !== "STUDENT" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $mobilityType = $body["mobility_type"] ?? null;
    if (!in_array($mobilityType, ["EUROPE", "OUTSIDE_EUROPE"], true)) {
      respond(400, ["ok" => false, "error" => "invalid_mobility_type"]);
    }

    $p = $pdo->prepare("SELECT id, mobility_type, status, created_at FROM processes WHERE student_id = :sid LIMIT 1");
    $p->execute([":sid" => $userId]);
    $existing = $p->fetch();

    if ($existing) {
      respond(200, [
        "ok" => true,
        "process" => [
          "id" => (int)$existing["id"],
          "mobilityType" => $existing["mobility_type"],
          "status" => $existing["status"],
          "createdAt" => $existing["created_at"]
        ]
      ]);
    }

    $ins = $pdo->prepare("
      INSERT INTO processes (student_id, mobility_type, status, payload_json)
      VALUES (:sid, :mt, 'DRAFT', NULL)
    ");
    $ins->execute([":sid" => $userId, ":mt" => $mobilityType]);
    $pid = (int)$pdo->lastInsertId();

    respond(201, [
      "ok" => true,
      "process" => [
        "id" => $pid,
        "mobilityType" => $mobilityType,
        "status" => "DRAFT"
      ]
    ]);
  }

  if ($action === "save") {
    if ($role !== "STUDENT" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $payload = $body["payload"] ?? null;
    if (!is_array($payload)) {
      respond(400, ["ok" => false, "error" => "payload_must_be_object"]);
    }

    $p = $pdo->prepare("SELECT id, status FROM processes WHERE student_id = :sid LIMIT 1");
    $p->execute([":sid" => $userId]);
    $proc = $p->fetch();

    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $status = (string)$proc["status"];
    if ($status !== "DRAFT" && $status !== "CHANGES_REQUESTED") {
      respond(409, ["ok" => false, "error" => "process_not_editable"]);
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $courseAcronym = trim($payload["personal"]["home_degree_acronym"] ?? "");
    $courseName    = trim($payload["personal"]["home_degree"] ?? "");

    if ($courseAcronym === "" && $courseName === "") {
      respond(400, ["ok" => false, "error" => "missing_course"]);
    }

    $course = null;
    if ($courseAcronym !== "") {
      $stmt = $pdo->prepare("SELECT id FROM courses WHERE LOWER(TRIM(acronym)) = LOWER(:acronym) LIMIT 1");
      $stmt->execute([":acronym" => $courseAcronym]);
      $course = $stmt->fetch();
    }
    if (!$course && $courseName !== "") {
      $stmt = $pdo->prepare("SELECT id FROM courses WHERE LOWER(TRIM(name)) = LOWER(:name) LIMIT 1");
      $stmt->execute([":name" => $courseName]);
      $course = $stmt->fetch();
    }
    $courseId = $course ? (int)$course["id"] : null;

    if ($courseId !== null) {
      $upd = $pdo->prepare("
        UPDATE processes
        SET payload_json = :pj,
            course_id = :course_id,
            updated_at = CURRENT_TIMESTAMP
        WHERE student_id = :sid
        LIMIT 1
      ");
      $upd->execute([":pj" => $json, ":course_id" => $courseId, ":sid" => $userId]);
    } else {
      $upd = $pdo->prepare("
        UPDATE processes
        SET payload_json = :pj,
            updated_at = CURRENT_TIMESTAMP
        WHERE student_id = :sid
        LIMIT 1
      ");
      $upd->execute([":pj" => $json, ":sid" => $userId]);
    }

    respond(200, ["ok" => true]);
  }

  if ($action === "submit") {
    if ($role !== "STUDENT" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $p = $pdo->prepare("SELECT id, status FROM processes WHERE student_id = :sid LIMIT 1");
    $p->execute([":sid" => $userId]);
    $proc = $p->fetch();

    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $status = (string)$proc["status"];
    if ($status !== "DRAFT" && $status !== "CHANGES_REQUESTED") {
      respond(409, ["ok" => false, "error" => "process_not_editable"]);
    }

    $upd = $pdo->prepare("
      UPDATE processes
      SET status = 'SUBMITTED',
          submitted_at = CURRENT_TIMESTAMP,
          updated_at = CURRENT_TIMESTAMP
      WHERE student_id = :sid
      LIMIT 1
    ");
    $upd->execute([":sid" => $userId]);

    respond(200, ["ok" => true, "status" => "SUBMITTED"]);
  }

  if ($action === "list_submitted") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN" && $role !== "STAFF") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }
    $processes = fetch_processes_by_status($pdo, $userId, "SUBMITTED", "p.submitted_at");
    respond(200, ["ok" => true, "processes" => $processes]);
  }

  if ($action === "list_approved") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }
    $processes = fetch_processes_by_status($pdo, $userId, "APPROVED", "p.updated_at");
    respond(200, ["ok" => true, "processes" => $processes]);
  }

  if ($action === "list_archived") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }
    $processes = fetch_processes_by_status($pdo, $userId, "ARCHIVED", "p.submitted_at");
    respond(200, ["ok" => true, "processes" => $processes]);
  }

  if ($action === "list_changes_requested") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }
    $processes = fetch_processes_by_status($pdo, $userId, "CHANGES_REQUESTED", "p.submitted_at");
    respond(200, ["ok" => true, "processes" => $processes]);
  }

  if ($action === "coordinator_decide") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["process_id"]) ? (int)$body["process_id"] : 0;
    $decision = safe_string($body["decision"] ?? "");
    $feedback = safe_string($body["feedback"] ?? "");

    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    if ($decision !== "APPROVE" && $decision !== "REQUEST_CHANGES") {
      respond(400, ["ok" => false, "error" => "invalid_decision"]);
    }

    $newStatus = $decision === "APPROVE" ? "APPROVED" : "CHANGES_REQUESTED";

    $sel = $pdo->prepare("SELECT payload_json, status FROM processes WHERE id = :pid LIMIT 1");
    $sel->execute([":pid" => $pid]);
    $proc = $sel->fetch();

    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    if ((string)$proc["status"] !== "SUBMITTED") {
      respond(409, ["ok" => false, "error" => "not_submitted"]);
    }

    $payload = [];
    if (!empty($proc["payload_json"])) {
      $decoded = json_decode($proc["payload_json"], true);
      if (is_array($decoded)) $payload = $decoded;
    }

    $payload["coordinator_decision"] = $decision;
    $payload["coordinator_feedback"] = $feedback;
    $payload["coordinator_decided_at"] = date("c");

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $upd = $pdo->prepare("
      UPDATE processes
      SET status = :st,
          payload_json = :pj,
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid
        AND status = 'SUBMITTED'
      LIMIT 1
    ");
    $upd->execute([":st" => $newStatus, ":pj" => $json, ":pid" => $pid]);

    if ($upd->rowCount() === 0) {
      respond(409, ["ok" => false, "error" => "not_submitted_or_not_found"]);
    }

    respond(200, ["ok" => true, "status" => $newStatus]);
  }

  if ($action === "get_by_id") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = 0;
    if (isset($body["pid"])) $pid = (int)$body["pid"];
    if (isset($body["process_id"])) $pid = (int)$body["process_id"];

    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    $p = $pdo->prepare("
      SELECT p.id, p.student_id, u.ist_id, p.mobility_type, p.status, p.payload_json, p.submitted_at, p.created_at, p.updated_at
      FROM processes p
      JOIN users u ON u.id = p.student_id
      WHERE p.id = :pid
      LIMIT 1
    ");
    $p->execute([":pid" => $pid]);
    $proc = $p->fetch();

    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $payload = null;
    if (!empty($proc["payload_json"])) {
      $payload = json_decode($proc["payload_json"], true);
    }

    respond(200, [
      "ok" => true,
      "process" => [
        "id" => (int)$proc["id"],
        "studentId" => (int)$proc["student_id"],
        "istId" => $proc["ist_id"],
        "mobilityType" => $proc["mobility_type"],
        "status" => $proc["status"],
        "submittedAt" => $proc["submitted_at"],
        "createdAt" => $proc["created_at"],
        "updatedAt" => $proc["updated_at"],
        "payload" => $payload
      ]
    ]);
  }

  if ($action === "list_equivalence_decisions") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = 0;
    if (isset($body["pid"])) $pid = (int)$body["pid"];
    if (isset($body["process_id"])) $pid = (int)$body["process_id"];

    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    $chk = $pdo->prepare("SELECT id FROM processes WHERE id = :pid LIMIT 1");
    $chk->execute([":pid" => $pid]);
    if (!$chk->fetch()) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $q = $pdo->prepare("
      SELECT pid, equivalence_id, approved_at, approved_by
      FROM approved_equivalences
      WHERE pid = :pid
      ORDER BY approved_at ASC
    ");

    $q->execute([":pid" => $pid]);
    $rows = $q->fetchAll();

    $items = [];
    foreach ($rows as $r) {
      $items[] = [
        "process_id" => (int)$r["pid"],
        "equivalence_id" => safe_string($r["equivalence_id"] ?? ""),
        "decision" => "APPROVED",
        "note" => "",
        "approved_at" => $r["approved_at"],
        "coordinator_user_id" => (string)$r["approved_by"]
      ];
    }

    respond(200, ["ok" => true, "items" => $items]);
  }

  if ($action === "find_similar_approved_equivalence") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = 0;
    if (isset($body["pid"])) $pid = (int)$body["pid"];

    $hostUniversity = safe_string($body["host_university"] ?? "");
    $homeCourses = $body["home_courses"] ?? [];
    $hostCourses = $body["host_courses"] ?? [];

    if (!is_array($homeCourses)) $homeCourses = [];
    if (!is_array($hostCourses)) $hostCourses = [];

    function normalize_course_names($courses): array {
      $names = [];
      foreach ($courses as $c) {
        $name = "";
        if (is_array($c)) $name = safe_string($c["name"] ?? "");
        else $name = safe_string($c);
        $name = trim(mb_strtolower($name));
        if ($name !== "") $names[] = $name;
      }
      sort($names, SORT_STRING);
      return $names;
    }

    $homeNames = normalize_course_names($homeCourses);
    $hostNames = normalize_course_names($hostCourses);

    if ($hostUniversity === "" || (!$homeNames && !$hostNames)) {
      respond(200, ["ok" => true, "found" => false]);
    }

    $q = $pdo->prepare("
      SELECT pid, equivalence_id, home_courses, host_courses, approved_at
      FROM approved_equivalences
      WHERE host_university = :hu
        AND pid <> :pid
    ");
    $q->execute([":hu" => $hostUniversity, ":pid" => $pid]);
    $rows = $q->fetchAll();

    $match = null;
    foreach ($rows as $r) {
      $rHome = json_decode($r["home_courses"] ?? "[]", true);
      $rHost = json_decode($r["host_courses"] ?? "[]", true);

      $rHomeNames = normalize_course_names(is_array($rHome) ? $rHome : []);
      $rHostNames = normalize_course_names(is_array($rHost) ? $rHost : []);

      if ($rHomeNames === $homeNames && $rHostNames === $hostNames) {
        $match = $r;
        break;
      }
    }

    if (!$match) {
      respond(200, ["ok" => true, "found" => false]);
    }

    respond(200, [
      "ok" => true,
      "found" => true,
      "process_id" => (int)$match["pid"],
      "approved_at" => $match["approved_at"]
    ]);
  }

  if ($action === "save_equivalence_decision") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = 0;
    if (isset($body["pid"])) $pid = (int)$body["pid"];
    if (isset($body["process_id"])) $pid = (int)$body["process_id"];

    $decision = safe_string($body["decision"] ?? "");
    $note = safe_string($body["note"] ?? "");
    $equivalence = $body["equivalence"] ?? null;
    $equivalenceId = safe_string($body["equivalence_id"] ?? "");

    if ($equivalenceId === "" && is_array($equivalence)) {
      $equivalenceId = safe_string($equivalence["id"] ?? "");
    }

    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    if ($equivalenceId === "") {
      respond(400, ["ok" => false, "error" => "missing_equivalence_id"]);
    }

    if (!is_uuid_v4_or_any_uuid($equivalenceId)) {
      respond(400, ["ok" => false, "error" => "invalid_equivalence_id"]);
    }

    if (!in_array($decision, ["APPROVED", "CHANGES_REQUESTED"], true)) {
      respond(400, ["ok" => false, "error" => "invalid_decision"]);
    }

    if ($decision === "APPROVED") {
      if (!is_array($equivalence)) {
        respond(400, ["ok" => false, "error" => "equivalence_must_be_object"]);
      }
    }

    $sel = $pdo->prepare("SELECT payload_json, status, version FROM processes WHERE id = :pid LIMIT 1");
    $sel->execute([":pid" => $pid]);
    $proc = $sel->fetch();

    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $processVersion = (int)$proc["version"];

    $status = (string)$proc["status"];
    if (!in_array($status, ["SUBMITTED", "CHANGES_REQUESTED", "APPROVED"], true)) {
      respond(409, ["ok" => false, "error" => "process_not_reviewable"]);
    }

    $payload = [];
    if (!empty($proc["payload_json"])) {
      $decoded = json_decode($proc["payload_json"], true);
      if (is_array($decoded)) $payload = $decoded;
    }

    $eqArr = $payload["equivalences"] ?? [];
    $existsInPayload = false;
    if (is_array($eqArr)) {
      foreach ($eqArr as $it) {
        if (!is_array($it)) continue;
        $id = safe_string($it["id"] ?? "");
        if ($id !== "" && $id === $equivalenceId) { $existsInPayload = true; break; }
      }
    }

    if (!$existsInPayload) {
      respond(409, ["ok" => false, "error" => "equivalence_not_in_payload"]);
    }

    $pdo->beginTransaction();
    try {
      $approved_at = null;
      $approved_by = null;

      if ($decision === "APPROVED") {
        $host_country = safe_string($payload["host"]["host_country"] ?? "");
        $host_university = safe_string($payload["host"]["host_university"] ?? "");
        if ($host_country === "") $host_country = "Unknown";
        if ($host_university === "") $host_university = "Unknown";

        $homeCourses = is_array($equivalence) ? ($equivalence["homeCourses"] ?? []) : [];
        $hostCourses = is_array($equivalence) ? ($equivalence["destinationCourses"] ?? []) : [];

        if (!is_array($homeCourses)) $homeCourses = [];
        if (!is_array($hostCourses)) $hostCourses = [];

        $hasInvalid = false;
        foreach ($homeCourses as $course) {
          if (!is_array($course)) continue;

          $name = safe_string($course["name"] ?? "");

          if (preg_match('/free option/i', $name) ||
              preg_match('/extracurricular courses/i', $name)) {
            $hasInvalid = true;
            break;
          }
        }

        if (!$hasInvalid) {
          $total_home = sum_credits($homeCourses);
          $total_host = sum_credits($hostCourses);

          $homeCoursesToStore = compact_course_list($homeCourses);
          $hostCoursesToStore = compact_course_list($hostCourses);

          $home_courses_json = json_encode($homeCoursesToStore, JSON_UNESCAPED_UNICODE);
          $host_courses_json = json_encode($hostCoursesToStore, JSON_UNESCAPED_UNICODE);

          $sqlApproved = "
            INSERT INTO approved_equivalences
              (pid, equivalence_id, host_country, host_university,
              host_courses, home_courses, total_host_ects, total_home_ects,
              approved_by)
            VALUES
              (:pid, :eqid, :hc, :hu,
              :host_courses, :home_courses, :thost, :thome,
              :approved_by)
            ON DUPLICATE KEY UPDATE
              host_country = VALUES(host_country),
              host_university = VALUES(host_university),
              host_courses = VALUES(host_courses),
              home_courses = VALUES(home_courses),
              total_host_ects = VALUES(total_host_ects),
              total_home_ects = VALUES(total_home_ects),
              approved_by = VALUES(approved_by),
              approved_at = CURRENT_TIMESTAMP
          ";

          $stmtA = $pdo->prepare($sqlApproved);
          $stmtA->execute([
            ":pid" => $pid,
            ":eqid" => $equivalenceId,
            ":hc" => $host_country,
            ":hu" => $host_university,
            ":host_courses" => $host_courses_json,
            ":home_courses" => $home_courses_json,
            ":thost" => $total_host,
            ":thome" => $total_home,
            ":approved_by" => (string)$istId
          ]);

          $t = $pdo->prepare("
            SELECT approved_at, approved_by
            FROM approved_equivalences
            WHERE pid = :pid AND equivalence_id = :eqid
            LIMIT 1
          ");
          $t->execute([":pid" => $pid, ":eqid" => $equivalenceId]);
          $row = $t->fetch();

          $approved_at = $row ? $row["approved_at"] : null;
          $approved_by = $row ? (string)$row["approved_by"] : (string)$istId;

        } else {
          $approved_at = null;
          $approved_by = (string)$istId;
        }

      } else if ($decision === "CHANGES_REQUESTED") {
        $del = $pdo->prepare("
          DELETE FROM approved_equivalences
          WHERE pid = :pid
            AND equivalence_id = :eqid
        ");
        $del->execute([
          ":pid" => $pid,
          ":eqid" => $equivalenceId
        ]);
      }

      $sqlReview = "
        INSERT INTO reviews
          (pid, process_version, decision, equivalence_id, comments, reviewed_by)
        VALUES
          (:pid, :pver, :decision, :eqid, :comments, :reviewed_by)
        ON DUPLICATE KEY UPDATE
          decision = VALUES(decision),
          comments = VALUES(comments),
          reviewed_by = VALUES(reviewed_by),
          updated_at = CURRENT_TIMESTAMP
      ";

      $stmtR = $pdo->prepare($sqlReview);
      $stmtR->execute([
        ":pid" => $pid,
        ":pver" => $processVersion,
        ":decision" => $decision,
        ":eqid" => $equivalenceId,
        ":comments" => $note,
        ":reviewed_by" => (string)$istId
      ]);

      $pdo->commit();

      respond(200, [
        "ok" => true,
        "approved_at" => $approved_at,
        "coordinator_user_id" => $approved_by ?? (string)$istId
      ]);

    } catch (Throwable $e) {
      $pdo->rollBack();
      respond(500, ["ok" => false, "error" => "db_error"]);
    }
  }

  if ($action === "list_reviews_for_student_process") {
    if ($role !== "STUDENT" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = 0;
    if (isset($body["pid"])) $pid = (int)$body["pid"];
    if (isset($body["process_id"])) $pid = (int)$body["process_id"];

    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_process_id"]);

    $p = $pdo->prepare("SELECT version FROM processes WHERE id = :pid LIMIT 1");
    $p->execute([":pid" => $pid]);
    $prow = $p->fetch();
    if (!$prow) respond(404, ["ok" => false, "error" => "no_process"]);

    $processVersion = (int)$prow["version"];
    $reviewsVersion = max(0, $processVersion-1);

    $q = $pdo->prepare("
      SELECT equivalence_id, decision, comments, reviewed_by, created_at, updated_at
      FROM reviews
      WHERE pid = :pid AND process_version = :v
      ORDER BY updated_at ASC
    ");

    $q->execute([":pid" => $pid, ":v" => $reviewsVersion]);

    $items = [];
    while ($r = $q->fetch()) {
      $items[] = [
        "equivalence_id" => safe_string($r["equivalence_id"] ?? ""),
        "decision" => (string)$r["decision"],
        "note" => (string)($r["comments"] ?? ""),
        "reviewed_by" => (string)($r["reviewed_by"] ?? ""),
        "created_at" => $r["created_at"],
        "updated_at" => $r["updated_at"],
        "process_version" => $reviewsVersion
      ];
    }

    respond(200, [
      "ok" => true,
      "pid" => $pid,
      "current_process_version" => $processVersion,
      "reviews_version" => $reviewsVersion,
      "items" => $items
    ]);
  }

  if ($action === "list_reviews_for_process") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = 0;
    if (isset($body["pid"])) $pid = (int)$body["pid"];
    if (isset($body["process_id"])) $pid = (int)$body["process_id"];

    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_process_id"]);

    $p = $pdo->prepare("SELECT version FROM processes WHERE id = :pid LIMIT 1");
    $p->execute([":pid" => $pid]);
    $prow = $p->fetch();
    if (!$prow) respond(404, ["ok" => false, "error" => "no_process"]);

    $processVersion = (int)$prow["version"];
    $reviewsVersion = max(0, $processVersion);

    $q = $pdo->prepare("
      SELECT equivalence_id, decision, comments, reviewed_by, created_at, updated_at
      FROM reviews
      WHERE pid = :pid
      ORDER BY updated_at ASC
    ");

    $q->execute([":pid" => $pid]);

    $items = [];
    while ($r = $q->fetch()) {
      $items[] = [
        "equivalence_id" => safe_string($r["equivalence_id"] ?? ""),
        "decision" => (string)$r["decision"],
        "note" => (string)($r["comments"] ?? ""),
        "reviewed_by" => (string)($r["reviewed_by"] ?? ""),
        "created_at" => $r["created_at"],
        "updated_at" => $r["updated_at"],
        "process_version" => $reviewsVersion
      ];
    }

    respond(200, [
      "ok" => true,
      "pid" => $pid,
      "current_process_version" => $processVersion,
      "reviews_version" => $reviewsVersion,
      "items" => $items
    ]);
  }

  if ($action === "finalize_request_changes") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = 0;
    if (isset($body["pid"])) $pid = (int)$body["pid"];
    if (isset($body["process_id"])) $pid = (int)$body["process_id"];
    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_process_id"]);

    $sel = $pdo->prepare("SELECT status, version, payload_json FROM processes WHERE id = :pid LIMIT 1");
    $sel->execute([":pid" => $pid]);
    $proc = $sel->fetch();
    if (!$proc) respond(404, ["ok" => false, "error" => "no_process"]);

    $processVersion = (int)$proc["version"];

    $payload = [];
    if (!empty($proc["payload_json"])) {
      $decoded = json_decode($proc["payload_json"], true);
      if (is_array($decoded)) $payload = $decoded;
    }

    $eq = $payload["equivalences"] ?? [];
    $eqIds = [];

    if (is_array($eq)) {
      foreach ($eq as $item) {
        if (!is_array($item)) continue;
        $st = strtoupper(trim((string)($item["status"] ?? "A")));
        if ($st === "R") continue;

        $id = safe_string($item["id"] ?? "");
        if ($id !== "") $eqIds[$id] = true;
      }
    }
    $totalEquivalences = count($eqIds);


    $q = $pdo->prepare("
      SELECT
        COUNT(DISTINCT equivalence_id) AS reviewed_equivalences,
        SUM(CASE WHEN decision = 'CHANGES_REQUESTED' THEN 1 ELSE 0 END) AS changes_count
      FROM reviews
      WHERE pid = :pid AND process_version = :v
    ");

    $q->execute([":pid" => $pid, ":v" => $processVersion]);
    $r = $q->fetch();

    $reviewedEquivalences = (int)($r["reviewed_equivalences"] ?? 0);

    $changesCount = (int)($r["changes_count"] ?? 0);


    if ($totalEquivalences > 0 && $reviewedEquivalences < $totalEquivalences) {
      respond(409, ["ok" => false, "error" => "not_all_equivalences_reviewed"]);
    }

    $pdo->beginTransaction();
    try {
      $sel2 = $pdo->prepare("SELECT version, payload_json FROM processes WHERE id = :pid LIMIT 1 FOR UPDATE");
      $sel2->execute([":pid" => $pid]);
      $row = $sel2->fetch();
      if (!$row) {
        $pdo->rollBack();
        respond(404, ["ok" => false, "error" => "no_process"]);
      }

      $currentVersion = (int)($row["version"] ?? 0);

      $payload2 = [];
      if (!empty($row["payload_json"])) {
        $decoded2 = json_decode($row["payload_json"], true);
        if (is_array($decoded2)) $payload2 = $decoded2;
      }

      if (!isset($payload2["equivalences"]) || !is_array($payload2["equivalences"])) {
        $payload2["equivalences"] = [];
      }

      $versionedKey = "equivalences_" . $currentVersion;

      $payload2[$versionedKey] = $payload2["equivalences"];
      $payload2["equivalences"] = [];

      $newPayloadJson = json_encode($payload2, JSON_UNESCAPED_UNICODE);

      $up = $pdo->prepare("
        UPDATE processes
        SET
          status = 'CHANGES_REQUESTED',
          version = COALESCE(version, 0) + 1,
          payload_json = :payload_json
        WHERE id = :pid
        LIMIT 1
      ");
      $up->execute([
        ":pid" => $pid,
        ":payload_json" => $newPayloadJson
      ]);

      $pdo->commit();

      respond(200, [
        "ok" => true,
        "pid" => $pid,
        "status" => "CHANGES_REQUESTED",
        "process_version" => $currentVersion,
        "new_process_version" => $currentVersion + 1
      ]);
    } catch (Throwable $e) {
      $pdo->rollBack();
      respond(500, ["ok" => false, "error" => "db_error"]);
    }
  }

  if ($action === "finalize_generate_docs") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = 0;
    if (isset($body["pid"])) $pid = (int)$body["pid"];
    if (isset($body["process_id"])) $pid = (int)$body["process_id"];
    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_process_id"]);

    $sel = $pdo->prepare("SELECT id, status FROM processes WHERE id = :pid LIMIT 1");
    $sel->execute([":pid" => $pid]);
    $proc = $sel->fetch();

    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $upd = $pdo->prepare("
      UPDATE processes
      SET status = 'APPROVED',
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid
      LIMIT 1
    ");
    $upd->execute([":pid" => $pid]);

    respond(200, ["ok" => true, "status" => "APPROVED"]);
  }

  if ($action === "student_request_edit") {
    if ($role !== "STUDENT" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["pid"]) ? (int)$body["pid"] : 0;
    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    $sel = $pdo->prepare("
      SELECT id, student_id, status
      FROM processes
      WHERE id = :pid
      LIMIT 1
    ");
    $sel->execute([":pid" => $pid]);
    $proc = $sel->fetch();

    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    if ((int)$proc["student_id"] !== (int)$userId && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    if ((string)$proc["status"] !== "APPROVED") {
      respond(409, ["ok" => false, "error" => "not_approved"]);
    }

    $upd = $pdo->prepare("
      UPDATE processes
      SET status = 'DRAFT',
          submitted_at = NULL,
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid
        AND status = 'APPROVED'
      LIMIT 1
    ");
    $upd->execute([":pid" => $pid]);

    if ($upd->rowCount() === 0) {
      respond(409, ["ok" => false, "error" => "not_approved_or_not_found"]);
    }

    respond(200, ["ok" => true, "status" => "DRAFT"]);
  }

  if ($action === "list_degrees") {
    $sel = $pdo->query("SELECT id, name, acronym FROM courses ORDER BY name ASC");
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    respond(200, ["ok" => true, "degrees" => $rows]);
  }

  if ($action === "list_home_courses") {

    $accessToken = safe_string($_SESSION["fenix_access_token"] ?? "");
    if ($accessToken === "") respond(401, ["ok" => false, "error" => "not_authenticated"]);

    $acronym = safe_string($body["acronym"] ?? "");

    if ($acronym === "") {
      $cur = fenix_get_json("/person/curriculum", $accessToken);
      if (!$cur["ok"] || !is_array($cur["data"])) {
        respond(502, ["ok" => false, "error" => "fenix_curriculum_failed", "code" => $cur["code"]]);
      }

      $entry = get_active_degree_entry($cur["data"]);
      if (!$entry) respond(404, ["ok" => false, "error" => "degree_not_found"]);

      $deg = $entry["degree"] ?? null;
      if (!is_array($deg)) respond(404, ["ok" => false, "error" => "degree_not_found"]);

      $acronym = safe_string($deg["acronym"] ?? "");
      if ($acronym === "") respond(404, ["ok" => false, "error" => "degree_not_found"]);
    }

    $studentCourse = $acronym;

    $sel = $pdo->prepare("
      SELECT course, name, ects, semester, link, created_at, updated_at
      FROM home_degrees
      WHERE course = :course
      ORDER BY semester ASC, name ASC
    ");
    $sel->execute([":course" => $studentCourse]);
    $items = $sel->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($items as $row) {
      $out[] = [
        "course" => safe_string($row["course"] ?? ""),
        "name" => safe_string($row["name"] ?? ""),
        "ects" => (int)($row["ects"] ?? 0),
        "semester" => (int)($row["semester"] ?? 0),
        "link" => safe_string($row["link"] ?? ""),
        "created_at" => safe_string($row["created_at"] ?? ""),
        "updated_at" => safe_string($row["updated_at"] ?? ""),
      ];
    }

    respond(200, [
      "ok" => true,
      "course" => $studentCourse,
      "count" => count($out),
      "items" => $out
    ]);
  }

  if ($action === "withdraw_process") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["pid"]) ? (int)$body["pid"] : 0;
    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    $sel = $pdo->prepare("SELECT id, status FROM processes WHERE id = :pid LIMIT 1");
    $sel->execute([":pid" => $pid]);
    $proc = $sel->fetch();

    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $currentStatus = (string)$proc["status"];
    $terminal = ["ARCHIVED", "WITHDRAWN"];
    if (in_array($currentStatus, $terminal, true)) {
      respond(409, ["ok" => false, "error" => "process_already_terminal"]);
    }

    $upd = $pdo->prepare("
      UPDATE processes
      SET status = 'WITHDRAWN',
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid
      LIMIT 1
    ");
    $upd->execute([":pid" => $pid]);

    respond(200, ["ok" => true, "status" => "WITHDRAWN"]);
  }

  if ($action === "list_all") {
    if ($role !== "ADMIN" && $role !== "STAFF") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $q = $pdo->query("
      SELECT p.id, p.student_id, u.ist_id, p.mobility_type, p.status, p.payload_json, p.submitted_at, p.created_at, p.updated_at
      FROM processes p
      JOIN users u ON u.id = p.student_id
      ORDER BY p.updated_at DESC, p.id DESC
    ");

    $rows = $q->fetchAll();
    $processes = [];

    foreach ($rows as $r) {
      $payload = null;
      if (!empty($r["payload_json"])) {
        $payload = json_decode($r["payload_json"], true);
      }

      $processes[] = [
        "id" => (int)$r["id"],
        "studentId" => (int)$r["student_id"],
        "istId" => $r["ist_id"],
        "mobilityType" => $r["mobility_type"],
        "status" => $r["status"],
        "submittedAt" => $r["submitted_at"],
        "createdAt" => $r["created_at"],
        "updatedAt" => $r["updated_at"],
        "payload" => $payload
      ];
    }

    respond(200, ["ok" => true, "processes" => $processes]);
  }

  if ($action === "admin_update_process") {
    if ($role !== "ADMIN" && $role !== "STAFF") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["pid"]) ? (int)$body["pid"] : 0;
    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    $status = safe_string($body["status"] ?? "");
    $mobilityType = safe_string($body["mobility_type"] ?? "");
    $payload = $body["payload"] ?? null;

    if (!in_array($status, ["DRAFT", "SUBMITTED", "IN_REVIEW", "CHANGES_REQUESTED", "APPROVED", "REJECTED", "ARCHIVED", "WITHDRAWN"], true)) {
      respond(400, ["ok" => false, "error" => "invalid_status"]);
    }

    if (!in_array($mobilityType, ["EUROPE", "OUTSIDE_EUROPE"], true)) {
      respond(400, ["ok" => false, "error" => "invalid_mobility_type"]);
    }

    if (!is_array($payload) && !is_null($payload)) {
      respond(400, ["ok" => false, "error" => "payload_must_be_object_or_null"]);
    }

    $chk = $pdo->prepare("SELECT id FROM processes WHERE id = :pid LIMIT 1");
    $chk->execute([":pid" => $pid]);
    if (!$chk->fetch()) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $payloadJson = $payload === null
      ? null
      : json_encode($payload, JSON_UNESCAPED_UNICODE);

    $upd = $pdo->prepare("
      UPDATE processes
      SET mobility_type = :mt,
          status = :st,
          payload_json = :pj,
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid
      LIMIT 1
    ");

    $upd->bindValue(":mt", $mobilityType, PDO::PARAM_STR);
    $upd->bindValue(":st", $status, PDO::PARAM_STR);
    if ($payloadJson === null) {
      $upd->bindValue(":pj", null, PDO::PARAM_NULL);
    } else {
      $upd->bindValue(":pj", $payloadJson, PDO::PARAM_STR);
    }
    $upd->bindValue(":pid", $pid, PDO::PARAM_INT);
    $upd->execute();

    respond(200, ["ok" => true]);
  }

  if ($action === "admin_delete_process") {
    if ($role !== "ADMIN" && $role !== "STAFF") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["pid"]) ? (int)$body["pid"] : 0;
    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    $chk = $pdo->prepare("SELECT id FROM processes WHERE id = :pid LIMIT 1");
    $chk->execute([":pid" => $pid]);
    if (!$chk->fetch()) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $pdo->beginTransaction();
    try {
      $delApproved = $pdo->prepare("
        DELETE FROM approved_equivalences
        WHERE pid = :pid
      ");
      $delApproved->execute([":pid" => $pid]);

      $delReviews = $pdo->prepare("
        DELETE FROM reviews
        WHERE pid = :pid
      ");
      $delReviews->execute([":pid" => $pid]);

      $delDocs = $pdo->prepare("
        DELETE FROM process_generated_docs
        WHERE pid = :pid
      ");
      $delDocs->execute([":pid" => $pid]);

      $delProcess = $pdo->prepare("
        DELETE FROM processes
        WHERE id = :pid
        LIMIT 1
      ");
      $delProcess->execute([":pid" => $pid]);

      if ($delProcess->rowCount() === 0) {
        $pdo->rollBack();
        respond(404, ["ok" => false, "error" => "no_process"]);
      }

      $pdo->commit();

      respond(200, [
        "ok" => true,
        "deleted" => true,
        "pid" => $pid
      ]);
    } catch (Throwable $e) {
      $pdo->rollBack();
      respond(500, ["ok" => false, "error" => "db_error"]);
    }
  }

  if ($action === "update_process_equivalences") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN" && $role !== "STAFF") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["pid"]) ? (int)$body["pid"] : 0;
    if (isset($body["process_id"])) $pid = (int)$body["process_id"];

    $equivalences = isset($body["equivalences"]) && is_array($body["equivalences"])
      ? $body["equivalences"]
      : null;

    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    if (!is_array($equivalences)) {
      respond(400, ["ok" => false, "error" => "equivalences_must_be_array"]);
    }

    $stmt = $pdo->prepare("
      SELECT payload_json, status
      FROM processes
      WHERE id = :pid
      LIMIT 1
    ");
    $stmt->execute([":pid" => $pid]);
    $row = $stmt->fetch();

    if (!$row) {
      respond(404, ["ok" => false, "error" => "process_not_found"]);
    }

    $status = (string)($row["status"] ?? "");
    if (!in_array($status, ["SUBMITTED", "CHANGES_REQUESTED", "APPROVED"], true)) {
      respond(409, ["ok" => false, "error" => "process_not_editable"]);
    }

    $payload = [];
    if (!empty($row["payload_json"])) {
      $decoded = json_decode($row["payload_json"], true);
      if (is_array($decoded)) $payload = $decoded;
    }

    $payload["equivalences"] = $equivalences;

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $upd = $pdo->prepare("
      UPDATE processes
      SET payload_json = :payload_json,
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid
      LIMIT 1
    ");
    $upd->execute([
      ":payload_json" => $payloadJson,
      ":pid" => $pid
    ]);

    respond(200, ["ok" => true]);
  }

  if ($action === "add_coordinator_global_comment") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["pid"]) ? (int)$body["pid"] : 0;
    $authorId = isset($body["author_id"]) ? (int)$body["author_id"] : null;
    $text = safe_string($body["text"] ?? "");

    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    if ($authorId === null) {
      respond(400, ["ok" => false, "error" => "missing_author_id"]);
    }

    if ($text === "") {
      respond(400, ["ok" => false, "error" => "empty_comment"]);
    }

    $stmt = $pdo->prepare("
      SELECT id, payload_json
      FROM processes
      WHERE id = :pid
      LIMIT 1
    ");
    $stmt->execute([":pid" => $pid]);
    $proc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $payload = [];
    if (!empty($proc["payload_json"])) {
      $decoded = json_decode($proc["payload_json"], true);
      if (is_array($decoded)) {
        $payload = $decoded;
      }
    }

    if (!isset($payload["coordinator_global_comments"]) || !is_array($payload["coordinator_global_comments"])) {
      $payload["coordinator_global_comments"] = [];
    }

    $comment = [
      "text" => $text,
      "created_at" => date("Y-m-d H:i:s"),
      "created_by" => (string)$authorId
    ];

    $payload["coordinator_global_comments"][] = $comment;

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $upd = $pdo->prepare("
      UPDATE processes
      SET payload_json = :pj,
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid
      LIMIT 1
    ");
    $upd->bindValue(":pj", $payloadJson, PDO::PARAM_STR);
    $upd->bindValue(":pid", $pid, PDO::PARAM_INT);
    $upd->execute();

    respond(200, [
      "ok" => true,
      "item" => $comment,
      "payload" => $payload
    ]);
  }

  if ($action === "set_english_certificate_verified") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["pid"]) ? (int)$body["pid"] : 0;
    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_pid"]);
    }

    $p = $pdo->prepare("
      SELECT payload_json
      FROM processes
      WHERE id = :pid
      LIMIT 1
    ");
    $p->execute([":pid" => $pid]);
    $proc = $p->fetch();

    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $payload = [];
    if (!empty($proc["payload_json"])) {
      $decoded = json_decode($proc["payload_json"], true);
      if (is_array($decoded)) $payload = $decoded;
    }

    if (!isset($payload["host"]) || !is_array($payload["host"])) {
      $payload["host"] = [];
    }

    $payload["host"]["english_certificate_verified"] = true;

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $upd = $pdo->prepare("
      UPDATE processes
      SET payload_json = :pj,
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid
      LIMIT 1
    ");
    $upd->execute([
      ":pj" => $payloadJson,
      ":pid" => $pid
    ]);

    respond(200, ["ok" => true]);
  }

  respond(400, ["ok" => false, "error" => "unknown_action"]);

} catch (Throwable $e) {
  respond(500, ["ok" => false, "error" => "server_error"]);
}
