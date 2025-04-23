<?php
// Database connection
$host = 'localhost';
$db = 'proxy_checker';
$user = 'root';
$pass = 'pass';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Ensure required columns exist
$columns_to_check = ['dns_info', 'service_info', 'os_info', 'scan_details'];
foreach ($columns_to_check as $column) {
    $result = $conn->query("SHOW COLUMNS FROM ip_hosts LIKE '$column'");
    if ($result && $result->num_rows == 0) {
        $conn->query("ALTER TABLE ip_hosts ADD COLUMN $column TEXT NULL");
    }
}

// Handle scan request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_hosts'])) {
    $scan_type = $_POST['scan_type'] ?? 'basic';
    $scan_intensity = $_POST['scan_intensity'] ?? 'normal';

    foreach ($_POST['selected_hosts'] as $host_id) {
        $host_id = intval($host_id);
        $conn->query("UPDATE ip_hosts SET scan_status = 'queued' WHERE id = $host_id");

        $host_result = $conn->query("SELECT ip_address FROM ip_hosts WHERE id = $host_id");
        if ($host_result && $host_row = $host_result->fetch_assoc()) {
            $ip_address = $host_row['ip_address'];

            if ($scan_type === 'domain') {
                $resolved_domain = gethostbyaddr($ip_address);
                $safe_domain = $conn->real_escape_string($resolved_domain ?: 'N/A');

                $whois = shell_exec("whois $ip_address");
                preg_match('/OrgName:\\s*(.*)/i', $whois, $orgMatch);
                $asn_info = $orgMatch[1] ?? 'Unknown';
                $asn_info = $conn->real_escape_string($asn_info);

                // Get additional DNS information
                $dns_records = @dns_get_record($resolved_domain ?: $ip_address, DNS_ALL);
                $dns_info = $dns_records ? json_encode($dns_records) : 'N/A';
                $dns_info = $conn->real_escape_string($dns_info);

                $conn->query("UPDATE ip_hosts SET
                    resolved_domain = '$safe_domain',
                    scan_status = 'resolved',
                    asn_info = '$asn_info',
                    dns_info = '$dns_info',
                    last_scan = NOW()
                    WHERE id = $host_id");
            } else {
                scan_target_with_proxy($ip_address, $host_id, $scan_type, $scan_intensity);
            }
        }
    }
    header("Location: nmap_scanner.php");
    exit;
}

$common_ports = ['21/tcp', '22/tcp', '80/tcp', '443/tcp', '8080/tcp', '8443/tcp', '3306/tcp', '3389/tcp'];

function scan_target_with_proxy($ip, $host_id, $scan_type, $intensity) {
    $command = "php /var/www/html/multi-port-scanner/scan_worker.php $ip $host_id $scan_type $intensity > /dev/null 2>&1 &";
    shell_exec($command);
}

function get_service_name($port) {
    $services = [
        '21/tcp' => 'FTP',
        '22/tcp' => 'SSH',
        '80/tcp' => 'HTTP',
        '443/tcp' => 'HTTPS',
        '8080/tcp' => 'HTTP-Alt',
        '8443/tcp' => 'HTTPS-Alt',
        '3306/tcp' => 'MySQL',
        '3389/tcp' => 'RDP'
    ];
    return $services[$port] ?? 'Unknown';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Nmap Scanner</title>
    <link rel="stylesheet" href="/multi-port-scanner/style.css">
</head>
<body class="nmap-scanner-page">

<nav class="sidebar">
    <a href="/multi-port-scanner/index.php">Dashboard</a>
    <a href="/multi-port-scanner/nmap_scanner.php" class="active">Nmap Scanner</a>
    <a href="/multi-port-scanner/hosts.php">Host Management</a>
    <a href="/multi-port-scanner/settings.php">Settings</a>
</nav>

<div class="main-content">
    <h1>Nmap Scanner</h1>

    <form method="POST" action="nmap_scanner.php">
        <div class="form-group">
            <div class="form-row">
                <div class="form-col">
                    <label for="scan_type">Scan Type:</label>
                    <select name="scan_type" id="scan_type">
                        <option value="basic">Basic Scan (Top Ports)</option>
                        <option value="full">Full Port Scan</option>
                        <option value="services">Service Detection</option>
                        <option value="os">OS Detection</option>
                        <option value="vuln">Vulnerability Scan</option>
                        <option value="domain">Domain Resolution Only</option>
                    </select>
                </div>
                <div class="form-col">
                    <label for="scan_intensity">Intensity:</label>
                    <select name="scan_intensity" id="scan_intensity">
                        <option value="normal">Normal</option>
                        <option value="aggressive">Aggressive</option>
                        <option value="stealthy">Stealthy</option>
                    </select>
                </div>
                <div class="form-col">
                    <button type="submit" class="btn">Scan Selected</button>
                    <button type="button" class="btn" onclick="selectAllHosts()">Select All</button>
                    <button type="button" class="btn" onclick="clearSelection()">Clear</button>
                </div>
            </div>
        </div>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th width="40px"><input type="checkbox" id="select-all" onclick="toggleAll()"></th>
                        <th>ID</th>
                        <th>IP Address</th>
                        <th>Domain</th>
                        <th>ASN/Org</th>
                        <th>Status</th>
                        <th>Scan</th>
                        <th>OS</th>
                        <th>Services</th>
                        <?php foreach ($common_ports as $port): ?>
                            <th><?= htmlspecialchars($port) ?></th>
                        <?php endforeach; ?>
                        <th>Last Scan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $result = $conn->query("SELECT * FROM ip_hosts ORDER BY last_scan DESC");
                    if ($result && $result->num_rows > 0):
                        while ($scan_data = $result->fetch_assoc()):
                            $open_ports = isset($scan_data['open_ports']) ? explode(', ', $scan_data['open_ports']) : [];
                            $service_info = isset($scan_data['service_info']) ? json_decode($scan_data['service_info'], true) : [];
                            $os_info = isset($scan_data['os_info']) ? json_decode($scan_data['os_info'], true) : [];
                            $os_guess = $os_info['os_match'][0]['name'] ?? 'Unknown';
                            $service_count = count($service_info);
                            $scan_details = isset($scan_data['scan_details']) ? substr($scan_data['scan_details'], 0, 100) : '';
                    ?>
                            <tr>
                                <td><input type="checkbox" name="selected_hosts[]" value="<?= $scan_data['id'] ?>"></td>
                                <td><?= $scan_data['id'] ?></td>
                                <td><?= htmlspecialchars($scan_data['ip_address']) ?></td>
                                <td>
                                    <?= htmlspecialchars($scan_data['resolved_domain'] ?? 'N/A') ?>
                                    <?php if (!empty($scan_data['dns_info'])): ?>
                                        <span class="info-icon" title="<?= htmlspecialchars(substr($scan_data['dns_info'], 0, 200)) ?>...">ℹ️</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($scan_data['asn_info'] ?? 'Unknown') ?></td>
                                <td><span class="status-badge <?= strtolower($scan_data['is_live']) ?>"><?= htmlspecialchars($scan_data['is_live']) ?></span></td>
                                <td><span class="status-badge <?= strtolower($scan_data['scan_status']) ?>"><?= htmlspecialchars($scan_data['scan_status']) ?></span></td>
                                <td><?= htmlspecialchars($os_guess) ?></td>
                                <td><?= $service_count ?></td>
                                <?php foreach ($common_ports as $port):
                                    $service = $service_info[$port] ?? [];
                                    $service_name = $service['name'] ?? '';
                                    $service_version = $service['version'] ?? '';
                                ?>
                                    <td>
                                        <?= in_array($port, $open_ports) ? '<span class="status-success">✓</span>' : '<span class="status-error">✗</span>' ?>
                                        <?php if (!empty($service_name)): ?>
                                            <div class="service-details">
                                                <?= htmlspecialchars($service_name) ?>
                                                <?php if (!empty($service_version)): ?>
                                                    <small><?= htmlspecialchars($service_version) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td><?= htmlspecialchars($scan_data['last_scan']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= (11 + count($common_ports)) ?>">No hosts found. Add hosts in Host Management.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<script>
    function selectAllHosts() {
        const checkboxes = document.querySelectorAll('input[name="selected_hosts[]"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
    }

    function clearSelection() {
        const checkboxes = document.querySelectorAll('input[name="selected_hosts[]"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
    }

    function toggleAll() {
        const masterCheckbox = document.getElementById('select-all');
        const checkboxes = document.querySelectorAll('input[name="selected_hosts[]"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = masterCheckbox.checked;
        });
    }

    // Auto-refresh the page every 60 seconds to update scan status
    setTimeout(function() {
        window.location.reload();
    }, 60000);
</script>

</body>
</html>
