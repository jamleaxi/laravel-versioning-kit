# Laravel Versioning Kit

Automatic app versioning for Laravel projects, in the form **`MAJOR.MINOR.BUILD`** (e.g. `1.0.25`).

- **MAJOR.MINOR** — you set it by hand in a `VERSION` file.
- **BUILD** — set automatically on every commit (= total number of commits).
- Available anywhere in the app as `config('app.version')`.

Works on Windows, macOS and Linux. No packages to install.

---

## Requirements

- PHP 8.1+ and Git (Git for Windows on Windows)
- A Laravel project that is the **root** of its git repository (run `git init` first if needed)

## Install (≈1 minute)

From anywhere, run the installer and point it at your project:

```bash
php path/to/laravel-versioning-kit/install.php path/to/your-project --version=1.0
```

Or run it from inside your project folder without a path:

```bash
php path/to/laravel-versioning-kit/install.php --version=1.0
```

`--version` is the starting MAJOR.MINOR (default `1.0`). It's ignored if the project already has a `VERSION` file.

The installer is safe to re-run — each step skips itself if it's already done.

### Then do these two things

**1. Show the version** somewhere in your layout (footer, sidebar, about page):

```blade
v{{ config('app.version') }}
```

**2. Commit the setup:**

```bash
git add VERSION version.json .githooks .gitattributes config/app.php composer.json
git update-index --chmod=+x .githooks/pre-commit
git commit -m "System Versioning"
```

After that commit, `version.json` will show your first real build number.

---

## Day-to-day use

| You want to… | Do this |
|---|---|
| Release a new minor/major | Edit `VERSION` (`1.0` → `1.1`, or `1.4` → `2.0`) and commit. The build number keeps counting. |
| Check the current version | Open `version.json`, or `php artisan tinker` → `config('app.version')` |
| Show it in the UI | `{{ config('app.version') }}` |

Never edit `version.json` by hand — the hook overwrites it on every commit.

---

## What the installer changes

| File | Change |
|---|---|
| `VERSION` | New. Holds `MAJOR.MINOR`. |
| `.githooks/pre-commit` | New. On every commit, writes `version.json` and stages it. |
| `version.json` | New. `{ "version": "1.0.25" }` — committed, so servers get it without git. |
| `.gitattributes` | Adds `/.githooks/* text eol=lf` (a hook saved with Windows line endings won't run). |
| `config/app.php` | Adds a `'version'` key that reads `version.json` (falls back to `dev`). |
| `composer.json` | Adds a `post-autoload-dump` script that turns the hook on (see below). |
| `.git/config` | Sets `core.hooksPath = .githooks` for your clone. |

### Why the composer.json change?

Git does **not** copy `core.hooksPath` when someone clones a repo. Without it, the hook silently doesn't run and the version stops updating.
The composer script re-enables it automatically every time someone runs `composer install` / `composer update`, so teammates don't have to remember anything.
If someone clones without running composer, they can enable it manually:

```bash
git config core.hooksPath .githooks
```

---

## Good to know

- **Merge conflicts in `version.json`** — just accept either side. The hook rewrites it with the correct number when you commit the merge.
- **Rewriting history changes build numbers.** Squash merges, rebases, and `git reset` lower the commit count, so build numbers can repeat. `git commit --amend` adds one extra. Plain commits keep it accurate.
- **Commits made outside your machine** (GitHub web editor, a teammate without the hook enabled) don't update `version.json`. The next normal commit fixes it.
- **Already using Husky or another hooks folder?** The installer stops instead of overwriting it. Copy the contents of `stubs/pre-commit` into your existing pre-commit hook, then re-run with `--force` only if you want to switch to `.githooks`.
- **Laravel project in a subfolder of the repo** (monorepo) isn't supported by the installer.

## Uninstall

1. `git config --unset core.hooksPath`
2. Delete `VERSION`, `version.json`, `.githooks/`
3. Remove the `'version'` block from `config/app.php`, the `core.hooksPath` line from `composer.json`, and the `.githooks` line from `.gitattributes`
4. Remove `config('app.version')` from your views

---

## Kit contents

```
laravel-versioning-kit/
├── install.php        # the installer
├── stubs/pre-commit   # the git hook that gets copied into projects
└── README.md
```
