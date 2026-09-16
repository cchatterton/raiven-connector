# Changelog

## 0.2.0 - 2026-09-16

- Added native WordPress GitHub updates, release packaging and GPL documentation.
- Corrected native API-key validation and model discovery; reused the existing rAIven logo in Connectors.
- Added an AlphaSys console header, version watermark, connection status and responsive, accessible controls.
- Restricted transcript administration to administrators and chat continuation and memory retrieval to the session owner.
- Started sessions on the first submitted message, retained drafts after failures and prevented concurrent sends.
- Validated HTTPS endpoints and generation settings; bounded requests, disabled credential redirects and removed raw remote errors.
- Cached model discovery and moved transcript indexing to WordPress background tasks.
- Preserved existing settings and transcripts; added a visible memory control, with memory off by default for new installations.

### Known service limitation

At release validation, rAIven model discovery worked but three advertised models returned HTTP 400 with no available endpoint. Live generation remains dependent on the service operator restoring model routing.

## 0.1 - 2026-06-06

- Initial rAIven provider, live chat console, session transcripts and memory prototype.
