<?php
declare(strict_types=1);

require_once __DIR__ . "/helpers.php";

function get_approved_equivalences_by_university(string $university, ?string $country = null): array {
  $pdo = pdo_connect();

  $university = safe_string($university);
  $country = $country !== null ? safe_string($country) : null;

  if ($university === "") return [];

  if ($country !== null && $country !== "") {
    $stmt = $pdo->prepare("
      SELECT *
      FROM approved_equivalences
      WHERE host_university = :uni
        AND host_country = :country
      ORDER BY approved_at DESC
    ");
    $stmt->execute([
      ":uni" => $university,
      ":country" => $country
    ]);
  } else {
    $stmt = $pdo->prepare("
      SELECT *
      FROM approved_equivalences
      WHERE host_university = :uni
      ORDER BY approved_at DESC
    ");
    $stmt->execute([
      ":uni" => $university
    ]);
  }

  $rows = $stmt->fetchAll();
  $out = [];

  foreach ($rows as $r) {
    $hostCourses = json_decode($r["host_courses"], true);
    $homeCourses = json_decode($r["home_courses"], true);

    $out[] = [
      "id" => (int)$r["id"],
      "pid" => (int)$r["pid"],
      "hostCountry" => $r["host_country"],
      "hostUniversity" => $r["host_university"],
      "hostCourses" => is_array($hostCourses) ? $hostCourses : [],
      "homeCourses" => is_array($homeCourses) ? $homeCourses : [],
      "totalHostEcts" => (float)$r["total_host_ects"],
      "totalHomeEcts" => (float)$r["total_home_ects"],
      "approvedAt" => $r["approved_at"],
      "approvedBy" => $r["approved_by"],
      "equivalenceId" => $r["equivalence_id"]
    ];
  }

  return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['university'])) {
  header("Content-Type: application/json");

  $uni = $_GET['university'] ?? '';
  $country = $_GET['country'] ?? null;

  try {
    $data = get_approved_equivalences_by_university($uni, $country);

    echo json_encode([
      "ok" => true,
      "count" => count($data),
      "data" => $data
    ]);
  } catch (Exception $e) {
    echo json_encode([
      "ok" => false,
      "error" => $e->getMessage()
    ]);
  }

  exit;
}