=== LangSail ===
Contributors: massimomazzariol
Tags: multilingual, translation, language switcher, hreflang, cookie-free
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookie-free multilingual sites with one structure: build pages once, translate their texts in a table.

== Description ==

LangSail keeps a single version of every page, template and menu, written in the base language. Each other language is a translation of its texts, so a layout change is made once and every language follows.

* Languages in the address (/it/, /es/...), no cookies and no browser storage
* A translation table with every text of the site, page and status filters, search and progress
* Links and formatting shown as markers, so translations cannot break the markup
* New texts collected by scanning the site as visitors see it, header, footer and plugin output included
* Untranslated texts fall back to the base language
* WordPress, theme and plugin strings in the page language; language packs downloaded on save
* hreflang alternates, localized canonical and internal links
* Language switcher block
* Opt out with translate="no" or the notranslate class
* Optional translated address words (/it/privacy/)
* AI ready: Abilities API (REST, MCP) to list texts, save translations and scan; no external service is called
* Translator role; WP-CLI commands (scan, stats, export, import, cleanup)
* Import and export: JSON for moving between sites, PO for translators

== Changelog ==

= 0.1.0 =
* First version: base language and translation languages, URL prefixes, text units and markers, translation table with scan and pages overview, page translation, hreflang, language switcher block, translated addresses, AI abilities, Translator role, Italian interface.
