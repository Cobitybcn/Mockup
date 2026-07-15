with open('index.php', 'r', encoding='utf-8') as f:
    c = f.read()

c = c.replace('array \\', 'array $content')
c = c.replace('json_encode(\\', 'json_encode($content')

with open('index.php', 'w', encoding='utf-8') as f:
    f.write(c)

print('Fixed syntax error')
