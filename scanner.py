import socks
import socket

def scan_port(host, port, proxy):
    ip, proxy_port = proxy
    s = socks.socksocket()
    s.set_proxy(socks.SOCKS5, ip, int(proxy_port))
    s.settimeout(5)
    try:
        s.connect((host, port))
        s.close()
        return True
    except Exception:
        return False
