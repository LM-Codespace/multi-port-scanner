<?php
// scan_worker.php

$ip = $argv[1] ?? null;
$host_id = intval($argv[2] ?? 0);  // Host ID passed in

if (!$ip || !$host_id) {
    echo "No IP or host ID provided.\n";
    exit;
}

// DB connection
$servername = "localhost";
$username = "root";
$password = "pass";
$dbname = "proxy_checker";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo "Connection failed: " . $conn->connect_error . "\n";
    exit;
}

// Check if the IP is a domain name or an actual IP address
if (filter_var($ip, FILTER_VALIDATE_IP)) {
    // It's already an IP address, no need for resolution
    $resolved_ip = $ip;
} else {
    // Resolve domain to IP
    $resolved_ip = gethostbyname($ip);
    if ($resolved_ip === $ip) {
        echo "Domain resolution failed for: $ip\n";
        exit;
    } else {
        echo "Resolved domain $ip to IP: $resolved_ip\n";
    }
}

// Fetch a working SOCKS5 proxy for this scan
$proxy_res = $conn->query("SELECT ip, port FROM valid_proxies WHERE status = 'working' ORDER BY RAND() LIMIT 1");
if ($proxy_res && $proxy_res->num_rows > 0) {
    $proxy = $proxy_res->fetch_assoc();
    $proxy_ip = $proxy['ip'];
    $proxy_port = $proxy['port'];
} else {
    echo "No working proxies available.\n";
    exit;
}

// Build a temporary proxychains config
$proxychains_config = "/tmp/proxychains_$host_id.conf";
file_put_contents($proxychains_config, "[ProxyList]\nsocks5 $proxy_ip $proxy_port\n");

// Command to run scan using proxychains (only for TCP connect scans)
$scan_command = "proxychains4 -f $proxychains_config -q nmap -sT -T4 -p- --open -Pn -v $resolved_ip";

// Execute scan
$scan_output = shell_exec($scan_command);

// Parse open ports
$open_ports = parse_nmap_output($scan_output);

// Update scan result in the database
$stmt = $conn->prepare("UPDATE ip_hosts SET scan_status = ?, open_ports = ?, last_scan = NOW() WHERE id = ?");
$status = 'completed';
$open_ports_str = $open_ports ?: 'None';
$stmt->bind_param("ssi", $status, $open_ports_str, $host_id);
$stmt->execute();

// Clean up
unlink($proxychains_config);
$conn->close();

// Parse Nmap output function
function parse_nmap_output($output) {
    preg_match_all('/(\d+\/tcp)\s+open/', $output, $matches);
    return implode(', ', $matches[1]);
}
?>
