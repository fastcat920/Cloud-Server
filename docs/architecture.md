# Architecture

- `index.php`: single HTTP entry. UI uses hash navigation; API uses `?api=...`. Nginx URL rewrites are unnecessary.
- `assets/`: buildless ES modules and semantic CSS tokens. No remote scripts, fonts, frameworks or CDN runtime dependencies.
- `_core/Store.php`: locked guarded-PHP snapshots, atomic rename and bounded events.
- `_core/Security.php`: authenticated encryption and guarded file-backed native PHP sessions.
- `_core/Cloud.php`: transport, fixed official endpoints, provider signing, billing and compute adapters.
- `_core/Objects.php`: provider object-storage signatures, directory listing and object operations.
- `_core/Configs.php`: canonical configuration and compatible output envelopes.
- `_core/Importer.php`: transactional legacy data import and key re-encryption.
- `_core/Api.php`: authorization, CSRF, validation and application workflows. Network calls do not hold the global data lock.

Git tracks only executable application source and documentation. Runtime state and secrets stay in `_core/data/`. Each sensitive file uses a PHP extension and a refusing PHP preamble, except the key file which returns a value internally and outputs nothing over HTTP.

New interfaces use a restrained teal / off-white palette, reusable panel/table/form components, labeled controls, native dialog keyboard behavior, visible focus, reduced motion and mobile navigation. Empty states represent actual empty data; test resources are injected only in isolated tests.

## Client configuration plans

Plans bind client version labels, default template IDs and independent publish targets. Templates are immutable; each revision stores its own schema snapshot. Existing data is migrated idempotently at overview/save entry. Draft updates reuse the draft, while edits of a released revision create a child draft. Publishing validates plan ownership and endpoint collisions, and archives only within the same plan. Schema-driven UI preserves unknown keys and missing optional fields; upgrade defaults and deletions are explicit and previewed. JSON Schema support is deliberately limited to the documented subset.
