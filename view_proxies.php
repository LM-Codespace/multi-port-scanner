<?php
require_once 'db.php';

// Function to check if a proxy is working
function check_proxy($ip, $port) {
    $timeout = 10;
    $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if ($sock) {
        fclose($sock);
        return true;
    } else {
        return false;
    }
}

// Handle proxy deletion request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_selected'])) {
        // Delete selected proxies
        if (isset($_POST['proxies'])) {
            foreach ($_POST['proxies'] as $proxy_id) {
                $stmt = $pdo->prepare("DELETE FROM valid_proxies WHERE id = :id");
                $stmt->bindParam(':id', $proxy_id, PDO::PARAM_INT);
                $stmt->execute();
            }
        }
    } elseif (isset($_POST['remove_all'])) {
        // Remove all proxies
        $stmt = $pdo->prepare("DELETE FROM valid_proxies");
        $stmt->execute();
    } else {
        // Rescan proxies and update their status
        $stmt = $pdo->query("SELECT id, ip, port, last_checked, status FROM valid_proxies ORDER BY id ASC");
        $proxies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($proxies as $proxy) {
            $status = check_proxy($proxy['ip'], $proxy['port']) ? 'working' : 'not working';

            $stmt = $pdo->prepare("UPDATE valid_proxies SET status = :status, last_checked = NOW() WHERE id = :id");
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':id', $proxy['id'], PDO::PARAM_INT);
            $stmt->execute();
        }
        header('Location: view_proxies.php');
        exit;
    }
}

$stmt = $pdo->query("SELECT id, ip, port, last_checked, status FROM valid_proxies ORDER BY id ASC");
$proxies = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Proxies</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="sidebar">
        <nav>
            <ul>
                <li><a href="index.php">Home</a></li>
                <li><a href="view_proxies.php">View Proxies</a></li>
                <li><a href="hosts.php">Hosts</a></li>
                <li><a href="ping_hosts.php">Ping Hosts</a></li>
            </ul>
        </nav>
    </div>

    <div class="main-content">
        <h1>Proxies List</h1>

        <form method="POST" action="view_proxies.php">
            <button type="submit" class="btn">Rescan Proxies</button>
        </form>

        <form method="POST" action="view_proxies.php">
            <button type="submit" name="remove_all" class="btn">Remove All Proxies</button>
        </form>

        <form method="POST" action="view_proxies.php">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Select</th>
                            <th>ID</th>
                            <th>IP</th>
                            <th>Port</th>
                            <th>Status</th>
                            <th>Last Checked</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proxies as $proxy): ?>
                            <tr>
                                <td><input type="checkbox" name="proxies[]" value="<?php echo htmlspecialchars($proxy['id']); ?>"></td>
                                <td><?php echo htmlspecialchars($proxy['id']); ?></td>
                                <td><?php echo htmlspecialchars($proxy['ip']); ?></td>
                                <td><?php echo htmlspecialchars($proxy['port']); ?></td>
                                <td class="status <?php echo $proxy['status'] === 'working' ? 'live' : 'offline'; ?>">
                                    <?php echo htmlspecialchars($proxy['status']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($proxy['last_checked']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button type="submit" name="delete_selected" class="btn">Delete Selected Proxies</button>
        </form>
    </div>
</body>
</html>
