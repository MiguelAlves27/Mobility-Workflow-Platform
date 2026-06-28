<?php
declare(strict_types=1);
require __DIR__ . "/config.php";

// Read $code before using it in the replay-protection check below
$code = $_GET["code"] ?? null;
if (!$code) {
  http_response_code(400);
  echo "Missing code";
  exit;
}

session_start();

if (isset($_SESSION["fenix_last_code"]) && $_SESSION["fenix_last_code"] === $code) {
  http_response_code(400);
  echo "Code already used";
  exit;
}
$_SESSION["fenix_last_code"] = $code;

$tokenUrl = "https://fenix.tecnico.ulisboa.pt/oauth/access_token";

$postFields = http_build_query([
  "client_id" => FENIX_CLIENT_ID,
  "client_secret" => FENIX_CLIENT_SECRET,
  "redirect_uri" => REDIRECT_URI,
  "code" => $code,
  "grant_type" => "authorization_code",
]);

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded"]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode >= 400) {
  http_response_code(500);
  echo "Token exchange failed";
  exit;
}

$data = json_decode($response, true);
if (!is_array($data) || empty($data["access_token"])) {
  http_response_code(500);
  echo "Invalid token response";
  exit;
}

$_SESSION["fenix_access_token"] = $data["access_token"];
$_SESSION["fenix_refresh_token"] = $data["refresh_token"] ?? null;
$_SESSION["fenix_expires_in"] = $data["expires_in"] ?? null;
$_SESSION["fenix_token_time"] = time();

header("Location: /confirmation_page.html");
exit;
