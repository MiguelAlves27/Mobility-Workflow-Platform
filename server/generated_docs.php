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

  $role = (string)$user["role"];

  $method = $_SERVER["REQUEST_METHOD"];

  if ($method === "GET") {
    $action = safe_string($_GET["action"] ?? "");

    if ($action !== "download") {
      respond(400, ["ok" => false, "error" => "unknown_action"]);
    }

    $pid = isset($_GET["pid"]) ? (int)$_GET["pid"] : 0;
    if ($pid <= 0) {
      respond(400, ["ok" => false, "error" => "invalid_process_id"]);
    }

    $base = realpath(dirname(__DIR__, 2));
    if ($base === false) {
      respond(500, ["ok" => false, "error" => "bad_base"]);
    }

    $dir = $base . "/_private/generated_docs";

    if (!is_dir($dir)) {
      respond(404, ["ok" => false, "error" => "no_generated_docs"]);
    }

    $pattern = $dir . "/" . $pid . "_*.zip";
    $files = glob($pattern);

    if (!$files || count($files) === 0) {
      respond(404, ["ok" => false, "error" => "no_generated_docs"]);
    }

    usort($files, function ($a, $b) {
      return strcmp(basename($b), basename($a));
    });

    $latestFile = $files[0];
    $oldFiles = array_slice($files, 1);

    $pq = $pdo->prepare("SELECT student_id FROM processes WHERE id = :pid LIMIT 1");
    $pq->execute([":pid" => $pid]);
    $processRow = $pq->fetch();

    if (!$processRow) {
      respond(404, ["ok" => false, "error" => "process_not_found"]);
    }

    $uq = $pdo->prepare("SELECT ist_id, name FROM users WHERE id = :uid LIMIT 1");
    $uq->execute([":uid" => $processRow["student_id"]]);
    $userRow = $uq->fetch();

    if (!$userRow) {
      respond(404, ["ok" => false, "error" => "student_not_found"]);
    }

    $istRaw = $userRow["ist_id"] ?? "unknown";
    $ist = strlen($istRaw) > 4 ? substr($istRaw, 4) : $istRaw;

    $name = preg_replace('/[^a-zA-Z0-9 _-]/', '', $userRow["name"] ?? "user");

    $downloadName = $ist . "_" . $name . ".zip";

    $tmpZipPath = tempnam(sys_get_temp_dir(), "proc_zip_");

    $zip = new ZipArchive();
    if ($zip->open($tmpZipPath, ZipArchive::OVERWRITE) !== true) {
      respond(500, ["ok" => false, "error" => "zip_create_failed"]);
    }

    $zip->addEmptyDir("documentos atuais");

    if (!empty($oldFiles)) {
      $zip->addEmptyDir("documentos antigos");
    }

    $currentZip = new ZipArchive();
    if ($currentZip->open($latestFile) === true) {
      for ($i = 0; $i < $currentZip->numFiles; $i++) {
        $stat = $currentZip->statIndex($i);
        if ($stat) {
          $stream = $currentZip->getStream($stat["name"]);
          if ($stream) {
            $content = stream_get_contents($stream);
            fclose($stream);
            $rawName = $stat["name"];
            $namePrefix = $name . "_";
            if (strncasecmp($rawName, $namePrefix, strlen($namePrefix)) === 0) {
              $rawName = substr($rawName, strlen($namePrefix));
            }
            $zip->addFromString("documentos atuais/" . $ist . "_" . $rawName, $content);
          }
        }
      }
      $currentZip->close();
    }

    if (!empty($oldFiles)) {
      foreach ($oldFiles as $old) {
        $zip->addFile($old, "documentos antigos/" . basename($old));
      }
    }

    $finalDir = $base . "/_private/final_generated_docs";
    $fpq = $pdo->prepare("SELECT id FROM final_processes WHERE student_id = :sid LIMIT 1");
    $fpq->execute([":sid" => $processRow["student_id"]]);
    $fpRow = $fpq->fetch();
    if ($fpRow && is_dir($finalDir)) {
      $fpid = (int)$fpRow["id"];
      $fpFiles = glob($finalDir . "/" . $fpid . "_*.zip");
      if ($fpFiles && count($fpFiles) > 0) {
        usort($fpFiles, fn($a, $b) => strcmp(basename($b), basename($a)));
        $zip->addEmptyDir("documentos finais");
        $pefZip = new ZipArchive();
        if ($pefZip->open($fpFiles[0]) === true) {
          for ($i = 0; $i < $pefZip->numFiles; $i++) {
            $stat = $pefZip->statIndex($i);
            if ($stat) {
              $stream = $pefZip->getStream($stat["name"]);
              if ($stream) {
                $content = stream_get_contents($stream);
                fclose($stream);
                $zip->addFromString("documentos finais/" . $stat["name"], $content);
              }
            }
          }
          $pefZip->close();
        }
      }
    }

    $zip->close();

    header("Content-Type: application/zip");
    header("Content-Disposition: attachment; filename=\"" . $downloadName . "\"");
    header("Content-Length: " . filesize($tmpZipPath));
    header("X-Content-Type-Options: nosniff");

    readfile($tmpZipPath);
    unlink($tmpZipPath);
    exit;
  }

  if ($method === "POST") {
    $raw = file_get_contents("php://input");
    $body = json_decode($raw, true);
    if (!is_array($body)) {
      respond(400, ["ok" => false, "error" => "invalid_json"]);
    }

    $action = safe_string($body["action"] ?? "");

    if ($action !== "list" && $action !== "download_all" && $action !== "has_zip") {
      respond(400, ["ok" => false, "error" => "unknown_action"]);
    }

    $base = realpath(dirname(__DIR__, 2));
    if ($base === false) {
      respond(500, ["ok" => false, "error" => "bad_base"]);
    }

    $docsDir = $base . "/_private/generated_docs";

    if ($action === "download_all") {
      $pids_raw = $body["pids"] ?? [];
      if (!is_array($pids_raw) || empty($pids_raw)) {
        respond(400, ["ok" => false, "error" => "no_pids"]);
      }

      $pids = array_values(array_unique(array_filter(array_map('intval', $pids_raw), fn($p) => $p > 0)));
      if (empty($pids)) {
        respond(400, ["ok" => false, "error" => "invalid_pids"]);
      }

      if (!is_dir($docsDir)) {
        respond(404, ["ok" => false, "error" => "no_generated_docs"]);
      }

      $tmpZipPath = tempnam(sys_get_temp_dir(), "bulk_zip_");
      $bulkZip = new ZipArchive();
      if ($bulkZip->open($tmpZipPath, ZipArchive::OVERWRITE) !== true) {
        respond(500, ["ok" => false, "error" => "zip_create_failed"]);
      }

      $finalDir = $base . "/_private/final_generated_docs";

      foreach ($pids as $pid) {
        $pattern = $docsDir . "/" . $pid . "_*.zip";
        $files = glob($pattern);
        if (!$files || count($files) === 0) continue;

        usort($files, fn($a, $b) => strcmp(basename($b), basename($a)));
        $latestFile = $files[0];
        $oldFiles = array_slice($files, 1);

        $pq = $pdo->prepare("SELECT student_id FROM processes WHERE id = :pid LIMIT 1");
        $pq->execute([":pid" => $pid]);
        $processRow = $pq->fetch();
        if (!$processRow) continue;

        $uq = $pdo->prepare("SELECT ist_id, name FROM users WHERE id = :uid LIMIT 1");
        $uq->execute([":uid" => $processRow["student_id"]]);
        $userRow = $uq->fetch();
        if (!$userRow) continue;

        $istRaw = $userRow["ist_id"] ?? "unknown";
        $ist = strlen($istRaw) > 4 ? substr($istRaw, 4) : $istRaw;
        $nameRaw = $userRow["name"] ?? "user";
        $nameAscii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nameRaw) ?: $nameRaw;
        $name = preg_replace('/[^a-zA-Z0-9 _-]/', '', $nameAscii);

        $folder = $ist . "_" . $name . "/";
        $bulkZip->addEmptyDir($folder . "documentos atuais");

        if (!empty($oldFiles)) {
          $bulkZip->addEmptyDir($folder . "documentos antigos");
        }

        $currentZip = new ZipArchive();
        if ($currentZip->open($latestFile) === true) {
          for ($i = 0; $i < $currentZip->numFiles; $i++) {
            $stat = $currentZip->statIndex($i);
            if ($stat) {
              $stream = $currentZip->getStream($stat["name"]);
              if ($stream) {
                $content = stream_get_contents($stream);
                fclose($stream);
                $bulkZip->addFromString($folder . "documentos atuais/" . $stat["name"], $content);
              }
            }
          }
          $currentZip->close();
        }

        if (!empty($oldFiles)) {
          foreach ($oldFiles as $old) {
            $bulkZip->addFile($old, $folder . "documentos antigos/" . basename($old));
          }
        }

        $fpq = $pdo->prepare("SELECT id FROM final_processes WHERE student_id = :sid LIMIT 1");
        $fpq->execute([":sid" => $processRow["student_id"]]);
        $fpRow = $fpq->fetch();
        if ($fpRow && is_dir($finalDir)) {
          $fpid = (int)$fpRow["id"];
          $fpFiles = glob($finalDir . "/" . $fpid . "_*.zip");
          if ($fpFiles && count($fpFiles) > 0) {
            usort($fpFiles, fn($a, $b) => strcmp(basename($b), basename($a)));
            $bulkZip->addEmptyDir($folder . "documentos finais");
            $pefZip = new ZipArchive();
            if ($pefZip->open($fpFiles[0]) === true) {
              for ($i = 0; $i < $pefZip->numFiles; $i++) {
                $stat = $pefZip->statIndex($i);
                if ($stat) {
                  $stream = $pefZip->getStream($stat["name"]);
                  if ($stream) {
                    $content = stream_get_contents($stream);
                    fclose($stream);
                    $bulkZip->addFromString($folder . "documentos finais/" . $stat["name"], $content);
                  }
                }
              }
              $pefZip->close();
            }
          }
        }
      }

      $bulkZip->close();

      header("Content-Type: application/zip");
      header("Content-Disposition: attachment; filename=\"todos_os_processos.zip\"");
      header("Content-Length: " . filesize($tmpZipPath));
      header("X-Content-Type-Options: nosniff");

      readfile($tmpZipPath);
      unlink($tmpZipPath);
      exit;
    }

    if ($action === "has_zip") {
      $pid = isset($body["pid"]) ? (int)$body["pid"] : 0;
      if ($pid <= 0) {
        respond(400, ["ok" => false, "error" => "invalid_process_id"]);
      }

      if ($role !== "ADMIN") {
        $userId = (int)$user["id"];
        $ccq = $pdo->prepare("SELECT course_id FROM coordinator_courses WHERE coordinator_id = ?");
        $ccq->execute([$userId]);
        $allowedCourses = $ccq->fetchAll(PDO::FETCH_COLUMN);

        $pq = $pdo->prepare("SELECT course_id FROM processes WHERE id = :pid LIMIT 1");
        $pq->execute([":pid" => $pid]);
        $pRow = $pq->fetch();

        if (!$pRow || !in_array((string)$pRow["course_id"], array_map('strval', $allowedCourses))) {
          respond(403, ["ok" => false, "error" => "forbidden"]);
        }
      }

      $hasGeneratedZip = false;
      if (is_dir($docsDir)) {
        $files = glob($docsDir . "/" . $pid . "_*.zip");
        $hasGeneratedZip = $files && count($files) > 0;
      }

      respond(200, ["ok" => true, "hasGeneratedZip" => $hasGeneratedZip]);
    }

    $userId = (int)$user["id"];

    $cc = $pdo->prepare("SELECT course_id FROM coordinator_courses WHERE coordinator_id = ?");
    $cc->execute([$userId]);
    $allowedCourses = $cc->fetchAll(PDO::FETCH_COLUMN);

    if (count($allowedCourses) === 0) {
      respond(200, ["ok" => true, "items" => []]);
    }

    $in = implode(",", array_fill(0, count($allowedCourses), "?"));

    $q = $pdo->prepare("
      SELECT
        p.id AS pid,
        p.student_id,
        u.ist_id,
        u.name,
        p.mobility_type,
        p.status,
        p.payload_json,
        p.submitted_at,
        p.created_at,
        p.updated_at
      FROM processes p
      JOIN users u ON u.id = p.student_id
      WHERE p.course_id IN ($in)
      ORDER BY p.updated_at DESC
    ");
    $q->execute($allowedCourses);

    $rows = $q->fetchAll();
    $items = [];

    foreach ($rows as $r) {
      $pid = (int)$r["pid"];

      $has = false;
      $latestFile = null;
      $sizeBytes = 0;
      $createdAt = null;

      if (is_dir($docsDir)) {
        $pattern = $docsDir . "/" . $pid . "_*.zip";
        $files = glob($pattern);

        if ($files && count($files) > 0) {
          usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
          });

          $latestFile = $files[0];
          $has = true;

          $sizeBytes = filesize($latestFile);
          $createdAt = date("Y-m-d H:i:s", filemtime($latestFile));
        }
      }

      $payload = !empty($r["payload_json"]) ? json_decode($r["payload_json"], true) : null;
      $hostCountry = safe_string($payload["host"]["host_country"] ?? "");
      $hostUniversity = safe_string($payload["host"]["host_university"] ?? "");

      $hasPef = false;
      $finalDir = $base . "/_private/final_generated_docs";
      $fpq = $pdo->prepare("SELECT id FROM final_processes WHERE student_id = :sid LIMIT 1");
      $fpq->execute([":sid" => $r["student_id"]]);
      $fpRow = $fpq->fetch();
      if ($fpRow && is_dir($finalDir)) {
        $fpFiles = glob($finalDir . "/" . (int)$fpRow["id"] . "_*.zip");
        $hasPef = $fpFiles && count($fpFiles) > 0;
      }

      $items[] = [
        "pid" => $pid,
        "studentId" => (int)$r["student_id"],
        "studentName" => safe_string($r["name"] ?? ""),
        "istId" => safe_string($r["ist_id"] ?? ""),
        "mobilityType" => safe_string($r["mobility_type"] ?? ""),
        "status" => safe_string($r["status"] ?? ""),
        "country" => $hostCountry,
        "university" => $hostUniversity,
        "submittedAt" => $r["submitted_at"],
        "updatedAt" => $r["updated_at"],
        "hasGeneratedZip" => $has,
        "hasPef" => $hasPef,
        "zipFilename" => $has ? basename($latestFile) : "",
        "zipMime" => $has ? "application/zip" : "",
        "zipSizeBytes" => $has ? $sizeBytes : 0,
        "zipCreatedAt" => $has ? $createdAt : null
      ];
    }

    respond(200, ["ok" => true, "items" => $items]);
  }

  respond(405, ["ok" => false, "error" => "method_not_allowed"]);
} catch (Throwable $e) {
  respond(500, ["ok" => false, "error" => "server_error"]);
}