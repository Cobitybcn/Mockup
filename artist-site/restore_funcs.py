import re

with open('index.php', 'r', encoding='utf-8') as f:
    c = f.read()

php_code = '''
function admin_content_file(): string
{
    return __DIR__ . '/data/content.json';
}

function admin_save_content(array \): void
{
    file_put_contents(admin_content_file(), json_encode(\, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
'''

c = c.replace('function admin_clean_field(', php_code + '\nfunction admin_clean_field(')

with open('index.php', 'w', encoding='utf-8') as f:
    f.write(c)

print('Functions restored')
