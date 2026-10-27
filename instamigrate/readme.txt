=== InstaMigrate ===
Contributors: instawp
Tags: migration, transfer, backup, clone, database
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 5.6
Stable tag: 1.9.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Secure REST API endpoints for WordPress site migration — database export/import, file transfer, and search-replace.

== Description ==

InstaMigrate provides authenticated REST API endpoints that enable full WordPress site migrations between servers. It handles database export/import with binary-safe hex encoding, file archiving and transfer, and serialization-aware search-replace for domain changes.

**Features:**

* Database export with binary-safe hex encoding (preserves serialized PHP data)
* Database import with streaming line-by-line parsing (handles large dumps)
* File upload, download, archiving (tar.gz/zip), and extraction
* Serialization-aware search-replace for domain migrations
* Dual authentication: API key header or WordPress Application Passwords
* WP-CLI detection (informational, reported in status endpoint)
* Admin UI showing API key and endpoint documentation

**Important:** This plugin grants full database and filesystem access via its REST API. Deactivate and delete it immediately after your migration is complete.

== Installation ==

1. Upload the `insta-migrate` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Tools > InstaMigrate to find your API key
4. Use the API key in the `X-Insta-Key` header for all REST API requests
5. **Remove the plugin after migration is complete**

== Frequently Asked Questions ==

= Where do I find my API key? =

Go to Tools > InstaMigrate in your WordPress admin. The API key is displayed there and can be regenerated if needed.

= Is this plugin safe to leave active? =

No. This plugin exposes powerful migration endpoints. Always deactivate and delete it after your migration is complete.

= Does it handle serialized data during search-replace? =

Yes. The search-replace engine correctly unserializes PHP data, performs replacements recursively, then re-serializes with updated byte counts.

= What authentication methods are supported? =

Two methods: (1) `X-Insta-Key` header with the plugin API key, or (2) WordPress Application Passwords via HTTP Basic Auth (requires admin privileges).

== Changelog ==

= 1.9.0 =
* Fix: migrations now work on sites that use a plugin to restrict the REST API to logged-in users.
* Improvement: bridge mode is more reliable on those sites.
* Improvement: better handling of malformed requests.

= 1.8.9 =
* Compatibility: now runs on PHP 5.6 and newer (was 7.0). Sites still on PHP 5.6 — common on older shared hosting — could not run the plugin at all, so they could not be migrated. Newer-PHP syntax was rewritten to its PHP 5.6 equivalent with identical behaviour on every version; error handling also catches ordinary exceptions on PHP 5.6, where the newer catch-all does not exist. Tested end to end on PHP 5.6 with WordPress 6.2, on PHP 7.0 with WordPress 6.5, and through the full release checks on current PHP.

= 1.8.8 =
* Compatibility: now runs on PHP 7.0 and newer (was 7.4). The plugin used nothing that needs 7.4, so sites on PHP 7.0–7.3 could not install it for no reason; it is now tested on PHP 7.0 with WordPress 6.5.
* Fix: Search-replace no longer rewrites email addresses when it is given a bare domain to replace. Replacing `old.com` with a new host used to also change every address ending in `@old.com` — contact addresses, the admin address, notification senders, addresses stored by payment plugins — because the domain reads the same whether it sits in a web address or an email address. Those addresses are now left alone by default, while web addresses, page text and page-builder content are rewritten exactly as before. If a move genuinely should carry the mailboxes to the new domain, send `rewrite_emails: true` with the request. The response now also reports `email_guard` (whether the protection applied) and `emails_protected` (how many addresses it left alone).

= 1.8.7 =
* Fix: `/disk-usage` reported a size that did not match the site. It delegated to WordPress core's Site Health size calculation, which caches each directory's size for ten years and only clears that cache on multisite installs — so on an ordinary single-site install the figure was measured once and then never changed again, however much the site grew. One site reported a 220 MB media library against a real 1.9 GB one. Sizes are now measured directly on every call, with our own one-hour cache and a `force=1` parameter to bypass it.
* Fix: a site too large to measure inside the time limit returned no size at all. It now returns the amount measured so far, flags the answer as `partial`, and lists the directories it did not reach, so a caller gets a usable floor instead of nothing.
* New: `exclude` accepts the same paths and patterns as `/files/archive`, so a caller that is skipping part of a site gets a size with those paths taken out, reported separately under `excluded`.
* New: `budget` caps how long the measurement may run (default 20 seconds).
* Scope is unchanged and deliberate: this is the whole install, `wp-admin` and `wp-includes` included, because those occupy disk. Only the paths a caller asks to exclude are left out.
* Robustness: an unreadable directory, a broken symlink, a symlink loop, or a file deleted while the site is being measured now skips that one entry and is reported under `unmeasured`, instead of failing the request or silently lowering the total.

= 1.8.6 =
* Fix (data integrity): `/search-replace` could overwrite every row sharing a `meta_key` with a single row's value. Rows were updated using only the LAST column MySQL reports as `PRI`, which is correct for standard WordPress (`postmeta` has `PRIMARY KEY (meta_id)`) but wrong wherever the primary key spans several columns. WP Engine ships `PRIMARY KEY (post_id, meta_key, meta_id)`, so the update matched on `meta_key` alone and applied one row's rewritten value to the whole group — silently, reporting success and a plausible change count.
* Updates are now matched on the complete primary key, with each value's type taken from the column definition rather than guessed from the value, so numeric-looking text keys can no longer mismatch a row. Sites with the standard single-column key are unaffected and behave exactly as before.
* Safeguard: any single update that would affect more than one row now aborts the request with a clear error instead of proceeding, so this class of problem cannot fail quietly again.
* The response now reports the key columns used per table, and any table skipped for having no primary key, making an unusual schema visible while the migration runs.

= 1.8.5 =
* New: `/cache/clear` clears the caches that still hold the OLD site's state after a migration, in one call — the object cache, page-builder caches, rewrite rules, and optionally expired transients, the PHP opcode cache, and plugin caches that hardcode the previous server's paths. Callers pick what to clear and get a per-target result back, so "this site has no object cache" is distinguishable from "the flush failed".
* Fix: page-builder caches are now reachable at all. A builder's generated CSS has the source site's domain baked into its asset URLs, and Divi keeps its copy in `wp-content/et-cache/` — outside the uploads folder, where the existing search-replace endpoint is confined. Migrated Divi sites could therefore serve the old domain's image URLs until each page was re-saved by hand. Divi, Elementor, Beaver Builder, WPBakery, Bricks and Breakdance are covered, each skipped cleanly when not installed.
* Each builder's own documented clear function is preferred over deleting files, with the file cleanup applied only to whatever the builder left behind. Cache deletion is confined to `wp-content` and refuses to follow symlinks out of it; the object cache is flushed through WordPress's own API, never by clearing a shared cache server wholesale.

= 1.8.4 =
* New: `/migration-details` records the migration on the destination site in the same place the InstaWP Connect plugin has always written it (`instawp_last_migration_details`) — the migration's id, the engine that ran it, and its status. Tools and dashboards built against the older migration flow keep working after a migration run by the newer engine, instead of finding no record at all. Written twice during a migration (when it starts, and again with the final outcome) and merged over any existing record, so nothing already stored is lost.

= 1.8.3 =
* Feature: New `/disk-probe` endpoint so a migration can check, before it starts copying files, whether the destination actually has room for the incoming site — including on hosts where a per-account disk quota isn't visible to the normal free-space check. It briefly writes and then deletes a test file sized to the incoming data; if it doesn't fit, the migration stops early and asks you to free space or move to a larger plan, instead of failing partway through the file copy. Bounded (time-limited, capped, always cleaned up) and authenticated like every other endpoint.

= 1.8.2 =
* Fix: `/files/extract` no longer unpacks into the site root when no destination is given. It used to fall back to the WordPress root silently, so an archive of `wp-content` could be scattered across the site root — over files like `wp-config.php` and `index.php` — with nothing in the response to show it. It now falls back to your `wp-content` folder instead, which is what the archives are for, and says so in the response. Existing calls that pass a destination are unaffected.
* Fix: `/files/extract` also accepts `extract_to` as a name for the destination. Sending that name previously did nothing — the value was ignored and the archive went to the site root instead.

= 1.8.1 =
* New: `/theme/activate` switches the active theme, installing it from wordpress.org first if it isn't present. Defaults to Twenty Twenty-One, which supports WordPress 5.3 and newer — a single theme that works across the whole supported range. This matters before a core change: WordPress ships a theme matched to each release, so the active theme is often newer than the core version being moved to, and it then calls functions that don't exist there and takes the whole site down. The response returns the previous theme so the change can be reversed. A request with no body at all does the whole step — it activates Twenty Twenty-One and installs it first if the site doesn't have it. On multisite it changes only the current site unless you ask for the whole network.
* New: `/core/update` updates WordPress core to a requested version. Intended for the case where the site you're copying from runs a newer WordPress than the site you're copying to — a migration replaces plugins, themes and the database, but not WordPress itself, so without this the destination keeps its older core and a plugin that needs the newer one can fatal on load. Raise-only by design: a request at or below the current version is reported as skipped and never downgrades. Multisite is refused outright — a network shares one copy of WordPress, so a core change there can't be limited to one site or undone per site. Hosts that require FTP/SSH credentials for filesystem writes return a distinct `fs_credentials_required` error rather than a generic failure.
* New: `/plugins/deactivate-plugin` deactivates plugins without deleting them, so the change can be undone — the response returns the previous active-plugin list as a restore point. Pass `all_except_self` to deactivate everything except InstaMigrate in one call (useful before a core update). Network-activated plugins are included on multisite. InstaMigrate refuses to deactivate itself, so a site is never left without a way back in.
* Fix: `/files/search-replace` no longer crashes the site when the `extensions` value is sent as plain text (for example `txt`) rather than a list. It previously produced a "critical error" page with no usable error message; it now accepts a list, a comma-separated string, or a JSON array, and always returns a proper error if something else goes wrong.
* Fix: `/files/mkdir` now keeps new directories inside `wp-content`, matching every other file endpoint. Previously it would create a directory anywhere the site's user could write. A path given relative to `wp-content` still works exactly as before — it is simply anchored there instead of to an undefined location — so existing callers are unaffected. Only a path pointing outside `wp-content` is now refused.
* Fix: the plugin-install, plugin-delete, plugin-deactivate, theme-activate, core-update and file-search-replace endpoints now always return a structured JSON error when something unexpected goes wrong. Previously an error raised outside the guarded section — including from another plugin's own activation or deactivation code — produced a bare HTTP 500 with no response body, which is indistinguishable from the site being down.

= 1.8.0 =
* New: skip files and folders during a migration. `/files/archive` now honors an `exclude` parameter (a JSON array or CSV of wp-content-relative paths/globs) — matched files and whole directories are left out of the archive. Default (no exclude) is byte-identical to before.
* New: `/files/list` endpoint lists a wp-content directory one level at a time, with file and directory sizes, so a migration UI can show what's on the site and let you choose what to skip. Confined to wp-content (no access outside it).
* Fix: archive and database-dump temp files now use a unique name (not just a per-second timestamp), so two exports of the same directory in the same second can't collide on one filename and have a CDN serve a stale copy.

= 1.7.9 =
* New: added a disk-usage endpoint that reports how much space the site uses (files plus database).
* New: the status endpoint now also returns the site's admin email address.

= 1.7.8 =
* Fix: `/files/archive` (tar.gz) no longer 500s on large `uploads/`/`plugins/` directories. It now streams a POSIX ustar tar straight into a gzip sink one file at a time (constant memory, single pass) instead of building the whole tar in memory and re-reading it to compress (two passes that OOM'd as an uncatchable PHP fatal). Output is unchanged on the wire (still a `.tar.gz`); long paths beyond ustar's 255-char limit are reported in a new `long_paths` field instead of producing an unextractable entry.
* Fix: archiving a directory that contains the plugin's own temp dir (e.g. `uploads/`, which holds `uploads/instamigrate/`) no longer self-includes the half-written archive — the temp dir is skipped during the walk. This removes a runaway-growth timeout and the long-standing silently-ignored `exclude` behaviour for the temp dir.

= 1.7.7 =
* Security: hardened the `source_url` SSRF guard. IP validation now normalizes addresses with inet_pton and tests an explicit CIDR denylist instead of PHP's range filter flags — closing bypasses via IPv4-mapped IPv6 (e.g. `::ffff:169.254.169.254`), NAT64 (`64:ff9b::/96`) and CGNAT (`100.64/10`). The host is resolved once, every returned address is validated, and the validated IP is pinned into the request (`CURLOPT_RESOLVE`) so DNS rebinding between check and connect can't reach an internal address.
* Fix: `/db/import` now deletes a `source_url`-pulled temp dump on every exit path (no orphaned gz left in `uploads/instamigrate/`), and guards the magic-byte read against an unreadable file (clean 500 instead of a fatal).
* Fix: `/db/export` `skip_regenerable` no longer over-matches — it uses a Wordfence allowlist (never skips `wfconfig`/`wfls_*` config + 2FA secrets) and skips only Elementor telemetry (`e_events`), never form submissions. `ignore_tables` now also accepts suffix-only table names.
* Improvement: `/files/fetch-probe` caps the body it buffers (origins that ignore Range can return a full body), avoiding a memory spike on large artifacts.

= 1.7.6 =
* New: Server-to-server pull. `/db/import` and `/files/extract` accept a `source_url` param and fetch the artifact directly from the source site (SSRF-guarded: http/https only, blocks loopback/private/reserved/link-local targets), so a destination can pull large archives straight from the source instead of routing every byte through a migration client. New `/files/fetch-probe` endpoint reports whether the destination can reach a given source URL, so callers can fall back to chunked upload when it can't.
* New: `/db/export` can skip the data of regenerable/log tables (opt-in via `skip_regenerable=1` and/or an explicit `ignore_tables` CSV) to shrink huge dumps — the DROP+CREATE schema is still emitted so dependent plugins don't fatal, only the rows are skipped. Default behavior is unchanged.
* New: `/files/extract` accepts `verify=1` to count any archive entries missing after extraction and report `complete:false` + `missing` — surfacing silent ZipArchive/Phar truncation on very large file trees instead of a false success.

= 1.7.5 =
* Performance: `/db/export` now compresses the SQL dump inline in a single pass at gzip level 1, instead of writing the full uncompressed dump to disk and then re-reading and re-compressing it at level 9. This removes a second full pass over the dump and the most CPU-intensive step of the export — on large databases the level-9 re-compression was the main cause of edge-timeout (HTTP 524) failures. The emitted SQL is byte-for-byte unchanged and the import side auto-detects gzip by magic bytes, so the compression level is fully transparent.

= 1.7.4 =
* New: `/insta-migrate/v1/plugins/install-activate-plugin` endpoint. Installs a plugin from a URL (or `.zip`) via WordPress's `Plugin_Upgrader` and activates it. Powers App-driven post-migration plugin actions, so an integrating App can request specific plugins be installed on the destination as part of the migration. Authenticated via `X-Insta-Key`.
* New: `/insta-migrate/v1/plugins/delete-plugin` endpoint. Accepts a `plugin_files` array, deactivates each plugin and removes it via `delete_plugins()`. InstaMigrate is filtered out of the request (both `instamigrate/insta-migrate.php` and `insta-migrate/insta-migrate.php` directory variants) so the endpoint cannot disable its own access. Authenticated via `X-Insta-Key`.

= 1.7.3 =
* New: Bridge auto-discover. Plugin polls the tracker every 5 minutes (and on every visit to the InstaMigrate admin page) to see if a bridge migration has been assigned to this site, and starts itself automatically. Removes the need for site owners to copy/paste tracker URL, migration ID, and shared secret. Discover URL is pinned (HTTPS, cert verification on); 60-second outbound rate limit with a manual "Check now" button to bypass.

= 1.7.2 =
* New: `push_file` bridge action. Source plugin can now multipart-POST a local file directly to a remote URL — typically the destination plugin's `/files/upload` or `/db/import` endpoint. Enables source-bridge inversion for sites where the source is firewalled (Imunify360 IP bans, custom WAFs) but the destination is reachable normally. The source plugin polls outbound, dest receives via its existing endpoints, no tracker upload sink required.

= 1.7.1 =
* Fix: Bridge self-trigger curl timeout raised from 500ms to 5s. On slow PHP-FPM hosts the original 500ms budget could abort before TCP connect + TLS handshake completed, breaking the bridge tick chain. WP-Cron is the safety net but can't fire on dead/CF-blocked sites.

= 1.7.0 =
* New: Bridge Mode. Plugin can run as an outbound-polling agent for sites where inbound REST is filtered (Cloudflare JS challenge, WAF blocking `/wp-json/`). The plugin POSTs to the tracker for the next command, dispatches it via the existing internal handlers, posts the result back, and self-triggers the next tick using `CURLOPT_RESOLVE` to the origin IP for CF bypass. WP-Cron `bridge_safety_tick` resumes the chain on the next admin pageview if a tick dies mid-flight.
* Admin UI: "Bridge Mode" card in Settings → InstaMigrate with Start / Stop / status fields.

= 1.6.0 =
* Performance: DB export now uses multi-row INSERT statements (10-50x faster imports)
* Performance: DB import streams gzip directly (no decompress-to-disk step)
* Performance: DB import uses transaction batching for legacy single-row dumps
* Performance: Added MySQL tuning flags (autocommit=0, unique_checks=0) during import/export
* Backward compatible: v1 single-row dumps still import correctly with auto-batched transactions

= 1.5.0 =
* Internal version alignment (header and constant now both report 1.5.0)
* No functional changes from 1.4.0

= 1.4.0 =
* Security: Permission callbacks now require `manage_network` (super admin) on multisite, instead of `manage_options` which subsite admins may have
* Security: File concat (`/files/concat`) output restricted to plugin temp directory only
* Security: File search-replace (`/files/search-replace`) restricted to uploads directory only
* Security: File upload (`/files/upload`) destination restricted to uploads directory only
* Security: File search-replace now uses WP_Filesystem for reads and writes instead of direct PHP file functions
* Change: Temp directory moved from `wp-content/insta-migrate-tmp/` to `wp-content/uploads/insta-migrate-tmp/` per wp.org guidelines

= 1.3.0 =
* Removed all exec()/shell_exec() calls per wp.org plugin guidelines
* Removed `/wp-cli` REST endpoint (required exec)
* Archive creation and extraction now use PharData/ZipArchive exclusively
* Binary detection (has_wp_cli) now uses hardcoded path checks only

= 1.2.0 =
* New: `/db/fix-prefix` endpoint — fixes usermeta meta_key and options option_name values after a migration where the table prefix changed. Prevents users losing roles/capabilities when the sed prefix-rewrite missed single-quoted values.

= 1.1.0 =
* New: `/files/concat` endpoint — concatenate chunked file uploads on the server (for large archives exceeding PHP upload limit)
* New: `/files/search-replace` endpoint — search-replace strings within static files on disk (CSS/JS/JSON), used for Elementor CSS domain replacement after DB search-replace
* Fix: Numeric columns (INT, BIGINT, DECIMAL, etc.) now exported as plain numbers, not hex-encoded. Prevents MySQL from misinterpreting hex literals as integers.

= 1.0.0 =
* Initial release
* Database export/import with hex encoding
* File upload, download, archive, extract
* Serialization-aware search-replace
* Dual authentication (API key + Application Passwords)
* WP-CLI detection in status endpoint
* Admin UI with API key management
