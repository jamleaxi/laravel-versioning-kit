# Laravel Versioning Kit

Versioning, a pending change log, and an auto-updated README **Version Trail** for Laravel projects.

- Version format **`MAJOR.MINOR.BUILD`** (e.g. `1.1.113`). `MAJOR.MINOR` is edited by hand in `VERSION`; `BUILD` goes up by one on every `vk push`.
- Changes are logged **as you work** (`vk change "..."`), building a draft commit message in the format `YYYY-MM-DD Title` + `• bullet` lines.
- **Nothing is committed or pushed except by `vk push`**, which bumps the version once, adds an entry to the README trail, makes one commit and pushes.
- Available in the app as `config('app.version')`.

Works on Windows, macOS and Linux. No packages to install.

## Requirements

- PHP 8.1+ and Git (Git for Windows on Windows)
- A Laravel project that is the **root** of its git repository

## Install

```bash
php path/to/laravel-versioning-kit/install.php path/to/project --name="App Name" --description="One-line description"
```

| Option | Meaning |
|---|---|
| `--version=1.0` | Starting MAJOR.MINOR (ignored if `VERSION` exists) |
| `--name=` / `--description=` | Shown at the top of the generated README |
| `--block-push` | Also install a `pre-push` hook so plain `git push` is blocked (only `vk push` works). Off by default, since GUI clients like GitKraken would be blocked too. |
| `--force` | Replace a different `core.hooksPath` / custom hooks |

The installer is safe to re-run. On first run it:

- moves an existing `README.md` to `docs/ABOUT.md` and writes a new README (badges, getting started, **Version Trail** backfilled from your dated commit messages),
- adds the `vk` tool, a `CLAUDE.md` rule block, `.gitignore`/`.gitattributes` entries,
- removes the old per-commit hook (`BUILD = commit count`); the build number now continues from `version.json`.

Then review the README and release the setup with `vk push`.

## Day to day

```bash
vk change "Fixed bug on request quantity"   # log a change (Claude does this for you, see CLAUDE.md)
vk title  "Bug Fix"                          # set the commit title
vk status                                    # draft commit message + upcoming version
vk push                                      # version bump + README trail + ONE commit + push
vk push --dry-run                            # preview, change nothing
vk push "Other Title"                        # override the title for this push
```

`vk` = `./vk` (bash) / `.\vk` (PowerShell, cmd). Without the wrappers: `php .vk/vk.php <command>`.

The draft lives in `.changes/` (git-ignored): `pending.md` (title + bullets) and `draft-message.txt` (the exact commit message). If a commit fails, nothing is changed; if the push fails, the commit is kept and `vk push` pushes it on the next run.

| You want to… | Do this |
|---|---|
| Release a new minor/major | Edit `VERSION` (`1.1` → `1.2`) and run `vk push`. BUILD keeps counting. |
| Check the current version | `version.json`, the README badge, or `config('app.version')` |

Never edit `version.json` or the trail markers in `README.md` by hand.

## Files the installer adds or changes

| File | Change |
|---|---|
| `.vk/vk.php`, `vk`, `vk.bat` | The tool and its launchers |
| `VERSION` | `MAJOR.MINOR`, hand-edited |
| `version.json` | `{ "version": "1.1.113" }`, committed so servers get it without git |
| `README.md` | Generated; contains `<!-- VK:TRAIL:START/END -->` markers that `vk push` writes between |
| `docs/ABOUT.md` | The project's previous README |
| `CLAUDE.md` | Rules: log changes after each task; never commit/push unless asked |
| `.githooks/pre-push` | Only with `--block-push` |
| `.gitattributes`, `.gitignore` | LF endings for hooks and `vk`; ignore `/.changes/` |
| `config/app.php` | `'version'` key reading `version.json` |
| `composer.json` | `post-autoload-dump` re-enables `core.hooksPath` after clone |

## Good to know

- `vk push` stages everything (`git add -A`, respecting `.gitignore`) — use `--dry-run` to see the file list first.
- The README trail is rebuilt from git history only once (at install). Re-run with `php .vk/vk.php backfill --force` to rebuild.
- A plain `git commit` does not bump the version or touch the trail; use `vk push` for releases.
- Merge conflicts in `version.json` or the trail: keep both sides' entries, fix the number, then `vk push`.
- Laravel project in a subfolder of the repo (monorepo) isn't supported.

## Kit contents

```
laravel-versioning-kit/
├── install.php                 # the installer
├── README.md
└── stubs/
    ├── vk.php                  # the tool (change / title / status / push / backfill)
    ├── vk, vk.bat              # launchers for bash and Windows
    ├── pre-push                # optional hook (--block-push)
    ├── README.template.md      # generated project README
    └── CLAUDE.block.md         # rules added to the project's CLAUDE.md
```

## Uninstall

1. `git config --unset core.hooksPath`
2. Delete `VERSION`, `version.json`, `.vk/`, `vk`, `vk.bat`, `.githooks/`, `.changes/`
3. Remove the vk block from `CLAUDE.md`, the `'version'` block from `config/app.php`, the `core.hooksPath` line from `composer.json`
