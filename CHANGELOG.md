# Changelog

## 0.1.0

- Base language confirmed at setup and translation languages chosen from every WordPress locale, common ones first (data/common-languages.json); WordPress, theme and plugin language packs downloaded on save.
- Languages in the URL (/it/, /es/...): the prefix is removed before WordPress parses the request (REQUEST_URI and PATH_INFO), WordPress runs in that locale, redirects stay in the language. No cookies.
- Text units found in the rendered page: text runs with their phrasing tags, readable attributes, page title and SEO meta. Wrapping and decorative elements stay out of the unit; translate="no" and notranslate are honored.
- Translation table with page and status filters, search, progress per language and a site scan; markers instead of HTML, validated on save.
- Pages translated on output, untranslated texts fall back to the base language; internal and canonical links localized; hreflang alternates with x-default.
- Language switcher block.
