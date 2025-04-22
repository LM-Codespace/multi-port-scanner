<?php
// Ensure the results directory exists
if (!file_exists('results')) {
    mkdir('results', 0755, true);
}

require_once 'db.php';  // Include the database connection

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check for valid proxy file upload
    if (!isset($_FILES['proxy_file']) || $_FILES['proxy_file']['error'] !== UPLOAD_ERR_OK) {
        response(false, 'Please upload a valid proxy file');
    }

    // Sanitize and extract input parameters
    $timeout = isset($_POST['timeout']) ? intval($_POST['timeout']) : 5;
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 20;
    $max_workers = isset($_POST['max_workers']) ? intval($_POST['max_workers']) : 20;
    $test_url = !empty($_POST['test_url']) ? escapeshellarg(trim($_POST['test_url'])) : escapeshellarg('http://www.google.com');
    $test_port = isset($_POST['test_port']) ? intval($_POST['test_port']) : 80;

    // Handle proxy file
    $temp_dir = sys_get_temp_dir();
    $proxy_file_path = tempnam($temp_dir, 'proxy_');

    if (!move_uploaded_file($_FILES['proxy_file']['tmp_name'], $proxy_file_path)) {
        response(false, 'Failed to move uploaded proxy file.');
    }

    // Result file setup
    $timestamp = time();
    $result_filename = "results/proxy_results_{$timestamp}.txt";

    // Build command
    $python_script = '/var/www/html/multi-port-scanner/proxy_checker.py';
    $cmd = sprintf(
        'python3 %s %s -o %s -t %d -b %d -w %d --test-url %s --test-port %d 2>&1',
        escapeshellarg($python_script),
        escapeshellarg($proxy_file_path),
        escapeshellarg($result_filename),
        $timeout,
        $batch_size,
        $max_workers,
        $test_url,
        $test_port
    );

    // Run Python script
    $output = shell_exec($cmd);

    // Read results
    if (!file_exists($result_filename)) {
        unlink($proxy_file_path);
        response(false, 'Failed to check proxies. Python script error: ' . $output);
    }

    $working_proxies = file($result_filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $working_count = count($working_proxies);
    $total_proxies = count(file($proxy_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    unlink($proxy_file_path);

    // Reset and insert into database
    try {
        $pdo->exec("TRUNCATE TABLE valid_proxies");
        $pdo->exec("ALTER TABLE valid_proxies AUTO_INCREMENT = 1");

        $stmt = $pdo->prepare("INSERT IGNORE INTO valid_proxies (ip, port) VALUES (?, ?)");

        foreach ($working_proxies as $proxy) {
            [$ip, $port] = explode(':', $proxy);
            $stmt->execute([$ip, $port]);
        }
    } catch (PDOException $e) {
        response(false, 'Database error: ' . $e->getMessage());
    }

    // Return JSON result
    response(true, 'Proxy check completed', [
        'total_proxies' => $total_proxies,
        'working_proxies' => $working_proxies,
        'percentage' => $total_proxies > 0 ? round(($working_count / $total_proxies) * 100, 1) : 0,
        'result_file' => $result_filename,
        'output' => $output
    ]);
}

/**
 * Helper function to send a JSON response and exit.
 */
function response($success, $message, $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}
?>
