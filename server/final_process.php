<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
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

  $u = $pdo->prepare("SELECT id, role, ist_id FROM users WHERE ist_id = :ist_id LIMIT 1");
  $u->execute([":ist_id" => $istId]);
  $user = $u->fetch();

  if (!$user) {
    respond(403, ["ok" => false, "error" => "user_not_in_db"]);
  }

  $userId = (int)$user["id"];
  $role = (string)$user["role"];

  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(405, ["ok" => false, "error" => "method_not_allowed"]);
  }

  $raw = file_get_contents("php://input");
  $body = json_decode($raw, true);

  if (!is_array($body)) {
    respond(400, ["ok" => false, "error" => "invalid_json"]);
  }

  $action = safe_string($body["action"] ?? "");

  if ($action === "open_final_process") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN" && $role !== "STUDENT") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["process_id"]) ? (int)$body["process_id"] : 0;
    if ($pid <= 0 && isset($body["pid"])) {
      $pid = (int)$body["pid"];
    }

    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    $pdo->beginTransaction();

    try {
      $sel = $pdo->prepare("
        SELECT id, student_id, mobility_type, status, payload_json, submitted_at, created_at, updated_at, version
        FROM processes
        WHERE id = :pid
        LIMIT 1
        FOR UPDATE
      ");
      $sel->execute([":pid" => $pid]);
      $proc = $sel->fetch();

      if (!$proc) {
        $pdo->rollBack();
        respond(404, ["ok" => false, "error" => "no_process"]);
      }

      if ($role === "STUDENT" && (int)$proc["student_id"] !== $userId) {
        $pdo->rollBack();
        respond(403, ["ok" => false, "error" => "forbidden"]);
      }

      $currentStatus = (string)$proc["status"];
      $studentId = (int)$proc["student_id"];

      $existingFinal = $pdo->prepare("
        SELECT id, student_id, mobility_type, status, payload_json, submitted_at, created_at, updated_at, version
        FROM final_processes
        WHERE student_id = :sid
        LIMIT 1
      ");
      $existingFinal->execute([":sid" => $studentId]);
      $finalProc = $existingFinal->fetch();

      if ($finalProc) {
        if ($currentStatus !== "ARCHIVED") {
          $strippedPayload = null;
          if (!empty($proc["payload_json"])) {
            $decoded = json_decode($proc["payload_json"], true);
            if (is_array($decoded) && isset($decoded["personal"]["signature_png_base64"])) {
              unset($decoded["personal"]["signature_png_base64"]);
              $strippedPayload = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
          }

          if ($strippedPayload !== null) {
            $updArchived = $pdo->prepare("
              UPDATE processes
              SET status = 'ARCHIVED',
                  payload_json = :payload_json,
                  updated_at = CURRENT_TIMESTAMP
              WHERE id = :pid
              LIMIT 1
            ");
            $updArchived->execute([":payload_json" => $strippedPayload, ":pid" => $pid]);
          } else {
            $updArchived = $pdo->prepare("
              UPDATE processes
              SET status = 'ARCHIVED',
                  updated_at = CURRENT_TIMESTAMP
              WHERE id = :pid
              LIMIT 1
            ");
            $updArchived->execute([":pid" => $pid]);
          }
        }

        $pdo->commit();

        respond(200, [
          "ok" => true,
          "already_exists" => true,
          "process_id" => (int)$proc["id"],
          "process" => [
            "id" => (int)$finalProc["id"],
            "studentId" => (int)$finalProc["student_id"],
            "mobilityType" => $finalProc["mobility_type"],
            "status" => $finalProc["status"],
            "submittedAt" => $finalProc["submitted_at"],
            "createdAt" => $finalProc["created_at"],
            "updatedAt" => $finalProc["updated_at"],
            "version" => (int)$finalProc["version"],
            "payload" => !empty($finalProc["payload_json"]) ? json_decode($finalProc["payload_json"], true) : null
          ]
        ]);
      }

      $ins = $pdo->prepare("
        INSERT INTO final_processes (
          student_id,
          mobility_type,
          status,
          payload_json,
          submitted_at,
          created_at,
          updated_at,
          version
        ) VALUES (
          :student_id,
          :mobility_type,
          :status,
          :payload_json,
          :submitted_at,
          :created_at,
          :updated_at,
          :version
        )
      ");

      $ins->bindValue(":student_id", $studentId, PDO::PARAM_INT);
      $ins->bindValue(":mobility_type", $proc["mobility_type"], PDO::PARAM_STR);
      $ins->bindValue(":status", "DRAFT", PDO::PARAM_STR);

      if ($proc["payload_json"] === null) {
        $ins->bindValue(":payload_json", null, PDO::PARAM_NULL);
      } else {
        $ins->bindValue(":payload_json", $proc["payload_json"], PDO::PARAM_STR);
      }

      if ($proc["submitted_at"] === null) {
        $ins->bindValue(":submitted_at", null, PDO::PARAM_NULL);
      } else {
        $ins->bindValue(":submitted_at", $proc["submitted_at"], PDO::PARAM_STR);
      }

      $ins->bindValue(":created_at", $proc["created_at"], PDO::PARAM_STR);
      $ins->bindValue(":updated_at", $proc["updated_at"], PDO::PARAM_STR);
      $ins->bindValue(":version", (int)$proc["version"], PDO::PARAM_INT);
      $ins->execute();

      $finalId = (int)$pdo->lastInsertId();

      $strippedPayload = null;
      if (!empty($proc["payload_json"])) {
        $decoded = json_decode($proc["payload_json"], true);
        if (is_array($decoded) && isset($decoded["personal"]["signature_png_base64"])) {
          unset($decoded["personal"]["signature_png_base64"]);
          $strippedPayload = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
      }

      if ($strippedPayload !== null) {
        $upd = $pdo->prepare("
          UPDATE processes
          SET status = 'ARCHIVED',
              payload_json = :payload_json,
              updated_at = CURRENT_TIMESTAMP
          WHERE id = :pid
          LIMIT 1
        ");
        $upd->execute([":payload_json" => $strippedPayload, ":pid" => $pid]);
      } else {
        $upd = $pdo->prepare("
          UPDATE processes
          SET status = 'ARCHIVED',
              updated_at = CURRENT_TIMESTAMP
          WHERE id = :pid
          LIMIT 1
        ");
        $upd->execute([":pid" => $pid]);
      }

      $pdo->commit();

      respond(200, [
        "ok" => true,
        "archived" => true,
        "process_id" => (int)$proc["id"],
        "process" => [
          "id" => $finalId,
          "studentId" => $studentId,
          "mobilityType" => $proc["mobility_type"],
          "status" => $currentStatus,
          "submittedAt" => $proc["submitted_at"],
          "createdAt" => $proc["created_at"],
          "updatedAt" => $proc["updated_at"],
          "version" => (int)$proc["version"],
          "payload" => !empty($proc["payload_json"]) ? json_decode($proc["payload_json"], true) : null
        ]
      ]);

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      respond(500, ["ok" => false, "error" => "db_error"]);
    }
  }

  if ($action === "start_standalone") {
    if ($role !== "STUDENT" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $mobilityType = safe_string($body["mobility_type"] ?? "");
    if (!in_array($mobilityType, ["EUROPE", "OUTSIDE_EUROPE"], true)) {
      respond(400, ["ok" => false, "error" => "invalid_mobility_type"]);
    }

    $pdo->beginTransaction();

    try {
      $existingFinal = $pdo->prepare("
        SELECT id, student_id, mobility_type, status, payload_json, submitted_at, created_at, updated_at, version
        FROM final_processes
        WHERE student_id = :sid
        LIMIT 1
        FOR UPDATE
      ");
      $existingFinal->execute([":sid" => $userId]);
      $finalProc = $existingFinal->fetch();

      if ($finalProc) {
        $pdo->commit();

        respond(200, [
          "ok" => true,
          "already_exists" => true,
          "process" => [
            "id" => (int)$finalProc["id"],
            "studentId" => (int)$finalProc["student_id"],
            "mobilityType" => $finalProc["mobility_type"],
            "status" => $finalProc["status"],
            "submittedAt" => $finalProc["submitted_at"],
            "createdAt" => $finalProc["created_at"],
            "updatedAt" => $finalProc["updated_at"],
            "version" => (int)$finalProc["version"],
            "payload" => !empty($finalProc["payload_json"]) ? json_decode($finalProc["payload_json"], true) : null
          ]
        ]);
      }

      $ins = $pdo->prepare("
        INSERT INTO final_processes (
          student_id,
          mobility_type,
          status,
          payload_json,
          submitted_at,
          created_at,
          updated_at,
          version
        ) VALUES (
          :student_id,
          :mobility_type,
          'DRAFT',
          NULL,
          NULL,
          CURRENT_TIMESTAMP,
          CURRENT_TIMESTAMP,
          1
        )
      ");

      $ins->bindValue(":student_id", $userId, PDO::PARAM_INT);
      $ins->bindValue(":mobility_type", $mobilityType, PDO::PARAM_STR);
      $ins->execute();

      $finalId = (int)$pdo->lastInsertId();

      $pdo->commit();

      respond(200, [
        "ok" => true,
        "process" => [
          "id" => $finalId,
          "studentId" => $userId,
          "mobilityType" => $mobilityType,
          "status" => "DRAFT",
          "submittedAt" => null,
          "createdAt" => date("c"),
          "updatedAt" => date("c"),
          "version" => 1,
          "payload" => null
        ]
      ]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      respond(500, ["ok" => false, "error" => "db_error"]);
    }
  }

  if ($action === "get_by_student") {
    if ($role !== "STUDENT" && $role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $sid = $userId;

    if (($role === "COORDINATOR" || $role === "ADMIN") && isset($body["student_id"])) {
      $sid = (int)$body["student_id"];
    }

    $q = $pdo->prepare("
      SELECT id, student_id, mobility_type, status, payload_json, submitted_at, created_at, updated_at, version
      FROM final_processes
      WHERE student_id = :sid
      LIMIT 1
    ");
    $q->execute([":sid" => $sid]);
    $proc = $q->fetch();

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
        "version" => (int)$proc["version"],
        "payload" => $payload
      ]
    ]);
  }

  if ($action === "get") {
    if ($role !== "STUDENT" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }
  
    $q = $pdo->prepare("
      SELECT id, student_id, mobility_type, status, payload_json, submitted_at, created_at, updated_at, version
      FROM final_processes
      WHERE student_id = :sid
      LIMIT 1
    ");
    $q->execute([":sid" => $userId]);
    $proc = $q->fetch();
  
    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_final_process"]);
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
        "version" => (int)$proc["version"],
        "payload" => $payload
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
  
    $sel = $pdo->prepare("
      SELECT id, status
      FROM final_processes
      WHERE student_id = :sid
      LIMIT 1
    ");
    $sel->execute([":sid" => $userId]);
    $proc = $sel->fetch();
  
    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_final_process"]);
    }
  
    $status = (string)$proc["status"];
    if ($status !== "DRAFT" && $status !== "CHANGES_REQUESTED") {
      respond(409, ["ok" => false, "error" => "process_not_editable"]);
    }
  
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  
    $upd = $pdo->prepare("
      UPDATE final_processes
      SET payload_json = :pj,
          updated_at = CURRENT_TIMESTAMP
      WHERE student_id = :sid
      LIMIT 1
    ");
    $upd->execute([
      ":pj" => $json,
      ":sid" => $userId
    ]);
  
    respond(200, ["ok" => true]);
  }
  
  if ($action === "submit") {
    if ($role !== "STUDENT" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }
  
    $sel = $pdo->prepare("
      SELECT id, status
      FROM final_processes
      WHERE student_id = :sid
      LIMIT 1
    ");
    $sel->execute([":sid" => $userId]);
    $proc = $sel->fetch();
  
    if (!$proc) {
      respond(404, ["ok" => false, "error" => "no_final_process"]);
    }
  
    $status = (string)$proc["status"];
    if ($status !== "DRAFT" && $status !== "CHANGES_REQUESTED") {
      respond(409, ["ok" => false, "error" => "process_not_editable"]);
    }
  
    $upd = $pdo->prepare("
      UPDATE final_processes
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
    if ($role !== "COORDINATOR" && $role !== "STAFF" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $sql = "
      SELECT fp.id, fp.student_id, fp.mobility_type, fp.status,
             fp.payload_json, fp.submitted_at, fp.created_at, fp.updated_at, fp.version
      FROM final_processes fp
      LEFT JOIN processes p ON p.student_id = fp.student_id
      WHERE fp.status = 'SUBMITTED'
    ";

    $params = [];

    if ($role === "COORDINATOR" || $role === "STAFF") {
      $stmt = $pdo->prepare("SELECT course_id FROM coordinator_courses WHERE coordinator_id = ?");
      $stmt->execute([$userId]);
      $allowedCourses = $stmt->fetchAll(PDO::FETCH_COLUMN);

      if (count($allowedCourses) === 0) {
        respond(200, ["ok" => true, "processes" => []]);
      }

      $in = implode(",", array_fill(0, count($allowedCourses), "?"));
      $sql .= " AND p.course_id IN ($in)";
      $params = array_merge($params, $allowedCourses);
    }

    $sql .= " ORDER BY fp.submitted_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $processes = [];
    foreach ($rows as $row) {
      $processes[] = [
        "id"          => (int)$row["id"],
        "studentId"   => (int)$row["student_id"],
        "mobilityType"=> $row["mobility_type"],
        "status"      => $row["status"],
        "submittedAt" => $row["submitted_at"],
        "createdAt"   => $row["created_at"],
        "updatedAt"   => $row["updated_at"],
        "version"     => (int)$row["version"],
        "payload"     => !empty($row["payload_json"]) ? json_decode($row["payload_json"], true) : null
      ];
    }

    respond(200, ["ok" => true, "processes" => $processes]);
  }
  
  if ($action === "list_approved") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $sql = "
      SELECT fp.id, fp.student_id, fp.mobility_type, fp.status,
             fp.payload_json, fp.submitted_at, fp.created_at, fp.updated_at, fp.version
      FROM final_processes fp
      LEFT JOIN processes p ON p.student_id = fp.student_id
      WHERE fp.status = 'APPROVED'
    ";

    $params = [];

    if ($role === "COORDINATOR") {
      $stmt2 = $pdo->prepare("SELECT course_id FROM coordinator_courses WHERE coordinator_id = ?");
      $stmt2->execute([$userId]);
      $allowedCourses = $stmt2->fetchAll(PDO::FETCH_COLUMN);

      if (count($allowedCourses) === 0) {
        respond(200, ["ok" => true, "processes" => []]);
      }

      $in = implode(",", array_fill(0, count($allowedCourses), "?"));
      $sql .= " AND p.course_id IN ($in)";
      $params = array_merge($params, $allowedCourses);
    }

    $sql .= " ORDER BY fp.updated_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $processes = [];
    foreach ($rows as $row) {
      $processes[] = [
        "id"          => (int)$row["id"],
        "studentId"   => (int)$row["student_id"],
        "mobilityType"=> $row["mobility_type"],
        "status"      => $row["status"],
        "submittedAt" => $row["submitted_at"],
        "createdAt"   => $row["created_at"],
        "updatedAt"   => $row["updated_at"],
        "version"     => (int)$row["version"],
        "payload"     => !empty($row["payload_json"]) ? json_decode($row["payload_json"], true) : null
      ];
    }

    respond(200, ["ok" => true, "processes" => $processes]);
  }

  if ($action === "list_approved_staff") {
    if ($role !== "STAFF" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $sql = "
      SELECT fp.id, fp.student_id, fp.mobility_type, fp.status,
             fp.payload_json, fp.submitted_at, fp.created_at, fp.updated_at, fp.version
      FROM final_processes fp
      LEFT JOIN processes p ON p.student_id = fp.student_id
      WHERE fp.status = 'STAFF_APPROVED'
    ";

    $params = [];

    if ($role === "STAFF") {
      $stmt2 = $pdo->prepare("SELECT course_id FROM coordinator_courses WHERE coordinator_id = ?");
      $stmt2->execute([$userId]);
      $allowedCourses = $stmt2->fetchAll(PDO::FETCH_COLUMN);

      if (count($allowedCourses) === 0) {
        respond(200, ["ok" => true, "processes" => []]);
      }

      $in = implode(",", array_fill(0, count($allowedCourses), "?"));
      $sql .= " AND p.course_id IN ($in)";
      $params = array_merge($params, $allowedCourses);
    }

    $sql .= " ORDER BY fp.updated_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $processes = [];
    foreach ($rows as $row) {
      $processes[] = [
        "id"          => (int)$row["id"],
        "studentId"   => (int)$row["student_id"],
        "mobilityType"=> $row["mobility_type"],
        "status"      => $row["status"],
        "submittedAt" => $row["submitted_at"],
        "createdAt"   => $row["created_at"],
        "updatedAt"   => $row["updated_at"],
        "version"     => (int)$row["version"],
        "payload"     => !empty($row["payload_json"]) ? json_decode($row["payload_json"], true) : null
      ];
    }

    respond(200, ["ok" => true, "processes" => $processes]);
  }
  
  if ($action === "list_changes_requested") {
    if ($role !== "COORDINATOR" && $role !== "STAFF" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $sql = "
      SELECT fp.id, fp.student_id, fp.mobility_type, fp.status,
             fp.payload_json, fp.submitted_at, fp.created_at, fp.updated_at, fp.version
      FROM final_processes fp
      LEFT JOIN processes p ON p.student_id = fp.student_id
      WHERE fp.status = 'CHANGES_REQUESTED'
    ";

    $params = [];

    if ($role === "COORDINATOR" || $role === "STAFF") {
      $stmt = $pdo->prepare("SELECT course_id FROM coordinator_courses WHERE coordinator_id = ?");
      $stmt->execute([$userId]);
      $allowedCourses = $stmt->fetchAll(PDO::FETCH_COLUMN);

      if (count($allowedCourses) === 0) {
        respond(200, ["ok" => true, "processes" => []]);
      }

      $in = implode(",", array_fill(0, count($allowedCourses), "?"));
      $sql .= " AND p.course_id IN ($in)";
      $params = array_merge($params, $allowedCourses);
    }

    $sql .= " ORDER BY fp.updated_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $processes = [];
    foreach ($rows as $row) {
      $processes[] = [
        "id"          => (int)$row["id"],
        "studentId"   => (int)$row["student_id"],
        "mobilityType"=> $row["mobility_type"],
        "status"      => $row["status"],
        "submittedAt" => $row["submitted_at"],
        "createdAt"   => $row["created_at"],
        "updatedAt"   => $row["updated_at"],
        "version"     => (int)$row["version"],
        "payload"     => !empty($row["payload_json"]) ? json_decode($row["payload_json"], true) : null
      ];
    }

    respond(200, ["ok" => true, "processes" => $processes]);
  }

  if ($action === "get_by_id") {
    if ($role !== "COORDINATOR"  && $role !== "STAFF" && $role !== "ADMIN") {
        respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["id"]) ? (int)$body["id"] : 0;
    if ($pid <= 0) {
        respond(400, ["ok" => false, "error" => "invalid_id"]);
    }

    $q = $pdo->prepare("
        SELECT id, student_id, mobility_type, status, payload_json, submitted_at, created_at, updated_at, version
        FROM final_processes
        WHERE id = :pid
        LIMIT 1
    ");
    $q->execute([":pid" => $pid]);
    $proc = $q->fetch();

    if (!$proc) {
        respond(404, ["ok" => false, "error" => "not_found"]);
    }

    respond(200, [
        "ok" => true,
        "process" => [
        "id"          => (int)$proc["id"],
        "studentId"   => (int)$proc["student_id"],
        "mobilityType"=> $proc["mobility_type"],
        "status"      => $proc["status"],
        "submittedAt" => $proc["submitted_at"],
        "createdAt"   => $proc["created_at"],
        "updatedAt"   => $proc["updated_at"],
        "version"     => (int)$proc["version"],
        "payload"     => !empty($proc["payload_json"]) ? json_decode($proc["payload_json"], true) : null
        ]
    ]);
  }

  if ($action === "staff_save") {
    if ($role !== "STAFF" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["id"]) ? (int)$body["id"] : 0;
    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_id"]);

    $newPayload = $body["payload"] ?? null;
    if (!is_array($newPayload)) respond(400, ["ok" => false, "error" => "payload_must_be_object"]);

    $q = $pdo->prepare("SELECT id, status FROM final_processes WHERE id = :pid LIMIT 1");
    $q->execute([":pid" => $pid]);
    $proc = $q->fetch();

    if (!$proc) respond(404, ["ok" => false, "error" => "not_found"]);
    if ((string)$proc["status"] !== "SUBMITTED") respond(409, ["ok" => false, "error" => "not_submitted"]);

    $upd = $pdo->prepare("
      UPDATE final_processes
      SET payload_json = :pj,
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid AND status = 'SUBMITTED'
      LIMIT 1
    ");
    $upd->execute([
      ":pj"  => json_encode($newPayload, JSON_UNESCAPED_UNICODE),
      ":pid" => $pid
    ]);

    if ($upd->rowCount() === 0) {
      respond(409, ["ok" => false, "error" => "update_failed"]);
    }

    respond(200, ["ok" => true]);
  }

  if ($action === "staff_decide") {
    if ($role !== "STAFF" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid      = isset($body["id"]) ? (int)$body["id"] : 0;
    $decision = safe_string($body["decision"] ?? ""); // "APPROVE" | "REJECT"
    $comment  = safe_string($body["comment"] ?? "");

    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_id"]);
    if (!in_array($decision, ["APPROVE", "REJECT"], true)) {
      respond(400, ["ok" => false, "error" => "invalid_decision"]);
    }

    $q = $pdo->prepare("
      SELECT id, student_id, status, payload_json
      FROM final_processes WHERE id = :pid LIMIT 1
    ");
    $q->execute([":pid" => $pid]);
    $proc = $q->fetch();

    if (!$proc) respond(404, ["ok" => false, "error" => "not_found"]);
    if ((string)$proc["status"] !== "SUBMITTED") {
      respond(409, ["ok" => false, "error" => "not_submitted"]);
    }

    $newStatus = $decision === "APPROVE" ? "STAFF_APPROVED" : "CHANGES_REQUESTED";

    $payload = [];
    if (!empty($proc["payload_json"])) {
      $decoded = json_decode($proc["payload_json"], true);
      if (is_array($decoded)) $payload = $decoded;
    }
    $payload["staff_decision"]    = $decision;
    $payload["staff_comment"]     = $comment;
    $payload["staff_decided_at"]  = date("c");
    $payload["staff_decided_by"]  = $istId;

    $upd = $pdo->prepare("
      UPDATE final_processes
      SET status = :status,
          payload_json = :pj,
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid AND status = 'SUBMITTED'
      LIMIT 1
    ");
    $upd->execute([
      ":status" => $newStatus,
      ":pj"     => json_encode($payload, JSON_UNESCAPED_UNICODE),
      ":pid"    => $pid
    ]);

    if ($upd->rowCount() === 0) {
      respond(409, ["ok" => false, "error" => "update_failed"]);
    }

    respond(200, ["ok" => true, "status" => $newStatus]);
  }

  if ($action === "list_staff_approved") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $sql = "
      SELECT fp.id, fp.student_id, fp.mobility_type, fp.status,
             fp.payload_json, fp.submitted_at, fp.created_at, fp.updated_at, fp.version
      FROM final_processes fp
      LEFT JOIN processes p ON p.student_id = fp.student_id
      WHERE fp.status = 'STAFF_APPROVED'
    ";

    $params = [];

    $stmt = $pdo->prepare("SELECT course_id FROM coordinator_courses WHERE coordinator_id = ?");
    $stmt->execute([$userId]);
    $allowedCourses = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($allowedCourses) === 0) {
      respond(200, ["ok" => true, "processes" => []]);
    }

    $in = implode(",", array_fill(0, count($allowedCourses), "?"));
    $sql .= " AND p.course_id IN ($in)";
    $params = array_merge($params, $allowedCourses);
    

    $sql .= " ORDER BY fp.submitted_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $processes = [];
    foreach ($rows as $row) {
      $processes[] = [
        "id"          => (int)$row["id"],
        "studentId"   => (int)$row["student_id"],
        "mobilityType"=> $row["mobility_type"],
        "status"      => $row["status"],
        "submittedAt" => $row["submitted_at"],
        "createdAt"   => $row["created_at"],
        "updatedAt"   => $row["updated_at"],
        "version"     => (int)$row["version"],
        "payload"     => !empty($row["payload_json"]) ? json_decode($row["payload_json"], true) : null
      ];
    }

    respond(200, ["ok" => true, "processes" => $processes]);
  }

  if ($action === "finalize_pef") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid      = isset($body["id"]) ? (int)$body["id"] : 0;
    $decision = safe_string($body["decision"] ?? ""); // "APPROVED" | "REJECTED"
    $comment  = safe_string($body["comment"] ?? "");

    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_id"]);
    if (!in_array($decision, ["APPROVED", "REJECTED"], true)) {
      respond(400, ["ok" => false, "error" => "invalid_decision"]);
    }

    $q = $pdo->prepare("
      SELECT id, student_id, status, payload_json
      FROM final_processes WHERE id = :pid LIMIT 1
    ");
    $q->execute([":pid" => $pid]);
    $proc = $q->fetch();

    if (!$proc) respond(404, ["ok" => false, "error" => "not_found"]);
    if ((string)$proc["status"] !== "STAFF_APPROVED") {
      respond(409, ["ok" => false, "error" => "not_staff_approved"]);
    }

    $newStatus = $decision === "APPROVED" ? "APPROVED" : "CHANGES_REQUESTED";

    $payload = [];
    if (!empty($proc["payload_json"])) {
      $decoded = json_decode($proc["payload_json"], true);
      if (is_array($decoded)) $payload = $decoded;
    }
    $payload["coordinator_pef_decision"]   = $decision;
    $payload["coordinator_pef_comment"]    = $comment;
    $payload["coordinator_pef_decided_at"] = date("c");
    $payload["coordinator_pef_decided_by"] = $istId;

    $upd = $pdo->prepare("
      UPDATE final_processes
      SET status = :status,
          payload_json = :pj,
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid AND status = 'STAFF_APPROVED'
      LIMIT 1
    ");
    $upd->execute([
      ":status" => $newStatus,
      ":pj"     => json_encode($payload, JSON_UNESCAPED_UNICODE),
      ":pid"    => $pid
    ]);

    if ($upd->rowCount() === 0) {
      respond(409, ["ok" => false, "error" => "update_failed"]);
    }

    respond(200, ["ok" => true, "status" => $newStatus]);
  }

  if ($action === "finalize_generate_docs") {
    if ($role !== "COORDINATOR" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["pid"]) ? (int)$body["pid"] : 0;
    if (isset($body["id"])) $pid = (int)$body["id"];
    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_id"]);

    $upd = $pdo->prepare("
      UPDATE final_processes
      SET status = 'APPROVED',
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid
      LIMIT 1
    ");
    $upd->execute([":pid" => $pid]);

    respond(200, ["ok" => true, "status" => "APPROVED"]);
  }

  if ($action === "finalize_request_changes") {
    if ($role !== "COORDINATOR" && $role !== "STAFF" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["pid"]) ? (int)$body["pid"] : 0;
    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_id"]);

    $upd = $pdo->prepare("
      UPDATE final_processes
      SET status = 'CHANGES_REQUESTED',
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid
      LIMIT 1
    ");
    $upd->execute([":pid" => $pid]);

    if ($upd->rowCount() === 0) {
      respond(409, ["ok" => false, "error" => "update_failed"]);
    }

    respond(200, ["ok" => true, "status" => "CHANGES_REQUESTED"]);
  }

  if ($action === "student_request_edit") {
    if ($role !== "STUDENT" && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $pid = isset($body["pid"]) ? (int)$body["pid"] : 0;
    if ($pid <= 0) respond(400, ["ok" => false, "error" => "invalid_pid"]);

    $q = $pdo->prepare("SELECT id, student_id, status FROM final_processes WHERE id = :pid LIMIT 1");
    $q->execute([":pid" => $pid]);
    $proc = $q->fetch();

    if (!$proc) respond(404, ["ok" => false, "error" => "not_found"]);
    if ((int)$proc["student_id"] !== $userId && $role !== "ADMIN") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $upd = $pdo->prepare("
      UPDATE final_processes
      SET status = 'DRAFT',
          submitted_at = NULL,
          updated_at = CURRENT_TIMESTAMP
      WHERE id = :pid
      LIMIT 1
    ");
    $upd->execute([":pid" => $pid]);

    respond(200, ["ok" => true, "status" => "DRAFT"]);
  }

  if ($action === "list_all") {
    if ($role !== "ADMIN" && $role !== "STAFF") {
      respond(403, ["ok" => false, "error" => "forbidden"]);
    }

    $q = $pdo->query("
      SELECT fp.id, fp.student_id, u.ist_id, fp.mobility_type, fp.status, fp.payload_json, fp.submitted_at, fp.created_at, fp.updated_at, fp.version
      FROM final_processes fp
      JOIN users u ON u.id = fp.student_id
      ORDER BY fp.updated_at DESC, fp.id DESC
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
        "version" => (int)$r["version"],
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

    if (!in_array($status, ["DRAFT", "SUBMITTED", "CHANGES_REQUESTED", "STAFF_APPROVED", "APPROVED"], true)) {
      respond(400, ["ok" => false, "error" => "invalid_status"]);
    }

    if (!in_array($mobilityType, ["EUROPE", "OUTSIDE_EUROPE"], true)) {
      respond(400, ["ok" => false, "error" => "invalid_mobility_type"]);
    }

    if (!is_array($payload) && !is_null($payload)) {
      respond(400, ["ok" => false, "error" => "payload_must_be_object_or_null"]);
    }

    $chk = $pdo->prepare("SELECT id FROM final_processes WHERE id = :pid LIMIT 1");
    $chk->execute([":pid" => $pid]);
    if (!$chk->fetch()) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    $payloadJson = $payload === null
      ? null
      : json_encode($payload, JSON_UNESCAPED_UNICODE);

    $upd = $pdo->prepare("
      UPDATE final_processes
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

    $del = $pdo->prepare("DELETE FROM final_processes WHERE id = :pid LIMIT 1");
    $del->execute([":pid" => $pid]);

    if ($del->rowCount() === 0) {
      respond(404, ["ok" => false, "error" => "no_process"]);
    }

    respond(200, ["ok" => true, "deleted" => true, "pid" => $pid]);
  }

  if ($action === "list_degrees") {
    $sel = $pdo->query("SELECT id, name, acronym FROM courses ORDER BY name ASC");
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    respond(200, ["ok" => true, "degrees" => $rows]);
  }

  if ($action === "list_home_courses") {
    $acronym = safe_string($body["acronym"] ?? "");

    if ($acronym === "") {
      $accessToken = safe_string($_SESSION["fenix_access_token"] ?? "");
      if ($accessToken === "") respond(401, ["ok" => false, "error" => "not_authenticated"]);

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

  respond(400, ["ok" => false, "error" => "unknown_action"]);

  } catch (Throwable $e) {
  respond(500, ["ok" => false, "error" => "server_error"]);
}