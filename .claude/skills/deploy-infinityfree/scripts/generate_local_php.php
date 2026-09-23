<?php
/**
 * Writes config/autoload/local.php by filling in the repo's own
 * config/autoload/local.php.dist with INFINITYFREE_DB_* env vars, so this
 * stays in sync with local.php.dist instead of carrying its own copy of the
 * mail/i18n sections that could silently drift.
 *
 * Never echoes the values it reads - only whether they were found.
 *
 * Usage: php generate_local_php.php <output-path>
 * (expects <output-path>.dist to exist alongside it)
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php generate_local_php.php <output-path>\n");
    exit(1);
}

$outputPath = $argv[1];
$distPath = $outputPath . '.dist';

if (! is_readable($distPath)) {
    fwrite(STDERR, "Missing dist template: $distPath\n");
    exit(1);
}

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

/**
 * Replaces $needle with $replacement, or fails loudly if $needle isn't found -
 * so a future edit to local.php.dist that changes this text breaks the build
 * instead of silently shipping a stale/wrong config.
 */
function replaceOrFail(string $haystack, string $needle, string $replacement, string $label): string
{
    if (! str_contains($haystack, $needle)) {
        fwrite(STDERR, "generate_local_php.php: expected to find $label in local.php.dist but didn't - "
            . "the dist file's wording has probably changed, update this script's replacements to match.\n");
        exit(1);
    }

    return str_replace($needle, $replacement, $haystack);
}

$content = file_get_contents($distPath);

$content = replaceOrFail($content, "'database' => '?',", "'database' => " . var_export($db['database'], true) . ',', 'the db.database placeholder');
$content = replaceOrFail($content, "'username' => '?',", "'username' => " . var_export($db['username'], true) . ',', 'the db.username placeholder');
$content = replaceOrFail($content, "'password' => '?',", "'password' => " . var_export($db['password'], true) . ',', 'the db.password placeholder');
$content = replaceOrFail($content, "'hostname' => 'localhost',", "'hostname' => " . var_export($db['hostname'], true) . ',', 'the db.hostname default');

$content = replaceOrFail(
    $content,
    "'type' => 'sendmail', // or 'smtp' or 'smtp-tls' (or 'file', to not send, but save to file (data/mails/))",
    "'type' => 'file', // saves outgoing mail to data/mails/ instead of sending it.\n"
        . "            // InfinityFree disables PHP's mail() and no SMTP credentials were provided.\n"
        . "            // To send real mail later, change this to 'smtp' (or 'smtp-tls') and fill in\n"
        . "            // the host/user/pw/port/auth fields below with your SMTP provider's details.",
    'the mail.type default'
);
$content = replaceOrFail($content, "'address' => 'info@bookings.example.com',", "'address' => 'noreply@bowlsbuddy.42web.io',", 'the mail.address default');
$content = replaceOrFail($content, "'locale' => 'de-DE',", "'locale' => 'en-US',", 'the i18n.locale default');

file_put_contents($outputPath, $content);

$lint = trim((string) shell_exec('php -l ' . escapeshellarg($outputPath) . ' 2>&1'));
fwrite(STDOUT, $lint . "\n");

if (! str_contains($lint, 'No syntax errors detected')) {
    exit(1);
}
