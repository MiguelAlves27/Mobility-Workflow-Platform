<?php
declare(strict_types=1);
require __DIR__ . "/config.php";

session_start();
$_SESSION = [];
session_destroy();

header("Location: " . BASE_PATH . "/index.html");
exit;
