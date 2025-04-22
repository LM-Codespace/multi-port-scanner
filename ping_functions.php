<?php
function ping_through_proxy($target_ip, $target_port, $proxy_ip, $proxy_port, $type = 'socks5') {
    return $type === 'socks4'
        ? ping_via_socks4($target_ip, $target_port, $proxy_ip, $proxy_port)
        : ping_via_socks5($target_ip, $target_port, $proxy_ip, $proxy_port);
}

function ping_via_socks4($target_ip, $target_port, $proxy_ip, $proxy_port, $timeout = 5) {
    $fp = @stream_socket_client("tcp://$proxy_ip:$proxy_port", $errno, $errstr, $timeout);
    if (!$fp) return false;

    $ip_parts = explode('.', $target_ip);
    $port = pack('n', $target_port);
    $ip = pack('C4', ...$ip_parts);
    $userid = "";
    $request = pack('C', 0x04) . pack('C', 0x01) . $port . $ip . $userid . chr(0);

    fwrite($fp, $request);
    $response = fread($fp, 8);
    fclose($fp);

    return strlen($response) === 8 && ord($response[1]) === 0x5A;
}

function ping_via_socks5($target_ip, $target_port, $proxy_ip, $proxy_port, $timeout = 5) {
    $fp = @stream_socket_client("tcp://$proxy_ip:$proxy_port", $errno, $errstr, $timeout);
    if (!$fp) return false;

    stream_set_timeout($fp, $timeout);
    fwrite($fp, pack("C3", 0x05, 0x01, 0x00));
    $response = fread($fp, 2);
    if (strlen($response) !== 2 || ord($response[1]) !== 0x00) {
        fclose($fp);
        return false;
    }

    $ip_parts = explode('.', $target_ip);
    $port = pack('n', $target_port);
    $addr = pack('C4', ...$ip_parts);
    $request = pack('C4', 0x05, 0x01, 0x00, 0x01) . $addr . $port;

    fwrite($fp, $request);
    $response = fread($fp, 10);
    fclose($fp);

    return strlen($response) >= 10 && ord($response[1]) === 0x00;
}
