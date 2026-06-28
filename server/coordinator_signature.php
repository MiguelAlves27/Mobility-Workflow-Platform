<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/helpers.php";

session_start();
header("Content-Type: application/json; charset=utf-8");

function is_debug(): bool {
  return isset($_GET["debug"]) && $_GET["debug"] === "1";
}

function detect_mime(string $path): string {
  $mime = "application/octet-stream";
  $fi = @finfo_open(FILEINFO_MIME_TYPE);
  if ($fi) {
    $d = @finfo_file($fi, $path);
    if (is_string($d) && $d !== "") $mime = $d;
    @finfo_close($fi);
    return $mime;
  }
  if (function_exists("mime_content_type")) {
    $d2 = @mime_content_type($path);
    if (is_string($d2) && $d2 !== "") $mime = $d2;
  }
  return $mime;
}

if (empty($_SESSION["fenix_access_token"]) || empty($_SESSION["person"])) {
  respond(401, ["ok" => false, "error" => "not_authenticated"]);
}

$person = $_SESSION["person"];
$istId = safe_string($person["username"] ?? "");
if ($istId === "") {
  respond(400, ["ok" => false, "error" => "missing_ist_id"]);
}

$displayName =
  safe_string($person["displayName"] ?? "") !== "" ? safe_string($person["displayName"] ?? "") :
  (safe_string($person["name"] ?? "") !== "" ? safe_string($person["name"] ?? "") : "Coordinator");

try {
  $pdo = pdo_connect();

  $u = $pdo->prepare("SELECT id, role FROM users WHERE ist_id = :ist_id LIMIT 1");
  $u->execute([":ist_id" => $istId]);
  $user = $u->fetch();

  if (!$user) {
    respond(403, ["ok" => false, "error" => "user_not_in_db"]);
  }

  $role = (string)($user["role"] ?? "");
  $userDbId = (int)($user["id"] ?? 0);

  if ($role !== "COORDINATOR" && $role !== "ADMIN") {
    respond(403, ["ok" => false, "error" => "forbidden"]);
  }

  $baseDir = realpath(dirname(__DIR__, 2));
  if ($baseDir === false) {
    respond(500, ["ok" => false, "error" => "base_dir_not_found"]);
  }

  $uploadsDirFs = $baseDir . "/_private/signatures";
  $publicBase = "/server/signature_file.php";

  if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $st = $pdo->prepare("
      SELECT ist_id, display_name, signature_path, updated_at,
             sending_institution, sending_department,
             coordinator_contact_europe, coordinator_contact_outside,
             coordinator_contact_double_degree, coordinator_email, coordinator_position
      FROM coordinator_signatures
      WHERE ist_id = :ist
      LIMIT 1
    ");
    $st->execute([":ist" => $istId]);
    $row = $st->fetch();

    if (!$row) {
      respond(200, [
        "ok" => true,
        "exists" => false,
        "ist_id" => $istId,
        "display_name" => $displayName,
        "sending_institution" => "",
        "sending_department" => "",
        "coordinator_contact_europe" => "",
        "coordinator_contact_outside" => "",
        "coordinator_contact_double_degree" => "",
        "coordinator_email" => "",
        "coordinator_position" => ""
      ]);
    }

    $dn = safe_string($row["display_name"] ?? "");
    if ($dn === "") $dn = $displayName;

    respond(200, [
      "ok" => true,
      "exists" => !empty($row["signature_path"]),
      "ist_id" => $istId,
      "display_name" => $dn,
      "signature_url" => safe_string($row["signature_path"] ?? ""),
      "updated_at" => $row["updated_at"] ?? null,
      "sending_institution" => safe_string($row["sending_institution"] ?? ""),
      "sending_department" => safe_string($row["sending_department"] ?? ""),
      "coordinator_contact_europe" => safe_string($row["coordinator_contact_europe"] ?? ""),
      "coordinator_contact_outside" => safe_string($row["coordinator_contact_outside"] ?? ""),
      "coordinator_contact_double_degree" => safe_string($row["coordinator_contact_double_degree"] ?? ""),
      "coordinator_email" => safe_string($row["coordinator_email"] ?? ""),
      "coordinator_position" => safe_string($row["coordinator_position"] ?? "")
    ]);
  }

  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(405, ["ok" => false, "error" => "method_not_allowed"]);
  }

  $dn = safe_string($_POST["display_name"] ?? "");
  if ($dn === "") $dn = $displayName;

  $si  = safe_string($_POST["sending_institution"] ?? "");
  $sd  = safe_string($_POST["sending_department"] ?? "");
  $cce = safe_string($_POST["coordinator_contact_europe"] ?? "");
  $cco = safe_string($_POST["coordinator_contact_outside"] ?? "");
  $ccd = safe_string($_POST["coordinator_contact_double_degree"] ?? "");
  $ce  = safe_string($_POST["coordinator_email"] ?? "");
  $cp  = safe_string($_POST["coordinator_position"] ?? "");

  $hasFile = isset($_FILES["signature"]) && is_array($_FILES["signature"])
    && isset($_FILES["signature"]["error"]) && (int)$_FILES["signature"]["error"] === UPLOAD_ERR_OK;

  $publicPath = null;

  if ($hasFile) {
    $f = $_FILES["signature"];

    $tmp = safe_string($f["tmp_name"] ?? "");
    if ($tmp === "" || !is_uploaded_file($tmp)) {
      respond(400, ["ok" => false, "error" => "invalid_uploaded_file"]);
    }

    $sizeBytes = (int)($f["size"] ?? 0);
    if ($sizeBytes <= 0) respond(400, ["ok" => false, "error" => "empty_file"]);

    $maxBytes = 2 * 1024 * 1024;
    if ($sizeBytes > $maxBytes) respond(413, ["ok" => false, "error" => "too_large", "max_bytes" => $maxBytes]);

    $mime = detect_mime($tmp);
    if ($mime !== "image/png") {
      respond(415, ["ok" => false, "error" => "must_be_png", "mime" => $mime]);
    }

    if (!is_dir($uploadsDirFs)) {
      if (!@mkdir($uploadsDirFs, 0755, true)) {
        respond(500, ["ok" => false, "error" => "cannot_create_dir"]);
      }
    }

    $fileName = preg_replace("/[^a-zA-Z0-9_]/", "_", $istId) . ".png";
    $destFs = $uploadsDirFs . "/" . $fileName;

    if (!@move_uploaded_file($tmp, $destFs)) {
      $last = error_get_last();
      clearstatcache(true, $uploadsDirFs);
      respond(500, [
        "ok" => false,
        "error" => "move_failed",
        "tmp" => $tmp,
        "dest" => $destFs,
        "dest_dir" => dirname($destFs),
        "dir_exists" => is_dir(dirname($destFs)),
        "dir_writable" => is_writable(dirname($destFs)),
        "dir_perms" => is_dir(dirname($destFs)) ? substr(sprintf("%o", fileperms(dirname($destFs))), -4) : null,
        "dir_owner" => is_dir(dirname($destFs)) ? fileowner(dirname($destFs)) : null,
        "open_basedir" => ini_get("open_basedir"),
        "last_error" => $last,
      ]);
    }

    $publicPath = $publicBase . "?f=" . rawurlencode($fileName);
  }

  $pdo->beginTransaction();
  try {
    $sigPath = $publicPath ?? "";
    $updateSig = $publicPath !== null ? "signature_path = VALUES(signature_path)," : "";

    $st = $pdo->prepare("
      INSERT INTO coordinator_signatures
        (ist_id, display_name, signature_path,
         sending_institution, sending_department,
         coordinator_contact_europe, coordinator_contact_outside,
         coordinator_contact_double_degree, coordinator_email, coordinator_position)
      VALUES (:ist, :dn, :path, :si, :sd, :cce, :cco, :ccd, :ce, :cp)
      ON DUPLICATE KEY UPDATE
        display_name = VALUES(display_name),
        $updateSig
        sending_institution = VALUES(sending_institution),
        sending_department = VALUES(sending_department),
        coordinator_contact_europe = VALUES(coordinator_contact_europe),
        coordinator_contact_outside = VALUES(coordinator_contact_outside),
        coordinator_contact_double_degree = VALUES(coordinator_contact_double_degree),
        coordinator_email = VALUES(coordinator_email),
        coordinator_position = VALUES(coordinator_position),
        updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
      ":ist" => $istId, ":dn" => $dn, ":path" => $sigPath,
      ":si" => $si, ":sd" => $sd, ":cce" => $cce, ":cco" => $cco, ":ccd" => $ccd, ":ce" => $ce, ":cp" => $cp
    ]);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    if (is_debug()) {
      respond(500, ["ok" => false, "error" => "db_error_save", "msg" => $e->getMessage()]);
    }
    respond(500, ["ok" => false, "error" => "db_error_save"]);
  }

  $respData = [
    "ok" => true,
    "ist_id" => $istId,
    "display_name" => $dn,
    "sending_institution" => $si,
    "sending_department" => $sd,
    "coordinator_contact_europe" => $cce,
    "coordinator_contact_outside" => $cco,
    "coordinator_contact_double_degree" => $ccd,
    "coordinator_email" => $ce,
    "coordinator_position" => $cp
  ];
  if ($publicPath !== null) $respData["signature_url"] = $publicPath;
  respond(200, $respData);

} catch (Throwable $e) {
  if (is_debug()) {
    respond(500, ["ok" => false, "error" => "server_error", "msg" => $e->getMessage(), "file" => $e->getFile(), "line" => $e->getLine()]);
  }
  respond(500, ["ok" => false, "error" => "server_error"]);
}
