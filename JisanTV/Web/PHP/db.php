<?php
$db_host = "sql301.infinityfree.com";
$db_user = "if0_40760436";
$db_pass = "RedZone20031100";
$db_name = "if0_40760436_LiveTV";

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
date_default_timezone_set('Asia/Dhaka');

if (!$conn) {
    die(json_encode(["error" => "Database Connection Failed"]));
}
?>