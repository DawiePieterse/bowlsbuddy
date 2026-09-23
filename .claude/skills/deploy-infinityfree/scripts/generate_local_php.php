<?php
/**
 * Writes config/autoload/local.php from INFINITYFREE_DB_* env vars.
 * Never echoes the values it reads - only whether they were found.
 *
 * Usage: php generate_local_php.php <output-path>
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php generate_local_php.php <output-path>\n");
    exit(1);
}

$outputPath = $argv[1];

$db = [
    'database' => getenv('INFINITYFREE_DB_NAME'),
    'username' => getenv('INFINITYFREE_DB_USER'),
    'password' => getenv('INFINITYFREE_DB_PASSWORD'),
    'hostname' => getenv('INFINITYFREE_DB_HOST'),
];

foreach ($db as $k => $v) {
    if ($v === false || $v === '') {
        fwrite(STDERR, "Missing env var for db.$k\n");
        exit(1);
    }
}

$template = <<<'PHP'
<?php
/**
 * Local application configuration
 *
 * Insert your local database credentials here
 * and provide the email address the system should use.
 */

return [
    'db' => [
        'database' => %s,
        'username' => %s,
        'password' => %s,

        'hostname' => %s,
        'port' => null,
    ],
    'mail' => [
        'type' => 'file', // saves outgoing mail to data/mails/ instead of sending it.
            // InfinityFree disables PHP's mail() and no SMTP credentials were provided.
            // To send real mail later, change this to 'smtp' (or 'smtp-tls') and fill in
            // the host/user/pw/port/auth fields below with your SMTP provider's details.
        'address' => 'noreply@bowlsbuddy.42web.io',

        'host' => '?', // for 'smtp' type only, otherwise remove or leave as is
        'user' => '?', // for 'smtp' type only, otherwise remove or leave as is
        'pw' => '?', // for 'smtp' type only, otherwise remove or leave as is

        'port' => 'auto', // for 'smtp' type only, otherwise remove or leave as is
        'auth' => 'plain', // for 'smtp' type only, change this to 'login' if you have problems with SMTP authentication
    ],
    'i18n' => [
        'choice' => [
            'en-US' => 'English',
            'de-DE' => 'Deutsch',

            // More possible languages:
            // 'fr-FR' => 'Français',
            // 'hu-HU' => 'Magyar',
        ],

        'currency' => 'EUR',

        // The language is usually detected from the user's web browser.
        // If it cannot be detected automatically and there is no cookie from a manual language selection,
        // the following locale will be used as the default "fallback":
        'locale' => 'en-US',
    ],
];

PHP;

$out = sprintf(
    $template,
    var_export($db['database'], true),
    var_export($db['username'], true),
    var_export($db['password'], true),
    var_export($db['hostname'], true)
);

file_put_contents($outputPath, $out);

if (php_sapi_name() === 'cli') {
    $lint = shell_exec('php -l ' . escapeshellarg($outputPath) . ' 2>&1');
    fwrite(STDOUT, trim($lint) . "\n");
}
