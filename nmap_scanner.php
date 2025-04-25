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

// Ensure all required columns exist
$columns_to_check = ['dns_info', 'service_info', 'os_info', 'scan_details'];
foreach ($columns_to_check as $column) {
    $result = $conn->query("SHOW COLUMNS FROM ip_hosts LIKE '$column'");
    if ($result && $result->num_rows == 0) {
        $conn->query("ALTER TABLE ip_hosts ADD COLUMN $column TEXT NULL");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_hosts'])) {
    $scan_type = $_POST['scan_type'] ?? 'basic';
    $scan_intensity = $_POST['scan_intensity'] ?? 'normal';
    $selected_hosts = $_POST['selected_hosts'];

    // Randomize scan order if checkbox selected
    if (isset($_POST['randomize_order'])) {
        shuffle($selected_hosts);
    }

    foreach ($selected_hosts as $host_id) {
        $host_id = intval($host_id);
        $conn->query("UPDATE ip_hosts SET scan_status = 'queued' WHERE id = $host_id");

        $host_result = $conn->query("SELECT ip_address FROM ip_hosts WHERE id = $host_id");
        if ($host_result && $host_row = $host_result->fetch_assoc()) {
            $ip_address = $host_row['ip_address'];

            $scan_status = 'queued';
            $stmt = $conn->prepare("INSERT INTO scan_history (host_id, scan_type, scan_intensity, scan_status) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $host_id, $scan_type, $scan_intensity, $scan_status);
            $stmt->execute();

            if ($scan_type === 'domain') {
                $resolved_domain = gethostbyaddr($ip_address);
                $safe_domain = $conn->real_escape_string($resolved_domain ?: 'N/A');

                $whois = shell_exec("whois $ip_address");
                preg_match('/OrgName:\s*(.*)/i', $whois, $orgMatch);
                $asn_info = $orgMatch[1] ?? 'Unknown';
                $asn_info = $conn->real_escape_string($asn_info);

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

                $scan_status = 'completed';
                $stmt = $conn->prepare("UPDATE scan_history SET scan_status = ? WHERE host_id = ? AND scan_type = ? AND scan_intensity = ?");
                $stmt->bind_param("ssss", $scan_status, $host_id, $scan_type, $scan_intensity);
                $stmt->execute();
            } else {
                $cmd = "php /var/www/html/multi-port-scanner/scan_worker.php " .
                       escapeshellarg($ip_address) . " " .
                       escapeshellarg($host_id) . " " .
                       escapeshellarg($scan_type) . " " .
                       escapeshellarg($scan_intensity) .
                       " > /dev/null 2>&1 &";
                shell_exec($cmd);
            }
        }
    }
    header("Location: nmap_scanner.php");
    exit;
}

$common_ports = ['21/tcp', '22/tcp', '80/tcp', '443/tcp', '8080/tcp', '8443/tcp', '3306/tcp', '3389/tcp'];

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
    <title>Port Scanner</title>
    <link rel="stylesheet" href="/multi-port-scanner/style.css">
    <style>
        .terminal {
            background-color: #000;
            color: #0f0;
            padding: 10px;
            font-family: monospace;
            height: 300px;
            overflow-y: auto;
            border: 1px solid #333;
            margin-bottom: 20px;
        }
        .progress-container {
            width: 100%;
            background-color: #ddd;
            margin: 10px 0;
        }
        .progress-bar {
            height: 20px;
            background-color: #4CAF50;
            width: 0%;
            text-align: center;
            line-height: 20px;
            color: white;
        }
    </style>
</head>
<body class="nmap-scanner-page">

    <div class="sidebar">
        <nav>
            <ul>
                <li><a href="index.php" class="active">Home</a></li>
                <li><a href="view_proxies.php">View Stored Proxies</a></li>
                <li><a href="hosts.php">Hosts</a></li>
                <li><a href="ping_hosts.php">Ping Hosts</a></li>
                <li><a href="nmap_scanner.php">Nmap Scans</a></li>
            </ul>
        </nav>
    </div>

<div class="main-content">
    <h1>Port Scanner</h1>

    <?php if (isset($_GET['view_log']) && is_numeric($_GET['view_log'])): ?>
        <h2>Scan Output for Host #<?= intval($_GET['view_log']) ?></h2>
        <pre id="scan-terminal" class="terminal">Loading log...</pre>
        <div class="progress-container">
            <div id="progress-bar" class="progress-bar">0%</div>
        </div>
        <script>
            const hostId = <?= intval($_GET['view_log']) ?>;
            function updateProgress() {
                fetch(`scan_progress.php?host_id=${hostId}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.progress) {
                            const bar = document.getElementById('progress-bar');
                            bar.style.width = data.progress + '%';
                            bar.textContent = data.progress + '%';
                        }
                    });
            }
            setInterval(updateProgress, 1000);
            setInterval(() => {
                fetch(`scan_log_fetcher.php?host_id=${hostId}`)
                    .then(res => res.text())
                    .then(text => {
                        const terminal = document.getElementById('scan-terminal');
                        terminal.textContent = text;
                        terminal.scrollTop = terminal.scrollHeight;
                    });
            }, 1000);
        </script>
    <?php endif; ?>

    <form method="POST" action="nmap_scanner.php">
        <div class="form-group">
            <div class="form-row">
                <div class="form-col">
                    <label for="scan_type">Scan Type:</label>
                    <select name="scan_type" id="scan_type">
                        <option value="basic">Basic Scan (Top Ports)</option>
                        <option value="full">Full Port Scan</option>
                        <option value="services">Service Detection</option>
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
                    <label>
                        <input type="checkbox" name="randomize_order" value="1">
                        Randomize Scan Order
                    </label>
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
                        <th>Services</th>
                        <?php foreach ($common_ports as $port): ?>
                            <th><?= htmlspecialchars($port) ?></th>
                        <?php endforeach; ?>
                        <th>Last Scan</th>
                        <th>View Log</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $result = $conn->query("SELECT * FROM ip_hosts ORDER BY last_scan DESC");
                    if ($result && $result->num_rows > 0):
                        while ($scan_data = $result->fetch_assoc()):
                            $open_ports = isset($scan_data['open_ports']) ? explode(', ', $scan_data['open_ports']) : [];
                            $service_info = isset($scan_data['service_info']) ? json_decode($scan_data['service_info'], true) : [];
                            $service_count = count($service_info);
                    ?>
                            <tr>
                                <td><input type="checkbox" name="selected_hosts[]" value="<?= $scan_data['id'] ?>"></td>
                                <td><?= $scan_data['id'] ?></td>
                                <td><?= htmlspecialchars($scan_data['ip_address']) ?></td>
                                <td><?= htmlspecialchars($scan_data['resolved_domain'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($scan_data['asn_info'] ?? 'Unknown') ?></td>
                                <td><span class="status-badge <?= strtolower($scan_data['is_live']) ?>"><?= htmlspecialchars($scan_data['is_live']) ?></span></td>
                                <td><span class="status-badge <?= strtolower($scan_data['scan_status']) ?>"><?= htmlspecialchars($scan_data['scan_status']) ?></span></td>
                                <td><?= $service_count ?></td>
                                <?php foreach ($common_ports as $port): ?>
                                    <?php
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
                                <td><a href="?view_log=<?= $scan_data['id'] ?>">View Log</a></td>
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
    function toggleAll() {
        const checkboxes = document.querySelectorAll('input[name="selected_hosts[]"]');
        const isChecked = document.getElementById('select-all').checked;
        checkboxes.forEach(cb => cb.checked = isChecked);
    }
    function selectAllHosts() {
        document.querySelectorAll('input[name="selected_hosts[]"]').forEach(cb => cb.checked = true);
    }
    function clearSelection() {
        document.querySelectorAll('input[name="selected_hosts[]"]').forEach(cb => cb.checked = false);
    }
</script>

</body>
</html>

<?php
$conn->close();
?>
