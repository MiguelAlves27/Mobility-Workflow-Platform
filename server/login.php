<?php
declare(strict_types=1);
require __DIR__ . "/config.php";

$url = "https://fenix.tecnico.ulisboa.pt/oauth/userdialog"
  . "?client_id=" . urlencode(FENIX_CLIENT_ID)
  . "&redirect_uri=" . urlencode(REDIRECT_URI);

header("Location: " . $url);
exit;
