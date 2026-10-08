=== LangSail ===
Contributors: massimomazzariol
Tags: multilingual, translation, language switcher, ai translation, hreflang
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual with one structure: build pages once, translate every text in one table, or let the AI you already use do it. No cookies.

== Description ==

Most multilingual plugins copy every page once per language: change a layout and you change it four times. LangSail keeps **one version** of every page, template and menu, in your base language. Every other language is a translation of its texts. Change the layout once and every language follows.

Completely free. No paid tier, no account, no API keys, no translation credits.

= Translate with the AI you already use =

LangSail never calls an AI service. Through the WordPress Abilities API and the official MCP Adapter, the agent you connect (Claude, Cursor, VS Code or any other MCP client) reads your texts, follows **your instructions** (tone, audience, fixed terms), translates and saves. Every translation is checked before it is stored, so the markup cannot break, and you can have them saved as "to review" and approve them in the table. A Translator role gives the agent access to translations and nothing else.

= Built for people who check their pages =

* **One table.** Every text of the site as visitors see it, header, footer, menus and other plugins' output included. Filter by page, language, missing or to review.
* **Pages overview.** Every page with what is still missing in each language, one click to exactly those texts and to the page in that language.
* **Markup-safe.** Links and bold words appear as markers, `Hello [1]our team[/1]`, never as HTML.
* **Edits are not lost.** A changed sentence keeps its old translation, marked to review. Untranslated texts fall back to the base language.
* **WordPress speaks the language.** On /it/ the whole site runs in Italian: WordPress, theme and plugin strings, dates.

= SEO first =

* Languages in the address: /it/, /es/, /de/, optional translated words (/it/chi-siamo/).
* hreflang alternates with x-default, localized canonical and internal links, translated titles, descriptions and JSON-LD.
* Language versions in the sitemap, and indexed only once the page is translated; half-done pages are sent with noindex.

= Light and private =

* 0 KB of JavaScript on the site, no cookies, no browser storage, no external calls.
* 0.7 KB of CSS, only on pages with the language switcher.
* About 8 ms to translate a 150 KB page; pages in the base language are not touched.
* Your data stays yours: deleting the plugin keeps everything unless you ask otherwise, and one JSON file backs up texts, translations and settings.

= Also =

* Language switcher block with native names or short codes and optional flags.
* JSON import and export (local to live), PO files for Poedit and translators.
* WP-CLI: wp langsail scan, stats, export, import, cleanup.
* Works with Fluent Forms (messages and visitor emails), The SEO Framework (sitemap), Mintchat (chat messages), and any plugin through public filters.

== Installation ==

1. Install and activate LangSail.
2. Confirm the base language and choose the translation languages under LangSail > Settings.
3. Open LangSail > Translations, scan the site and translate, by hand or with your AI agent.
4. Add the Language switcher block to your header.

To connect an AI agent, install the WordPress MCP Adapter plugin and follow the step-by-step guide: https://github.com/massimomazzariol/langsail/blob/main/docs/ai-translation.md

== External services ==

LangSail calls no external service for translating: texts and translations stay in your database, and an AI agent can reach them only through the abilities, with the permissions of the user it acts for.

When you add a language in Settings, LangSail asks WordPress to download the WordPress, theme and plugin translations for it from WordPress.org (translate.wordpress.org language packs), the same service WordPress uses for its own updates. No visitor data is sent. WordPress.org privacy policy: https://wordpress.org/about/privacy/

== Frequently Asked Questions ==

= Does it duplicate my pages? =

No. There is one page in your base language; the other languages are translations of its texts, applied when the page is shown. Delete LangSail and your pages are exactly as you built them.

= Which AI can translate my site? =

Any client that speaks MCP, through the official WordPress MCP Adapter: Claude Desktop, Claude Code, Cursor, VS Code and others. LangSail itself sends nothing anywhere and needs no API key.

= What happens to my translations if I delete the plugin? =

Nothing, by default: deleting LangSail keeps texts, translations and settings, and installing it again brings everything back. They are erased only if you turn on Settings > Your data > Delete all LangSail data. Your pages are never changed.

= How do I back up or move the translations? =

Translations > Import and export > All languages (JSON), or wp langsail export. Importing the file restores texts, translations, translated addresses and, on a site without languages yet, the settings.

= How do I keep a brand name untranslated? =

Add it to Settings > Never translate, or mark it in HTML with translate="no" or the notranslate class.

== Screenshots ==

1. The Translations screen: progress per language, pages overview with what is missing, translation table.
2. A page in Italian with the language switcher in the header.
3. Settings: base language, translation languages, indexing threshold, never-translate list.

== Changelog ==

= 0.1.0 =
* First version: base language and translation languages, URL prefixes, text units and markers, translation table with scan and pages overview, page translation, hreflang, language switcher block, translated addresses, AI abilities with owner instructions, Translator role, JSON and PO import and export, Italian interface.
