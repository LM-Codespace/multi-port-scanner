import sqlite3

DB_NAME = "scanner.db"

def init_db():
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS hosts (
            id INTEGER PRIMARY KEY,
            host TEXT NOT NULL,
            ports TEXT NOT NULL,
            last_scanned TIMESTAMP
        )
    ''')
    cursor.execute('''
    CREATE TABLE IF NOT EXISTS proxies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        proxy TEXT UNIQUE
    )
    ''')
    conn.commit()
    conn.close()

def add_host(host, ports):
    ports_str = ",".join(map(str, ports))
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute("INSERT INTO hosts (host, ports, last_scanned) VALUES (?, ?, NULL)", (host, ports_str))
    conn.commit()
    conn.close()

def add_proxy(ip, port):
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute("INSERT INTO proxies (ip, port) VALUES (?, ?)", (ip, port))
    conn.commit()
    conn.close()

def update_last_scanned(host_id):
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute("UPDATE hosts SET last_scanned = CURRENT_TIMESTAMP WHERE id = ?", (host_id,))
    conn.commit()
    conn.close()

def get_hosts_to_scan(delay_minutes=60):
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute(f"""
        SELECT id, host, ports FROM hosts 
        WHERE last_scanned IS NULL OR last_scanned <= datetime('now', '-{delay_minutes} minutes')
    """)
    rows = cursor.fetchall()
    conn.close()
    return rows

def get_proxies():
    conn = sqlite3.connect(DB_NAME)
    cursor = conn.cursor()
    cursor.execute("SELECT ip, port FROM proxies")
    proxies = cursor.fetchall()
    conn.close()
    return proxies
