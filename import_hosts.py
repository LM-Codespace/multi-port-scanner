import csv
from netaddr import IPRange
from db import add_host

def import_hosts_from_csv(csv_path, ports):
    with open(csv_path, newline='') as csvfile:
        reader = csv.DictReader(csvfile)
        if 'range' not in reader.fieldnames:
            raise ValueError("CSV file must have a 'range' column as the header.")

        for row in reader:
            try:
                ip_range = row['range'].strip()
                if '-' in ip_range:
                    start_ip, end_ip = ip_range.split('-')
                    for ip in IPRange(start_ip.strip(), end_ip.strip()):
                        add_host(str(ip), ports)
                else:
                    add_host(ip_range, ports)
            except Exception as e:
                print(f"[!] Error parsing row {row}: {e}")
