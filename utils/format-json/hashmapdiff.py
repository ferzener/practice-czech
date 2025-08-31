file1_path = input("file 1 path -> ")
file2_path = input("file 2 path -> ")

with open("file1_path", "r") as file1:
  file1_lines = file1.remove("{").remove("}").split(",")

with open("file2_path", "r") as file2:
  file2_lines = file2.remove("{").remove("}").split(",")

for line in file2_lines:
  if line in file1_lines:
    file2_lines.remove(line)

print("{" + ",".join(file2_lines) + "}")
