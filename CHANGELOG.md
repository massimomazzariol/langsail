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
