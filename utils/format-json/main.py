import json

words_file_name = input("Base file path -> ")

with open(words_file_name, "r") as words_file:
  words_file_lines = words_file.read().split("\n")

words_hashmap = dict()

for line in words_file_lines:
  word = line.split(":")[0]
  print(word)
  raw_meanings = line.split(":")[1].split(",")
  meanings = []
  for meaning in raw_meanings:
    meanings.append(meaning.strip())
  words_hashmap[word] = meaning

print(json.dumps(words_hashmap))
