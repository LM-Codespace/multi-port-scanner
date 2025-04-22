<?php
// Database connection (adjust as needed)
$servername = "localhost";
$username = "root";
$password = "pass";
$dbname = "proxy_checker";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get the host IP from the request
$host = isset($_GET['host']) ? $_GET['host'] : null;

if (!$host) {
    echo json_encode(['success' => false, 'message' => 'No host specified']);
    exit();
}

// Fetch a working proxy (you can choose one proxy or loop through proxies)
$sql_proxies = "SELECT ip, port FROM valid_proxies WHERE status = 'working' LIMIT 1";
$result_proxies = $conn->query($sql_proxies);

if ($result_proxies->num_rows > 0) {
    $proxy = $result_proxies->fetch_assoc();
    $proxy_ip = $proxy['ip'];
    $proxy_port = $proxy['port'];

    // Ping the host through the selected proxy
    function ping_host($host, $proxy_ip, $proxy_port) {
        $connection = @fsockopen($proxy_ip, $proxy_port, $errno, $errstr, 10); // Timeout after 10 seconds
        if (!$connection) {
            return false; // Proxy is not reachable
        }

        // Try to make a simple HTTP request through the proxy to the host
        $request = "GET http://$host HTTP/1.1\r\n";
        $request .= "Host: $host\r\n";
        $request .= "Connection: Close\r\n\r\n";

        fwrite($connection, $request);

        // Read the response
        $response = fread($connection, 512);  // Read the response (you can adjust the length as needed)

        fclose($connection);

        return !empty($response);
    }

    $ping_success = ping_host($host, $proxy_ip, $proxy_port);

    if ($ping_success) {
        echo json_encode(['success' => true, 'message' => 'Host is reachable through proxy']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Host is not reachable through proxy']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No working proxies found']);
}

// Close the database connection
$conn->close();
?>
