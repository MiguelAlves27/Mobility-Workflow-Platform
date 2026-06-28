<?php declare(strict_types=1);

const BASE_PATH = "";
const REDIRECT_URI = "https://mobilidade.dei.tecnico.ulisboa.pt/server/callback.php";

// Harden session cookies - must be set before session_start()
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS'])) {
  ini_set('session.cookie_secure', '1');
}

$secretsFile = "/afs/ist.utl.pt/groups/mobilidade-dei/_private/fenix_oauth.php";
if (!is_readable($secretsFile)) {
  http_response_code(500);
  echo "Configuration error";
  exit;
}
require $secretsFile;
