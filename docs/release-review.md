# v0.2.0 standards review

Standards source: cchatterton/codex-standards, commit 156c5e1, reviewed 2026-09-16.

## Resolved findings

| Area | POC issue | v0.2.0 resolution |
|---|---|---|
| Native provider | Availability read the saved key instead of the authentication supplied by WordPress | Availability and metadata directory receive native request authentication; replacement keys tested independently |
| Models | Repeated remote calls and invented fallback models | Endpoint/credential-scoped cache; explicit errors when discovery fails |
| Connector identity | Repeated unsupported icon metadata | Native provider logoPath using the existing logo |
| Request safety | Arbitrary HTTP endpoints, redirects, raw remote response errors | Validated public HTTPS destinations, redirects disabled, bounded direct responses/timeouts, generic errors |
| Validation | Loose numeric settings and silent coercion | Numeric ranges, scalar checks and previous-value preservation with WordPress notices |
| Session access | Ordinary post capabilities exposed transcripts to editorial roles | manage_options capability map; author-only console and memory access |
| Session creation | Merely opening a URL created a session | First deliberate message creates the session |
| Concurrency | Concurrent sends could overwrite history | Browser busy state and atomic per-user server lock |
| Draft recovery | Draft was removed before success | Draft preserved until successful response; no duplicate stored prompt on provider error |
| Data integrity | WordPress unslashing could damage code/path content | Slashed writes and verified history persistence |
| Memory | Cross-user context and synchronous post-response indexing | Owner filtering, visible control, bounded excerpts and scheduled indexing |
| Admin UX | Fixed desktop columns, unlabeled input, pixel CSS | AlphaSys tokens, rem dimensions, responsive layout, explicit labels, log announcements and focus states |
| Packaging | No release metadata, license/readme, updater or build | GPL metadata, docs, release manifest, build script and native GitHub updates |

## Compatibility decisions

- Retain the plugin slug, public prefix, saved options, post type and meta keys.
- Keep existing memory behaviour on upgrade; new installs default to memory disabled.
- Retain transcripts and settings on plugin deletion. Delete unwanted sessions through the native WordPress list.
- Use the standard native connector card, not a custom settings replacement.
- Do not advertise capabilities not established by the existing OpenAI-compatible text integration.
- Local prototype remains separately backed up before any replacement.

## Validation

- WordPress 7.0.4 / PHP 8.2.23 disposable runtime, separate database.
- 26 integration checks for native registration/generation, credential replacement, caching, errors, settings, storage, permissions, memory and update discovery.
- Six AJAX checks: unauthenticated user, invalid nonce, malformed input, oversized input, invalid session and concurrent request.
- Browser: empty and populated console, successful mocked send, loading, mocked 429 response with draft retained and focus restored.
- Desktop 1280×720 and mobile 390×844 screenshots reviewed. Native notices remain outside the hero; version watermark and controls remain visible and uncut.
- Accessibility: programmatic labels, conversation log/live region, readonly busy input, disabled send, keyboard focus and Enter/Shift+Enter support. No formal assistive-technology certification.

## Known limits

- Contributor field intentionally blank: no WordPress.org username was verified. This is a GitHub release, not a WordPress.org submission.
- AlphaSys privacy policy linked. Public rAIven-specific service terms were not verified; users must consult their account agreement or AlphaSys. Do not invent a terms URL.
- Single-site runtime tested; network/multisite activation has no global migration and uses current-site settings, but multisite and older WordPress versions were not runtime-tested.
- PHP 8.1 is the compatibility floor; the executed runtime checks used PHP 8.2.23. No PHP 8.1-specific syntax was introduced.
- Background indexing depends on WP-Cron. Large conversations return a new-session instruction instead of silently truncating the active conversation.

## Live service check

The configured service accepted the existing native connector key and returned six model IDs. Synthetic direct and native WordPress completions for qwen3.6-27b-nvfp4, qwen3.8-27b-nvfp4 and gpt-4o all reached the service but received HTTP 400: the service reported no available endpoint for each advertised model. Live generation could not be verified. Model routing must be restored by the rAIven service operator; the connector now reports this condition without exposing the remote response. No existing site settings were changed for these tests.

## Published release and installation verification

- Public repository: https://github.com/cchatterton/raiven-connector
- Release: https://github.com/cchatterton/raiven-connector/releases/tag/v0.2.0
- Release tag points to commit 8c6568aa27bfeb1aed4bc3c43d91a744f8874d46.
- Published ZIP SHA-256: 30193b11447c6c479855c4ec57b36311e745d2d3d2ef99ae3cfa81f97091ad71.
- WordPress detected the published release from a disposable downgraded version fixture containing the new updater. The Check for updates action, native details modal and native update installation all succeeded. The original POC has no updater, so its first upgrade requires ZIP installation.
- Installed the published ZIP on the local My.AlphaSys site after backing up the original plugin. Existing settings and API key checksums were checked after installation.
- No WordPress.org submission or service-side configuration change was made.

## v0.2.1 patch verification

Added an empty model selection labelled rAIven default. Console requests omit the model field in this mode, allowing rAIven to select its configured default. Existing explicit model selections are preserved; failures do not silently retry with another model. Native AI Client callers continue to select their own model.

Corrected key-status wording and added actionable inactive-model guidance and HTTP status information for non-JSON responses. This does not claim to repair upstream outages or the previously observed, unreproduced malformed response.

Validation: 30 integration checks and six AJAX security checks passed; all six plugin PHP files passed syntax checks. The installed v0.2.1 plugin returned OK using the live service default. Console HTML includes the default selector and revised key status. The local console was switched to the service default. No new visual browser pass was performed for this patch. An old disposable UI mock was disabled before running integration checks because it conflicted with the test suite's HTTP mocks.

## v0.2.2 patch verification

Removed the console model selector and always omit model from console generation, including memory requests. Old saved model IDs are ignored without requiring a settings save. Native provider model selection is unchanged. Application-level chat errors now use HTTP 200 with success:false, preserving the error JSON on hosts that intercept HTTP 502. Access and nonce errors retain their status codes. Host interception is a plausible explanation for the reported HTML response, not a confirmed diagnosis of the user's remote server.

Validation: 31 integration checks and seven AJAX checks passed, including stale model handling, selector removal and upstream failure status/envelope. All six plugin PHP files passed lint. The installed v0.2.2 completed a live authenticated request through admin-ajax.php with HTTP 200, JSON success and OK. A separate live generation with a simulated saved gpt-4 also returned OK. Temporary diagnostic login was revoked and the synthetic chat was trashed. No visual browser pass or remote multisite deployment was performed.

## v0.3.0 exchange logging

Added a dedicated Logs submenu and per-site `{prefix}as329_rai_exchanges` table. Direct model discovery/console/memory requests and the native AI Client transport are instrumented at their outbound boundary. Native transport decoration also covers custom transport implementations. Cached model discovery makes no remote exchange and is not logged. Native generation options remain unchanged.

An initial pending row is saved before transmission; completion updates its response, status and duration. Query strings, headers and actual authentication secrets are excluded/redacted. Structured credential fields are redacted recursively. Bodies are capped at 2 MiB with a visible marker. Administrators see paginated summaries and load details on demand. Retention is ten days, enforced by daily cron, traffic-triggered cleanup and page visits. Inactive sites must resume activity for cleanup. Database failures set a visible incomplete-log warning and do not break generation. Logs and transcripts remain separate.

Validation: 31 integration checks, seven AJAX checks, 20 exchange checks and two multisite checks passed (60 total). The disposable installation was converted to multisite and network-activated to test per-site table/cron initialization and cross-site isolation. Tests cover both transport paths, credential redaction, failed HTTP/network/JSON responses, pending entries, pagination, retention, access control, escaping, truncation and failed database writes. All seven plugin PHP files passed syntax checks. Browser review verified list/detail navigation and escaped response rendering at a narrow viewport. Installed v0.3.0 on local My.AlphaSys; live console and native AI Client generation both succeeded, both produced log rows, and the configured key was absent from stored bodies. The release was not installed on the user's separate remote network.
