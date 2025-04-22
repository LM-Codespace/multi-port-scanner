<?php
$conn = new mysqli("localhost", "root", "pass", "proxy_checker");
$result = $conn->query("SELECT ip_address FROM ip_hosts");
$hosts = [];
while ($row = $result->fetch_assoc()) {
    $hosts[] = $row;
}
echo json_encode($hosts);
$conn->close();
?>
root@s
