import csv
from netaddr import IPRange
from db import add_host

def import_hosts_from_csv(csv_path, ports):
    with open(csv_path, newline='') as csvfile:
        reader = csv.reader(csvfile)
        rows = list(reader)

        # Check if there's a header
        if rows[0][0].lower() in ['range', 'start']:
            rows = rows[1:]  # skip header

        for row in rows:
            try:
                if len(row) == 1 and '-' in row[0]:
                    start_ip, end_ip = row[0].split('-')
                elif len(row) == 2:
                    start_ip, end_ip = row[0].strip(), row[1].strip()
                else:
                    print(f"[!] Invalid row format: {row}")
                    continue

                for ip in IPRange(start_ip, end_ip):
                    add_host(str(ip), ports)

            except Exception as e:
                print(f"[!] Error parsing row {row}: {e}")
