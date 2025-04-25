<?php
// scan_worker.php - Rewritten for better reliability

$host = 'localhost';
$db = 'proxy_checker';
$user = 'root';
$pass = 'pass';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$ip_address = $argv[1] ?? '';
$host_id = intval($argv[2] ?? 0);
$scan_type = $argv[3] ?? 'basic';
$intensity = $argv[4] ?? 'normal';

if (empty($ip_address) || $host_id <= 0) {
    die("Invalid parameters");
}

// Update scan status
$conn->query("UPDATE ip_hosts SET scan_status = 'scanning' WHERE id = $host_id");

// Initialize variables
$open_ports = [];
$service_info = [];
$scan_details = "Scan started at: " . date('Y-m-d H:i:s') . "\n";

// Define ports to scan based on scan type
$ports_to_scan = [];
switch ($scan_type) {
    case 'basic':
        $ports_to_scan = [21, 22, 80, 443, 8080, 8443, 3306, 3389];
        break;
    case 'full':
        $ports_to_scan = range(1, 1024); // Scanning all 65535 ports would take too long
        break;
    case 'services':
        $ports_to_scan = [21, 22, 23, 25, 53, 80, 110, 143, 443, 465, 587, 993, 995, 3306, 3389];
        break;
    case 'sshcheck':
        $ports_to_scan = [22];
        break;
    default:
        $ports_to_scan = [21, 22, 80, 443, 8080, 8443];
}

// Set timeout based on intensity
$timeout = $intensity === 'stealthy' ? 5 : ($intensity === 'aggressive' ? 1 : 3);

foreach ($ports_to_scan as $port) {
    // Get a random working SOCKS5 proxy
    $proxy = $conn->query("SELECT ip, port FROM valid_proxies WHERE type = 'socks5' AND status = 'working' ORDER BY RAND() LIMIT 1");
    if (!$proxy || !$proxy_data = $proxy->fetch_assoc()) {
        $scan_details .= "No working proxies available for port $port\n";
        continue;
    }

    $proxy_ip = $proxy_data['ip'];
    $proxy_port = $proxy_data['port'];
    $conn->query("UPDATE valid_proxies SET last_checked = NOW() WHERE ip = '$proxy_ip' AND port = $proxy_port");

    $scan_details .= "Scanning port $port using proxy $proxy_ip:$proxy_port\n";

    // Use fsockopen through SOCKS5 proxy
    $fp = @fsockopen("tcp://$proxy_ip", $proxy_port, $errno, $errstr, $timeout);
    if (!$fp) {
        $scan_details .= "Failed to connect to proxy: $errstr ($errno)\n";
        continue;
    }

    // Send CONNECT request to proxy
    fwrite($fp, "\x05\x01\x00"); // SOCKS5 handshake
    $response = fread($fp, 2);
    if ($response !== "\x05\x00") {
        $scan_details .= "SOCKS5 authentication failed\n";
        fclose($fp);
        continue;
    }

    // Send connection request to target
    $request = "\x05\x01\x00\x01"; // SOCKS5 connect command
    $ipParts = explode('.', $ip_address);
    $request .= chr($ipParts[0]) . chr($ipParts[1]) . chr($ipParts[2]) . chr($ipParts[3]);
    $request .= pack('n', $port);
    fwrite($fp, $request);

    // Read response
    $response = fread($fp, 10);
    if (strlen($response) < 10) {
        $scan_details .= "No response from proxy for port $port\n";
        fclose($fp);
        continue;
    }

    // Check if connection was successful (response byte 1 should be 0x00)
    if ($response[1] === "\x00") {
        $open_ports[] = "$port/tcp";

        // Try to get service banner if this is a service scan
        if ($scan_type === 'services' || $scan_type === 'sshcheck') {
            $banner = '';
            stream_set_timeout($fp, $timeout);
            $banner = fread($fp, 1024);

            if (!empty($banner)) {
                $service_name = getServiceName($port);
                $service_info["$port/tcp"] = ['name' => $service_name, 'version' => trim($banner)];
                $scan_details .= "Service banner for port $port: $banner\n";
            }
        }

        $scan_details .= "Port $port is open\n";
    } else {
        $scan_details .= "Port $port is closed\n";
    }

    fclose($fp);
    usleep(5000); // Small delay between scans
}

// Update database with results
$open_ports_str = implode(', ', $open_ports);
$service_info_json = $conn->real_escape_string(json_encode($service_info));
$scan_details_escaped = $conn->real_escape_string($scan_details);

$conn->query("UPDATE ip_hosts SET
    open_ports = '$open_ports_str',
    service_info = '$service_info_json',
    scan_details = '$scan_details_escaped',
    scan_status = 'completed',
    last_scan = NOW()
    WHERE id = $host_id");

$stmt = $conn->prepare("UPDATE scan_history SET scan_status = 'completed' WHERE host_id = ? AND scan_type = ? AND scan_intensity = ?");
$stmt->bind_param("iss", $host_id, $scan_type, $intensity);
$stmt->execute();

$conn->close();

function getServiceName($port) {
    $services = [
        21 => 'FTP',
        22 => 'SSH',
        23 => 'Telnet',
        25 => 'SMTP',
        53 => 'DNS',
        80 => 'HTTP',
        110 => 'POP3',
        143 => 'IMAP',
        443 => 'HTTPS',
        465 => 'SMTPS',
        587 => 'SMTP',
        993 => 'IMAPS',
        995 => 'POP3S',
        3306 => 'MySQL',
        3389 => 'RDP'
    ];

    return $services[$port] ?? 'Unknown';
}
?>
