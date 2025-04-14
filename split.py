import os
import csv
import random
import shutil
import time
from termcolor import colored

def split_large_csvs(base_dir="ip_ranges", max_lines=50000):
    """
    Split large CSV files into smaller chunks.

    :param base_dir: Directory containing CSV files.
    :param max_lines: Maximum number of lines per chunk.
    """
    if not os.path.exists(base_dir):
        print(colored("[ERROR] Base directory does not exist.", "red"))
        return

    for root, dirs, files in os.walk(base_dir):
        for file in files:
            if file.endswith(".csv") and "Split-Chunk" not in file:
                file_path = os.path.join(root, file)
                output_base = os.path.join(root, file.replace(".csv", "_Split-Chunk"))

                try:
                    with open(file_path, "r") as f:
                        reader = csv.reader(f)
                        lines = list(reader)

                    chunk_number = 1
                    for i in range(0, len(lines), max_lines):
                        chunk_file = f"{output_base}{chunk_number}.csv"
                        with open(chunk_file, "w") as cf:
                            writer = csv.writer(cf)
                            writer.writerows(lines[i:i + max_lines])
                        chunk_number += 1

                    print(colored(f"[INFO] Processed {file} into {chunk_number - 1} chunks.", "green"))

                except Exception as e:
                    log_error(f"Failed to process {file}: {e}")
              
split_large_csvs()
