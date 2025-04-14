import requests
from db import add_proxy

def load_proxies_from_file(file_path="socks5.txt"):
    try:
        with open(file_path, "r") as file:
            proxies = [line.strip() for line in file if line.strip()]
            cursor.executemany("INSERT OR IGNORE INTO proxies (proxy) VALUES (?)", [(p,) for p in proxies])
            conn.commit()
            print(f"[+] Loaded {len(proxies)} proxies from file")
    except Exception as e:
        print(f"[!] Failed to load proxies from file: {e}")
