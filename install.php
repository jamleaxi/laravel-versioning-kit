<?php

/*
|--------------------------------------------------------------------------
| Laravel Versioning Kit - installer
|--------------------------------------------------------------------------
|
| Usage:  php install.php [path/to/laravel-project] [--version=1.0] [--force]
|
| Sets up automatic MAJOR.MINOR.BUILD versioning:
|   VERSION               hand-edited MAJOR.MINOR
|   .githooks/pre-commit  writes version.json on every commit (BUILD = commit count)
|   config/app.php        exposes it as config('app.version')
|   composer.json         re-enables the hook on every `composer install`
|
| Safe to re-run: every step skips itself when already done.
|
*/

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$base = '1.0';
$target = getcwd();

foreach ($args as $arg) {
    if (str_starts_with($arg, '--version=')) {
        $base = substr($arg, strlen('--version='));
    } elseif ($arg !== '--force') {
        $target = $arg;
    }
}

function ok(string $msg): void { echo "  [ok]   $msg\n"; }
function skip(string $msg): void { echo "  [skip] $msg\n"; }
function warn(string $msg): void { echo "  [warn] $msg\n"; }
function fail(string $msg): never { fwrite(STDERR, "  [fail] $msg\n"); exit(1); }

function git(string $root, string $command, ?int &$code = null): string
{
    exec('git -C '.escapeshellarg($root).' '.$command.' 2>&1', $out, $code);

    return trim(implode("\n", $out));
}

function normalize(string $path): string
{
    return rtrim(strtolower(str_replace('\\', '/', $path)), '/');
}

function eol(string $content): string
{
    return str_contains($content, "\r\n") ? "\r\n" : "\n";
}

// ---------------------------------------------------------------------------
// Preflight
// ---------------------------------------------------------------------------

if (! preg_match('/^\d+\.\d+$/', $base)) {
    fail("--version must be MAJOR.MINOR (e.g. 1.0), got '$base'.");
}

$root = realpath($target);
if ($root === false || ! is_file("$root/artisan")) {
    fail("'$target' is not a Laravel project (no artisan file).");
}

$top = git($root, 'rev-parse --show-toplevel', $code);
if ($code !== 0) {
    fail("'$root' is not a git repository. Run `git init` first.");
}
if (normalize($top) !== normalize($root)) {
    fail("The Laravel project must be the git repository root (repo root is '$top').");
}

$hooksPath = git($root, 'config --get core.hooksPath');
if ($hooksPath !== '' && $hooksPath !== '.githooks' && ! $force) {
    fail("core.hooksPath is already '$hooksPath' (e.g. Husky). Re-run with --force to replace it.");
}

$kit = __DIR__;
echo "Installing versioning into $root\n\n";

// ---------------------------------------------------------------------------
// 1. VERSION
// ---------------------------------------------------------------------------

if (is_file("$root/VERSION")) {
    $base = trim(file_get_contents("$root/VERSION"));
    skip("VERSION already exists ($base).");
} else {
    file_put_contents("$root/VERSION", "$base\n");
    ok("Created VERSION ($base).");
}

// ---------------------------------------------------------------------------
// 2. .githooks/pre-commit
// ---------------------------------------------------------------------------

$hook = "$root/.githooks/pre-commit";
$stub = str_replace("\r\n", "\n", file_get_contents("$kit/stubs/pre-commit"));

if (is_file($hook) && file_get_contents($hook) === $stub) {
    skip('.githooks/pre-commit already installed.');
} elseif (is_file($hook) && ! $force) {
    fail('.githooks/pre-commit already exists with other content. Merge it manually or re-run with --force.');
} else {
    @mkdir("$root/.githooks", 0755, true);
    file_put_contents($hook, $stub);
    @chmod($hook, 0755);
    ok('Installed .githooks/pre-commit.');
}

// ---------------------------------------------------------------------------
// 3. .gitattributes - hooks must keep LF endings or `sh` cannot run them
// ---------------------------------------------------------------------------

$attrFile = "$root/.gitattributes";
$attrRule = '/.githooks/* text eol=lf';
$attrs = is_file($attrFile) ? file_get_contents($attrFile) : '';

if (str_contains($attrs, $attrRule)) {
    skip('.gitattributes already forces LF on hooks.');
} else {
    $nl = $attrs === '' ? "\n" : eol($attrs);
    $prefix = ($attrs === '' || str_ends_with($attrs, "\n")) ? '' : $nl;
    file_put_contents($attrFile, $attrs.$prefix.$attrRule.$nl);
    ok('Added LF rule for hooks to .gitattributes.');
}

// ---------------------------------------------------------------------------
// 4. config/app.php - 'version' key
// ---------------------------------------------------------------------------

$configFile = "$root/config/app.php";
$config = is_file($configFile) ? file_get_contents($configFile) : null;

if ($config === null) {
    warn('config/app.php not found - add the version key manually (see README).');
} elseif (preg_match("/^\s*'version'\s*=>/m", $config)) {
    skip("config/app.php already has a 'version' key.");
} else {
    $block = <<<'PHP'

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | Generated by .githooks/pre-commit on every commit (see VERSION).
    |
    */

    'version' => json_decode(@file_get_contents(base_path('version.json')), true)['version'] ?? 'dev',

PHP;
    $block = str_replace("\n", eol($config), $block);

    $patched = preg_replace_callback(
        "/^[ \t]*'env'[ \t]*=>.*\R/m",
        fn ($m) => $m[0].$block,
        $config,
        1,
        $count
    );

    if ($count === 1) {
        file_put_contents($configFile, $patched);
        ok("Added 'version' to config/app.php.");
    } else {
        warn("Could not find the 'env' key in config/app.php - add the version key manually (see README).");
    }
}

// ---------------------------------------------------------------------------
// 5. composer.json - re-enable the hook after every clone + composer install
// ---------------------------------------------------------------------------

$composerFile = "$root/composer.json";
$raw = is_file($composerFile) ? file_get_contents($composerFile) : '';
$composer = json_decode($raw);
$hookCmd = "@php -r \"if (is_dir('.git')) { exec('git config core.hooksPath .githooks'); }\"";

if (! $composer instanceof stdClass) {
    warn('composer.json missing or invalid - teammates must run `git config core.hooksPath .githooks` themselves.');
} elseif (str_contains($raw, 'core.hooksPath')) {
    skip('composer.json already enables the hook.');
} else {
    // Insert as text after the last post-autoload-dump entry so the rest of the file keeps its formatting.
    $encoded = json_encode($hookCmd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $patched = preg_replace_callback(
        '/("post-autoload-dump"\s*:\s*\[)(.*?\S)(\s*\])/s',
        function ($m) use ($encoded) {
            preg_match('/(\R[ \t]*)[^\r\n]*$/', $m[2], $last);

            return $m[1].$m[2].','.($last[1] ?? ' ').$encoded.$m[3];
        },
        $raw,
        1,
        $count
    );

    if ($count !== 1) {
        // No (or empty) post-autoload-dump array: fall back to re-encoding the whole file.
        $composer->scripts ??= new stdClass;
        $composer->scripts->{'post-autoload-dump'} = [$hookCmd];
        $patched = str_replace("\n", eol($raw), json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    if (json_decode($patched) === null) {
        warn('Could not update composer.json safely - add the hook command manually (see README).');
    } else {
        file_put_contents($composerFile, $patched);
        ok('composer.json now enables the hook on composer install/update.');
    }
}

// ---------------------------------------------------------------------------
// 6. Activate for this clone + write the current version.json
// ---------------------------------------------------------------------------

git($root, 'config core.hooksPath .githooks');
ok('Enabled hook for this clone (core.hooksPath = .githooks).');

$count = (int) git($root, 'rev-list --count HEAD', $code);
$current = $base.'.'.($code === 0 ? $count : 0);
file_put_contents("$root/version.json", "{\n    \"version\": \"$current\"\n}\n");
ok("Wrote version.json ($current). Next commit will be $base.".(($code === 0 ? $count : 0) + 1).'.');

// ---------------------------------------------------------------------------
// Next steps
// ---------------------------------------------------------------------------

echo <<<TXT

Done. Next steps:

  1. Show the version somewhere in your layout, e.g.:
         v{{ config('app.version') }}

  2. Commit the setup:
         git add VERSION version.json .githooks .gitattributes config/app.php composer.json
         git update-index --chmod=+x .githooks/pre-commit
         git commit -m "System Versioning"

  3. To bump MAJOR/MINOR later, edit VERSION (e.g. 1.0 -> 1.1) and commit.

TXT;
