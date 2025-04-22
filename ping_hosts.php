<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "pass";
$dbname = "proxy_checker";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Proxy functions
function getRandomWorkingProxy($conn) {
    $sql = "SELECT ip, port, type FROM valid_proxies WHERE status = 'working' ORDER BY RAND() LIMIT 1";
    $result = $conn->query($sql);
    return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
}

function ping_through_proxy($target_ip, $target_port, $proxy_ip, $proxy_port, $type = 'socks5', $timeout = 5) {
    $fp = @fsockopen($proxy_ip, $proxy_port, $errno, $errstr, $timeout);
    if (!$fp) return false;
    stream_set_timeout($fp, $timeout);

    if ($type === 'socks5') {
        fwrite($fp, pack("C3", 0x05, 0x01, 0x00));
        $resp = fread($fp, 2);
        if (strlen($resp) != 2 || ord($resp[1]) != 0x00) return false;

        $ip_parts = explode('.', $target_ip);
        $addr = pack('C4', ...$ip_parts);
        $port = pack('n', $target_port);
        fwrite($fp, pack("C4", 0x05, 0x01, 0x00, 0x01) . $addr . $port);
        $resp = fread($fp, 10);
        fclose($fp);
        return strlen($resp) >= 10 && ord($resp[1]) === 0x00;
    } elseif ($type === 'socks4') {
        $ip_parts = explode('.', $target_ip);
        $port = pack('n', $target_port);
        $ip = pack('C4', ...$ip_parts);
        $userid = "";
        $req = pack('C', 0x04) . pack('C', 0x01) . $port . $ip . $userid . chr(0);
        fwrite($fp, $req);
        $resp = fread($fp, 8);
        fclose($fp);
        return strlen($resp) === 8 && ord($resp[1]) === 0x5A;
    }
    return false;
}

// Handle single ping
if (isset($_POST['ping'])) {
    $ip_address = $_POST['ip_address'];
    $proxy = getRandomWorkingProxy($conn);
    if ($proxy) {
        $live = ping_through_proxy($ip_address, 80, $proxy['ip'], $proxy['port'], $proxy['type']) ? 'live' : 'offline';
        if ($live === 'offline') {
            $conn->query("DELETE FROM ip_hosts WHERE ip_address = '$ip_address'");
        } else {
            $stmt = $conn->prepare("UPDATE ip_hosts SET is_live = ? WHERE ip_address = ?");
            $stmt->bind_param("ss", $live, $ip_address);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// Handle delete
if (isset($_POST['delete_selected']) && isset($_POST['hosts'])) {
    foreach ($_POST['hosts'] as $host_id) {
        $conn->query("DELETE FROM ip_hosts WHERE id = $host_id");
    }
}

// Handle remove all
if (isset($_POST['remove_all'])) {
    $conn->query("DELETE FROM ip_hosts");
}

// Fetch all hosts
$hosts = $conn->query("SELECT * FROM ip_hosts");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ping Hosts</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .progress-container { margin: 10px 0; background: #ccc; border-radius: 20px; overflow: hidden; }
        .progress-bar { height: 20px; background: green; width: 0%; color: white; text-align: center; }
        .log-box { background: #111; color: #0f0; padding: 10px; font-family: monospace; height: 200px; overflow-y: auto; margin-top: 10px; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="sidebar">
        <a href="index.php">Home</a>
        <a href="view_proxies.php">View Stored Proxies</a>
        <a href="hosts.php">Hosts</a>
        <a href="ping_hosts.php">Ping Hosts</a>
    </div>

    <div class="main-content">
        <h1>Ping Hosts with Working Proxies</h1>

        <form method="POST" action="ping_hosts.php">
            <button type="submit" name="remove_all" class="btn">Remove All Hosts</button>
        </form>

        <form method="POST" action="ping_hosts.php">
            <button type="submit" name="delete_selected" class="btn">Delete Selected Hosts</button>
            <button type="button" onclick="scanAll()" class="btn">Scan All Hosts</button>
        </form>

        <div class="progress-container">
            <div id="progress-bar" class="progress-bar">0%</div>
        </div>

        <div id="log" class="log-box">Scan log will appear here...</div>

        <form method="POST" action="ping_hosts.php">
            <table class="host-table">
                <thead>
                <tr>
                    <th>Select</th>
                    <th>ID</th>
                    <th>IP Address</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                <?php
                if ($hosts->num_rows > 0) {
                    while ($row = $hosts->fetch_assoc()) {
                        echo "<tr>
                            <td><input type='checkbox' name='hosts[]' value='{$row['id']}'></td>
                            <td>{$row['id']}</td>
                            <td>{$row['ip_address']}</td>
                            <td class='status {$row['is_live']}'>{$row['is_live']}</td>
                            <td>
                                <form action='ping_hosts.php' method='POST'>
                                    <input type='hidden' name='ip_address' value='{$row['ip_address']}'>
                                    <button class='ping-btn' type='submit' name='ping'>Ping</button>
                                </form>
                            </td>
                        </tr>";
                    }
                } else {
                    echo "<tr><td colspan='5'>No hosts found</td></tr>";
                }
                ?>
                </tbody>
            </table>
        </form>
    </div>
</div>

<script>
async function scanAll() {
    const response = await fetch('scan_api.php');
    const hosts = await response.json();
    const log = document.getElementById('log');
    const bar = document.getElementById('progress-bar');

    for (let i = 0; i < hosts.length; i++) {
        const res = await fetch('scan_one.php?ip=' + hosts[i].ip_address);
        const data = await res.json();
        log.innerHTML += `[${i + 1}/${hosts.length}] ${data.ip} → ${data.status}<br>`;
        bar.style.width = ((i + 1) / hosts.length * 100).toFixed(0) + '%';
        bar.innerText = bar.style.width;
    }

    location.reload(); // Refresh page after scan
}
</script>
</body>
</html>

<?php $conn->close(); ?>
