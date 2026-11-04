<!-- VK:START -->
## Change log & versioning (vk)

Changes are logged as they are made and only committed/pushed when the user asks.

- After finishing any task that changes project files, log it: `php .vk/vk.php change "Past-tense description"`.
  - One short bullet per user-visible change, past tense, same style as earlier commits (e.g. "Fixed bug on request quantity", "Added error pages for different error types").
  - Group related edits into one bullet. Don't log trivial edits or changes to the vk files themselves.
- Keep the commit title current with `php .vk/vk.php title "Title"`: a short category for the pending changes (e.g. "Bug Fix", "Feature Optimization", "UI Improvements", "<Module> Module").
- NEVER run `git commit`, `git push` or `vk push` on your own. Only when the user explicitly asks to push, run `php .vk/vk.php push`.
- `php .vk/vk.php status` shows the draft commit message and the upcoming version.
- The version bump (`version.json`) and the README version trail are handled by `vk push`; never edit them by hand.
<!-- VK:END -->
