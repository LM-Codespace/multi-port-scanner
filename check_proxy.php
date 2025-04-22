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
