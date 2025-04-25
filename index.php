<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SOCKS5 Proxy Checker</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrapper index-page">
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
        <h1>SOCKS5 Proxy Checker</h1>

        <form action="run_checker.php" method="post" enctype="multipart/form-data" id="checker-form">
            <div class="form-group">
                <label for="proxy_file">Proxy List File (ip:port format):</label>
                <input type="file" name="proxy_file" id="proxy_file" accept=".txt,.csv">
                <small>One proxy per line in format: 127.0.0.1:1080</small>
            </div>

            <div class="form-group">
                <label for="proxy_urls">Or enter URLs to scrape proxies from (one per line):</label>
               <textarea name="proxy_urls" id="proxy_urls" class="results-textarea" placeholder="https://example.com/proxylist.txt
                https://anothersite.com/proxies.html"></textarea>

                <small>Enter URLs that contain proxy lists (IP:PORT format)</small>
            </div>

            <div class="form-group">
                <label for="timeout">Connection Timeout (seconds):</label>
                <input type="number" name="timeout" id="timeout" value="5" min="1" max="30">
            </div>

            <div class="form-group">
                <label for="batch_size">Batch Size:</label>
                <input type="number" name="batch_size" id="batch_size" value="20" min="1" max="100">
            </div>

            <div class="form-group">
                <label for="max_workers">Max Workers per Batch:</label>
                <input type="number" name="max_workers" id="max_workers" value="20" min="1" max="100">
            </div>

            <div class="form-group">
                <label for="test_url">Test URL:</label>
                <input type="text" name="test_url" id="test_url" value="www.google.com">
            </div>

            <div class="form-group">
                <label for="test_port">Test Port:</label>
                <input type="number" name="test_port" id="test_port" value="80" min="1" max="65535">
            </div>

            <div class="form-group">
                <label for="method">Test Method:</label>
                <select name="method" id="method">
                    <option value="http">HTTP</option>
                    <option value="dns">DNS</option>
                </select>
            </div>

            <div class="form-group">
                <button type="submit">Check Proxies</button>
            </div>
        </form>

        <div class="progress" id="progress-container">
            <h3>Processing...</h3>
            <div class="progress-bar">
                <div class="progress-bar-fill" id="progress-bar"></div>
                <div id="progress-text">0%</div>
            </div>
        </div>

        <div class="results" id="results-container">
            <h2>Results</h2>
            <div id="results-content"></div>
            <a href="#" id="download-link" class="btn" style="display:none;">Download Results</a>
        </div>
    </div>
</div>

<script>
    document.getElementById('checker-form').addEventListener('submit', function(e) {
        e.preventDefault();
        document.getElementById('progress-container').style.display = 'block';
        document.getElementById('results-container').style.display = 'none';

        const formData = new FormData(this);

        fetch('run_checker.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            let progress = 0;
            const progressBar = document.getElementById('progress-bar');
            const progressText = document.getElementById('progress-text');
            const interval = setInterval(() => {
                progress += 5;
                if (progress >= 100) {
                    clearInterval(interval);
                    showResults(data);
                }
                progressBar.style.width = progress + '%';
                progressText.textContent = progress + '%';
            }, 200);
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('results-content').innerHTML =
                '<div class="error">An error occurred while processing your request.</div>';
            document.getElementById('results-container').style.display = 'block';
        });
    });

    function showResults(data) {
        const resultsContainer = document.getElementById('results-container');
        const resultsContent = document.getElementById('results-content');
        const downloadLink = document.getElementById('download-link');

        document.getElementById('progress-container').style.display = 'none';
        resultsContainer.style.display = 'block';

        if (data.success) {
            resultsContent.innerHTML = `
                <p>Total proxies checked: ${data.total_proxies}</p>
                <p>Working proxies: ${data.working_proxies.length} (${data.percentage}%)</p>
                <h3>Working Proxies:</h3>
                <textarea class="results-textarea" readonly>${data.working_proxies.join('\n')}</textarea>
            `;
            if (data.result_file) {
                downloadLink.href = data.result_file;
                downloadLink.download = 'working_proxies.txt';
                downloadLink.textContent = 'Download Full Results';
                downloadLink.style.display = 'inline-block';
            }
        } else {
            resultsContent.innerHTML = `<div class="error">${data.message}</div>`;
        }
    }
</script>
</body>
</html>
