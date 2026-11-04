<?php

/*
|--------------------------------------------------------------------------
| Laravel Versioning Kit - installer
|--------------------------------------------------------------------------
|
| Usage:  php install.php [path/to/laravel-project] [--version=1.0] [--force]
|                         [--name="App Name"] [--description="..."] [--block-push]
|
| Sets up MAJOR.MINOR.BUILD versioning with a pending change log:
|   VERSION               hand-edited MAJOR.MINOR
|   .vk/vk.php, vk, vk.bat  `vk change` / `vk push` (BUILD +1 per push)
|   README.md             project README with an auto-updated Version Trail
|   CLAUDE.md             tells Claude to log changes (never to push on its own)
|   config/app.php        exposes the version as config('app.version')
|   composer.json         re-enables hooks on every `composer install`
|   --block-push          also installs a pre-push hook so only `vk push` can push
|
| Safe to re-run: every step skips itself when already done.
|
*/

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$blockPush = in_array('--block-push', $args, true);
$base = '1.0';
$name = null;
$description = null;
$target = getcwd();

foreach ($args as $arg) {
    if (str_starts_with($arg, '--version=')) {
        $base = substr($arg, strlen('--version='));
    } elseif (str_starts_with($arg, '--name=')) {
        $name = substr($arg, strlen('--name='));
    } elseif (str_starts_with($arg, '--description=')) {
        $description = substr($arg, strlen('--description='));
    } elseif (! in_array($arg, ['--force', '--block-push'], true)) {
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
// 2. vk tool + hooks
// ---------------------------------------------------------------------------

function install_file(string $src, string $dest, bool $exec = false): void
{
    global $root;
    $content = str_replace("\r\n", "\n", file_get_contents($src));
    $rel = ltrim(substr($dest, strlen($root)), '/\\');
    if (is_file($dest) && file_get_contents($dest) === $content) {
        skip("$rel already up to date.");

        return;
    }
    @mkdir(dirname($dest), 0755, true);
    file_put_contents($dest, $content);
    if ($exec) {
        @chmod($dest, 0755);
    }
    ok("Installed $rel.");
}

install_file("$kit/stubs/vk.php", "$root/.vk/vk.php");
install_file("$kit/stubs/vk", "$root/vk", true);
install_file("$kit/stubs/vk.bat", "$root/vk.bat");

// The old per-commit hook (BUILD = commit count) conflicts with `vk push`.
$oldHook = "$root/.githooks/pre-commit";
if (is_file($oldHook)) {
    $oldBody = file_get_contents($oldHook);
    if (str_contains($oldBody, 'laravel-versioning-kit') || (str_contains($oldBody, 'rev-list --count') && str_contains($oldBody, 'version.json'))) {
        unlink($oldHook);
        ok('Removed old .githooks/pre-commit (versioning now happens in `vk push`).');
    } else {
        warn('.githooks/pre-commit is a custom hook - make sure it does not write version.json.');
    }
}

if ($blockPush) {
    install_file("$kit/stubs/pre-push", "$root/.githooks/pre-push", true);
} elseif (is_file("$root/.githooks/pre-push") && str_contains(file_get_contents("$root/.githooks/pre-push"), 'laravel-versioning-kit')) {
    skip('pre-push hook is installed (re-run without it to keep; delete .githooks/pre-push to allow plain git push).');
}

// ---------------------------------------------------------------------------
// 3. .gitattributes - hooks and the vk script must keep LF endings
// ---------------------------------------------------------------------------

$attrFile = "$root/.gitattributes";
foreach (['/.githooks/* text eol=lf', '/vk text eol=lf'] as $attrRule) {
    $attrs = is_file($attrFile) ? file_get_contents($attrFile) : '';
    if (str_contains($attrs, $attrRule)) {
        skip(".gitattributes already has '$attrRule'.");
    } else {
        $nl = $attrs === '' ? "\n" : eol($attrs);
        $prefix = ($attrs === '' || str_ends_with($attrs, "\n")) ? '' : $nl;
        file_put_contents($attrFile, $attrs.$prefix.$attrRule.$nl);
        ok("Added '$attrRule' to .gitattributes.");
    }
}

// .changes/ holds the pending draft and must not be committed
$ignoreFile = "$root/.gitignore";
$ignore = is_file($ignoreFile) ? file_get_contents($ignoreFile) : '';
if (preg_match('#^/?\.changes/?\s*$#m', $ignore)) {
    skip('.gitignore already ignores .changes/.');
} else {
    $nl = $ignore === '' ? "\n" : eol($ignore);
    $prefix = ($ignore === '' || str_ends_with($ignore, "\n")) ? '' : $nl;
    file_put_contents($ignoreFile, $ignore.$prefix.'/.changes/'.$nl);
    ok('Added /.changes/ to .gitignore.');
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
if (is_file("$root/version.json")) {
    $current = json_decode(file_get_contents("$root/version.json"), true)['version'] ?? "$base.0";
    skip("version.json already exists ($current); the next `vk push` continues from it.");
} else {
    $current = $base.'.'.($code === 0 ? $count : 0);
    file_put_contents("$root/version.json", "{\n    \"version\": \"$current\"\n}\n");
    ok("Wrote version.json ($current).");
}

// ---------------------------------------------------------------------------
// 7. CLAUDE.md - rules so Claude logs changes (and never pushes on its own)
// ---------------------------------------------------------------------------

$claudeFile = "$root/CLAUDE.md";
$claude = is_file($claudeFile) ? file_get_contents($claudeFile) : '';
$block = str_replace("\r\n", "\n", file_get_contents("$kit/stubs/CLAUDE.block.md"));
if (str_contains($claude, '<!-- VK:START -->')) {
    $claude = preg_replace_callback('/<!-- VK:START -->.*?<!-- VK:END -->\R?/s', fn () => $block, $claude);
    file_put_contents($claudeFile, $claude);
    ok('Refreshed vk rules in CLAUDE.md.');
} else {
    file_put_contents($claudeFile, $claude === '' ? $block : rtrim($claude)."\n\n".$block);
    ok('Added vk rules to CLAUDE.md.');
}

// ---------------------------------------------------------------------------
// 8. README.md - project README with an auto-updated Version Trail
// ---------------------------------------------------------------------------

$readmeFile = "$root/README.md";
$oldReadme = is_file($readmeFile) ? file_get_contents($readmeFile) : '';

if (str_contains($oldReadme, '<!-- VK:TRAIL:START -->')) {
    skip('README.md already has a version trail.');
} else {
    $docs = '';
    if (trim($oldReadme) !== '') {
        @mkdir("$root/docs", 0755, true);
        if (is_file("$root/docs/ABOUT.md")) {
            warn('docs/ABOUT.md already exists - old README not moved; it will be overwritten in README.md.');
        } else {
            rename($readmeFile, "$root/docs/ABOUT.md");
            ok('Moved the previous README to docs/ABOUT.md.');
            $docs = "\n## 📚 Documentation\n\n- [About this project](docs/ABOUT.md)\n";
        }
    }

    $composerData = json_decode(is_file($composerFile) ? file_get_contents($composerFile) : '{}', true) ?: [];
    $require = $composerData['require'] ?? [];
    $badge = fn (string $label, string $value, string $color) => '![' . $label . '](https://img.shields.io/badge/' .
        rawurlencode(str_replace('-', '--', $label)) . '-' . rawurlencode(str_replace('-', '--', $value)) . '-' . $color . '?style=flat-square)';
    $badges = [];
    if (isset($require['laravel/framework']) && preg_match('/\d+/', $require['laravel/framework'], $m)) {
        $badges[] = $badge('Laravel', $m[0], 'ff2d20');
    }
    if (isset($require['php']) && preg_match('/\d+\.\d+/', $require['php'], $m)) {
        $badges[] = $badge('PHP', $m[0].'+', '777bb4');
    }
    if (isset($require['livewire/livewire'])) {
        $badges[] = $badge('Livewire', 'enabled', 'fb70a9');
    }
    if (is_file("$root/package.json") && str_contains(file_get_contents("$root/package.json"), 'tailwindcss')) {
        $badges[] = $badge('Tailwind CSS', 'enabled', '38bdf8');
    }

    if ($name === null) {
        $envExample = is_file("$root/.env") ? file_get_contents("$root/.env") : '';
        $name = preg_match("/^APP_NAME=['\"]?([^'\"\r\n]+)/m", $envExample, $m) && $m[1] !== 'Laravel' ? trim($m[1]) : basename($root);
    }
    $remote = preg_replace('/\.git$/', '', git($root, 'remote get-url origin', $rc));
    $remote = $rc === 0 && $remote !== '' ? $remote.'.git' : 'https://github.com/your-account/'.basename($root).'.git';

    $readme = strtr(file_get_contents("$kit/stubs/README.template.md"), [
        '{{NAME}}' => $name,
        '{{DESCRIPTION}}' => $description ?? 'A Laravel application.',
        '{{VERSION}}' => $current,
        '{{BADGES}}' => implode(' ', $badges),
        '{{REMOTE}}' => $remote,
        '{{FOLDER}}' => basename($root),
        '{{DOCS}}' => $docs === '' ? "\n" : "\n".ltrim($docs, "\n"),
        '{{TRAIL_START}}' => '<!-- VK:TRAIL:START -->',
        '{{TRAIL_END}}' => '<!-- VK:TRAIL:END -->',
    ]);
    file_put_contents($readmeFile, $readme);
    ok("Created README.md for '$name'.");
}

passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg("$root/.vk/vk.php").' backfill');

// ---------------------------------------------------------------------------
// Next steps
// ---------------------------------------------------------------------------

echo <<<TXT

Done. Next steps:

  1. Show the version somewhere in your layout, e.g.:
         v{{ config('app.version') }}

  2. Review README.md (edit the description at the top), then log the setup:
         php .vk/vk.php change "Added change log and version trail"
         php .vk/vk.php push          # one commit + push, bumps the version

  3. Day to day:
         vk change "What changed"     # or let Claude do it (see CLAUDE.md)
         vk status                    # draft commit message + upcoming version
         vk push                      # only this commits and pushes

  4. To bump MAJOR/MINOR later, edit VERSION (e.g. 1.0 -> 1.1).

TXT;
