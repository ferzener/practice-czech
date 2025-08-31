import json

words_file_name = input("Base file path")

with open(words_file_name, "r") as words_file:
  words_file_lines = words_file.read().split("\n")

words_hashmap = dict()

for line in words_file_lines:
  word = line.split('"')[1].split['"'][0]
  raw_meanings = line.split("[")[1].split("]")[0]
  meanings = []
  for meaning in raw_meanings:
    meanings.append(strip(meaning))
  words_hashmap[word] = meaning

print(json.dumps(words_hashmap))
