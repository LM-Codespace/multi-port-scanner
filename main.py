from db import init_db, get_hosts_to_scan, get_proxies, update_last_scanned
from scanner import scan_port
from concurrent.futures import ThreadPoolExecutor, as_completed
import random
import csv
from import_hosts import import_hosts_from_csv
from proxy_loader import load_proxies_from_url


def scan_host(host_id, host, ports, proxy):
    results = []
    for port in ports:
        open_status = scan_port(host, port, proxy)
        results.append((host, port, open_status))
    update_last_scanned(host_id)
    return results

def run():
    init_db()

    proxies = get_proxies()
    hosts = get_hosts_to_scan()
    
    if not proxies or not hosts:
        print("No proxies or hosts available for scanning.")
        return

    with ThreadPoolExecutor(max_workers=10) as executor:
        futures = []
        for host_id, host, ports_str in hosts:
            ports = list(map(int, ports_str.split(',')))
            proxy = random.choice(proxies)
            futures.append(executor.submit(scan_host, host_id, host, ports, proxy))

        all_results = []
        for future in as_completed(futures):
            res = future.result()
            all_results.extend(res)
            for host, port, is_open in res:
                print(f"{host}:{port} is {'open' if is_open else 'closed'}")

    export_to_csv(all_results)

def export_to_csv(results, filename="scan_results.csv"):
    with open(filename, "w", newline="") as csvfile:
        writer = csv.writer(csvfile)
        writer.writerow(["Host", "Port", "Status"])
        for host, port, status in results:
            writer.writerow([host, port, "open" if status else "closed"])

if __name__ == "__main__":
    init_db()
    # CONFIGURE THESE:
    proxy_url = "https://raw.githubusercontent.com/user/proxy-list/main/socks5.txt"
    hosts_csv = "host_ranges.csv"
    ports = [22, 80, 443]  # You can customize this

    load_proxies_from_url(proxy_url)
    import_hosts_from_csv(hosts_csv, ports)
    run()
