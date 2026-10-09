# Translate your site with the AI you already use

LangSail never calls an AI service and needs no API key or translation credits. It offers four abilities on the WordPress Abilities API; an AI agent you connect (Claude, Cursor, VS Code, any MCP client) reads the texts, translates them and saves the translations, with the permissions of the WordPress user it acts for.

Tested with the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) 0.7.0 on WordPress 7.1.

## 1. Prepare the site

1. Install and activate LangSail and choose your languages (**LangSail > Settings**).
2. Write the **Instructions for AI translators** in the same screen: who the site is for, the tone, words with a fixed translation. Example:
   > A family bakery by the sea. Warm, simple tone, short sentences. German: use "du". Italian: "lievito madre" for sourdough. Never translate "Harbor Bakery".
3. Install the [MCP Adapter](https://github.com/WordPress/mcp-adapter/releases) plugin (download `mcp-adapter.zip`, then **Plugins > Add New > Upload**).
4. Create a user with the **Translator** role for the agent. It can read and save translations and nothing else: no posts, no settings, no plugins.

## 2. Connect your AI client

The examples are for Claude Desktop (`claude_desktop_config.json`); other MCP clients take the same `command`, `args` and `env`.

**A site on your computer** (WP-CLI available, for example WordPress Studio or a local server):

```json
{
  "mcpServers": {
    "my-site": {
      "command": "wp",
      "args": [
        "--path=/path/to/your/site",
        "mcp-adapter",
        "serve",
        "--server=mcp-adapter-default-server",
        "--user=translator"
      ]
    }
  }
}
```

In WordPress Studio use `"command": "studio"` and the same `args` with `"wp"` in front: `[ "wp", "--path=/path/to/your/site", "mcp-adapter", "serve", ... ]`.

**A site online:** create an Application Password for the translator user (**Users > Profile > Application Passwords**), then:

```json
{
  "mcpServers": {
    "my-site": {
      "command": "npx",
      "args": [ "-y", "@automattic/mcp-wordpress-remote@latest" ],
      "env": {
        "WP_API_URL": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "translator",
        "WP_API_PASSWORD": "the application password"
      }
    }
  }
}
```

Restart the client. It now sees three tools from the adapter (discover, get info, execute an ability) and, through them, LangSail's abilities.

## 3. Ask

> Translate every missing text of my site into German. Read the instructions first, keep the [1]...[/1] markers around the matching words, and save the translations as "to review".

What the agent does:

| Step | Ability |
| --- | --- |
| Reads your instructions, the never-translate list and the progress | `langsail/list-languages` |
| Optionally collects new texts after content changes, 20 pages per call until `next_offset` is null | `langsail/scan` with `offset` |
| Fetches the texts still missing, 50 at a time | `langsail/list-texts` with `locale`, `status: missing`, `limit`, `offset` |
| Saves them; wrong markers come back as errors to fix | `langsail/save-translations` with `locale`, `status`, `translations` |

Saved as **to review**, the translations wait for you in **LangSail > Translations** (filter **Show: To review**): read them, adjust, save. Saved as **translated**, they go live at once.

## Good to know

- **Markers.** Links and formatting appear as `[1]...[/1]`, a line break as `[2/]`. A translation must keep the same markers; LangSail checks every one and refuses the translation otherwise, so the page markup cannot break.
- **Nothing leaves the site by itself.** Texts reach the AI only because your client asked for them, and only what the translator user may read.
- **Indexing.** A language version of a page is offered to search engines only once it is translated (threshold in Settings), so half-done work is never indexed.
- **Undo.** Every text keeps its base version; clearing a translation brings the base language back on that page. A JSON export (**Translations > Import and export**) before a big run is a full backup.
