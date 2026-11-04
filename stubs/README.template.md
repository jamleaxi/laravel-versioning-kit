<div align="center">

# {{NAME}}

{{DESCRIPTION}}

![Version](https://img.shields.io/badge/version-{{VERSION}}-2563eb?style=for-the-badge)
{{BADGES}}

**Latest release:** v<!-- VK:V -->{{VERSION}}<!-- /VK:V -->

</div>

---

## 🚀 Getting Started

```bash
git clone {{REMOTE}}
cd {{FOLDER}}

composer install
cp .env.example .env
php artisan key:generate
php artisan migrate

npm install
npm run dev
php artisan serve
```
{{DOCS}}
## 🔖 Versioning

Versions follow **`MAJOR.MINOR.BUILD`**.

- **MAJOR.MINOR** is set by hand in the `VERSION` file.
- **BUILD** goes up by one on every `vk push`.
- Changes are logged while working (`vk change "..."`) and released with a single `vk push`, which bumps the version, updates the trail below, makes one commit and pushes.

## 🗂️ Version Trail

Latest first. This section is updated automatically by `vk push`.

{{TRAIL_START}}
{{TRAIL_END}}
