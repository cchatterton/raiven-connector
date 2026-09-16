=== rAIven Connector ===
Contributors:
Tags: ai, connector, chat, alphasys
Requires at least: 7.0
Tested up to: 7.0.4
Stable tag: 0.2.1
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect rAIven to the native WordPress AI Client, with an administrator chat console and GitHub updates.

== Description ==

rAIven Connector is an AlphaSys plugin. It registers rAIven in Settings > Connectors and exposes authenticated text models to the native WordPress AI Client.

The administrator console provides model and response settings, private saved conversations and optional memory from your own previous sessions. New installations start with memory disabled. Existing POC installations retain their memory behaviour and saved data.

Administrators can manage transcripts. Only a session's author can continue it in the console or include it in memory retrieval. Editor and author roles cannot access the session post type.

Updates are delivered through the WordPress Plugins screen from the public GitHub release at https://github.com/cchatterton/raiven-connector.

Live generation verified with the service default and qwen3.8-flash-next-nvfp4. Some advertised models have no active endpoint. Use rAIven default in the console to let the service select its configured model.

== Installation ==

1. Download raiven-connector.zip from the GitHub release.
2. Upload it through Plugins > Add Plugin > Upload Plugin, or replace the existing raiven-connector plugin with the ZIP.
3. Activate the plugin on WordPress 7.0 or later with PHP 8.1 or later.
4. Add your rAIven key in Settings > Connectors. RAIVEN_API_KEY may also be supplied as an environment variable or PHP constant.
5. Open rAIven > Live Chat, choose the model, save settings and send a message.

== Frequently Asked Questions ==

= Where is my API key stored? =
In the native WordPress connector option, unless supplied by environment variable or constant. Keys are never included in the chat UI, transcripts or GitHub requests.

= Are existing POC settings and chats migrated? =
The plugin keeps the original settings option, provider ID, session post type and meta keys. Install it as an update to the same plugin folder. Do not activate a second renamed copy alongside the POC.

= What does memory do? =
It sends saved transcripts for indexing in a WordPress scheduled task, identifies entities in new prompts and sends matching excerpts from the current user's own previous sessions. Disable it in Chat settings to stop new indexing and retrieval. Existing indexes remain stored. WP-Cron must run for indexing.

= What happens when I uninstall? =
Settings, credentials and saved transcripts are retained. Administrators can trash sessions in the rAIven list. Manage or disconnect the API key through Settings > Connectors.

= Does this stream responses or generate images? =
This release supports non-streaming text generation and conversation history. It does not advertise image, audio or tool-calling capabilities.

== External services ==

rAIven (AlphaSys): https://raiven.alphasys.com/
The configured HTTPS endpoint receives your API key to list models and validate credentials. On generation it receives your prompt, conversation history, selected model and response parameters. With memory enabled it also receives transcripts for indexing, new prompts for entity extraction and matching excerpts from your own previous sessions. Other plugins using the native provider control their own prompts and generation options.
Privacy: https://alphasys.com.au/privacy-policy/
Service terms are supplied with your rAIven account agreement. No public rAIven-specific terms URL was verified for this release; obtain the applicable terms from AlphaSys at https://alphasys.com.au/contact/ before enabling the service. A custom endpoint has its own applicable terms and privacy policy.

GitHub: https://github.com/cchatterton/raiven-connector
WordPress periodically retrieves public release metadata and downloads the ZIP when an administrator installs an update. GitHub receives normal HTTP connection metadata and the plugin version; rAIven credentials and chats are not sent.
Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

== Changelog ==

= 0.2.1 =
* Added rAIven default model selection, clearer key status, and actionable model and HTTP error messages.

= 0.2.1 =
* Native credential validation, model caching and bounded HTTPS requests.
* AlphaSys admin console with version watermark, responsive controls and accessible feedback.
* Private sessions, owner-scoped memory, retained drafts and background indexing.
* GitHub updates, release packaging and documentation.

= 0.1 =
* Initial proof of concept.

== Upgrade Notice ==

= 0.2.1 =
Requires WordPress 7.0+. Existing settings and chats are retained. Transcript access now requires manage_options. Chat continuation and memory are restricted to the session author.
