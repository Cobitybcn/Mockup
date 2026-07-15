import re

c = open('index.php', encoding='utf-8').read()
funcs = re.findall(r'([a-zA-Z0-9_]+)\s*\(', c)
funcs = set(funcs)
print('Extracted', len(funcs), 'function calls')
