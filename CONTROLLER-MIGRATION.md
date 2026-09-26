# Standards migration 0.3.1

Alpha status means reviewed compliance with the current codex-standards WordPress, GitHub update, development and branding requirements. It is not a GitHub prerelease channel.

The release replaces independent GitHub discovery with the guarded AS controller API 1 client. Package basename, namespaces, settings, activation scope and feature hooks are preserved. WordPress 7.0 and PHP 7.4 are the minimums. Licence, readme, version and release metadata are aligned. Normal plugin row and native update reads make zero discovery HTTP requests; features continue without the controller.

Branding mode: Author Branded (AlphaSys), retaining the existing WordPress-native interface. Applies codex-standards/Branding_and_UX_standards.md. Existing feature interfaces are retained. Content Stream admin JavaScript is now enqueued and authored CSS dimensions use rem.

Validation: PHP 7.4.30 and 8.1.23 activation and integration; PHP 8.5.7 syntax/runtime checks; isolated WordPress 7.0 single-site and multisite; controller metadata/link checks, zero update-discovery HTTP, package identity, settings/activation preservation during update. GF field payload and retention-policy regressions use dependency fixtures. rAIven native generation and logging tests mock remote responses; no customer endpoint or real submissions are used. Content Stream verifies network queue capture and admin surfaces without WPML; WPML-specific behaviour is unchanged and is not an end-to-end live-service test. See tests and release notes for exact scope.
