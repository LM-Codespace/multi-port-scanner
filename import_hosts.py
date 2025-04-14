import csv
from netaddr import IPRange
from db import add_host

def import_hosts_from_csv(csv_path, ports):
    with open(csv_path, newline='') as csvfile:
        reader = csv.DictReader(csvfile)
        for row in reader:
            if '-' in row['range']:
                start_ip, end_ip = row['range'].split('-')
                for ip in IPRange(start_ip.strip(), end_ip.strip()):
                    add_host(str(ip), ports)
            else:
                add_host(row['range'].strip(), ports)
