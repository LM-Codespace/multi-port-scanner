<?php

function check_proxy($ip, $port) {
    // Example of checking a proxy with cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://$ip:$port");  // You might want to use a more reliable test URL
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);  // Timeout of 10 seconds

    $result = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['is_working' => $status_code == 200];
}
?>
root@server94458:/var/www/html/multi-port-scanner# cat hosts.php
<?php
$servername = "localhost";
$username = "root";
$password = "pass";
$dbname = "proxy_checker";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function ip2long_custom($ip) {
    return sprintf('%u', ip2long($ip));
}

function getIpRange($start_ip, $end_ip) {
    $ips = [];
    $start = ip2long($start_ip);
    $end = ip2long($end_ip);
    if ($start === false || $end === false) return [];

    for ($ip = $start; $ip <= $end; $ip++) {
        $ips[] = long2ip($ip);
    }
    return $ips;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['start_ip'], $_POST['end_ip'])) {
    $start_ip = $_POST['start_ip'];
    $end_ip = $_POST['end_ip'];
    $ip_range = getIpRange($start_ip, $end_ip);

    foreach ($ip_range as $ip) {
        $stmt = $conn->prepare("INSERT IGNORE INTO ip_hosts (ip_address, date_added, is_live) VALUES (?, NOW(), 'unknown')");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
    }

    header("Location: hosts.php");
    exit;
}

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$result = $conn->query("SELECT * FROM ip_hosts ORDER BY id ASC LIMIT 20 OFFSET $offset");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Hosts</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .table-container {
            max-height: 400px; /* Set the height of the container */
            overflow-y: scroll; /* Enable vertical scrolling */
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th, table td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .load-more {
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <nav>
            <ul>
                <li><a href="index.php">Home</a></li>
                <li><a href="view_proxies.php">View Stored Proxies</a></li>
                <li><a href="hosts.php">Hosts</a></li>
                <li><a href="ping_hosts.php">Ping Hosts</a></li>
                <li><a href="nmap_scanner.php">Nmap Scans</a></li>
        </ul>
        </nav>
    </div>

    <div class="main-content">
        <h1>Hosts Management</h1>

        <form method="POST" class="form">
            <label for="start_ip">Start IP:</label>
            <input type="text" id="start_ip" name="start_ip" required>
            <label for="end_ip">End IP:</label>
            <input type="text" id="end_ip" name="end_ip" required>
            <button type="submit" class="btn">Add IP Range</button>
        </form>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>IP Address</th>
                        <th>Date Added</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php
                    if ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $statusClass = $row['is_live'] === 'live' ? 'status live' : ($row['is_live'] === 'offline' ? 'status offline' : 'status unknown');
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['ip_address']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['date_added']) . "</td>";
                            echo "<td class='{$statusClass}'>" . htmlspecialchars($row['is_live']) . "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='4'>No hosts found.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <div class="load-more">
            <button id="loadMore" onclick="loadMoreRows()">Load More</button>
        </div>
    </div>

    <script>
        let offset = <?php echo $offset + 20; ?>; // Starting offset after the initial 20 rows

        function loadMoreRows() {
            // Send an AJAX request to fetch more rows
            let xhr = new XMLHttpRequest();
            xhr.open("GET", "hosts.php?offset=" + offset, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    let newRows = xhr.responseText;
                    let tbody = document.getElementById("tableBody");
                    tbody.innerHTML += newRows; // Append new rows to the table body
                    offset += 20; // Increase offset for next load
                }
            };
            xhr.send();
        }
    </script>
</body>
</html>

<?php $conn->close(); ?>
