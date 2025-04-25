<?php
// Ensure the results directory exists
if (!file_exists('results')) {
    mkdir('results', 0755, true);
}

require_once 'db.php';  // Include the database connection

function scrapeProxiesFromUrls($urls) {
    $allProxies = [];
    $proxyPattern = '/\b(?:\d{1,3}\.){3}\d{1,3}:\d{1,5}\b/';

    foreach ($urls as $url) {
        $url = trim($url);
        if (empty($url)) continue;

        try {
            $context = stream_context_create([
                'http' => ['timeout' => 10],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
            ]);

            $content = file_get_contents($url, false, $context);
            if ($content === false) continue;

            preg_match_all($proxyPattern, $content, $matches);
            if (!empty($matches[0])) {
                $allProxies = array_merge($allProxies, $matches[0]);
            }
        } catch (Exception $e) {
            error_log("Failed to scrape proxies from $url: " . $e->getMessage());
            continue;
        }
    }

    return array_unique($allProxies);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $proxies = [];
    $proxySources = [];

    // Handle file upload if provided
    if (isset($_FILES['proxy_file']) && $_FILES['proxy_file']['error'] === UPLOAD_ERR_OK) {
        $proxy_file_path = tempnam(sys_get_temp_dir(), 'proxy_');
        if (move_uploaded_file($_FILES['proxy_file']['tmp_name'], $proxy_file_path)) {
            $fileProxies = file($proxy_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $proxies = array_merge($proxies, $fileProxies);
            unlink($proxy_file_path);
            $proxySources[] = 'file';
        }
    }

    // Handle URL scraping if provided
    if (!empty($_POST['proxy_urls'])) {
        $urls = explode("\n", trim($_POST['proxy_urls']));
        $urlProxies = scrapeProxiesFromUrls($urls);
        $proxies = array_merge($proxies, $urlProxies);
        if (!empty($urlProxies)) {
            $proxySources[] = 'urls';
        }
    }

    if (empty($proxies)) {
        response(false, 'Please provide either a proxy file or URLs to scrape proxies from');
    }

    // Sanitize: Keep only IP:PORT formatted proxies
    $valid_format_proxies = array_filter($proxies, function($proxy) {
        return preg_match('/^\d{1,3}(?:\.\d{1,3}){3}:\d{1,5}$/', trim($proxy));
    });

    // Insert into database in chunks of 50
    try {
        $pdo->exec("TRUNCATE TABLE valid_proxies");
        $pdo->exec("ALTER TABLE valid_proxies AUTO_INCREMENT = 1");

        $stmt = $pdo->prepare("INSERT IGNORE INTO valid_proxies (ip, port) VALUES (?, ?)");

        $chunks = array_chunk($valid_format_proxies, 50);
        foreach ($chunks as $chunk) {
            foreach ($chunk as $proxy) {
                [$ip, $port] = explode(':', $proxy);
                $stmt->execute([$ip, $port]);
            }
        }

        response(true, "Inserted " . count($valid_format_proxies) . " proxies into the database (sources: " . implode(' and ', $proxySources) . ").");
    } catch (PDOException $e) {
        response(false, 'Database error: ' . $e->getMessage());
    }
}

/**
 * Helper function to send a JSON response and exit.
 */
function response($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}
?>
