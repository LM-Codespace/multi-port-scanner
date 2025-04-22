
<?php
$servername = "localhost";
$username = "root";
$password = "pass";
$dbname = "proxy_checker";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die(json_encode(['status' => 'error']));
}

$host_id = intval($_GET['id']);
$host_query = $conn->query("SELECT ip_address FROM ip_hosts WHERE id = $host_id LIMIT 1");
if (!$host_query || $host_query->num_rows === 0) {
    echo json_encode(['status' => 'not_found']);
    exit;
}
$host = $host_query->fetch_assoc();
$ip = $host['ip_address'];

// Get working proxies
$proxies_result = $conn->query("SELECT ip, port FROM valid_proxies WHERE status = 'working'");
$proxies = [];
while ($row = $proxies_result->fetch_assoc()) {
    $proxies[] = $row;
}

// Try host through proxies
$is_live = false;
foreach ($proxies as $proxy) {
    $proxy_ip = $proxy['ip'];
    $proxy_port = $proxy['port'];

    $ch = curl_init("http://$ip");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_PROXY, "$proxy_ip:$proxy_port");

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_errno($ch);
    curl_close($ch);

    if (!$error && $http_code >= 200 && $http_code < 400) {
        $is_live = true;
        break;
    }
}

// Update DB
$status = $is_live ? 'live' : 'offline';
$stmt = $conn->prepare("UPDATE ip_hosts SET is_live = ? WHERE id = ?");
$stmt->bind_param("si", $status, $host_id);
$stmt->execute();
$stmt->close();

echo json_encode(['status' => $status]);
?>
