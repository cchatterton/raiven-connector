# Changelog

## 0.2.2

Locked console generation to the rAIven service default and removed model selection. Existing saved model IDs are ignored automatically, with no settings changes required after updating. Chat application failures now return a JSON error envelope with HTTP 200 to avoid hosting proxies replacing HTTP 502 with HTML. Authentication and permission failures keep their HTTP error status.

## 0.2.1

Added rAIven default model selection for console requests. New installs use the service default; existing explicit choices are preserved. Clarified that key validation only checks model-list access. Improved inactive-model guidance and non-JSON HTTP errors while preserving drafts. Verified live generation with the default and qwen3.8-flash-next-nvfp4.


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
