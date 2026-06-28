<?php
declare(strict_types=1);

if (!defined("REDIRECT_URI")) define("REDIRECT_URI", "https://mobilidade.dei.tecnico.ulisboa.pt/server/callback.php");

$secretsFile = "/afs/ist.utl.pt/groups/mobilidade-dei/_private/mobility_db.php";
if (!file_exists($secretsFile)) {
  http_response_code(500);
  echo "Secrets file not found";
  exit;
}

require_once $secretsFile;
