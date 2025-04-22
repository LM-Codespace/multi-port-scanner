import argparse
import os
import socket
import socks
import multiprocessing
from functools import partial
from datetime import datetime

# Verification methods
def verify_by_http(s, host='www.google.com'):
    req = f"GET / HTTP/1.0\r\nHost: {host}\r\n\r\n"
    s.connect((host, 80))
    s.send(req.encode("utf-8"))
    rsp = s.recv(4096)
    decoded = rsp.decode("utf-8", errors="ignore")
    return decoded.startswith("HTTP/1.0 200 OK") or decoded.startswith("HTTP/1.1 200 OK")

def verify_by_dns(s):
    req = b"\x00\x1b\x12\x34\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00\x05\x62\x61\x69\x64\x75\x03\x63\x6f\x6d\x00\x00\x01\x00\x01"
    s.connect(("8.8.8.8", 53))
    s.send(req)
    rsp = s.recv(4096)
    return rsp[2] == req[2] and rsp[3] == req[3]

def check_proxy_tcp(proxy, method='http', test_host='www.google.com', timeout=5):
    try:
        ip, port = proxy.strip().split(":")
        s = socks.socksocket(socket.AF_INET, socket.SOCK_STREAM)
        s.settimeout(timeout)
        s.set_proxy(socks.SOCKS5, ip, int(port))
        if method == 'http':
            result = verify_by_http(s, test_host)
        elif method == 'dns':
            result = verify_by_dns(s)
        else:
            result = False
        s.close()
        return proxy if result else None
    except Exception:
        return None

def process_batch(batch, method, test_host, timeout):
    return list(filter(None, [check_proxy_tcp(proxy, method, test_host, timeout) for proxy in batch]))

def main():
    parser = argparse.ArgumentParser(description="SOCKS5 Proxy Checker")
    parser.add_argument("input_file", help="Input file with list of proxies (ip:port)")
    parser.add_argument("-o", "--output_file", help="Output file for working proxies", default=None)
    parser.add_argument("-t", "--timeout", help="Timeout in seconds", type=int, default=5)
    parser.add_argument("-b", "--batch_size", help="Batch size", type=int, default=20)
    parser.add_argument("-w", "--workers", help="Number of parallel workers", type=int, default=20)
    parser.add_argument("--test-url", help="Test URL for HTTP method", default="www.google.com")
    parser.add_argument("--test-port", help="Test port (not used here but reserved)", type=int, default=80)
    parser.add_argument("--method", help="Method to test proxy: http or dns", choices=['http', 'dns'], default="http")

    args = parser.parse_args()

    # Read proxies
    with open(args.input_file, "r") as f:
        proxies = [line.strip() for line in f if line.strip()]

    total = len(proxies)
    print(f"Loaded {total} proxies to check using '{args.method}' method...")

    # Split into batches
    batches = [proxies[i:i + args.batch_size] for i in range(0, total, args.batch_size)]

    # Run with multiprocessing
    with multiprocessing.Pool(processes=args.workers) as pool:
        results = pool.map(partial(process_batch, method=args.method, test_host=args.test_url, timeout=args.timeout), batches)

    # Flatten results
    working_proxies = [proxy for batch in results for proxy in batch]

    # Output
    print(f"\nChecked {total} proxies.")
    print(f"Working proxies: {len(working_proxies)}")
    print(f"Success rate: {round((len(working_proxies)/total)*100, 2)}%")

    if not args.output_file:
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        args.output_file = f"results/proxy_results_{timestamp}.txt"

    os.makedirs(os.path.dirname(args.output_file), exist_ok=True)

    with open(args.output_file, "w") as f:
        f.write("\n".join(working_proxies))

    print(f"Results saved to: {args.output_file}")

if __name__ == "__main__":
    main()
