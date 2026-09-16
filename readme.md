# rAIven Connector

An AlphaSys AI provider for the native WordPress Connectors and AI Client APIs, with an administrator chat console.

**Version:** 0.3.0
**Requirements:** WordPress 7.0+, PHP 8.1+
**License:** GPL v2 or later

Download **raiven-connector.zip** from [the latest release](https://github.com/cchatterton/raiven-connector/releases/latest), then upload it in WordPress. Updates appear on the Plugins screen, including a nonce-protected **Check for updates** link.

> Live generation verified with the service default and `qwen3.8-flash-next-nvfp4`. Some other advertised models have no active endpoint. The console supports the service default without hardcoding a model.

## Configure

1. Add a key in **Settings → Connectors → rAIven**. `RAIVEN_API_KEY` may instead be an environment variable or PHP constant.
2. Open **rAIven → Live Chat**. Confirm the public HTTPS endpoint, confirm the response limits and save settings if needed.
3. Send a message. A private session is created only after submitting the first message.

Credentials use environment variable → constant → native connector option priority. Legacy key options are retained as direct-console fallbacks. Native clients should use the native connector setting or the standard environment variable/constant.

## Native AI Client

```php
$result = wp_ai_client_prompt( 'Write a short greeting.' )
    ->using_model_preference( array( 'raiven', 'your-model-id' ) )
    ->generate_text();

if ( is_wp_error( $result ) ) {
    // Display an appropriately escaped error to an authorised user.
}
```

Use a model ID returned by your rAIven account. Native clients supply their own generation options. The console always uses the service default model; its temperature and token settings apply to console calls. This release supports non-streaming text and chat history; it does not claim image, audio or tool support.

## Exchange logs

Open **rAIven → Logs** to inspect every outgoing connector HTTP exchange, including native AI Client requests made by other plugins, model discovery, console generation and memory indexing/retrieval. Rows include UTC time, initiating WordPress user ID (or system), request source, endpoint, model, HTTP status, duration, and redacted request/response bodies. Pending rows preserve evidence of interrupted requests. Cached model lookups and validation failures before any outbound request are not remote exchanges and do not create rows.

Logs start when this version is installed. They live in `{site_prefix}as329_rai_exchanges`, separate from chat posts. Site administrators can view their site's log; multisite uses separate tables for each site, initialized on first use. Pagination loads 25 summaries at a time and fetches bodies only for the selected exchange.

Rows older than 10 days are purged daily by WP-Cron, on log-page visits, and at most hourly during connector traffic. On inactive sites, deletion waits until cron/traffic resumes. Credentials and headers are excluded/redacted, but prompts and responses are stored and visible to administrators. Each body is capped at 2 MiB and marked when truncated. No third-party analytics service receives the logs. Deactivation/uninstall retains the table; inactive plugins cannot run cleanup. Database failures do not block generation and show an incomplete-log warning on the Logs page.

## Data and access

The API key remains in WordPress or server configuration. The configured service receives prompts and conversation history. Administrators (`manage_options`) manage transcripts; only the author continues a session or retrieves it as memory. Sessions remain stored until an administrator removes them through WordPress.

Memory is off for new installs. Existing POC installs retain their prior memory behaviour. When enabled, it sends transcripts for background indexing and matching excerpts from the current administrator's previous chats as context. WordPress scheduled tasks must run for indexing. Disabling memory stops new indexing/retrieval without deleting prior data.

The original `as329_rai_settings` option, `as329_rai_session` post type, meta keys, provider ID and `as329_rai_*` functions are retained. Update the existing folder; do not run two renamed copies together. Deactivation and deletion retain data. No site data or keys are shipped in the repository or ZIP.

See [readme.txt](raiven-connector/readme.txt) for external-service disclosures and the rAIven account-terms limitation.

## Standards and design

Reviewed against [codex-standards](https://github.com/cchatterton/codex-standards) at commit `156c5e1`:

- General development standards
- WordPress plugin standards
- Branding and UX standards
- WordPress GitHub update standard

The console is **Author Branded (AlphaSys)**. The native Connectors card is **Extension Branded (WordPress)**, using the existing rAIven logo. The existing prefix and file structure remain to preserve compatibility.

[Review and validation notes](docs/release-review.md) cover the changes, checks and limits.

## Development and release

The distributable source is in `raiven-connector/`. Build with:

```sh
bash scripts/build-plugin-zip.sh
```

The script produces matching root and `dist/` ZIPs with one `raiven-connector/` top-level folder. Tests and development files are excluded.

Run the integration and AJAX tests only in a disposable WordPress 7.0+ installation with the database named `raiven_release_test`, the plugin active, and an administrator at user ID 1:

```sh
wp eval-file tests/integration.php
wp eval-file tests/ajax.php
```

The tests create synthetic users and chats in that disposable database and mock external responses. Use a clean test database; never run them on a real site. Test the UI and native upgrade separately in a browser.

For each release, update the header/constant, readme stable tag, changelog and `update.json`; run validation; rebuild and commit the ZIP; push; publish a matching `vX.Y.Z` release with the ZIP asset; verify the native WordPress upgrade.
