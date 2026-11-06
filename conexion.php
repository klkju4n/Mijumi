<?php
$dbHost = getenv("MYTEAM_DB_HOST") ?: "127.0.0.1";
$dbUser = getenv("MYTEAM_DB_USER") ?: "root"; // van a tener quev cambiar esto 
$dbPassword = getenv("MYTEAM_DB_PASSWORD");
if ($dbPassword === false) {
    $dbPassword = "";
}
$dbName = getenv("MYTEAM_DB_NAME") ?: "MyTeam";
$dbPort = (int) (getenv("MYTEAM_DB_PORT") ?: 3308);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName, $dbPort);
$db->set_charset("utf8mb4");