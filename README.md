# LangSail

Cookie-free multilingual WordPress sites with **one structure**. Pages, templates and menus are built once in the base language; every other language is a translation of their texts, kept in a table. Change the layout once and every language follows.

## How it works

- **Languages in the address.** The base language lives at `/`, the others at `/it/`, `/es/`, `/ru/`... No cookies, no redirects by browser language, nothing stored in the visitor's browser.
- **Text units.** A text unit is what a person reads as one piece: a paragraph with its links and bold words, a heading, a menu item, a button, an image description (alt), a field placeholder, the page title and SEO description. LangSail finds them in the page as visitors see it, header, footer and plugin output included.
- **The translation table.** LangSail > Translations lists every text of the site, one column per language, with page and status filters, search and progress. Links and formatting appear as markers (`Hello [1]our team[/1]`), so a translation cannot break the HTML.
- **New texts are collected** with "Scan the site for new texts": every published page is read as visitors see it and anything new appears as missing.
- **Untranslated texts fall back** to the base language: nothing breaks while a translation is in progress.
- **WordPress itself speaks the language.** On `/it/` the site runs in Italian: WordPress, theme and plugin strings, dates, `<html lang>`. Saving the languages downloads their WordPress.org language packs.
- **Search engines.** Each page links its language versions with `hreflang` (base language as `x-default`); canonical links and internal links move into the page's language.
- **Opt out** of translation with the HTML standard: `translate="no"` or the `notranslate` class (brand names, code). Hidden anti-spam fields are skipped too (`data/skip-classes.json`, filter `langsail_skip_classes`).
- **Translated addresses (optional).** Each word of an address can have its own word per language (`/it/privacy/` for `/privacy-policy/`); links, the switcher, hreflang and the sitemap follow, and the original words keep working.
- **AI ready.** Through the WordPress Abilities API (REST and MCP Adapter) an AI agent can list the languages and progress (`langsail/list-languages`), fetch the texts still to translate (`langsail/list-texts`), save translations with marker validation (`langsail/save-translations`) and scan the site (`langsail/scan`). LangSail calls no external service: the agent the site owner connects does the translating, with that user's permissions.
- **Translator role.** Translators (role *Translator*, capability `langsail_translate`) see the translation table, imports and exports; the settings stay with administrators.

## Usage

1. Activate the plugin and confirm the base language under **LangSail > Settings**.
2. Choose the translation languages (common ones are listed first).
3. Open **LangSail > Translations**, scan the site, translate. The **Pages** overview shows what is missing per page and language, with links to exactly those texts and to the page in each language.
4. Add the **Language switcher** block where visitors should change language (usually the header).

WP-CLI: `wp langsail scan`, `stats`, `export`, `import`, `cleanup`.

## Development

The integration test runs on any WordPress site with the plugin active:

```
wp eval-file wp-content/plugins/langsail/tests/integration.php
```

In WordPress Studio, prefix the command with `studio`.

## License

GPL-2.0-or-later. Copyright 2026 Massimo Mazzariol, https://github.com/massimomazzariol/langsail. See [NOTICE](NOTICE): keep it, with the copyright notices, when you redistribute LangSail or a work based on it.
