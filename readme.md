# rAIven Connector

An AlphaSys AI provider for the native WordPress Connectors and AI Client APIs, with an administrator chat console.

**Version:** 0.2.0  
**Requirements:** WordPress 7.0+, PHP 8.1+  
**License:** GPL v2 or later

Download **raiven-connector.zip** from [the latest release](https://github.com/cchatterton/raiven-connector/releases/latest), then upload it in WordPress. Updates appear on the Plugins screen, including a nonce-protected **Check for updates** link.

> Service status at release validation: model discovery succeeded, but three advertised models returned “no available endpoint”. Live generation requires the rAIven operator to restore model routing.

## Configure

1. Add a key in **Settings → Connectors → rAIven**. `RAIVEN_API_KEY` may instead be an environment variable or PHP constant.
2. Open **rAIven → Live Chat**. Confirm the public HTTPS endpoint, choose a model and save settings.
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

Use a model ID returned by your rAIven account. Native clients supply their own generation options. The console's model, temperature and token settings apply to console calls. This release supports non-streaming text and chat history; it does not claim image, audio or tool support.

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
