# Changelog

## 0.1.0

- Base language confirmed at setup and translation languages chosen from every WordPress locale, common ones first (data/common-languages.json); WordPress, theme and plugin language packs downloaded on save.
- Languages in the URL (/it/, /es/...): the prefix is removed before WordPress parses the request (REQUEST_URI and PATH_INFO), WordPress runs in that locale, redirects stay in the language. No cookies.
- Text units found in the rendered page: text runs with their phrasing tags, readable attributes, page title and SEO meta. Wrapping and decorative elements stay out of the unit; translate="no" and notranslate are honored.
- Translation table with page and status filters, search, progress per language and a site scan; markers instead of HTML, validated on save.
- Pages translated on output, untranslated texts fall back to the base language; internal and canonical links localized; hreflang alternates with x-default.
- Language switcher block: native names or codes, optional flags (circle-flags language set, MIT); flags also in the admin.
- SEO: attribute, title and JSON-LD texts are plain-text units shared across them; JSON-LD names and URLs translated; a language version is indexed (hreflang, sitemap) only once the threshold share of its page is translated, otherwise sent with X-Robots-Tag noindex; language versions added to The SEO Framework sitemap and to XML sitemaps that pass through output buffers.
- Background requests (admin-ajax, REST) made by a translated page run in its language (Referer) and their JSON or HTML responses are translated.
- Public filters langsail_translate (plain text) and langsail_translate_html (HTML, unit by unit): texts collected during scans, translated on translated requests.
- Integrations: Mintchat pre-filled message (mintchat_message), Fluent Forms browser and server validation messages, confirmation message, and emails sent to the visitor (recipient from a form field) in the visitor's language; emails to fixed addresses keep the base language.
- Readable attributes are listed in data/attributes.json (names and prefixes, data-label-* included); units made only of merge placeholders like {all_data} are skipped.
- Import and export: JSON with every text and language (moves translations between sites), PO per language with markers and fuzzy entries; WP-CLI wp langsail export, import, stats.
- Unit keys treat language-specific quote marks as one (WordPress writes them differently per language), so texts match on every language version.
- LangSail keeps itself first among active plugins, so the request language is set before other plugins load their translations.
- Never-translate list in the settings (brands, codes); link-tag titles are not collected; empty icons inside wrapped links stay out of units.
- Translation maps (.json, source text to translation) import into one language; date picker screen reader labels (data/flatpickr.json) for Fluent Forms.
- Filters for other plugins' settings: langsail_languages, langsail_get_translation, langsail_set_translation (Mintchat shows a message field per language).
- Scan covers the not-found and search pages (one key each); texts containing the search query are skipped.
- Edited texts inherit the translations of the most similar text they replaced on the page, marked to review (data/similarity.json).
- Saving in the block or site editor scans the page (templates, parts, menus and patterns: every page).
- Remove texts no longer on any page (button and wp langsail cleanup).
- Pages overview on the Translations screen: every page with its title, texts missing per language (a link to exactly those texts) and a link to the page in each language.
- Filters: one language at a time (only its column), "missing" and "to review" per language.
- Translated address words per language (optional), mapped back on requests; original words keep working.
- AI abilities (Abilities API, REST and MCP Adapter): list-languages, list-texts, save-translations, scan. No external service is called.
- Translator role and langsail_translate capability; settings stay with administrators.
- Scans without a browser: wp langsail scan and the scan ability use a short-lived token.
- Hidden anti-spam fields (Fluent Forms honeypot, whose label changes on every load) are never collected (data/skip-classes.json, filter langsail_skip_classes).
- Italian translation of the interface.
- A complete scan forgets pages that no longer exist (deleted or unpublished), so their texts can be cleaned up; scans cover every published post.
- Language names fall back to the native name when the settings were written without names (code, WP-CLI).
- Translated address words travel with the JSON export.
- Plugin Check (WordPress.org) passes with no errors or warnings.
- Deleting the plugin keeps all data unless Settings > Your data > Delete all LangSail data is on; pages are never changed.
- The JSON backup carries the settings; a site without languages takes them on import (the delete-data choice is never imported).
