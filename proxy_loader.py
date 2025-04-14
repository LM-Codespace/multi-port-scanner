import requests
from db import add_proxy

def load_proxies_from_url(url):
    try:
        response = requests.get(url)
        response.raise_for_status()
        lines = response.text.splitlines()
        for line in lines:
            if ':' in line:
                ip, port = line.strip().split(':')
                add_proxy(ip.strip(), int(port.strip()))
    except Exception as e:
        print(f"[!] Failed to load proxies: {e}")
