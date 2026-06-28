<?php
declare(strict_types=1);
require_once __DIR__ . "/config.php";

session_start();
header("Content-Type: application/json; charset=utf-8");

$token = $_SESSION["fenix_access_token"] ?? null;
if (!$token) {
  http_response_code(401);
  echo json_encode(["authenticated" => false]);
  exit;
}

function fenixGet(string $url, string $token): array
{
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $token,
    "Accept: application/json"
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  if ($response === false || $httpCode >= 400) {
    return [
      "ok" => false,
      "http_code" => $httpCode,
      "error" => $curlError ?: "request_failed",
      "data" => null
    ];
  }

  $data = json_decode($response, true);

  return [
    "ok" => true,
    "http_code" => $httpCode,
    "error" => null,
    "data" => $data
  ];
}

$personUrl = "https://fenix.tecnico.ulisboa.pt/api/fenix/v1/person?lang=en-GB";
$curriculumUrl = "https://fenix.tecnico.ulisboa.pt/api/fenix/v1/person/curriculum?lang=en-GB";

$personResult = fenixGet($personUrl, $token);
$curriculumResult = fenixGet($curriculumUrl, $token);

if (!$personResult["ok"] || !$curriculumResult["ok"]) {
  http_response_code(401);
  echo json_encode([
    "authenticated" => false,
    "error" => "token_invalid"
  ]);
  exit;
}

$_SESSION["person"] = $personResult["data"];
$_SESSION["curriculum"] = $curriculumResult["data"];

echo json_encode([
  "authenticated" => true,
  "person" => $personResult["data"],
  "curriculum" => $curriculumResult["data"]
]);