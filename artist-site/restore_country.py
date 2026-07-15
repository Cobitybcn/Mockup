with open('index.php', 'r', encoding='utf-8') as f:
    c = f.read()

php_code = '''
function admin_country_options(): array
{
    return [
        '' => 'Select country',
        'Argentina' => 'Argentina',
        'Australia' => 'Australia',
        'Austria' => 'Austria',
        'Belgium' => 'Belgium',
        'Brazil' => 'Brazil',
        'Canada' => 'Canada',
        'Chile' => 'Chile',
        'Colombia' => 'Colombia',
        'Bulgaria' => 'Bulgaria',
        'Denmark' => 'Denmark',
        'France' => 'France',
        'Germany' => 'Germany',
        'Greece' => 'Greece',
        'Italy' => 'Italy',
        'Mexico' => 'Mexico',
        'Netherlands' => 'Netherlands',
        'Norway' => 'Norway',
        'Portugal' => 'Portugal',
        'Spain' => 'Spain',
        'Sweden' => 'Sweden',
        'Switzerland' => 'Switzerland',
        'United Kingdom' => 'United Kingdom',
        'United States' => 'United States',
        'Uruguay' => 'Uruguay',
    ];
}
'''

c = c.replace('function admin_content_file(): string', php_code + '\nfunction admin_content_file(): string')

with open('index.php', 'w', encoding='utf-8') as f:
    f.write(c)

print('Restored admin_country_options')
