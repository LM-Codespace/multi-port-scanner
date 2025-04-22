<?php
$conn = new mysqli("localhost", "root", "pass", "proxy_checker");

function getRandomWorkingProxy($conn) {
    $sql = "SELECT ip, port, type FROM valid_proxies WHERE status = 'working' ORDER BY RAND() LIMIT 1";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
}

function ping_through_proxy($ip, $port, $proxy_ip, $proxy_port, $type = 'socks5') {
    $fp = @fsockopen($proxy_ip, $proxy_port, $errno, $errstr, 5);
    if (!$fp) return false;
    stream_set_timeout($fp, 5);
    if ($type === 'socks5') {
        fwrite($fp, pack("C3", 0x05, 0x01, 0x00));
        $r = fread($fp, 2);
        if (strlen($r) !== 2 || ord($r[1]) !== 0x00) return false;
        $addr = pack('C4', ...explode('.', $ip));
        $p = pack('n', $port);
        fwrite($fp, pack('C4', 0x05, 0x01, 0x00, 0x01) . $addr . $p);
        $r = fread($fp, 10);
        fclose($fp);
        return strlen($r) >= 10 && ord($r[1]) === 0x00;
    }
    return false;
}

$ip = $_GET['ip'] ?? '';
$proxy = getRandomWorkingProxy($conn);
$status = 'offline';

if ($ip && $proxy) {
    $live = ping_through_proxy($ip, 80, $proxy['ip'], $proxy['port'], $proxy['type']);
    if ($live) {
        $stmt = $conn->prepare("UPDATE ip_hosts SET is_live = 'live' WHERE ip_address = ?");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $status = 'live';
    } else {
        $stmt = $conn->prepare("DELETE FROM ip_hosts WHERE ip_address = ?");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $status = 'offline';
    }
}

echo json_encode(['ip' => $ip, 'status' => $status]);
$conn->close();
?>
