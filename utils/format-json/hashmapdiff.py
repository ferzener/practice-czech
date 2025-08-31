import json
import sys

file1_path = input("file 1 path -> ")
file2_path = input("file 2 path -> ")

# Load both files as JSON objects (dicts)
with open(file1_path, "r", encoding="utf-8") as f1:
    obj1 = json.load(f1)  # keys to remove

with open(file2_path, "r", encoding="utf-8") as f2:
    obj2 = json.load(f2)  # source map

# Remove from obj2 any key that appears in obj1
keys_to_remove = set(obj1.keys())
result = {k: v for k, v in obj2.items() if k not in keys_to_remove}

# Print valid JSON (preserve Unicode)
json.dump(result, sys.stdout, ensure_ascii=False, indent=2, sort_keys=True)
print()
