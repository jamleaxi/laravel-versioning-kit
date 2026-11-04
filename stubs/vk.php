<?php

/*
|--------------------------------------------------------------------------
| vk - change log + versioning helper (Laravel Versioning Kit)
|--------------------------------------------------------------------------
|
|   vk change "Fixed bug on request quantity"   log a change (updates draft message)
|   vk title  "Bug Fix"                         set the commit title
|   vk status                                   show draft message + upcoming version
|   vk push [Title] [--dry-run]                 bump version, update README trail,
|                                               commit once, push
|   vk backfill                                 (installer) build README trail from git history
|
| Nothing is ever committed or pushed except by `vk push`.
|
*/

declare(strict_types=1);

const TRAIL_START = '<!-- VK:TRAIL:START -->';
const TRAIL_END = '<!-- VK:TRAIL:END -->';

$root = dirname(__DIR__);
$changesDir = "$root/.changes";
$pendingFile = "$changesDir/pending.md";
$draftFile = "$changesDir/draft-message.txt";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function out(string $msg = ''): void { echo $msg."\n"; }
function fail(string $msg): never { fwrite(STDERR, "vk: $msg\n"); exit(1); }

function git(string $command, ?int &$code = null): string
{
    global $root;
    exec('git -C '.escapeshellarg($root).' '.$command.' 2>&1', $lines, $code);

    return rtrim(implode("\n", $lines));
}

function baseVersion(): string
{
    global $root;
    $f = "$root/VERSION";

    return is_file($f) ? trim((string) file_get_contents($f)) : '1.0';
}

function currentBuild(): int
{
    global $root;
    $f = "$root/version.json";
    $v = is_file($f) ? (json_decode((string) file_get_contents($f), true)['version'] ?? '') : '';

    return preg_match('/\.(\d+)$/', (string) $v, $m) ? (int) $m[1] : 0;
}

function currentVersion(): string
{
    global $root;
    $f = "$root/version.json";

    return is_file($f) ? (json_decode((string) file_get_contents($f), true)['version'] ?? 'dev') : 'dev';
}

function nextVersion(): string
{
    return baseVersion().'.'.(currentBuild() + 1);
}

function parsePending(): array
{
    global $pendingFile;
    $title = '';
    $bullets = [];
    if (is_file($pendingFile)) {
        foreach (preg_split('/\R/', (string) file_get_contents($pendingFile)) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '# ')) {
                $title = trim(substr($line, 2));
            } elseif (str_starts_with($line, '•')) {
                $bullets[] = trim(substr($line, strlen('•')));
            }
        }
    }

    return [$title, $bullets];
}

function savePending(string $title, array $bullets): void
{
    global $changesDir, $pendingFile, $draftFile;
    @mkdir($changesDir, 0755, true);

    $body = ($title !== '' ? "# $title\n" : '');
    foreach ($bullets as $b) {
        $body .= "• $b\n";
    }
    file_put_contents($pendingFile, $body);
    file_put_contents($draftFile, buildMessage($title, $bullets));
}

function buildMessage(string $title, array $bullets): string
{
    $msg = date('Y-m-d').' '.($title !== '' ? $title : 'Updates')."\n";
    foreach ($bullets as $b) {
        $msg .= "• $b\n";
    }

    return $msg;
}

function showDraft(string $title, array $bullets): void
{
    out('Draft commit message:');
    out('  ----------------------------------------');
    foreach (explode("\n", rtrim(buildMessage($title, $bullets))) as $line) {
        out("  $line");
    }
    out('  ----------------------------------------');
    out('Upcoming version: '.nextVersion().'  (current: '.currentVersion().')');
}

function cleanBullet(string $text): string
{
    return trim(preg_replace('/^\s*[•\-\*]\s*/u', '', trim($text)));
}

function trailEntry(string $version, string $date, string $title, array $bullets): string
{
    $out = '### '.($version !== '' ? "v$version · " : '')."$date — $title\n";
    $out .= $bullets ? "\n" : '';
    foreach ($bullets as $b) {
        $out .= "- $b\n";
    }

    return $out;
}

/** Prepend an entry to the README version trail and refresh the version badge. */
function updateReadme(string $entry, string $version): bool
{
    global $root;
    $file = "$root/README.md";
    if (! is_file($file)) {
        return false;
    }
    $readme = str_replace("\r\n", "\n", (string) file_get_contents($file));
    $pos = strpos($readme, TRAIL_START);
    if ($pos === false || strpos($readme, TRAIL_END) === false) {
        return false;
    }
    $at = $pos + strlen(TRAIL_START);
    $readme = substr($readme, 0, $at)."\n\n".rtrim($entry)."\n\n".ltrim(substr($readme, $at));
    $readme = preg_replace('#(img\.shields\.io/badge/version-)[^-"\s)]+(-)#', '${1}'.$version.'${2}', $readme);
    $readme = preg_replace('#<!-- VK:V -->.*?<!-- /VK:V -->#s', '<!-- VK:V -->'.$version.'<!-- /VK:V -->', $readme);
    file_put_contents($file, $readme);

    return true;
}

// ---------------------------------------------------------------------------
// Commands
// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
$cmd = array_shift($args) ?? 'status';

switch ($cmd) {
    case 'change':
        $title = null;
        $words = [];
        foreach ($args as $a) {
            if (str_starts_with($a, '--title=')) {
                $title = trim(substr($a, 8));
            } else {
                $words[] = $a;
            }
        }
        $text = cleanBullet(implode(' ', $words));
        if ($text === '') {
            fail('usage: vk change "What changed" [--title="Title"]');
        }
        [$curTitle, $bullets] = parsePending();
        if (! in_array($text, $bullets, true)) {
            $bullets[] = $text;
        }
        savePending($title ?? $curTitle, $bullets);
        out("Logged: • $text");
        out();
        showDraft($title ?? $curTitle, $bullets);
        break;

    case 'title':
        $title = trim(implode(' ', $args));
        if ($title === '') {
            fail('usage: vk title "Commit title"');
        }
        [, $bullets] = parsePending();
        savePending($title, $bullets);
        out("Title set: $title");
        break;

    case 'status':
        [$title, $bullets] = parsePending();
        if (! $bullets) {
            out('No pending changes.');
            out('Current version: '.currentVersion());
            break;
        }
        showDraft($title, $bullets);
        break;

    case 'push':
        $dry = in_array('--dry-run', $args, true);
        $override = trim(implode(' ', array_filter($args, fn ($a) => ! str_starts_with($a, '--'))));
        [$title, $bullets] = parsePending();
        $title = $override !== '' ? $override : $title;

        if (! $bullets) {
            $ahead = (int) git('rev-list --count @{u}..HEAD', $code);
            if ($code === 0 && $ahead > 0) {
                out("No pending changes, but $ahead unpushed commit(s). Pushing only.");
                if (! $dry) {
                    pushNow();
                }
                exit(0);
            }
            fail('Nothing to push. Log a change first: vk change "What changed"');
        }

        $version = nextVersion();
        $message = buildMessage($title, $bullets);
        showDraft($title, $bullets);
        out();
        out('Files to be committed:');
        out(git('status --short') ?: '  (none)');
        if ($dry) {
            out();
            out('Dry run - nothing changed.');
            break;
        }

        // Bump version + README trail (restored if the commit fails).
        $vjFile = "$root/version.json";
        $rdFile = "$root/README.md";
        $oldVj = is_file($vjFile) ? file_get_contents($vjFile) : null;
        $oldRd = is_file($rdFile) ? file_get_contents($rdFile) : null;

        file_put_contents($vjFile, "{\n    \"version\": \"$version\"\n}\n");
        $entry = trailEntry($version, date('Y-m-d'), $title !== '' ? $title : 'Updates', $bullets);
        if (! updateReadme($entry, $version)) {
            out('Note: README.md has no version-trail markers; trail not updated.');
        }

        $msgFile = "$changesDir/commit-message.txt";
        file_put_contents($msgFile, $message);
        git('add -A');
        $res = git('commit -F '.escapeshellarg($msgFile), $code);
        if ($code !== 0) {
            $oldVj === null ? @unlink($vjFile) : file_put_contents($vjFile, $oldVj);
            $oldRd === null ? null : file_put_contents($rdFile, $oldRd);
            git('reset -q');
            fail("commit failed, nothing was changed:\n$res");
        }
        @unlink($msgFile);
        @unlink($pendingFile);
        @unlink($draftFile);
        out();
        out("Committed v$version.");
        pushNow();
        break;

    case 'backfill':
        backfill(in_array('--force', $args, true));
        break;

    default:
        fail("unknown command '$cmd'. Try: change, title, status, push");
}

function pushNow(): void
{
    putenv('VK_PUSH=1');
    $branch = git('rev-parse --abbrev-ref HEAD');
    git('rev-parse --abbrev-ref --symbolic-full-name @{u}', $code);
    $res = git($code === 0 ? 'push' : 'push -u origin '.escapeshellarg($branch), $pushCode);
    out($res);
    if ($pushCode !== 0) {
        fail('push failed. The commit is saved locally; fix the problem and run `vk push` again to push it.');
    }
    out('Pushed.');
}

/** Build the README version trail from existing dated commit messages. Used once by the installer. */
function backfill(bool $force): void
{
    global $root;
    $file = "$root/README.md";
    if (! is_file($file)) {
        fail('README.md not found.');
    }
    $readme = str_replace("\r\n", "\n", (string) file_get_contents($file));
    $s = strpos($readme, TRAIL_START);
    $e = strpos($readme, TRAIL_END);
    if ($s === false || $e === false) {
        fail('README.md has no version-trail markers.');
    }
    $existing = trim(substr($readme, $s + strlen(TRAIL_START), $e - $s - strlen(TRAIL_START)));
    if ($existing !== '' && ! $force) {
        out('Version trail already has entries - skipped (use --force to rebuild).');

        return;
    }

    $entries = [];
    foreach (explode("\n", git('log --format=%H')) as $hash) {
        if ($hash === '') {
            continue;
        }
        $body = git('show -s --format=%B '.$hash);
        $lines = preg_split('/\R/', $body);
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})\s*(.*)$/u', $lines[0], $m)) {
            continue;
        }
        $title = trim($m[2]);
        $bullets = [];
        foreach (array_slice($lines, 1) as $l) {
            $l = trim($l);
            if ($l === '' || stripos($l, 'Co-Authored-By:') === 0) {
                continue;
            }
            $bullets[] = cleanBullet($l);
        }
        // "2026-04-16 • SDG icons" style: no title, first line already has a bullet.
        if (str_starts_with($title, '•')) {
            array_unshift($bullets, cleanBullet($title));
            $title = '';
        }
        $version = '';
        git('cat-file -e '.escapeshellarg($hash.':VERSION'), $has);
        if ($has === 0) {
            $version = trim(git('show '.escapeshellarg($hash.':VERSION'))).'.'.git('rev-list --count '.$hash);
        }
        $entries[] = trailEntry($version, $m[1], $title !== '' ? $title : 'Updates', $bullets);
    }

    $block = $entries ? "\n\n".implode("\n", $entries)."\n" : "\n";
    file_put_contents($file, substr($readme, 0, $s + strlen(TRAIL_START)).$block.substr($readme, $e));
    out('Version trail rebuilt from git history ('.count($entries).' entries).');
}
