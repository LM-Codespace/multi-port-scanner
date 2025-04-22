<?php
// scan_worker.php
$ip = $argv[1] ?? null;

if (!$ip) exit;

// DB and proxy config
$servername = "localhost";
$username = "root";
$password = "pass";
$dbname = "proxy_checker";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) exit;

// Reuse original ping functions
require_once 'ping_functions.php';

// Get one working proxy
$res = $conn->query("SELECT ip, port, type FROM valid_proxies WHERE status = 'working' ORDER BY RAND() LIMIT 1");
if ($res && $res->num_rows > 0) {
    $proxy = $res->fetch_assoc();

    $is_live = ping_through_proxy($ip, 80, $proxy['ip'], $proxy['port'], $proxy['type']) ? 'live' : 'offline';

    $stmt = $conn->prepare("UPDATE ip_hosts SET is_live = ? WHERE ip_address = ?");
    $stmt->bind_param("ss", $is_live, $ip);
    $stmt->execute();
}
$conn->close();
