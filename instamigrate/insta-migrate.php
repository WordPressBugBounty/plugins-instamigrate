<?php
/**
 * Plugin Name: InstaMigrate
 * Plugin URI: https://wordpress.org/plugins/instamigrate/
 * Description: Migration helper for WordPress site transfers. Provides secure REST API endpoints for database import, file transfer, and search-replace. REMOVE THIS PLUGIN AFTER MIGRATION.
 * Version: 1.9.0
 * Author: InstaWP
 * Author URI: https://instawp.com/
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: instamigrate
 * Requires at least: 5.0
 * Requires PHP: 5.6
 */

if (!defined('ABSPATH')) {
    exit;
}

define('INSTA_MIGRATE_VERSION', '1.9.0');
define('INSTA_MIGRATE_OPTION_KEY', 'insta_migrate_api_key');
define('INSTA_MIGRATE_TEMP_DIR', wp_upload_dir()['basedir'] . '/instamigrate');

// Bridge mode: file-based config so it survives DB replacement during migration.
// Path is under WP_CONTENT_DIR because uploads/ may be archived and restored.
define('INSTA_MIGRATE_BRIDGE_CONFIG_FILE', WP_CONTENT_DIR . '/.instamigrate-bridge.json');
define('INSTA_MIGRATE_BRIDGE_STATE_FILE', WP_CONTENT_DIR . '/.instamigrate-bridge-state.json');
define('INSTA_MIGRATE_BRIDGE_CRON_HOOK', 'insta_migrate_bridge_safety_tick');
define('INSTA_MIGRATE_DISCOVER_CRON_HOOK', 'insta_migrate_bridge_discover');
define('INSTA_MIGRATE_DISCOVER_URL', 'https://migrate.instawp.io/api/bridge/discover');

class InstaMigrate {

    /**
     * Default theme for /theme/activate — the wide-compatibility theme used in the safe
     * core-change procedure (deactivate plugins → switch theme → change core).
     *
     * Twenty Twenty-One declares "Requires at least: 5.3", so a single theme covers WP 5.3 through
     * current. The theme WordPress bundles with a given release is the usual hazard: it is often
     * NEWER than the core version being moved to, calls functions that do not exist there, and
     * hard-fatals every route before WP boots.
     */
    const SAFE_THEME = 'twentytwentyone';

    /**
     * Accepted values for the `status` field of instawp_last_migration_details, and the engine
     * recorded when a caller does not name one. These mirror what instawp-connect writes, so
     * tooling reading the option cannot tell a v4 (agent) migration apart by its shape.
     */
    const MIGRATION_DETAILS_STATUSES       = [ 'initiated', 'completed', 'failed' ];
    const MIGRATION_DETAILS_DEFAULT_ENGINE = 'v4';

    /**
     * Targets accepted by /cache/clear, and the subset cleared when a caller names no scope.
     *
     * The default is deliberately narrow. A migration's finalize phase needs the caches that hold
     * STALE MIGRATED STATE — the object cache (autoloaded options from the old site), the builders'
     * generated CSS (the source domain baked into asset URLs) and the rewrite rules (permalinks
     * pointing at the old structure). `transients`, `opcache` and `plugin_caches` are real repair
     * tools but touch things a caller should ask for on purpose, so they stay opt-in.
     */
    const CACHE_CLEAR_TARGETS       = [ 'object', 'builder', 'rewrite', 'transients', 'opcache', 'plugin_caches' ];
    const CACHE_CLEAR_DEFAULT_SCOPE = [ 'object', 'builder', 'rewrite' ];

    /**
     * Page-builder cache registry — one row per builder, extended by adding a row.
     *
     * Each row is tried in this order, and every step is optional:
     *   guard     plugin file(s) / class / constant proving the builder is actually here. Nothing
     *             matches  →  the target reports `skipped`, never a failure: a site without Divi
     *             has not failed to clear Divi.
     *   callables the builder's OWN documented clear function, preferred over touching files. The
     *             builder knows what its cache contains and keeps working after its own API runs;
     *             a recursive unlink is us guessing.
     *   dirs      cache directories, relative to wp-content, unlinked only when no callable ran.
     *             The FALLBACK, not the primary — see clear_builder_caches().
     *   options   generated-CSS options that must go with the files, or the builder will believe
     *             it has already generated what we just deleted.
     *
     * Divi is the reason this exists: `wp-content/et-cache/` sits OUTSIDE uploads/, so
     * /files/search-replace can never rewrite the source domain out of it, and a migrated site
     * serves the old domain's asset URLs until each page is re-saved.
     */
    const CACHE_CLEAR_BUILDERS = [
        'divi' => [
            'guard'     => [ 'class' => [ 'ET_Core_PageResource' ], 'theme' => [ 'Divi', 'Extra' ], 'plugin' => [ 'divi-builder/divi-builder.php' ] ],
            'callables' => [ 'et_core_clear_wp_cache', 'et_fb_delete_builder_assets', 'et_core_page_resource_remove_static_resources' ],
            'dirs'      => [ 'et-cache' ],
            'options'   => [ 'et_divi_dynamic_css_cache', 'et_pb_clear_templates_cache' ],
        ],
        'elementor' => [
            'guard'     => [ 'class' => [ '\Elementor\Plugin' ], 'plugin' => [ 'elementor/elementor.php' ] ],
            'callables' => [],
            'dirs'      => [ 'uploads/elementor/css' ],
            'options'   => [ 'elementor_global_css', '_elementor_global_css' ],
        ],
        'beaver_builder' => [
            'guard'     => [ 'class' => [ 'FLBuilder' ], 'plugin' => [ 'beaver-builder-lite-version/fl-builder.php', 'bb-plugin/fl-builder.php' ] ],
            'callables' => [],
            'dirs'      => [ 'uploads/bb-plugin/cache' ],
            'options'   => [],
        ],
        'wpbakery' => [
            'guard'     => [ 'class' => [ 'Vc_Manager' ], 'plugin' => [ 'js_composer/js_composer.php' ] ],
            'callables' => [],
            'dirs'      => [ 'uploads/js_composer' ],
            'options'   => [],
        ],
        'bricks' => [
            'guard'     => [ 'theme' => [ 'Bricks' ], 'class' => [ '\Bricks\Theme' ] ],
            'callables' => [],
            'dirs'      => [ 'uploads/bricks/css' ],
            'options'   => [],
        ],
        'breakdance' => [
            'guard'     => [ 'plugin' => [ 'breakdance/plugin.php' ], 'constant' => [ '__BREAKDANCE_VERSION' ] ],
            'callables' => [],
            'dirs'      => [ 'uploads/breakdance/css' ],
            'options'   => [],
        ],
    ];

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        // Lets a request that carries this plugin's own credential past other plugins'
        // "REST API for logged-in users only" blocks, on this plugin's routes only.
        add_filter('rest_authentication_errors', [$this, 'rest_authentication_bypass'], 999);
        // Admin menu: network admin when network-activated on multisite, otherwise regular admin
        if ($this->is_network_active()) {
            add_action('network_admin_menu', [$this, 'add_admin_page']);
            add_action('network_admin_notices', [$this, 'admin_notice']);
        } else {
            add_action('admin_menu', [$this, 'add_admin_page']);
            add_action('admin_notices', [$this, 'admin_notice']);
        }
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        // Auto-create API key if missing (needed for mu-plugins which skip activation hook)
        add_action('init', [$this, 'ensure_api_key']);

        // Bridge mode: admin-post handlers + safety-net cron
        add_action('admin_post_instamigrate_bridge_start', [$this, 'bridge_handle_start']);
        add_action('admin_post_instamigrate_bridge_stop', [$this, 'bridge_handle_stop']);
        add_action('admin_post_instamigrate_bridge_discover', [$this, 'bridge_handle_discover_now']);
        add_action(INSTA_MIGRATE_BRIDGE_CRON_HOOK, [$this, 'bridge_safety_tick']);
        add_action(INSTA_MIGRATE_DISCOVER_CRON_HOOK, [$this, 'bridge_discover_cron']);
        add_filter('cron_schedules', [$this, 'bridge_cron_schedules']);
        // Auto-discover on InstaMigrate admin page view (rate-limited).
        add_action('admin_init', [$this, 'maybe_discover_on_admin']);
    }

    /**
     * Register a 'minute' cron schedule used by the bridge safety-net.
     */
    public function bridge_cron_schedules($schedules) {
        if (!isset($schedules['minute'])) {
            $schedules['minute'] = ['interval' => 60, 'display' => 'Every Minute'];
        }
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
        }
        return $schedules;
    }

    /**
     * Check if this plugin is network-activated on a multisite install.
     */
    private function is_network_active() {
        if (!is_multisite()) {
            return false;
        }
        // Check before plugins_loaded completes (activation hook context)
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active_for_network(plugin_basename(__FILE__));
    }

    /**
     * Get the API key — uses site_option on multisite (shared across network).
     */
    private function get_api_key() {
        if (is_multisite()) {
            return get_site_option(INSTA_MIGRATE_OPTION_KEY);
        }
        return get_option(INSTA_MIGRATE_OPTION_KEY);
    }

    /**
     * Set the API key — uses site_option on multisite.
     */
    private function set_api_key($key) {
        if (is_multisite()) {
            return update_site_option(INSTA_MIGRATE_OPTION_KEY, $key);
        }
        return update_option(INSTA_MIGRATE_OPTION_KEY, $key);
    }

    /**
     * Delete the API key.
     */
    private function delete_api_key() {
        if (is_multisite()) {
            return delete_site_option(INSTA_MIGRATE_OPTION_KEY);
        }
        return delete_option(INSTA_MIGRATE_OPTION_KEY);
    }

    public function ensure_api_key() {
        if (!$this->get_api_key()) {
            $this->set_api_key(wp_generate_password(48, false));
        }
    }

    /**
     * Initialize WP_Filesystem for file operations.
     *
     * @return WP_Filesystem_Base|false
     */
    /**
     * The wp-content directory, guarded.
     *
     * WP_CONTENT_DIR is authoritative — wp-content can be renamed or relocated, and a hand-built
     * ABSPATH.'/wp-content' would point at a directory that does not exist on those installs (see
     * kb/known-issues.md "Non-Standard WP_CONTENT_DIR"). But the constant is only defined once
     * WordPress has loaded its default constants, so fall back to the conventional path if it is
     * somehow absent rather than emitting an undefined-constant error.
     *
     * ABSPATH carries a trailing slash by WordPress convention, so it is trimmed before joining —
     * concatenating DIRECTORY_SEPARATOR directly would yield a doubled separator.
     */
    private function content_dir() {
        if (defined('WP_CONTENT_DIR') && WP_CONTENT_DIR) {
            return WP_CONTENT_DIR;
        }
        return rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR . 'wp-content';
    }

    private function init_filesystem() {
        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        return $wp_filesystem;
    }

    // ─── Activation / Deactivation ───────────────────────────────

    public function activate() {
        if (!$this->get_api_key()) {
            $this->set_api_key(wp_generate_password(48, false));
        }
        if (!file_exists(INSTA_MIGRATE_TEMP_DIR)) {
            wp_mkdir_p(INSTA_MIGRATE_TEMP_DIR);
        }
        // Write .htaccess to protect temp dir
        $wp_filesystem = $this->init_filesystem();
        if ($wp_filesystem) {
            $wp_filesystem->put_contents(INSTA_MIGRATE_TEMP_DIR . '/.htaccess', "Deny from all\n", FS_CHMOD_FILE);
        }
        // Schedule auto-discover so the plugin polls the tracker for any pending
        // bridge migration assigned to this site, without the owner doing anything.
        if (!wp_next_scheduled(INSTA_MIGRATE_DISCOVER_CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'five_minutes', INSTA_MIGRATE_DISCOVER_CRON_HOOK);
        }
    }

    public function deactivate() {
        // Clean up temp directory
        $this->rmdir_recursive(INSTA_MIGRATE_TEMP_DIR);
        $this->delete_api_key();
        wp_clear_scheduled_hook(INSTA_MIGRATE_DISCOVER_CRON_HOOK);
        wp_clear_scheduled_hook(INSTA_MIGRATE_BRIDGE_CRON_HOOK);
        delete_option('insta_migrate_last_discover');
    }

    // ─── Admin UI ────────────────────────────────────────────────

    public function admin_notice() {
        $screen = get_current_screen();
        if (!$screen) return;

        echo '<div class="notice notice-warning"><p>';
        echo '<strong>InstaMigrate</strong> plugin is active. ';
        echo 'This plugin exposes migration endpoints. <strong>Remove it after migration is complete.</strong>';
        echo '</p></div>';
    }

    public function add_admin_page() {
        if ($this->is_network_active()) {
            add_submenu_page(
                'settings.php',
                'InstaMigrate',
                'InstaMigrate',
                'manage_network',
                'insta-migrate',
                [$this, 'render_admin_page']
            );
        } else {
            add_options_page(
                'InstaMigrate',
                'InstaMigrate',
                'manage_options',
                'insta-migrate',
                [$this, 'render_admin_page']
            );
        }
    }

    public function render_admin_page() {
        $api_key = $this->get_api_key();
        $site_url = get_site_url();
        $rest_url = rest_url('insta-migrate/v1/');

        // Handle key regeneration
        if (isset($_POST['regenerate_key']) && check_admin_referer('insta_migrate_regen')) {
            $api_key = wp_generate_password(48, false);
            $this->set_api_key($api_key);
            echo '<div class="notice notice-success"><p>API key regenerated.</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>InstaMigrate</h1>
            <div class="card" style="max-width:700px;padding:20px;">
                <h2>API Configuration</h2>
                <table class="form-table">
                    <tr>
                        <th>REST API Base</th>
                        <td><code><?php echo esc_html($rest_url); ?></code></td>
                    </tr>
                    <tr>
                        <th>API Key</th>
                        <td>
                            <code style="background:#f0f0f0;padding:5px 10px;font-size:14px;user-select:all;"><?php echo esc_html($api_key); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th>Site URL</th>
                        <td><code><?php echo esc_html($site_url); ?></code></td>
                    </tr>
                    <tr>
                        <th>WP Content Dir</th>
                        <td><code><?php echo esc_html(WP_CONTENT_DIR); ?></code></td>
                    </tr>
                    <tr>
                        <th>ABSPATH</th>
                        <td><code><?php echo esc_html(ABSPATH); ?></code></td>
                    </tr>
                    <tr>
                        <th>PHP Max Upload</th>
                        <td><code><?php echo esc_html(ini_get('upload_max_filesize')); ?></code> (post_max_size: <?php echo esc_html(ini_get('post_max_size')); ?>)</td>
                    </tr>
                    <tr>
                        <th>PHP Memory Limit</th>
                        <td><code><?php echo esc_html(ini_get('memory_limit')); ?></code></td>
                    </tr>
                    <tr>
                        <th>Max Execution Time</th>
                        <td><code><?php echo esc_html(ini_get('max_execution_time')); ?>s</code></td>
                    </tr>
                </table>

                <form method="post">
                    <?php wp_nonce_field('insta_migrate_regen'); ?>
                    <p><input type="submit" name="regenerate_key" class="button" value="Regenerate API Key" /></p>
                </form>
            </div>

            <div class="card" style="max-width:700px;padding:20px;margin-top:20px;">
                <h2>Available Endpoints</h2>
                <ul style="list-style:disc;padding-left:20px;">
                    <li><code>GET  /status</code> — Health check + site info</li>
                    <li><code>GET  /disk-usage</code> — Site size (files + database) via Site Health</li>
                    <li><code>POST /disk-probe</code> — Write-probe for a per-account disk quota</li>
                    <li><code>GET  /db/export</code> — Export database as SQL (optional gzip)</li>
                    <li><code>POST /db/import</code> — Import SQL file (multipart upload)</li>
                    <li><code>POST /db/query</code> — Run a read-only SQL query</li>
                    <li><code>POST /db/fix-prefix</code> — Fix usermeta/options prefix keys after import</li>
                    <li><code>POST /files/archive</code> — Create tar.gz/zip of a directory</li>
                    <li><code>GET  /files/download</code> — Download a file from the server</li>
                    <li><code>POST /files/upload</code> — Upload a file to a path</li>
                    <li><code>POST /files/extract</code> — Extract tar.gz/zip archive</li>
                    <li><code>POST /files/mkdir</code> — Create a directory</li>
                    <li><code>GET  /files/list</code> — List a directory</li>
                    <li><code>POST /files/concat</code> — Concatenate chunked uploads</li>
                    <li><code>POST /files/search-replace</code> — Search-replace inside static files (uploads only)</li>
                    <li><code>POST /files/fetch-probe</code> — Check this site can reach a source URL</li>
                    <li><code>POST /search-replace</code> — Domain search-replace in DB</li>
                    <li><code>POST /cache/clear</code> — Clear object, page-builder, rewrite and opcode caches</li>
                    <li><code>POST /plugins/install-activate-plugin</code> — Install &amp; activate a plugin</li>
                    <li><code>POST /plugins/deactivate-plugin</code> — Deactivate plugins</li>
                    <li><code>POST /plugins/delete-plugin</code> — Deactivate + delete plugins</li>
                    <li><code>POST /theme/activate</code> — Activate a bundled theme</li>
                    <li><code>POST /core/update</code> — Raise WordPress core to a given version</li>
                    <li><code>POST /migration-details</code> — Record the migration on this site</li>
                    <li><code>POST /cleanup</code> — Remove temp files</li>
                    <li><code>GET  /api-key</code> — Return this key (Application Password auth only)</li>
                </ul>
                <p><em>All endpoints require header: <code>X-Insta-Key: YOUR_API_KEY</code>, or <code>?api_key=YOUR_API_KEY</code> as a fallback where a CDN strips custom headers. <code>/api-key</code> is the exception &mdash; it requires WordPress Application Password authentication.</em></p>
            </div>

            <?php $this->render_bridge_card(); ?>

            <div class="card" style="max-width:700px;padding:20px;margin-top:20px;border-left:4px solid #dc3232;">
                <h2>&#9888; Security Warning</h2>
                <p>This plugin grants full database and filesystem access via its API. <strong>Deactivate and delete this plugin immediately after your migration is complete.</strong></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Bridge Mode card — outbound-driven migration for sites blocked by
     * Cloudflare or other inbound filters. When activated, the plugin polls a tracker
     * for commands and executes them locally.
     */
    private function render_bridge_card() {
        $cfg   = $this->bridge_load_config();
        $state = $this->bridge_load_state();
        $active = !empty($cfg['active']);
        $tracker_url   = (isset($cfg['tracker_url']) ? $cfg['tracker_url'] : '');
        $migration_id  = (isset($cfg['migration_id']) ? $cfg['migration_id'] : '');
        $origin_ip     = (isset($cfg['origin_ip']) ? $cfg['origin_ip'] : ((isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '')));
        $last_tick     = (isset($state['last_tick_at']) ? $state['last_tick_at'] : null);
        $last_action   = (isset($state['last_action']) ? $state['last_action'] : '');
        $last_error    = (isset($state['last_error']) ? $state['last_error'] : '');
        $tick_count    = (int) ((isset($state['tick_count']) ? $state['tick_count'] : 0));

        $start_url    = admin_url('admin-post.php?action=instamigrate_bridge_start');
        $stop_url     = admin_url('admin-post.php?action=instamigrate_bridge_stop');
        $discover_url = admin_url('admin-post.php?action=instamigrate_bridge_discover');
        $last_disc    = get_option('insta_migrate_last_discover', null);
        ?>
        <div class="card" style="max-width:700px;padding:20px;margin-top:20px;border-left:4px solid <?php echo $active ? '#46b450' : '#ccc'; ?>;">
            <h2>Bridge Mode <?php if ($active) echo '<span style="color:#46b450;font-size:14px;">&#9679; ACTIVE</span>'; ?></h2>
            <p><em>Outbound-driven migration. The plugin polls a tracker server for commands instead of accepting inbound API calls. Use this when the site is behind Cloudflare or another inbound filter that blocks the migration agent.</em></p>

            <?php if ($active): ?>
                <table class="form-table">
                    <tr><th>Tracker</th><td><code><?php echo esc_html($tracker_url); ?></code></td></tr>
                    <tr><th>Migration ID</th><td><code><?php echo esc_html($migration_id); ?></code></td></tr>
                    <tr><th>Origin IP</th><td><code><?php echo esc_html($origin_ip); ?></code></td></tr>
                    <tr><th>Ticks executed</th><td><?php echo $tick_count; ?></td></tr>
                    <tr><th>Last tick</th><td><?php echo $last_tick ? esc_html(human_time_diff($last_tick) . ' ago (' . gmdate('Y-m-d H:i:s', $last_tick) . ' UTC)') : 'never'; ?></td></tr>
                    <tr><th>Last action</th><td><code><?php echo esc_html($last_action ?: '—'); ?></code></td></tr>
                    <?php if ($last_error): ?>
                        <tr><th>Last error</th><td style="color:#dc3232;"><code><?php echo esc_html($last_error); ?></code></td></tr>
                    <?php endif; ?>
                </table>
                <form method="post" action="<?php echo esc_url($stop_url); ?>">
                    <?php wp_nonce_field('insta_migrate_bridge_stop'); ?>
                    <p><input type="submit" class="button" value="Stop Bridge" /></p>
                </form>
            <?php else: ?>
                <div style="background:#f4f9fc;border:1px solid #c3d9e6;padding:12px 16px;margin-bottom:16px;border-radius:4px;">
                    <strong>Auto-discover</strong> &mdash; this plugin checks the tracker every 5 minutes (and on every visit to this page) to see if a bridge migration has been assigned to this site. If one has, it'll start automatically; you don't need to fill in the form below.
                    <?php
                    if (is_array($last_disc) && !empty($last_disc['at'])) {
                        $when   = (int) $last_disc['at'];
                        $status = (string) ((isset($last_disc['status']) ? $last_disc['status'] : 'unknown'));
                        $human  = human_time_diff($when) . ' ago';
                        echo '<div style="margin-top:8px;font-size:13px;color:#444;">Last check: <code>' . esc_html($status) . '</code> &mdash; ' . esc_html($human) . '</div>';
                    } else {
                        echo '<div style="margin-top:8px;font-size:13px;color:#666;">Not checked yet.</div>';
                    }
                    ?>
                    <form method="post" action="<?php echo esc_url($discover_url); ?>" style="margin-top:10px;">
                        <?php wp_nonce_field('insta_migrate_bridge_discover'); ?>
                        <input type="submit" class="button" value="Check now" />
                    </form>
                </div>
                <details style="margin-bottom:16px;">
                    <summary style="cursor:pointer;color:#555;">Or start manually (advanced)</summary>
                <form method="post" action="<?php echo esc_url($start_url); ?>">
                    <?php wp_nonce_field('insta_migrate_bridge_start'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="tracker_url">Tracker URL</label></th>
                            <td><input type="url" name="tracker_url" id="tracker_url" class="regular-text" required
                                placeholder="https://migrate.instawp.io"
                                value="<?php echo esc_attr($tracker_url); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="migration_id">Migration ID</label></th>
                            <td><input type="text" name="migration_id" id="migration_id" class="regular-text" required
                                value="<?php echo esc_attr($migration_id); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="bridge_secret">Shared Secret</label></th>
                            <td><input type="text" name="bridge_secret" id="bridge_secret" class="regular-text" required />
                                <p class="description">Shared with the tracker. Used to authenticate outbound and inbound bridge traffic.</p></td>
                        </tr>
                        <tr>
                            <th><label for="origin_ip">Origin IP (optional)</label></th>
                            <td><input type="text" name="origin_ip" id="origin_ip" class="regular-text"
                                placeholder="<?php echo esc_attr((isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '')); ?>" />
                                <p class="description">Leave blank to auto-detect from <code>SERVER_ADDR</code>. The plugin uses this IP to bypass Cloudflare when triggering itself.</p></td>
                        </tr>
                    </table>
                    <p><input type="submit" class="button button-primary" value="Start Bridge" /></p>
                </form>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─── Bridge Mode ─────────────────────────────────────────────

    /**
     * Config file layout (JSON on disk, outside the DB so it survives import):
     *   {
     *     "active":       bool,
     *     "tracker_url":  "https://migrate.instawp.io",
     *     "migration_id": "uuid",
     *     "secret":       "shared-secret",
     *     "origin_ip":    "1.2.3.4",
     *     "started_at":   unix timestamp
     *   }
     */
    private function bridge_load_config() {
        if (!file_exists(INSTA_MIGRATE_BRIDGE_CONFIG_FILE)) {
            return null;
        }
        $raw = @file_get_contents(INSTA_MIGRATE_BRIDGE_CONFIG_FILE);
        $cfg = $raw ? json_decode($raw, true) : null;
        return is_array($cfg) ? $cfg : null;
    }

    private function bridge_save_config($cfg) {
        $dir = dirname(INSTA_MIGRATE_BRIDGE_CONFIG_FILE);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        $ok = @file_put_contents(INSTA_MIGRATE_BRIDGE_CONFIG_FILE, wp_json_encode($cfg, JSON_PRETTY_PRINT));
        if ($ok !== false) {
            @chmod(INSTA_MIGRATE_BRIDGE_CONFIG_FILE, 0600);
        }
        return $ok !== false;
    }

    private function bridge_delete_config() {
        if (file_exists(INSTA_MIGRATE_BRIDGE_CONFIG_FILE)) {
            @unlink(INSTA_MIGRATE_BRIDGE_CONFIG_FILE);
        }
        if (file_exists(INSTA_MIGRATE_BRIDGE_STATE_FILE)) {
            @unlink(INSTA_MIGRATE_BRIDGE_STATE_FILE);
        }
    }

    /**
     * State file — updated on every tick. Tracks progress and last error.
     */
    private function bridge_load_state() {
        if (!file_exists(INSTA_MIGRATE_BRIDGE_STATE_FILE)) {
            return [];
        }
        $raw = @file_get_contents(INSTA_MIGRATE_BRIDGE_STATE_FILE);
        $st = $raw ? json_decode($raw, true) : null;
        return is_array($st) ? $st : [];
    }

    private function bridge_save_state($state) {
        @file_put_contents(INSTA_MIGRATE_BRIDGE_STATE_FILE, wp_json_encode($state, JSON_PRETTY_PRINT));
        @chmod(INSTA_MIGRATE_BRIDGE_STATE_FILE, 0600);
    }

    /**
     * Admin-post handler: start the bridge.
     * Stores config, captures origin IP, fires the first tick.
     */
    public function bridge_handle_start() {
        if (!current_user_can(is_multisite() ? 'manage_network' : 'manage_options')) {
            wp_die('Insufficient permissions.', 403);
        }
        check_admin_referer('insta_migrate_bridge_start');

        $tracker_url  = esc_url_raw(wp_unslash((isset($_POST['tracker_url']) ? $_POST['tracker_url'] : '')));
        $migration_id = sanitize_text_field(wp_unslash((isset($_POST['migration_id']) ? $_POST['migration_id'] : '')));
        $secret       = sanitize_text_field(wp_unslash((isset($_POST['bridge_secret']) ? $_POST['bridge_secret'] : '')));
        $origin_ip    = sanitize_text_field(wp_unslash((isset($_POST['origin_ip']) ? $_POST['origin_ip'] : '')));

        if (!$tracker_url || !$migration_id || !$secret) {
            wp_die('tracker_url, migration_id, and bridge_secret are required.', 400);
        }

        // Auto-detect origin IP if not provided.
        if (!$origin_ip) {
            $origin_ip = (isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '');
            if (!$origin_ip) {
                $origin_ip = @gethostbyname(gethostname()) ?: '';
            }
        }
        if (!filter_var($origin_ip, FILTER_VALIDATE_IP)) {
            wp_die('Could not determine origin IP. Provide it manually.', 400);
        }

        $cfg = [
            'active'       => true,
            'tracker_url'  => rtrim($tracker_url, '/'),
            'migration_id' => $migration_id,
            'secret'       => $secret,
            'origin_ip'    => $origin_ip,
            'started_at'   => time(),
        ];
        $this->bridge_save_config($cfg);
        $this->bridge_save_state([
            'last_tick_at' => null,
            'last_action'  => '',
            'last_error'   => '',
            'tick_count'   => 0,
            'started_at'   => time(),
        ]);

        // Register safety-net cron (runs on the next admin pageview if chain dies).
        if (!wp_next_scheduled(INSTA_MIGRATE_BRIDGE_CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'minute', INSTA_MIGRATE_BRIDGE_CRON_HOOK);
        }

        // Fire the first tick.
        $this->bridge_self_trigger();

        wp_safe_redirect(add_query_arg('bridge', 'started', wp_get_referer() ?: admin_url()));
        exit;
    }

    /**
     * Admin-post handler: stop the bridge.
     */
    public function bridge_handle_stop() {
        if (!current_user_can(is_multisite() ? 'manage_network' : 'manage_options')) {
            wp_die('Insufficient permissions.', 403);
        }
        check_admin_referer('insta_migrate_bridge_stop');

        $cfg = $this->bridge_load_config();
        if ($cfg) {
            $cfg['active'] = false;
            $this->bridge_save_config($cfg);
        }
        wp_clear_scheduled_hook(INSTA_MIGRATE_BRIDGE_CRON_HOOK);

        wp_safe_redirect(add_query_arg('bridge', 'stopped', wp_get_referer() ?: admin_url()));
        exit;
    }

    // ─── Bridge auto-discover ────────────────────────────────────
    //
    // Lets the plugin pick up its own bridge config from the tracker without the
    // owner having to copy/paste tracker URL + migration ID + secret. The plugin
    // POSTs {site_url, api_key} to a pinned discover URL; if a pending bridge_source
    // migration matches the site (by api_key match in the tracker DB), the response
    // returns {migration_id, secret} and the plugin auto-starts the bridge.
    //
    // Security model:
    // - Discover URL is pinned via INSTA_MIGRATE_DISCOVER_URL (no override accepted
    //   from the response body) and is HTTPS with cert verification on.
    // - Auth is the plugin's own API key — same credential that already grants full
    //   plugin access, so this doesn't widen the threat surface.
    // - Plugin verifies the response site_url (if present) matches its own home_url
    //   to defend against cross-site config replay.
    // - 60s rate limit on outbound calls (transient) — manual button bypasses.

    /**
     * Try to discover and auto-start a pending bridge migration for this site.
     * No-op if the bridge is already running. Returns a status array (also written
     * to the 'insta_migrate_last_discover' option for admin display).
     *
     * @param string $source Where the call originated (admin_view, cron, manual).
     * @param bool   $force  Bypass the 60s rate limit (manual button only).
     */
    private function bridge_try_discover($source = 'unknown', $force = false) {
        $cfg = $this->bridge_load_config();
        if ($cfg && !empty($cfg['active'])) {
            return ['status' => 'already_active', 'at' => time()];
        }

        $api_key = $this->get_api_key();
        if (!$api_key) {
            return ['status' => 'no_api_key', 'at' => time()];
        }

        if (!$force) {
            $last = (int) get_transient('instamigrate_discover_last');
            if ($last && (time() - $last) < 60) {
                return ['status' => 'rate_limited', 'at' => time(), 'source' => $source];
            }
        }
        set_transient('instamigrate_discover_last', time(), 120);

        $home_url = home_url();
        $body = wp_json_encode([
            'site_url'       => $home_url,
            'api_key'        => $api_key,
            'plugin_version' => INSTA_MIGRATE_VERSION,
        ]);

        $resp = wp_remote_post(INSTA_MIGRATE_DISCOVER_URL, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent'   => 'InstaMigrate-Discover/' . INSTA_MIGRATE_VERSION,
            ],
            'body'      => $body,
            'sslverify' => true, // Pinned tracker host — real cert verification.
        ]);

        $log = ['status' => 'unknown', 'at' => time(), 'source' => $source];

        if (is_wp_error($resp)) {
            $log['status'] = 'request_error';
            $log['error']  = $resp->get_error_message();
            update_option('insta_migrate_last_discover', $log, false);
            return $log;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);

        if ($code === 404) {
            $log['status'] = 'no_pending_migration';
            update_option('insta_migrate_last_discover', $log, false);
            return $log;
        }
        if ($code !== 200) {
            $log['status'] = 'http_error';
            $log['http']   = $code;
            update_option('insta_migrate_last_discover', $log, false);
            return $log;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['migration_id']) || empty($data['secret'])) {
            $log['status'] = 'bad_response';
            update_option('insta_migrate_last_discover', $log, false);
            return $log;
        }

        // Defence in depth: if the response echoes a site_url, it must match ours.
        if (!empty($data['site_url'])
            && rtrim((string) $data['site_url'], '/') !== rtrim($home_url, '/')) {
            $log['status'] = 'site_mismatch';
            update_option('insta_migrate_last_discover', $log, false);
            return $log;
        }

        // Tracker URL is derived from the pinned discover URL — never from response body.
        $tracker_url = rtrim(preg_replace('#/api/bridge/discover.*$#', '', INSTA_MIGRATE_DISCOVER_URL), '/');

        // Auto-detect origin IP, same as bridge_handle_start.
        $origin_ip = (isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '');
        if (!$origin_ip) {
            $origin_ip = @gethostbyname(gethostname()) ?: '';
        }
        if (!filter_var($origin_ip, FILTER_VALIDATE_IP)) {
            $log['status'] = 'no_origin_ip';
            update_option('insta_migrate_last_discover', $log, false);
            return $log;
        }

        $bridge_cfg = [
            'active'       => true,
            'tracker_url'  => $tracker_url,
            'migration_id' => sanitize_text_field((string) $data['migration_id']),
            'secret'       => sanitize_text_field((string) $data['secret']),
            'origin_ip'    => $origin_ip,
            'started_at'   => time(),
            'auto_started' => true,
            'started_via'  => $source,
        ];
        $this->bridge_save_config($bridge_cfg);
        $this->bridge_save_state([
            'last_tick_at' => null,
            'last_action'  => '',
            'last_error'   => '',
            'tick_count'   => 0,
            'started_at'   => time(),
        ]);
        if (!wp_next_scheduled(INSTA_MIGRATE_BRIDGE_CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'minute', INSTA_MIGRATE_BRIDGE_CRON_HOOK);
        }
        $this->bridge_self_trigger();

        $log['status']       = 'started';
        $log['migration_id'] = $bridge_cfg['migration_id'];
        update_option('insta_migrate_last_discover', $log, false);
        return $log;
    }

    /**
     * Cron handler — runs every 5 minutes (registered on activation) so the plugin
     * can pick up a newly-assigned migration without anyone visiting wp-admin.
     */
    public function bridge_discover_cron() {
        $this->bridge_try_discover('cron');
    }

    /**
     * Fire discover when an admin loads the InstaMigrate admin page. Rate-limited
     * to once per 60s so reloading the page doesn't hammer the tracker.
     */
    public function maybe_discover_on_admin() {
        if (!current_user_can(is_multisite() ? 'manage_network' : 'manage_options')) return;
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== 'insta-migrate') return;
        $this->bridge_try_discover('admin_view');
    }

    /**
     * Manual "Check for pending migration" button — bypasses the rate limit.
     */
    public function bridge_handle_discover_now() {
        if (!current_user_can(is_multisite() ? 'manage_network' : 'manage_options')) {
            wp_die('Insufficient permissions.', 403);
        }
        check_admin_referer('insta_migrate_bridge_discover');
        $this->bridge_try_discover('manual', /*force=*/ true);
        wp_safe_redirect(add_query_arg('discover', 'done', wp_get_referer() ?: admin_url()));
        exit;
    }

    /**
     * Safety-net cron: re-fires the tick chain if the last tick is more than 90s old.
     * Fires on any admin pageview (WP-Cron is triggered by normal traffic) — designed
     * to recover from a chain that died mid-execution without an owner noticing.
     */
    public function bridge_safety_tick() {
        $cfg = $this->bridge_load_config();
        if (!$cfg || empty($cfg['active'])) {
            wp_clear_scheduled_hook(INSTA_MIGRATE_BRIDGE_CRON_HOOK);
            return;
        }
        $state = $this->bridge_load_state();
        $last = (isset($state['last_tick_at']) ? $state['last_tick_at'] : 0);
        if (time() - $last < 90) {
            return; // Chain is healthy, no intervention needed.
        }
        $this->bridge_self_trigger();
    }

    /**
     * Fire a non-blocking self-trigger tick. Uses CURLOPT_RESOLVE to hit the local
     * origin IP directly, bypassing any DNS-level filters (Cloudflare, etc.).
     */
    private function bridge_self_trigger() {
        $cfg = $this->bridge_load_config();
        if (!$cfg || empty($cfg['active'])) return;

        // Resolve the site URL to the origin IP, not the DNS-advertised (CF) IP.
        $tick_url = trailingslashit(home_url()) . 'wp-json/insta-migrate/v1/bridge/tick';
        $parts = wp_parse_url($tick_url);
        $host = (isset($parts['host']) ? $parts['host'] : '');
        $port = isset($parts['port']) ? (int) $parts['port']
              : ((((isset($parts['scheme']) ? $parts['scheme'] : 'http')) === 'https') ? 443 : 80);
        if (!$host) return;

        $ch = curl_init($tick_url);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => wp_json_encode(['secret' => $cfg['secret']]),
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_RESOLVE         => ["{$host}:{$port}:{$cfg['origin_ip']}"],
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => 5,     // Fire-and-forget: server does fastcgi_finish_request and returns fast.
                                              // Budget must cover TCP connect + TLS handshake + tiny POST, which can
                                              // exceed 500ms on slow hosts. If curl aborts early the next tick never
                                              // fires and the chain stalls.
            CURLOPT_NOSIGNAL        => 1,
            CURLOPT_SSL_VERIFYPEER  => false, // Origin cert may not validate (CF Flexible/self-signed).
            CURLOPT_SSL_VERIFYHOST  => 0,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FORBID_REUSE    => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * REST route: POST /bridge/tick — called by the plugin itself (via self-trigger)
     * or by the safety-net cron. Executes one command from the tracker queue.
     *
     * Auth: bridge secret in JSON body or X-Bridge-Secret header.
     */
    public function route_bridge_tick(WP_REST_Request $request) {
        ignore_user_abort(true);
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $cfg = $this->bridge_load_config();
        if (!$cfg || empty($cfg['active'])) {
            return new WP_Error('bridge_inactive', 'Bridge is not active.', ['status' => 409]);
        }
        if (!$this->bridge_secret_matches($request, $cfg)) {
            return new WP_Error('unauthorized', 'Invalid bridge secret.', ['status' => 401]);
        }

        // Acknowledge immediately and detach so the rest runs in the background.
        $this->bridge_send_and_detach(['ok' => true, 'tick_received' => true]);

        try {
            $this->bridge_do_tick($cfg);
        } catch (Exception $e) {
            $st = $this->bridge_load_state();
            $st['last_error'] = 'tick_exception: ' . $e->getMessage();
            $this->bridge_save_state($st);
            $this->bridge_self_trigger();
        }
        exit;
    }

    /**
     * Whether the request carries the active bridge's secret, in the JSON body or the
     * X-Bridge-Secret header. Shared by route_bridge_tick() and the REST authentication gate.
     *
     * @param WP_REST_Request $request
     * @param array           $cfg     Bridge config from bridge_load_config().
     * @return bool
     */
    private function bridge_secret_matches(WP_REST_Request $request, $cfg) {
        $body   = $request->get_json_params() ?: [];
        $secret = (isset($body['secret']) ? $body['secret'] : $request->get_header('x-bridge-secret'));
        $stored = isset($cfg['secret']) ? $cfg['secret'] : '';

        return is_string($secret) && $secret !== '' && is_string($stored) && $stored !== ''
            && hash_equals($stored, $secret);
    }

    /**
     * Flush a JSON response and detach from the originating HTTP request so
     * the rest of the tick runs in the background.
     */
    private function bridge_send_and_detach($payload) {
        $json = wp_json_encode($payload);
        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: application/json');
            header('Connection: close');
            header('Content-Length: ' . strlen($json));
        }
        echo $json;
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    /**
     * Execute one tick: poll the tracker, run the command, report the result,
     * then fire the next tick via self-trigger.
     */
    private function bridge_do_tick($cfg) {
        $state = $this->bridge_load_state();
        $state['last_tick_at'] = time();
        $state['tick_count']   = (int) ((isset($state['tick_count']) ? $state['tick_count'] : 0)) + 1;
        $state['last_error']   = '';

        $cmd = $this->bridge_poll($cfg);
        if ($cmd === null) {
            $state['last_error']  = 'poll_failed';
            $state['last_action'] = 'poll_failed';
            $this->bridge_save_state($state);
            sleep(10);
            $this->bridge_self_trigger();
            return;
        }

        $action = (string) ((isset($cmd['action']) ? $cmd['action'] : ''));
        $params = (isset($cmd['params']) ? $cmd['params'] : []);
        $cmd_id = (isset($cmd['id']) ? $cmd['id'] : null);
        $state['last_action'] = $action;
        $this->bridge_save_state($state);

        if ($action === '' || $action === 'idle' || $action === 'sleep') {
            $sleep_for = (int) ((isset($params['seconds']) ? $params['seconds'] : 5));
            $sleep_for = max(1, min(30, $sleep_for));
            sleep($sleep_for);
            $this->bridge_self_trigger();
            return;
        }

        if ($action === 'stop') {
            $cfg['active'] = false;
            $this->bridge_save_config($cfg);
            wp_clear_scheduled_hook(INSTA_MIGRATE_BRIDGE_CRON_HOOK);
            $this->bridge_report($cfg, $cmd_id, ['ok' => true, 'stopped' => true]);
            return;
        }

        $result = $this->bridge_dispatch($action, $params);

        if (!empty($result['error'])) {
            $state['last_error'] = substr((string) $result['error'], 0, 255);
            $this->bridge_save_state($state);
        }

        $this->bridge_report($cfg, $cmd_id, $result);
        $this->bridge_self_trigger();
    }

    /**
     * Poll the tracker for the next command. Returns decoded JSON or null on error.
     * Expected shape: {"id":"...","action":"...","params":{...}}  or  {"action":"idle"}
     */
    private function bridge_poll($cfg) {
        $url = $cfg['tracker_url'] . '/api/bridge/' . rawurlencode($cfg['migration_id']) . '/poll';
        $resp = wp_remote_post($url, [
            'timeout'     => 30,
            'redirection' => 2,
            'headers'     => [
                'Content-Type'    => 'application/json',
                'X-Bridge-Secret' => $cfg['secret'],
                'User-Agent'      => 'InstaMigrate-Bridge/' . INSTA_MIGRATE_VERSION,
            ],
            'body'        => wp_json_encode([
                'secret'       => $cfg['secret'],
                'migration_id' => $cfg['migration_id'],
                'state'        => $this->bridge_load_state(),
            ]),
        ]);
        if (is_wp_error($resp)) {
            return null;
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        return is_array($json) ? $json : null;
    }

    private function bridge_report($cfg, $cmd_id, $result) {
        $url = $cfg['tracker_url'] . '/api/bridge/' . rawurlencode($cfg['migration_id']) . '/result';
        wp_remote_post($url, [
            'timeout'     => 60,
            'redirection' => 2,
            'headers'     => [
                'Content-Type'    => 'application/json',
                'X-Bridge-Secret' => $cfg['secret'],
                'User-Agent'      => 'InstaMigrate-Bridge/' . INSTA_MIGRATE_VERSION,
            ],
            'body'        => wp_json_encode([
                'secret'     => $cfg['secret'],
                'command_id' => $cmd_id,
                'result'     => $result,
            ]),
        ]);
    }

    /**
     * Dispatch a bridge action to the corresponding handler. Returns a
     * serializable result array. Any exception is captured as {"error": "..."}.
     */
    private function bridge_dispatch($action, $params) {
        try {
            switch ($action) {
                case 'ping':
                    return $this->bridge_act_ping($params);
                case 'download_file':
                    return $this->bridge_act_download_file($params);
                case 'push_file':
                    return $this->bridge_act_push_file($params);
                case 'extract_archive':
                    return $this->bridge_act_extract_archive($params);
                case 'import_db':
                    return $this->bridge_act_import_db($params);
                case 'db_query_read':
                    return $this->bridge_act_db_query($params, false);
                case 'db_query_write':
                    return $this->bridge_act_db_query($params, true);
                case 'db_fix_prefix':
                    return $this->bridge_act_db_fix_prefix($params);
                case 'search_replace':
                    return $this->bridge_act_search_replace($params);
                case 'files_search_replace':
                    return $this->bridge_act_files_search_replace($params);
                case 'files_mkdir':
                    return $this->bridge_act_files_mkdir($params);
                case 'files_archive':
                    return $this->bridge_act_files_archive($params);
                case 'write_file':
                    return $this->bridge_act_write_file($params);
                case 'delete_path':
                    return $this->bridge_act_delete_path($params);
                case 'cleanup':
                    $this->rmdir_recursive(INSTA_MIGRATE_TEMP_DIR);
                    wp_mkdir_p(INSTA_MIGRATE_TEMP_DIR);
                    return ['ok' => true];
                default:
                    return ['error' => "unknown_action: $action"];
            }
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ─── Bridge actions ──────────────────────────────────────────

    private function bridge_act_ping($params) {
        $req = new WP_REST_Request('GET', '/insta-migrate/v1/status');
        return $this->unwrap($this->route_status($req));
    }

    /**
     * Download a URL to a local path. Supports SHA-256 verification.
     * params: {url, dest, expected_sha256?, headers?}
     */
    private function bridge_act_download_file($params) {
        $url  = (isset($params['url']) ? $params['url'] : '');
        $dest = (isset($params['dest']) ? $params['dest'] : '');
        if (!$url || !$dest) {
            return ['error' => 'url and dest are required'];
        }
        $parent = dirname($dest);
        if (!file_exists($parent)) {
            wp_mkdir_p($parent);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming write of large downloaded files.
        $fp = fopen($dest, 'wb');
        if (!$fp) {
            return ['error' => "cannot_open_dest: $dest"];
        }
        $ch = curl_init($url);
        $headers = ['User-Agent: InstaMigrate-Bridge/' . INSTA_MIGRATE_VERSION];
        foreach ((array) ((isset($params['headers']) ? $params['headers'] : [])) as $k => $v) {
            $headers[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FAILONERROR    => false,
        ]);
        $ok   = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $http < 200 || $http >= 300) {
            @unlink($dest);
            return ['error' => "download_failed: http=$http curl=$err"];
        }
        $size   = filesize($dest);
        $result = ['ok' => true, 'dest' => $dest, 'size' => $size, 'http' => $http];

        if (!empty($params['expected_sha256'])) {
            $actual = hash_file('sha256', $dest);
            $result['sha256'] = $actual;
            if (!hash_equals(strtolower($params['expected_sha256']), strtolower($actual))) {
                @unlink($dest);
                return ['error' => 'sha256_mismatch', 'expected' => $params['expected_sha256'], 'actual' => $actual];
            }
        }
        return $result;
    }

    /**
     * Push a local file to a remote URL via multipart POST. Used to invert the
     * bridge for source-side use: source plugin uploads its export directly to
     * the destination plugin's /files/upload or /db/import endpoint, with no
     * inbound traffic to the source needed.
     *
     * params: {
     *   src:     string  local file path to upload (required)
     *   url:     string  target URL (required)
     *   field:   string  multipart field name for the file (default 'file';
     *                    use 'sql_file' for /db/import)
     *   form:    array   additional form fields (e.g. {path: '/dest/path'})
     *   headers: array   additional request headers (e.g. {X-Insta-Key: ...})
     *   timeout: int     curl timeout seconds (default 600)
     * }
     */
    private function bridge_act_push_file($params) {
        $src        = (isset($params['src']) ? $params['src'] : '');
        $url        = (isset($params['url']) ? $params['url'] : '');
        $field      = (isset($params['field']) ? $params['field'] : 'file');
        $form       = (array) ((isset($params['form']) ? $params['form'] : []));
        $headers_in = (array) ((isset($params['headers']) ? $params['headers'] : []));
        $timeout    = (int)   ((isset($params['timeout']) ? $params['timeout'] : 600));

        if (!$src || !$url) {
            return ['error' => 'src and url are required'];
        }
        if (!file_exists($src)) {
            return ['error' => "src_not_found: $src"];
        }
        if (!class_exists('CURLFile')) {
            return ['error' => 'CURLFile not available (PHP 5.5+ required)'];
        }

        $size = filesize($src);

        $post = $form;
        $post[$field] = new CURLFile($src);

        $headers = ['User-Agent: InstaMigrate-Bridge/' . INSTA_MIGRATE_VERSION];
        foreach ($headers_in as $k => $v) {
            $headers[] = "$k: $v";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FAILONERROR    => false,
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $http < 200 || $http >= 300) {
            return [
                'error' => "push_failed: http=$http curl=$err",
                'http'  => $http,
                'body'  => is_string($body) ? substr($body, 0, 500) : null,
            ];
        }

        $decoded = json_decode((string) $body, true);
        return [
            'ok'       => true,
            'url'      => $url,
            'src'      => $src,
            'size'     => $size,
            'http'     => $http,
            'response' => $decoded !== null ? $decoded : substr((string) $body, 0, 500),
        ];
    }

    private function bridge_act_extract_archive($params) {
        $req = new WP_REST_Request('POST', '/insta-migrate/v1/files/extract');
        $req->set_body_params([
            'archive_path' => (isset($params['archive_path']) ? $params['archive_path'] : ''),
            'dest_dir'     => (isset($params['dest_dir']) ? $params['dest_dir'] : ''),
        ]);
        return $this->unwrap($this->route_files_extract($req));
    }

    private function bridge_act_import_db($params) {
        $req = new WP_REST_Request('POST', '/insta-migrate/v1/db/import');
        $req->set_param('file_path', (isset($params['file_path']) ? $params['file_path'] : ''));
        return $this->unwrap($this->route_db_import($req));
    }

    /**
     * Execute a SQL query. Write mode allows arbitrary SQL — used for plugin
     * activation injection, multisite domain fixes, etc.
     */
    private function bridge_act_db_query($params, $allow_write) {
        global $wpdb;
        $query = (isset($params['query']) ? $params['query'] : '');
        if (!$query) return ['error' => 'query is required'];

        if (!$allow_write) {
            $upper   = strtoupper(ltrim($query));
            $is_read = strpos($upper, 'SELECT') === 0 || strpos($upper, 'SHOW') === 0 || strpos($upper, 'DESCRIBE') === 0;
            if (!$is_read) return ['error' => 'write_not_allowed'];
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Migration diagnostics.
            $rows = $wpdb->get_results($query, ARRAY_A);
            return ['ok' => true, 'rows' => $rows, 'count' => is_array($rows) ? count($rows) : 0];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Authenticated bridge write query.
        $affected = $wpdb->query($query);
        return [
            'ok'        => $affected !== false,
            'affected'  => $affected === false ? null : (int) $affected,
            'error'     => $wpdb->last_error ?: null,
            'insert_id' => $wpdb->insert_id ?: null,
        ];
    }

    private function bridge_act_db_fix_prefix($params) {
        $req = new WP_REST_Request('POST', '/insta-migrate/v1/db/fix-prefix');
        $req->set_body_params([
            'old_prefix' => (isset($params['old_prefix']) ? $params['old_prefix'] : ''),
            'new_prefix' => (isset($params['new_prefix']) ? $params['new_prefix'] : ''),
        ]);
        return $this->unwrap($this->route_db_fix_prefix($req));
    }

    private function bridge_act_search_replace($params) {
        $req = new WP_REST_Request('POST', '/insta-migrate/v1/search-replace');
        $req->set_body_params([
            'search'  => (isset($params['search']) ? $params['search'] : ''),
            'replace' => (isset($params['replace']) ? $params['replace'] : ''),
            'dry_run' => !empty($params['dry_run']),
            'tables'  => (isset($params['tables']) ? $params['tables'] : null),
        ]);
        return $this->unwrap($this->route_search_replace($req));
    }

    private function bridge_act_files_search_replace($params) {
        $req = new WP_REST_Request('POST', '/insta-migrate/v1/files/search-replace');
        $req->set_body_params([
            'directory'  => (isset($params['directory']) ? $params['directory'] : ''),
            'search'     => (isset($params['search']) ? $params['search'] : ''),
            'replace'    => (isset($params['replace']) ? $params['replace'] : ''),
            'extensions' => (isset($params['extensions']) ? $params['extensions'] : ['css', 'js', 'json']),
            'dry_run'    => !empty($params['dry_run']),
        ]);
        return $this->unwrap($this->route_files_search_replace($req));
    }

    private function bridge_act_files_mkdir($params) {
        $req = new WP_REST_Request('POST', '/insta-migrate/v1/files/mkdir');
        $req->set_body_params(['path' => (isset($params['path']) ? $params['path'] : '')]);
        return $this->unwrap($this->route_files_mkdir($req));
    }

    private function bridge_act_files_archive($params) {
        $req = new WP_REST_Request('POST', '/insta-migrate/v1/files/archive');
        $req->set_body_params([
            'source_dir' => (isset($params['source_dir']) ? $params['source_dir'] : ''),
            'format'     => (isset($params['format']) ? $params['format'] : 'tar.gz'),
        ]);
        return $this->unwrap($this->route_files_archive($req));
    }

    /**
     * Write raw content to a file path. Parent dirs created as needed.
     * Path is intentionally unrestricted — the bridge secret is the trust boundary.
     * Used for wp-config.php edits, .htaccess rewrites, etc.
     */
    private function bridge_act_write_file($params) {
        $path    = (isset($params['path']) ? $params['path'] : '');
        $content = (isset($params['content']) ? $params['content'] : '');
        if (!$path) return ['error' => 'path is required'];
        $parent = dirname($path);
        if (!file_exists($parent)) {
            wp_mkdir_p($parent);
        }
        $ok = @file_put_contents($path, $content);
        if ($ok === false) return ['error' => "write_failed: $path"];
        return ['ok' => true, 'path' => $path, 'bytes' => $ok];
    }

    private function bridge_act_delete_path($params) {
        $path = (isset($params['path']) ? $params['path'] : '');
        if (!$path) return ['error' => 'path is required'];
        if (!file_exists($path)) return ['ok' => true, 'missing' => true];
        if (is_dir($path)) {
            $this->rmdir_recursive($path);
        } else {
            wp_delete_file($path);
        }
        return ['ok' => true, 'path' => $path, 'removed' => !file_exists($path)];
    }

    /**
     * Unwrap a WP_REST_Response / WP_Error into a plain array for tracker reporting.
     */
    private function unwrap($resp) {
        if ($resp instanceof WP_REST_Response) {
            return $resp->get_data();
        }
        if (is_wp_error($resp)) {
            $data = $resp->get_error_data();
            return [
                'error'  => $resp->get_error_message(),
                'code'   => $resp->get_error_code(),
                'status' => is_array($data) ? ((isset($data['status']) ? $data['status'] : 500)) : 500,
            ];
        }
        return ['error' => 'unknown_response_type'];
    }

    // ─── REST API Routes ─────────────────────────────────────────

    public function register_routes() {
        $namespace = 'insta-migrate/v1';

        register_rest_route($namespace, '/status', [
            'methods'  => 'GET',
            'callback' => [$this, 'route_status'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/disk-usage', [
            'methods'  => 'GET',
            'callback' => [$this, 'route_disk_usage'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/disk-probe', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_disk_probe'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/db/import', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_db_import'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/db/query', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_db_query'],
            'permission_callback' => [$this, 'check_auth'],
            'args' => [
                'query' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route($namespace, '/files/upload', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_files_upload'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/files/extract', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_files_extract'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/files/fetch-probe', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_files_fetch_probe'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/files/mkdir', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_files_mkdir'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/search-replace', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_search_replace'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        // WP-CLI route removed: exec() is not allowed by wp.org plugin guidelines.

        register_rest_route($namespace, '/db/export', [
            'methods'  => 'GET',
            'callback' => [$this, 'route_db_export'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/files/archive', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_files_archive'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/files/download', [
            'methods'  => 'GET',
            'callback' => [$this, 'route_files_download'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/files/list', [
            'methods'  => 'GET',
            'callback' => [$this, 'route_files_list'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/cleanup', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_cleanup'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/files/concat', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_files_concat'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/files/search-replace', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_files_search_replace'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/db/fix-prefix', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_db_fix_prefix'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        // API key retrieval — requires WP Application Password (admin only)
        register_rest_route($namespace, '/api-key', [
            'methods'  => 'GET',
            'callback' => [$this, 'route_api_key'],
            'permission_callback' => [$this, 'check_auth_wp_admin'],
        ]);

        // Bridge tick — internal endpoint, auth via bridge secret (not API key).
        // Called by the plugin itself to advance the outbound-driven migration chain.
        register_rest_route($namespace, '/bridge/tick', [
            'methods'  => 'POST',
            'callback' => [$this, 'route_bridge_tick'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($namespace, '/plugins/install-activate-plugin', [
            'methods'             => 'POST',
            'callback'            => [$this, 'install_activate_plugin'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/plugins/delete-plugin', [
            'methods'             => 'POST',
            'callback'            => [$this, 'delete_plugin'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/plugins/deactivate-plugin', [
            'methods'             => 'POST',
            'callback'            => [$this, 'deactivate_plugin'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/theme/activate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'theme_activate'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/core/update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'core_update'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/migration-details', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migration_details'],
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($namespace, '/cache/clear', [
            'methods'             => 'POST',
            'callback'            => [$this, 'cache_clear'],
            'permission_callback' => [$this, 'check_auth'],
        ]);
    }

    // ─── Auth ────────────────────────────────────────────────────

    /**
     * Dual auth: accepts EITHER X-Insta-Key header OR WordPress Application Password.
     * X-Insta-Key is checked first (faster, no DB user lookup).
     * Falls back to WP Application Password Basic Auth (requires manage_options).
     */
    public function check_auth(WP_REST_Request $request) {
        if ($this->request_has_valid_api_key($request)) {
            return true;
        }

        // Method 2: WordPress Application Password (Basic Auth)
        // WP core validates the Authorization header automatically and sets the current user.
        // On multisite, require manage_network (super admin) since migration endpoints
        // perform network-wide database and filesystem operations.
        $user = wp_get_current_user();
        $required_cap = is_multisite() ? 'manage_network' : 'manage_options';
        if ($user && $user->ID > 0 && $user->has_cap($required_cap)) {
            return true;
        }

        return new WP_Error(
            'unauthorized',
            'Invalid or missing credentials. Send X-Insta-Key header or use WordPress Application Password.',
            ['status' => 401]
        );
    }

    /**
     * Whether the request carries this site's API key. The key half of check_auth(), shared
     * with the REST authentication gate so both accept exactly the same requests.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    private function request_has_valid_api_key(WP_REST_Request $request) {
        // Method 1: X-Insta-Key header
        $provided = $request->get_header('X-Insta-Key');

        // Method 1b: Query parameter fallback (CDNs like Bunny strip custom headers)
        if (!$provided && isset($_GET['api_key'])) {
            $provided = sanitize_text_field(wp_unslash($_GET['api_key']));
        }
        if (!$provided) {
            $provided = $request->get_param('api_key');
        }

        // Method 1c: Direct $_SERVER check (Apache may mangle header name)
        if (!$provided && !empty($_SERVER['HTTP_X_INSTA_KEY'])) {
            $provided = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_INSTA_KEY']));
        }

        $stored = $this->get_api_key();

        // A non-string value (e.g. api_key[]=x) is not a key; hash_equals() would throw on it.
        return is_string($provided) && $provided !== '' && is_string($stored) && $stored !== ''
            && hash_equals($stored, $provided);
    }

    // ─── REST authentication gate ────────────────────────────────

    /**
     * Per-route results of rest_request_is_authenticated() for the current request.
     *
     * @var array<string, bool>
     */
    private $rest_auth_results = [];

    /**
     * `rest_authentication_errors` at priority 999.
     *
     * Plugins such as "Disable WP REST API" or "Force Login" reject every REST request from a
     * visitor who is not logged in, before any route's permission_callback runs, so a valid
     * X-Insta-Key never gets checked. This clears such a block ONLY when all of these hold:
     *   - the route is in this plugin's namespace (anchored, case-insensitive, like core routing);
     *   - the request presents no WordPress user credential: no login cookie and no
     *     Authorization header. Such requests keep core's result, including its cookie nonce
     *     check and its application-password errors;
     *   - the request carries that route's own credential, checked by the same function the
     *     route uses: the API key, or the bridge secret for /bridge/tick.
     * Otherwise $access is returned untouched, so a wrong or missing credential still gets the
     * blocking plugin's error. Only a request that already carries an authentication error is
     * changed; note this clears ANY plugin's error on these routes (an IP allowlist built on this
     * filter included) for a holder of the key, which already controls the site through them.
     *
     * @param WP_Error|null|true $access
     * @return WP_Error|null|true
     */
    public function rest_authentication_bypass($access) {
        if (!is_wp_error($access)) {
            return $access;
        }

        $route = $this->current_rest_route();
        if ($route === '' || !preg_match('@^/insta-migrate/@i', $route)) {
            return $access;
        }

        return $this->rest_request_is_authenticated($route) ? true : $access;
    }

    /**
     * The REST route being served, as core will route it.
     *
     * @return string
     */
    private function current_rest_route() {
        if (!isset($GLOBALS['wp']) || !is_object($GLOBALS['wp']) || empty($GLOBALS['wp']->query_vars['rest_route'])) {
            return '';
        }
        $route = $GLOBALS['wp']->query_vars['rest_route'];

        return is_string($route) ? untrailingslashit($route) : '';
    }

    /**
     * Whether the current request carries a valid credential for $route.
     *
     * @param string $route
     * @return bool
     */
    private function rest_request_is_authenticated($route) {
        // Before rest_api_init there is no REST server to read the request with.
        if ($this->has_wp_user_credentials() || !did_action('rest_api_init')) {
            return false;
        }

        $key = strtolower($route);
        if (isset($this->rest_auth_results[$key])) {
            return $this->rest_auth_results[$key];
        }

        // Anchored like core's route matching, whose `$` also matches before a trailing newline.
        if (preg_match('@^/insta-migrate/v1/api-key$@i', $route)) {
            // Application-password only; core has already authenticated such a request.
            $result = false;
        } elseif (preg_match('@^/insta-migrate/v1/bridge/tick$@i', $route)) {
            $cfg    = $this->bridge_load_config();
            $result = $cfg && !empty($cfg['active'])
                && $this->bridge_secret_matches($this->rest_request_from_globals($route), $cfg);
        } else {
            $result = $this->request_has_valid_api_key($this->rest_request_from_globals($route));
        }

        return $this->rest_auth_results[$key] = $result;
    }

    /**
     * Whether the request presents a WordPress user credential: a login cookie, or an
     * Authorization header / Basic auth pair (what core reads for application passwords).
     * The migration service authenticates with the API key alone, so such a request is never
     * exempted and keeps core's authentication result.
     *
     * @return bool
     */
    private function has_wp_user_credentials() {
        foreach (['AUTH_COOKIE', 'SECURE_AUTH_COOKIE', 'LOGGED_IN_COOKIE'] as $cookie) {
            if (defined($cookie) && !empty($_COOKIE[constant($cookie)])) {
                return true;
            }
        }
        foreach (['PHP_AUTH_USER', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $var) {
            if (!empty($_SERVER[$var])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a WP_REST_Request from the current HTTP request the way WP_REST_Server::serve_request()
     * does, because `rest_authentication_errors` runs before the server builds its own.
     * The raw body is read only for a JSON request (the only kind get_param()/get_json_params()
     * parse it for), so a large upload body is never copied.
     *
     * @param string $route
     * @return WP_REST_Request
     */
    private function rest_request_from_globals($route) {
        $method  = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $request = new WP_REST_Request($method, $route);
        $request->set_headers(rest_get_server()->get_headers(wp_unslash($_SERVER)));
        $request->set_query_params(wp_unslash($_GET));
        $request->set_body_params(wp_unslash($_POST));
        // is_json_content_type() is WP 5.6+; this plugin supports 5.0.
        $ct      = $request->get_content_type();
        $is_json = method_exists($request, 'is_json_content_type')
            ? $request->is_json_content_type()
            : ($ct && 'application/json' === $ct['value']);
        if ($is_json) {
            $request->set_body(WP_REST_Server::get_raw_data());
        }
        return $request;
    }

    /**
     * Auth check that requires WP Application Password only (for sensitive endpoints like /api-key).
     */
    public function check_auth_wp_admin(WP_REST_Request $request) {
        $user = wp_get_current_user();
        $required_cap = is_multisite() ? 'manage_network' : 'manage_options';
        if ($user && $user->ID > 0 && $user->has_cap($required_cap)) {
            return true;
        }

        return new WP_Error(
            'unauthorized',
            'This endpoint requires WordPress Application Password authentication with admin privileges.',
            ['status' => 401]
        );
    }

    /**
     * Route: GET /api-key — returns the plugin's API key for authenticated WP admins.
     */
    public function route_api_key(WP_REST_Request $request) {
        $this->ensure_api_key();
        $api_key = $this->get_api_key();

        return rest_ensure_response([
            'success' => true,
            'api_key' => $api_key,
        ]);
    }

    // ─── Route: Status ───────────────────────────────────────────

    public function route_status(WP_REST_Request $request) {
        global $wpdb, $wp_version;

        $response = [
            'status'       => 'ok',
            'version'      => INSTA_MIGRATE_VERSION,
            'wp_version'   => $wp_version,
            'php_version'  => PHP_VERSION,
            'site_url'     => get_site_url(),
            'home_url'     => get_home_url(),
            'abspath'      => ABSPATH,
            'wp_content'   => WP_CONTENT_DIR,
            'uploads_dir'  => wp_upload_dir()['basedir'],
            'table_prefix' => $wpdb->prefix,
            'db_name'      => DB_NAME,
            'upload_max'   => ini_get('upload_max_filesize'),
            'post_max'     => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit'),
            'max_exec'     => ini_get('max_execution_time'),
            'disk_free'    => function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : null,
            'disk_total'   => function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : null,
            'wp_admin_email' => get_option('admin_email'),
            'wp_cli'       => $this->has_wp_cli(),
            'temp_dir'     => INSTA_MIGRATE_TEMP_DIR,
            'is_multisite' => is_multisite(),
        ];

        if (is_multisite()) {
            $response['is_main_site']  = is_main_site();
            $response['network_sites'] = get_blog_count();
            $response['blog_id']       = get_current_blog_id();
        }

        return rest_ensure_response($response);
    }

    // ─── Route: Disk Usage ───────────────────────────────────────

    /**
     * GET /disk-usage — the site's real disk usage (files + database).
     *
     * SCOPE IS THE WHOLE INSTALL, rooted at ABSPATH. wp-admin and wp-includes ARE counted: they
     * occupy disk, so they are disk usage. Nothing is skipped by default — no cache dirs, no backup
     * dirs, no node_modules. The ONLY thing subtracted is the caller's own `exclude` list.
     *
     * Params (all optional):
     *   exclude=<csv|json>  paths/globs to omit — see normalize_disk_exclude() for the base path.
     *   budget=<seconds>    wall-clock cap for the walk (default 20, clamped 1..120).
     *   force=1             bypass the 1-hour result cache.
     *
     * Separate from /status by design: a full recursive walk is far too expensive for the
     * frequently-polled health check. Always HTTP 200 with a structured array (either
     * 'success'=>true plus sizes, or {success:false,error_code,error_message}), so callers branch on
     * 'success' and never on the truthiness of a size field.
     */
    public function route_disk_usage(WP_REST_Request $request) {
        $exclude = $this->normalize_disk_exclude($request->get_param('exclude'));

        $budget = (int) $request->get_param('budget');
        if ($budget <= 0) { $budget = 20; }
        $budget = max(1, min(120, $budget));

        return rest_ensure_response(
            $this->get_disk_usage($exclude, $budget, (bool) $request->get_param('force'))
        );
    }

    /**
     * Normalise the /disk-usage `exclude` param.
     *
     * Patterns here are ABSPATH-RELATIVE ("wp-content/updraft", "wp-admin", "*.zip"), because this
     * endpoint measures the whole install — unlike /files/archive, whose entries are
     * wp-content-relative. So that ONE skip-list works on both, every pattern not already anchored
     * under the wp-content directory is ALSO registered with that prefix: "updraft" (what the
     * skip-files UI sends) therefore matches "wp-content/updraft" here. Without that the excludes
     * would silently no-op, which is exactly the failure class this endpoint already had.
     */
    private function normalize_disk_exclude($raw) {
        $patterns = $this->normalize_exclude($raw);
        if (empty($patterns)) {
            return [];
        }

        $content_rel = $this->abspath_relative(WP_CONTENT_DIR); // normally "wp-content"
        $out = [];
        foreach ($patterns as $p) {
            $out[$p] = true;
            if ($content_rel !== '' && $p !== $content_rel && strpos($p, $content_rel . '/') !== 0) {
                $out[$content_rel . '/' . $p] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * A path expressed relative to ABSPATH, or '' when it IS ABSPATH or resolves outside it.
     * Used to give every entry in the walk a stable, ABSPATH-relative name for exclude matching.
     */
    private function abspath_relative($path) {
        $root = @realpath(ABSPATH);
        $real = @realpath($path);
        if ($root === false || $real === false) {
            return '';
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $real = rtrim(str_replace('\\', '/', $real), '/');
        if ($real === $root || strpos($real, $root . '/') !== 0) {
            return '';
        }
        return substr($real, strlen($root) + 1);
    }

    /**
     * Measure one directory tree. Returns bytes; entry paths are ABSPATH-relative.
     *
     * DELIBERATELY NOT WP core's recurse_dirsize(). Core caches every directory it measures in the
     * `dirsize_cache` transient for 10 * YEAR_IN_SECONDS (or forever, with an external object
     * cache) and returns it on an is_int() check with no freshness test, while its only invalidator,
     * clean_dirsize_cache(), is gated `if ( is_multisite() )`. On a single-site install — nearly
     * every site we measure — the cached size is NEVER invalidated, so the reported figure freezes at
     * whatever the site was the first time anyone asked and drifts further wrong as the site grows.
     * That is the silent under-report this endpoint used to ship (a 220 MB answer for a 1.9 GB
     * uploads tree). Nothing here reads or writes that transient.
     *
     * Every filesystem touch is individually guarded: a file deleted mid-walk, an unreadable
     * directory, a broken symlink or an open_basedir restriction skips that ONE entry and the walk
     * keeps counting, with the path recorded in $state['unmeasured'] so an under-count is visible
     * rather than silent. A realpath() visited-set breaks symlink loops (core's walker has none) and
     * doubles as the "already counted in another bucket" guard.
     *
     * @param string $dir     Absolute directory to walk.
     * @param string $rel     Its ABSPATH-relative path ('' for the install root).
     * @param array  $exclude Patterns from normalize_disk_exclude().
     * @param array  $state   By-ref accumulator (deadline, visited, skip_real, unmeasured,
     *                        excluded_paths, excluded_bytes, partial).
     * @param bool   $is_root True for the top of a pass. The five pass roots (ABSPATH + the four
     *                        buckets) are pre-seeded into the visited set so a symlink pointing at
     *                        one of them is pruned wherever it is met; without this flag a root
     *                        would then reject its own walk.
     * @return int Bytes measured under $dir; 0 when unreadable, already visited or pruned.
     */
    private function measure_tree($dir, $rel, $exclude, array &$state, $is_root = false) {
        if (!empty($state['partial'])) {
            return 0; // the budget is gone; unwind without touching the disk again
        }

        $label = ($rel === '') ? '.' : $rel;

        try {
            $real = @realpath($dir);
            if ($real === false) {
                $state['unmeasured'][] = $label;
                return 0;
            }
            if (!$is_root && isset($state['visited'][$real])) {
                return 0; // symlink loop, or already counted under another bucket
            }
            $state['visited'][$real] = true;

            // Never count our own temp/working dir as site content.
            if ($state['skip_real'] !== false && $real === $state['skip_real']) {
                return 0;
            }

            $items = @scandir($dir);
            if ($items === false) {
                $state['unmeasured'][] = $label;
                return 0;
            }
        } catch (\Exception $e) {
            $state['unmeasured'][] = $label;
            return 0;
        } catch (\Throwable $e) {
            $state['unmeasured'][] = $label;
            return 0;
        }

        $size = 0;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (microtime(true) > $state['deadline']) {
                // Out of budget: keep what we have and say so. Never collapse the whole answer —
                // a partial number is strictly more useful to the caller than none.
                $state['partial']      = true;
                $state['unmeasured'][] = $label;
                return $size;
            }

            $child_rel = ($rel === '') ? $item : $rel . '/' . $item;
            $full      = $dir . '/' . $item;

            try {
                if ($this->is_excluded($child_rel, $exclude)) {
                    // Measure it anyway, but bill it to 'excluded' so the caller can see what their
                    // skip-list is worth. A matched directory prunes its whole subtree from the total.
                    // A matched FILE must be filesize()'d, not walked — handing a file to the
                    // directory walker silently bills it 0 bytes and files it as unmeasured.
                    $state['excluded_paths'][$child_rel] = true;
                    if (is_dir($full)) {
                        $state['excluded_bytes'] += $this->measure_tree($full, $child_rel, array(), $state);
                    } else {
                        $bytes = @filesize($full);
                        if ($bytes !== false) { $state['excluded_bytes'] += (int) $bytes; }
                    }
                    continue;
                }

                if (is_dir($full)) {
                    $size += $this->measure_tree($full, $child_rel, $exclude, $state);
                } elseif (is_file($full)) {
                    $bytes = @filesize($full);
                    if ($bytes === false) {
                        $state['unmeasured'][] = $child_rel;
                    } else {
                        $size += (int) $bytes;
                    }
                }
            } catch (\Exception $e) {
                // One bad entry must never cost the site's total.
                $state['unmeasured'][] = $child_rel;
                continue;
            } catch (\Throwable $e) {
                // One bad entry must never cost the site's total.
                $state['unmeasured'][] = $child_rel;
                continue;
            }
        }

        return $size;
    }

    /**
     * Database footprint: Data_length + Index_length over every table in the schema — identical to
     * WP core's own Site Health figure and to instawp-connect's instawp_get_database_details(), so
     * the number does not move for anyone already reading it.
     *
     * Note for whoever reads total_size as a MIGRATION payload: /db/export ships only
     * SHOW TABLES LIKE '{prefix}%', so on a SHARED database this figure is larger than what a
     * migration actually transfers. That is disk usage doing its job, not a bug here.
     */
    private function get_database_size() {
        global $wpdb;

        $size = 0;
        try {
            $rows = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $data  = isset($row['Data_length']) ? (int) $row['Data_length'] : 0;
                    $index = isset($row['Index_length']) ? (int) $row['Index_length'] : 0;
                    $size += $data + $index;
                }
            }
        } catch (\Exception $e) {
            return 0; // a DB hiccup must not fail the whole measurement
        } catch (\Throwable $e) {
            return 0; // a DB hiccup must not fail the whole measurement
        }

        return $size;
    }

    /**
     * Site disk usage (files + database), measured by our own walk.
     *
     * Always returns an ARRAY so callers can tell what happened:
     *   - success: per-directory raw byte counts + 'success' => true. total_size.raw = files + DB.
     *     'partial' => true means the time budget ran out: the number is a floor, and 'unmeasured'
     *     names the directories it stopped short of.
     *   - failure: ['success' => false, 'error_code' => ..., 'error_message' => ...] — never a bare
     *     null, so a fatal / unreadable root is diagnosable by the caller. Whatever had already been
     *     measured rides along, so a late throw still returns a number.
     *
     * The per-directory keys (wordpress_size / themes_size / plugins_size / uploads_size /
     * fonts_size) keep WP core's meanings, including "wordpress_size excludes the other four" — the
     * four buckets are walked FIRST, which puts them in the visited-set so the ABSPATH pass cannot
     * double-count them.
     *
     * Caching is OURS, not core's: a 1-hour transient keyed on the exclude list + budget, bypassed
     * with ?force=1, mirroring instawp-connect's get_total_sizes(). A partial result is never
     * cached — serving a timeout snapshot for an hour would re-create the staleness this replaces.
     */
    private function get_disk_usage($exclude = array(), $budget = 20, $force = false) {
        $state = array(
            'deadline'       => microtime(true) + $budget,
            'visited'        => array(),
            'skip_real'      => false,
            'unmeasured'     => array(),
            'excluded_paths' => array(),
            'excluded_bytes' => 0,
            'partial'        => false,
        );
        $files_total = 0;

        try {
            $cache_key = 'insta_migrate_size_' . md5(wp_json_encode(array($exclude, $budget)));

            if (!$force) {
                $cached = get_transient($cache_key);
                if (is_array($cached) && isset($cached['total_size']['raw'])) {
                    $cached['cached'] = true;
                    return $cached;
                }
            }

            @set_time_limit($budget + 30);
            $state['skip_real'] = @realpath(INSTA_MIGRATE_TEMP_DIR);

            $upload_dir = wp_upload_dir();
            $font_dir   = function_exists('wp_get_font_dir') ? wp_get_font_dir() : array();

            // Walked first so the ABSPATH pass below skips them via the visited-set.
            $buckets = array(
                'themes_size'  => function_exists('get_theme_root') ? get_theme_root() : '',
                'plugins_size' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '',
                'uploads_size' => isset($upload_dir['basedir']) ? $upload_dir['basedir'] : '',
                'fonts_size'   => isset($font_dir['basedir']) ? $font_dir['basedir'] : '',
            );

            $wp_root = untrailingslashit(ABSPATH);

            // Seed the visited-set with all five pass roots BEFORE walking anything. Buckets are
            // measured before ABSPATH, so without this a symlink inside (say) uploads that resolves
            // to the install root would drag the whole site into uploads_size, and the later ABSPATH
            // pass would then find it "visited" and report 0 — a correct total in the wrong buckets.
            // Each pass passes $is_root=true so it is not blocked by its own seed.
            foreach (array_merge(array($wp_root), array_values($buckets)) as $root_path) {
                if (!is_string($root_path) || $root_path === '') { continue; }
                $root_real = @realpath($root_path);
                if ($root_real !== false) { $state['visited'][$root_real] = true; }
            }

            $sizes = array();
            foreach ($buckets as $key => $path) {
                $bytes = 0;
                if (is_string($path) && $path !== '' && @is_dir($path)) {
                    $rel = $this->abspath_relative($path);
                    if ($rel === '') {
                        $rel = basename(rtrim(str_replace('\\', '/', $path), '/'));
                    }
                    if ($this->is_excluded($rel, $exclude)) {
                        $state['excluded_paths'][$rel] = true;
                        $state['excluded_bytes'] += $this->measure_tree($path, $rel, array(), $state, true);
                    } else {
                        $bytes = $this->measure_tree($path, $rel, $exclude, $state, true);
                    }
                }
                $sizes[$key] = array(
                    'path' => is_string($path) ? $path : '',
                    'raw'  => $bytes,
                    'size' => size_format($bytes, 2),
                );
                $files_total += $bytes;
            }

            // Everything else in the install — wp-admin and wp-includes INCLUDED. They sit on the
            // disk, so they count toward disk usage; this endpoint is not a transfer estimate.
            $wp_bytes = $this->measure_tree($wp_root, '', $exclude, $state, true);
            $sizes['wordpress_size'] = array(
                'path' => $wp_root,
                'raw'  => $wp_bytes,
                'size' => size_format($wp_bytes, 2),
            );
            $files_total += $wp_bytes;

            $db_bytes = $this->get_database_size();
            $total    = $files_total + $db_bytes;

            $unmeasured = array_values(array_unique($state['unmeasured']));
            $excluded   = array_keys($state['excluded_paths']);

            $result = array(
                'success'        => true,
                'partial'        => (bool) $state['partial'],
                'wordpress_size' => $sizes['wordpress_size'],
                'themes_size'    => $sizes['themes_size'],
                'plugins_size'   => $sizes['plugins_size'],
                'uploads_size'   => $sizes['uploads_size'],
                'fonts_size'     => $sizes['fonts_size'],
                'database_size'  => array('raw' => $db_bytes, 'size' => size_format($db_bytes, 2)),
                'files_size'     => array('raw' => $files_total, 'size' => size_format($files_total, 2)),
                'total_size'     => array('raw' => $total, 'size' => size_format($total, 2)),
                'excluded'       => array(
                    'paths' => array_slice($excluded, 0, 50),
                    'count' => count($excluded),
                    'raw'   => (int) $state['excluded_bytes'],
                    'size'  => size_format((int) $state['excluded_bytes'], 2),
                ),
                'unmeasured'       => array_slice($unmeasured, 0, 50),
                'unmeasured_count' => count($unmeasured),
                'budget_seconds'   => (int) $budget,
                'measured_at'      => time(),
                'cached'           => false,
            );

            if (!$result['partial']) {
                set_transient($cache_key, $result, HOUR_IN_SECONDS);
            }

            return $result;

        } catch (\Exception $e) {
            // A sizing fatal must never break the endpoint — this runs against live customer sites
            // we do not control. Keep the legacy envelope (the tracker branches on 'success' and
            // treats false as "unknown, proceed") and carry whatever was measured before the throw.
            $unmeasured = array_values(array_unique($state['unmeasured']));
            return array(
                'success'          => false,
                'error_code'       => 'exception',
                'error_message'    => $e->getMessage(),
                'partial'          => true,
                'measured_size'    => array('raw' => $files_total, 'size' => size_format($files_total, 2)),
                'unmeasured'       => array_slice($unmeasured, 0, 50),
                'unmeasured_count' => count($unmeasured),
            );
        } catch (\Throwable $e) {
            // A sizing fatal must never break the endpoint — this runs against live customer sites
            // we do not control. Keep the legacy envelope (the tracker branches on 'success' and
            // treats false as "unknown, proceed") and carry whatever was measured before the throw.
            $unmeasured = array_values(array_unique($state['unmeasured']));
            return array(
                'success'          => false,
                'error_code'       => 'exception',
                'error_message'    => $e->getMessage(),
                'partial'          => true,
                'measured_size'    => array('raw' => $files_total, 'size' => size_format($files_total, 2)),
                'unmeasured'       => array_slice($unmeasured, 0, 50),
                'unmeasured_count' => count($unmeasured),
            );
        }
    }

    /**
     * POST /disk-probe { "bytes": N } — will N more bytes fit on this destination?
     *
     * The "blocked mid-files_import on an undersized dest" class: a per-account quota (cgroup/quota
     * layer) that disk_free_space() can't see reports the shared filesystem as huge, so the wall isn't
     * hit until the file transfer runs. On InstaWP dests the App passes the plan quota and the agent
     * preflights; on OTHER hosts there is no quota to pass, so we probe: actually write N bytes to a
     * temp file and see if it fits. exec()/posix_fallocate are unavailable (wp.org bans exec), so this
     * is a pure-PHP chunked write — time-budgeted so a big payload can't run long, and always deleted.
     *
     * Random (not zero) bytes are written so a compressing/dedup filesystem can't under-count them.
     *
     * Always HTTP 200 with { success:true, fits, ... }; callers branch on `fits`:
     *   fits=true   — wrote all N without error → there is room
     *   fits=false  — a write hit the quota/disk limit → NOT enough room (reason=quota_or_disk_full)
     *   fits=null   — the time budget elapsed before finishing and before any error → indeterminate
     */
    public function route_disk_probe(WP_REST_Request $request) {
        ignore_user_abort(true);
        @set_time_limit(60);

        $requested = (int) $request->get_param('bytes');
        if ($requested <= 0) {
            return new WP_REST_Response(['success' => false, 'error' => 'bytes must be a positive integer'], 400);
        }
        // Defensive cap so a bogus huge value can't churn the disk; the agent passes payload x ~1.5.
        $cap = 50 * 1024 * 1024 * 1024; // 50 GB
        if ($requested > $cap) { $requested = $cap; }

        if (!file_exists(INSTA_MIGRATE_TEMP_DIR)) {
            wp_mkdir_p(INSTA_MIGRATE_TEMP_DIR);
        }
        $probe = INSTA_MIGRATE_TEMP_DIR . '/.disk-probe-' . wp_generate_password(8, false);
        // Guarantee cleanup even on a fatal or a client abort mid-write.
        register_shutdown_function(function () use ($probe) { if (file_exists($probe)) { @unlink($probe); } });

        $disk_free = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : null;
        $budget_s  = 25;                       // stop after ~25s of writing → fits=null (indeterminate)
        $chunk     = 8 * 1024 * 1024;          // 8 MB per write
        // Random bytes defeat filesystem compression/dedup that would under-count zero-filled probes.
        $buf       = function_exists('random_bytes') ? random_bytes($chunk) : str_repeat('x', $chunk);
        $started   = microtime(true);
        $written   = 0;
        $fits      = null;                      // null until proven true/false
        $reason    = 'time_budget';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming probe write; WP_Filesystem buffers whole files in memory.
        $fp = @fopen($probe, 'wb');
        if (!$fp) {
            @unlink($probe);
            return rest_ensure_response([
                'success' => true, 'fits' => false, 'requested_bytes' => $requested,
                'written_bytes' => 0, 'disk_free' => $disk_free, 'reason' => 'cannot_open_temp_file',
            ]);
        }

        while ($written < $requested) {
            if ((microtime(true) - $started) > $budget_s) { $fits = null; $reason = 'time_budget'; break; }
            $remaining = $requested - $written;
            $toWrite   = $remaining < $chunk ? $remaining : $chunk;
            $slice     = $toWrite === $chunk ? $buf : substr($buf, 0, $toWrite);
            // fflush each chunk so a quota/ENOSPC surfaces on THIS write, not a deferred buffered flush.
            $n = @fwrite($fp, $slice);
            if ($n === false || $n < $toWrite || @fflush($fp) === false) {
                $written += ($n === false ? 0 : $n);
                $fits = false; $reason = 'quota_or_disk_full';
                break;
            }
            $written += $n;
        }
        if ($written >= $requested && $fits === null) { $fits = true; $reason = 'ok'; }

        if (is_resource($fp)) { @fclose($fp); }
        @unlink($probe);

        return rest_ensure_response([
            'success'         => true,
            'fits'            => $fits,          // true | false | null
            'requested_bytes' => $requested,
            'written_bytes'   => $written,
            'disk_free'       => $disk_free,
            'reason'          => $reason,
        ]);
    }

    // ─── Route: DB Import ────────────────────────────────────────

    // ─── Server-to-server pull (source_url ingest, v1.7.6) ──────

    /**
     * SSRF guard for server-side fetches. Allows only http/https and rejects hosts that
     * resolve to loopback / private / reserved / link-local (incl. cloud-metadata 169.254.x)
     * ranges, so an authenticated caller can't turn this site into a fetch proxy for
     * internal endpoints. Resolves the host ONCE and returns ['ok'=>true,'host','port','ip']
     * (the validated IP the caller must pin curl onto via CURLOPT_RESOLVE), or a WP_Error.
     */
    private function url_is_safe($url) {
        $p = wp_parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            return new WP_Error('bad_url', 'source_url is not a valid URL', ['status' => 400]);
        }
        $scheme = strtolower($p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return new WP_Error('bad_scheme', 'source_url must be http or https', ['status' => 400]);
        }
        $host = $p['host'];
        $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);

        // Resolve ONCE (a literal-IP host is its own resolution). Validate EVERY returned address,
        // then the caller pins curl to the validated IP — so a low-TTL attacker domain can't
        // re-resolve to an internal IP between this check and the connect (DNS-rebinding TOCTOU).
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $a = @gethostbynamel($host);
            if (is_array($a)) { $ips = array_merge($ips, $a); }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) { foreach ($aaaa as $r) { if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; } } }
        }
        $ips = array_values(array_unique($ips));
        if (empty($ips)) {
            return new WP_Error('unresolvable', "source_url host did not resolve: $host", ['status' => 400]);
        }
        foreach ($ips as $ip) {
            if (!$this->ip_is_public($ip)) {
                return new WP_Error('blocked_target', "source_url resolves to a non-public address ($ip)", ['status' => 403]);
            }
        }
        return ['ok' => true, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * True only for a PUBLIC unicast IP. Normalizes via inet_pton and tests against an explicit
     * CIDR denylist rather than PHP's FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE — those flags only
     * inspect the textual form, so IPv4-mapped IPv6 (::ffff:169.254.169.254), NAT64
     * (64:ff9b::169.254.169.254) and CGNAT (100.64/10) slip an internal IPv4 past them. We unwrap
     * v4-mapped/NAT64 to the embedded IPv4 and re-check, so an internal IPv4 can't hide in an IPv6.
     */
    private function ip_is_public($ip) {
        $bin = @inet_pton($ip);
        if ($bin === false) { return false; }
        if (strlen($bin) === 16) {
            $v4mapped = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff"; // ::ffff:0:0/96
            $nat64    = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00"; // 64:ff9b::/96
            if (strncmp($bin, $v4mapped, 12) === 0 || strncmp($bin, $nat64, 12) === 0) {
                $bin = substr($bin, 12); // treat as the embedded IPv4
            }
        }
        if (strlen($bin) === 4) {
            $deny = [
                ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
                ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
                ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24],
                ['224.0.0.0', 4], ['240.0.0.0', 4], ['255.255.255.255', 32],
            ];
        } elseif (strlen($bin) === 16) {
            $deny = [['::', 128], ['::1', 128], ['fc00::', 7], ['fe80::', 10], ['ff00::', 8]];
        } else {
            return false;
        }
        foreach ($deny as $c) {
            $nb = @inet_pton($c[0]);
            if ($nb !== false && $this->ip_in_cidr($bin, $nb, $c[1])) { return false; }
        }
        return true;
    }

    /** Binary CIDR match: is packed IP $bin inside $net_bin/$prefix? Arch-independent (byte-wise). */
    private function ip_in_cidr($bin, $net_bin, $prefix) {
        if (strlen($bin) !== strlen($net_bin)) { return false; }
        $full = (int) floor($prefix / 8);
        $rem  = $prefix % 8;
        if ($full > 0 && strncmp($bin, $net_bin, $full) !== 0) { return false; }
        if ($rem === 0) { return true; }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($bin[$full]) & $mask) === (ord($net_bin[$full]) & $mask);
    }

    /**
     * Download a remote URL to a local path, SSRF-guarded. Mirrors the bridge downloader's
     * curl settings; redirects are disabled so a redirect can't bypass the guard. Returns
     * ['ok'=>true,'size'=>N,...] or a WP_Error.
     */
    private function fetch_url_to_temp($url, $dest, $expected_sha256 = '') {
        $safe = $this->url_is_safe($url);
        if (is_wp_error($safe)) { return $safe; }
        $parent = dirname($dest);
        if (!file_exists($parent)) { wp_mkdir_p($parent); }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming write of a large downloaded artifact.
        $fp = fopen($dest, 'wb');
        if (!$fp) { return new WP_Error('cannot_open_dest', "Cannot open $dest for writing", ['status' => 500]); }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE        => ["{$safe['host']}:{$safe['port']}:{$safe['ip']}"], // pin the validated IP (anti-rebinding)
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => 1800,
            CURLOPT_HTTPHEADER     => ['User-Agent: InstaMigrate/' . INSTA_MIGRATE_VERSION],
            CURLOPT_FAILONERROR    => false,
        ]);
        $ok   = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fp);
        if ($ok === false || $http < 200 || $http >= 300) {
            wp_delete_file($dest);
            return new WP_Error('fetch_failed', "Fetch failed (http=$http) $err", ['status' => 502]);
        }
        if ($expected_sha256 && !hash_equals(strtolower($expected_sha256), strtolower((string) hash_file('sha256', $dest)))) {
            wp_delete_file($dest);
            return new WP_Error('sha256_mismatch', 'Fetched file checksum mismatch', ['status' => 422]);
        }
        return ['ok' => true, 'dest' => $dest, 'size' => filesize($dest), 'http' => $http];
    }

    /**
     * Dest-side reachability probe for source_url ingest. Does a 1-byte range GET of the
     * (SSRF-guarded) source_url and reports whether this site can reach it, so the
     * orchestrator can fall back to the chunk-upload path when it can't.
     */
    public function route_files_fetch_probe(WP_REST_Request $request) {
        $url = $request->get_param('source_url');
        if (!$url) { return new WP_Error('no_url', 'source_url is required', ['status' => 400]); }
        $safe = $this->url_is_safe($url);
        if (is_wp_error($safe)) { return $safe; }
        // Cap the buffered body — origins that ignore Range can return a multi-GB 200; we judge
        // reachability from the HTTP status, not a full transfer. Pin the validated IP (anti-rebind).
        $seen = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RANGE          => '0-0',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE        => ["{$safe['host']}:{$safe['port']}:{$safe['ip']}"],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['User-Agent: InstaMigrate/' . INSTA_MIGRATE_VERSION],
            CURLOPT_WRITEFUNCTION  => function ($c, $data) use (&$seen) {
                $seen += strlen($data);
                return $seen > 8192 ? 0 : strlen($data); // returning 0 aborts the transfer once capped
            },
        ]);
        curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $reachable = ($http >= 200 && $http < 400);
        return rest_ensure_response([
            'reachable' => $reachable,
            'http'      => $http,
            'error'     => $reachable ? null : ($err ?: null),
        ]);
    }

    /**
     * Count how many of an archive's entries are missing under $dest after extraction —
     * turns a silent truncation (ZipArchive stopping early at a PHP limit) into a loud
     * signal. Precise for zip; tar/phar reports the entry count only (its extractTo throws
     * on hard failure). Returns ['archive_entries'=>N,'present'=>M|null,'missing'=>K|null]
     * or null if the archive can't be re-opened.
     */
    private function verify_extracted($archive, $dest, $ext) {
        try {
            if ($ext === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($archive) !== true) { return null; }
                $files = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $n = $zip->getNameIndex($i);
                    if ($n !== false && substr($n, -1) !== '/') { $files[] = $n; }
                }
                $zip->close();
                $present = 0;
                foreach ($files as $rel) {
                    if (file_exists(rtrim($dest, '/') . '/' . ltrim($rel, '/'))) { $present++; }
                }
                return ['archive_entries' => count($files), 'present' => $present, 'missing' => count($files) - $present];
            }
            $phar = new PharData($archive);
            $n = 0;
            foreach (new RecursiveIteratorIterator($phar) as $f) { $n++; }
            return ['archive_entries' => $n, 'present' => null, 'missing' => null];
        } catch (Exception $e) {
            return null;
        }
    }

    public function route_db_import(WP_REST_Request $request) {
        global $wpdb;

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $wp_filesystem = $this->init_filesystem();
        $files = $request->get_file_params();
        $body = $request->get_body();

        $sql_file = null;
        $pulled = null;   // a source_url-pulled temp dump to delete before every return (M2)

        // Option 0: pull the dump from a remote source URL (server-to-server, SSRF-guarded).
        if ($src = $request->get_param('source_url')) {
            $pulled = INSTA_MIGRATE_TEMP_DIR . '/import-pull-' . time() . '.sqlz';
            $r = $this->fetch_url_to_temp($src, $pulled);
            if (is_wp_error($r)) { return $r; }
            $sql_file = $pulled; // gzip is detected by magic bytes below, so the extension is irrelevant
        }
        // Option 1: File uploaded via multipart
        elseif (!empty($files['sql_file']['tmp_name'])) {
            $sql_file = $files['sql_file']['tmp_name'];
        }
        // Option 2: Path to file already on server
        elseif ($path = $request->get_param('file_path')) {
            if (file_exists($path)) {
                $sql_file = $path;
            } else {
                return new WP_Error('not_found', "File not found: $path", ['status' => 404]);
            }
        }
        // Option 3: SQL in request body
        elseif (!empty($body) && stripos($body, 'CREATE') !== false || stripos($body, 'INSERT') !== false) {
            $sql_file = INSTA_MIGRATE_TEMP_DIR . '/import-' . time() . '.sql';
            if ($wp_filesystem) {
                $wp_filesystem->put_contents($sql_file, $body, FS_CHMOD_FILE);
            }
        }

        if (!$sql_file) {
            return new WP_Error('no_input', 'Provide SQL via file upload (sql_file), file_path param, or request body.', ['status' => 400]);
        }

        // Check if gzipped — need direct fopen for binary 2-byte magic check.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Binary magic-byte detection requires direct stream access.
        $fh = fopen($sql_file, 'rb');
        if ($fh === false) {
            if ($pulled) { wp_delete_file($pulled); }
            return new WP_Error('read_error', "Cannot open SQL file: $sql_file", ['status' => 500]);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $magic = fread($fh, 2);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($fh);

        $is_gzip = ($magic === "\x1f\x8b");

        // Stream directly from gzip (no decompress-to-disk step).
        if ($is_gzip) {
            $handle = gzopen($sql_file, 'rb');
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Line-by-line SQL parsing of large dumps requires streaming; WP_Filesystem loads entire file into memory.
            $handle = fopen($sql_file, 'r');
        }
        if (!$handle) {
            if ($pulled) { wp_delete_file($pulled); }
            return new WP_Error('read_error', 'Cannot read SQL file', ['status' => 500]);
        }

        $queries_run = 0;
        $errors = [];
        $current_query = '';
        $tx_batch = 0;
        $tx_interval = 500; // COMMIT every N queries for non-v2 dumps

        // MySQL performance tuning for import
        $wpdb->query('SET foreign_key_checks = 0');
        $wpdb->query('SET unique_checks = 0');
        $wpdb->query('SET autocommit = 0');
        $wpdb->query('SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"');
        $wpdb->query('SET NAMES utf8mb4');

        // Detect if dump already manages transactions (v2 exports include autocommit=0 + COMMIT)
        $dump_manages_tx = false;

        $read_func = $is_gzip ? 'gzgets' : 'fgets';
        $eof_func = $is_gzip ? 'gzeof' : 'feof';

        while (!$eof_func($handle)) {
            $line = $read_func($handle);
            if ($line === false) break;

            $trimmed = trim($line);

            // Skip comments and empty lines
            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
                // Detect v2 dump format (has autocommit + COMMIT built in)
                if (strpos($trimmed, '-- InstaMigrate DB Export v2') === 0) {
                    $dump_manages_tx = true;
                }
                continue;
            }
            // Skip MySQL conditional comments
            if (strpos($trimmed, '/*') === 0 && strpos($trimmed, '*/;') !== false) {
                continue;
            }

            $current_query .= $line;

            // Check if query is complete (ends with semicolon)
            if (preg_match('/;\s*$/', $trimmed)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Executing raw SQL statements from a trusted migration dump; these are arbitrary DDL/DML that cannot be parameterized.
                $result = $wpdb->query($current_query);
                if ($result === false && $wpdb->last_error) {
                    $errors[] = [
                        'query_num' => $queries_run + 1,
                        'error' => $wpdb->last_error,
                        'query_preview' => substr(trim($current_query), 0, 200),
                    ];
                }
                $queries_run++;
                $current_query = '';

                // For legacy (v1) dumps: commit in batches to reduce fsync overhead
                if (!$dump_manages_tx) {
                    $tx_batch++;
                    if ($tx_batch >= $tx_interval) {
                        $wpdb->query('COMMIT');
                        $wpdb->query('START TRANSACTION');
                        $tx_batch = 0;
                    }
                }
            }
        }

        if ($is_gzip) {
            gzclose($handle);
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
        }

        // Remove the source_url-pulled temp dump now it's fully imported (M2 — no orphan gz left in
        // uploads/instamigrate). Multipart/file_path inputs are NOT ours to delete.
        if ($pulled) { wp_delete_file($pulled); }

        // Final commit and restore settings
        $wpdb->query('COMMIT');
        $wpdb->query('SET unique_checks = 1');
        $wpdb->query('SET foreign_key_checks = 1');
        $wpdb->query('SET autocommit = 1');

        return rest_ensure_response([
            'success'      => true,
            'queries_run'  => $queries_run,
            'errors_count' => count($errors),
            'errors'       => array_slice($errors, 0, 20), // First 20 errors only
        ]);
    }

    // ─── Route: DB Query (read-only) ────────────────────────────

    /**
     * Execute a read-only SQL query for migration diagnostics.
     *
     * Only SELECT, SHOW, and DESCRIBE statements are allowed.
     * Write operations (INSERT, UPDATE, DELETE, DROP, etc.) are rejected —
     * use the dedicated endpoints (/db/import, /search-replace, /db/fix-prefix) instead.
     */
    public function route_db_query(WP_REST_Request $request) {
        global $wpdb;

        $query = $request->get_param('query');
        if (!$query) {
            return new WP_Error('no_query', 'Provide a "query" parameter.', ['status' => 400]);
        }

        // Only allow read-only statements.
        $trimmed = ltrim($query);
        $upper   = strtoupper(substr($trimmed, 0, 10));
        $is_read = (
            strpos($upper, 'SELECT') === 0 ||
            strpos($upper, 'SHOW')   === 0 ||
            strpos($upper, 'DESCRIBE') === 0
        );

        if (!$is_read) {
            return new WP_Error(
                'write_not_allowed',
                'Only SELECT, SHOW, and DESCRIBE queries are allowed. Use /db/import, /search-replace, or /db/fix-prefix for write operations.',
                ['status' => 403]
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Migration diagnostics: read-only queries for table inspection / data verification; column names and table names are dynamic and cannot be parameterized via prepare().
        $results = $wpdb->get_results($query, ARRAY_A);
        return rest_ensure_response([
            'success' => true,
            'rows'    => $results,
            'count'   => is_array($results) ? count($results) : 0,
        ]);
    }

    // ─── Route: DB Fix Prefix ─────────────────────────────────────
    // Fixes usermeta meta_key and options option_name values after a migration
    // where the table prefix changed but the prefixed meta/option keys were not updated.
    // Without this fix, all users lose their roles/capabilities.

    public function route_db_fix_prefix(WP_REST_Request $request) {
        global $wpdb;

        $old_prefix = $request->get_param('old_prefix');
        $new_prefix = $request->get_param('new_prefix');

        if (!$old_prefix || !$new_prefix) {
            return new WP_Error('missing_params', 'Both "old_prefix" and "new_prefix" parameters are required.', ['status' => 400]);
        }

        // Sanitize: prefixes should only contain alphanumeric and underscores
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $old_prefix) || !preg_match('/^[a-zA-Z0-9_]+$/', $new_prefix)) {
            return new WP_Error('invalid_prefix', 'Prefixes must contain only alphanumeric characters and underscores.', ['status' => 400]);
        }

        // If prefixes are the same, nothing to do
        if ($old_prefix === $new_prefix) {
            return rest_ensure_response([
                'success' => true,
                'message' => 'Prefixes are identical — no changes needed.',
                'usermeta_updated' => 0,
                'options_updated' => 0,
            ]);
        }

        // Verify the tables exist with the new prefix (confirms the table rename happened)
        $usermeta_table = $new_prefix . 'usermeta';
        $options_table = $new_prefix . 'options';

        $usermeta_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($usermeta_table)));
        $options_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($options_table)));

        if (!$usermeta_exists) {
            return new WP_Error('table_not_found', "Table '{$usermeta_table}' not found. Verify the new_prefix is correct.", ['status' => 404]);
        }
        if (!$options_exists) {
            return new WP_Error('table_not_found', "Table '{$options_table}' not found. Verify the new_prefix is correct.", ['status' => 404]);
        }

        $results = [];
        $old_len = strlen($old_prefix);

        // Escape underscores for LIKE patterns (underscore is a SQL wildcard)
        $old_prefix_like = $wpdb->esc_like($old_prefix);

        // Use CONCAT + SUBSTRING instead of REPLACE for idempotency:
        // REPLACE('wp_new_caps', 'wp_', 'wp_new_') would double-replace if new contains old.
        // CONCAT('wp_new_', SUBSTRING(col, len('wp_')+1)) replaces only the leading prefix, once.

        // 1. Fix usermeta meta_key values: {old_prefix}capabilities → {new_prefix}capabilities, etc.
        $usermeta_count = $wpdb->query($wpdb->prepare(
            "UPDATE `{$usermeta_table}` SET `meta_key` = CONCAT(%s, SUBSTRING(`meta_key`, %d)) WHERE `meta_key` LIKE %s",
            $new_prefix,
            $old_len + 1,
            $old_prefix_like . '%'
        ));
        $results['usermeta_updated'] = $usermeta_count !== false ? $usermeta_count : 0;
        $results['usermeta_error'] = $wpdb->last_error ?: null;

        // 2. Fix options option_name values: {old_prefix}user_roles → {new_prefix}user_roles, etc.
        $options_count = $wpdb->query($wpdb->prepare(
            "UPDATE `{$options_table}` SET `option_name` = CONCAT(%s, SUBSTRING(`option_name`, %d)) WHERE `option_name` LIKE %s",
            $new_prefix,
            $old_len + 1,
            $old_prefix_like . '%'
        ));
        $results['options_updated'] = $options_count !== false ? $options_count : 0;
        $results['options_error'] = $wpdb->last_error ?: null;

        // 3. For multisite, also fix sitemeta if it exists
        $sitemeta_table = $new_prefix . 'sitemeta';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from validated prefix
        $sitemeta_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($sitemeta_table)));
        if ($sitemeta_exists) {
            $sitemeta_count = $wpdb->query($wpdb->prepare(
                "UPDATE `{$sitemeta_table}` SET `meta_key` = CONCAT(%s, SUBSTRING(`meta_key`, %d)) WHERE `meta_key` LIKE %s",
                $new_prefix,
                $old_len + 1,
                $old_prefix_like . '%'
            ));
            $results['sitemeta_updated'] = $sitemeta_count !== false ? $sitemeta_count : 0;
            $results['sitemeta_error'] = $wpdb->last_error ?: null;
        }

        // 4. Verify the fix worked — check for any remaining old-prefix keys
        $remaining_usermeta = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$usermeta_table}` WHERE `meta_key` LIKE %s",
            $old_prefix_like . '%'
        ));
        $remaining_options = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$options_table}` WHERE `option_name` LIKE %s",
            $old_prefix_like . '%'
        ));

        $has_errors = !empty($results['usermeta_error']) || !empty($results['options_error']) || !empty((isset($results['sitemeta_error']) ? $results['sitemeta_error'] : null));
        $results['success'] = !$has_errors;
        $results['remaining_old_prefix_usermeta'] = (int) $remaining_usermeta;
        $results['remaining_old_prefix_options'] = (int) $remaining_options;

        if ($remaining_usermeta > 0 || $remaining_options > 0) {
            $results['warning'] = 'Some entries still have the old prefix — this may indicate nested or overlapping prefixes.';
        }

        return rest_ensure_response($results);
    }

    // ─── Route: File Upload ──────────────────────────────────────

    public function route_files_upload(WP_REST_Request $request) {
        @set_time_limit(300);

        $wp_filesystem = $this->init_filesystem();
        $files = $request->get_file_params();
        $dest_path = $request->get_param('path');

        if (empty($files['file']['tmp_name'])) {
            return new WP_Error('no_file', 'No file uploaded. Send as multipart with field name "file".', ['status' => 400]);
        }

        // Default destination: temp dir (within uploads)
        if (!$dest_path) {
            $dest_path = INSTA_MIGRATE_TEMP_DIR . '/' . ((isset($files['file']['name']) ? $files['file']['name'] : 'upload-' . time()));
        }

        // Validate destination is within the uploads directory
        $uploads_basedir = wp_upload_dir()['basedir'];
        $parent = dirname($dest_path);
        if (!file_exists($parent)) {
            wp_mkdir_p($parent);
        }
        $real_parent = realpath($parent);
        $real_uploads = realpath($uploads_basedir);
        if ($real_parent === false || $real_uploads === false || strpos($real_parent, $real_uploads) !== 0) {
            return new WP_Error('invalid_path', 'Upload destination must be within the uploads directory.', ['status' => 400]);
        }

        // Copy uploaded file to destination via WP_Filesystem, then remove temp.
        if ($wp_filesystem) {
            $wp_filesystem->copy($files['file']['tmp_name'], $dest_path, true);
            $wp_filesystem->chmod($dest_path, FS_CHMOD_FILE);
        }

        if (!file_exists($dest_path)) {
            return new WP_Error('move_failed', "Failed to move uploaded file to: $dest_path", ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'path'    => $dest_path,
            'size'    => filesize($dest_path),
        ]);
    }

    // ─── Route: File Extract ─────────────────────────────────────

    public function route_files_extract(WP_REST_Request $request) {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $archive_path = $request->get_param('archive_path');
        // `extract_to` accepted as an alias: callers reached for that name, and an unrecognised
        // param used to fall through to the ABSPATH default below — silently unpacking into the
        // webroot instead of erroring.
        $dest_dir = $request->get_param('dest_dir');
        if (!$dest_dir) {
            $dest_dir = $request->get_param('extract_to');
        }

        // Pull the archive from a remote source URL (server-to-server, SSRF-guarded) when no
        // local archive_path is given. archive_name preserves the extension so the format
        // dispatch below works; basename() blocks path traversal.
        $pulled = null;
        if (!$archive_path && ($src = $request->get_param('source_url'))) {
            $name = basename($request->get_param('archive_name') ?: ('pull-' . time() . '.tar.gz'));
            $archive_path = INSTA_MIGRATE_TEMP_DIR . '/' . $name;
            $r = $this->fetch_url_to_temp($src, $archive_path);
            if (is_wp_error($r)) { return $r; }
            $pulled = $archive_path;
        }

        if (!$archive_path || !file_exists($archive_path)) {
            return new WP_Error('no_archive', "Archive not found: $archive_path", ['status' => 400]);
        }

        // Default to wp-content, NOT the webroot. The old ABSPATH fallback meant a wp-content
        // archive unpacked over the site root — potentially across wp-config.php, index.php and
        // .htaccess — while reporting success. wp-content is what every real caller targets, so
        // this keeps callers that omit the destination working AND removes the dangerous outcome.
        //
        // WP_CONTENT_DIR, never ABSPATH . '/wp-content': WordPress allows wp-content to be renamed
        // or relocated, and the constructed path would be wrong on those installs (see
        // kb/known-issues.md "Non-Standard WP_CONTENT_DIR").
        $dest_defaulted = false;
        if (!$dest_dir) {
            $dest_dir = $this->content_dir();
            $dest_defaulted = true;
        }

        // Keep extraction inside the install. Both roots are allowed because WP_CONTENT_DIR can sit
        // OUTSIDE ABSPATH on a relocated-wp-content install — confining to ABSPATH alone would
        // reject the legitimate default above. realpath() resolves against the nearest existing
        // ancestor, since the destination may not exist yet (created just below).
        $allowed_roots = array_values(array_filter(array_unique([
            realpath(ABSPATH),
            realpath($this->content_dir()),
        ])));
        $probe = $dest_dir;
        $hops  = 0;
        while (!file_exists($probe) && $hops < 64) {
            $parent = dirname($probe);
            if ($parent === $probe) break;
            $probe = $parent;
            $hops++;
        }
        $probe_real = realpath($probe);
        $inside = false;
        foreach ($allowed_roots as $root) {
            if ($probe_real !== false && strpos($probe_real, $root) === 0) { $inside = true; break; }
        }
        if (!$inside) {
            return new WP_Error(
                'invalid_path',
                'Extraction destination must be inside the WordPress installation or wp-content.',
                ['status' => 400]
            );
        }

        if (!file_exists($dest_dir)) {
            wp_mkdir_p($dest_dir);
        }

        $ext = strtolower(pathinfo($archive_path, PATHINFO_EXTENSION));
        $filename = basename($archive_path);

        // Detect tar.gz / tgz
        if ($ext === 'gz' || $ext === 'tgz' || strpos($filename, '.tar.gz') !== false) {
            $result = $this->extract_tar_gz($archive_path, $dest_dir); $vext = 'tar';
        } elseif ($ext === 'zip') {
            $result = $this->extract_zip($archive_path, $dest_dir); $vext = 'zip';
        } elseif ($ext === 'tar') {
            $result = $this->extract_tar($archive_path, $dest_dir); $vext = 'tar';
        } else {
            if ($pulled) { wp_delete_file($pulled); }
            return new WP_Error('unknown_format', "Unknown archive format: $ext", ['status' => 400]);
        }

        // Optional post-extract verification (v1.7.6): count archive entries missing under
        // dest so a silent truncation surfaces as complete:false instead of a false success.
        if ($request->get_param('verify') && !is_wp_error($result)) {
            $v = $this->verify_extracted($archive_path, $dest_dir, $vext);
            if ($v) {
                $data = $result->get_data();
                $data['archive_entries'] = $v['archive_entries'];
                $data['missing']  = $v['missing'];
                $data['complete'] = ($v['missing'] === null) ? true : ($v['missing'] === 0);
                $result->set_data($data);
            }
        }

        // Tell the caller when no destination was supplied and wp-content was used. The response
        // already carries `dest`, but an explicit flag means a caller that mistyped the parameter
        // name can detect it programmatically instead of comparing paths.
        if ($dest_defaulted && !is_wp_error($result)) {
            $data = $result->get_data();
            $data['dest_defaulted'] = true;
            $result->set_data($data);
        }

        if ($pulled) { wp_delete_file($pulled); }
        return $result;
    }

    private function extract_tar_gz($archive, $dest) {
        try {
            $phar = new PharData($archive);
            $phar->extractTo($dest, null, true);
            return rest_ensure_response([
                'success' => true,
                'method'  => 'phar',
                'dest'    => $dest,
            ]);
        } catch (Exception $e) {
            return new WP_Error('extract_failed', 'Extraction failed: ' . $e->getMessage(), ['status' => 500]);
        }
    }

    private function extract_zip($archive, $dest) {
        $zip = new ZipArchive();
        if ($zip->open($archive) === true) {
            $zip->extractTo($dest);
            $count = $zip->numFiles;
            $zip->close();
            return rest_ensure_response([
                'success' => true,
                'method'  => 'ziparchive',
                'files'   => $count,
                'dest'    => $dest,
            ]);
        }

        return new WP_Error('extract_failed', 'Failed to open zip archive', ['status' => 500]);
    }

    private function extract_tar($archive, $dest) {
        try {
            $phar = new PharData($archive);
            $phar->extractTo($dest, null, true);
            return rest_ensure_response([
                'success' => true,
                'method'  => 'phar',
                'dest'    => $dest,
            ]);
        } catch (Exception $e) {
            return new WP_Error('extract_failed', 'Extraction failed: ' . $e->getMessage(), ['status' => 500]);
        }
    }

    // ─── Route: Mkdir ────────────────────────────────────────────

    public function route_files_mkdir(WP_REST_Request $request) {
        $path = $request->get_param('path');
        if (!$path) {
            return new WP_Error('no_path', 'Provide a "path" parameter.', ['status' => 400]);
        }

        // Confine to wp-content, mirroring every sibling path endpoint (/files/upload,
        // /files/concat and /files/search-replace all validate; this one did not, so an
        // authenticated caller could create a directory anywhere the PHP user can write).
        //
        // A RELATIVE path is resolved against wp-content rather than refused. Left alone it would
        // resolve against the request's CWD, which is undefined — so anchoring it is a fix, not a
        // new restriction, and it keeps any existing caller that passes a relative path working.
        // The only behaviour that changes is the one worth changing: a path pointing OUTSIDE
        // wp-content is refused.
        if (strpos($path, '/') !== 0 && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            $path = rtrim(WP_CONTENT_DIR, '/') . '/' . ltrim($path, '/');
        }

        // realpath() on the target itself always fails — it does not exist yet — so walk up to the
        // nearest ANCESTOR that does exist and check that instead. The loop is bounded because
        // dirname() reaches a fixed point ("/" or ".") which always exists.
        $content_real = realpath(WP_CONTENT_DIR);
        $probe        = $path;
        $hops         = 0;
        while (!file_exists($probe) && $hops < 64) {
            $parent = dirname($probe);
            if ($parent === $probe) break;
            $probe = $parent;
            $hops++;
        }
        $real_probe = realpath($probe);
        if ($content_real === false || $real_probe === false || strpos($real_probe, $content_real) !== 0) {
            return new WP_Error(
                'invalid_path',
                'Directory must be within wp-content.',
                ['status' => 400]
            );
        }

        $result = wp_mkdir_p($path);
        return rest_ensure_response([
            'success' => $result,
            'path'    => $path,
            'exists'  => file_exists($path),
        ]);
    }

    // ─── Route: Search-Replace ───────────────────────────────────

    /** @var bool Hold email-shaped runs out of the replace (set per request; bare-domain search only). */
    private $sr_guard_emails = false;

    /** @var int How many email occurrences the guard held back during this request. */
    private $sr_emails_protected = 0;

    /** @var int How many values the guard refused to touch because PCRE bailed. */
    private $sr_guard_bailouts = 0;

    /** @var string Per-request sentinel prefix, so a literal token already in a blob can't collide. */
    private $sr_token_prefix = "\x02IMEG\x03";

    public function route_search_replace(WP_REST_Request $request) {
        global $wpdb;

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $search = $request->get_param('search');
        $replace = $request->get_param('replace');
        $dry_run = (bool) $request->get_param('dry_run');
        $tables = $request->get_param('tables'); // null = all WP tables

        if (!$search || !$replace) {
            return new WP_Error('missing_params', 'Provide "search" and "replace" parameters.', ['status' => 400]);
        }

        // Bare-domain email guard (v1.8.5). A scheme-less search term ("old.com") matches the
        // domain half of an EMAIL ADDRESS exactly as readily as it matches a URL — the leaf
        // replace is a plain str_replace with no URL awareness — so a bare pair silently rewrites
        // contact, admin and payment-gateway addresses. This has shipped on real migrations
        // (cityboycoffee.com left matt@<dest-host> in wcpay_account_data; retireesforjustice.org
        // was caught only because the rewritten address happened to render as visible page text).
        //
        // So when `search` is a bare host, email-shaped runs that contain it are held out of the
        // replace and restored afterwards. URLs, prose and serialized blobs are unaffected.
        // Pass rewrite_emails=true to opt out — for a genuine permanent domain change that should
        // carry the mailboxes with it. The route declares no args schema, so the guarded branch
        // has to be the one a caller lands on by omission; filter_var (not a bare cast) so the
        // string "false" opts IN to the guard rather than out of it.
        $this->sr_guard_emails = !filter_var($request->get_param('rewrite_emails'), FILTER_VALIDATE_BOOLEAN)
            && $this->is_bare_host($search);
        $this->sr_emails_protected = 0;
        $this->sr_guard_bailouts = 0;
        // Fresh sentinel per request: a stored blob could in principle already contain a fixed
        // token, and restoring would then overwrite it. A nonce makes that unreachable.
        $this->sr_token_prefix = "\x02IM" . bin2hex(random_bytes(4)) . '-';

        // Get tables to process
        if (!$tables) {
            $tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($wpdb->prefix) . '%'));
        }

        $total_changes = 0;
        $table_results = [];
        // Which key columns we resolved per table (audit trail in the response) and which tables
        // we had to skip for having no primary key at all — previously an invisible `continue`.
        $tables_keys     = [];
        $skipped_tables  = [];

        foreach ($tables as $table) {
            // Table name comes from SHOW TABLES (trusted DB metadata).
            $safe_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $columns = $wpdb->get_results("DESCRIBE `$safe_table_name`", ARRAY_A);
            if (!$columns) continue;

            // Resolve the FULL primary key — every column DESCRIBE marks PRI, not just the last
            // one seen. Keeping only one column is a data-corruption bug on composite-PK schemas:
            // WP Engine ships postmeta as PRIMARY KEY (post_id, meta_key, meta_id), so DESCRIBE
            // marks all three PRI and a single-column where-clause degenerates to
            // `WHERE meta_key = '_menu_item_url'` — broadcasting one row's rewritten value over
            // every row sharing that meta_key, silently. Standard WP (PRIMARY KEY (meta_id)) is
            // unaffected and behaves exactly as before.
            $pk_columns   = [];   // safe column name => $wpdb placeholder format
            $text_columns = [];
            foreach ($columns as $col) {
                if ($col['Key'] === 'PRI') {
                    $safe_pk_col = preg_replace('/[^a-zA-Z0-9_]/', '', $col['Field']);
                    // Derive the format from the DECLARED COLUMN TYPE, never from the value:
                    // is_numeric() misreads a numeric-looking string key (a meta_key of "123",
                    // a slug of "2024") as an integer and can mismatch the row.
                    $pk_columns[$safe_pk_col] = preg_match(
                        '/^\s*(tinyint|smallint|mediumint|int|integer|bigint|year|bit)\b/i',
                        $col['Type']
                    ) ? '%d' : '%s';
                }
                // Only process text-like columns
                if (preg_match('/(char|text|blob|binary|enum|set)/i', $col['Type'])) {
                    $text_columns[] = $col['Field'];
                }
            }

            if (empty($pk_columns)) {
                // No primary key at all — we cannot safely address a row. Skip as before, but
                // report it so a silently-skipped table is visible rather than invisible.
                $skipped_tables[] = $table;
                continue;
            }
            if (empty($text_columns)) continue;

            $table_changes = 0;

            // Sanitize identifiers — these come from DESCRIBE which returns actual DB column names.
            $safe_table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $tables_keys[$table] = array_keys($pk_columns);

            // Check each text column for matches
            foreach ($text_columns as $column) {
                $safe_col = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
                // Select EVERY primary-key column alongside the text column, so each row can be
                // addressed by its full identity. The text column may itself be part of the key
                // (postmeta.meta_key on the composite-PK schema) — don't select it twice.
                $select_cols = array_keys($pk_columns);
                if (!in_array($safe_col, $select_cols, true)) {
                    $select_cols[] = $safe_col;
                }
                $select_list = '`' . implode('`, `', $select_cols) . '`';

                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT {$select_list} FROM `{$safe_table}` WHERE `{$safe_col}` LIKE %s",
                        '%' . $wpdb->esc_like($search) . '%'
                    ),
                    ARRAY_A
                );

                foreach ($rows as $row) {
                    $old_value = $row[$safe_col];
                    $new_value = $this->do_search_replace($search, $replace, $old_value);

                    if ($old_value !== $new_value) {
                        $table_changes++;
                        if (!$dry_run) {
                            // Where-clause built from ALL key columns, each with the format
                            // derived from its declared type. $wpdb matches $where_format
                            // positionally against $where, and PHP preserves insertion order.
                            $where        = [];
                            $where_format = [];
                            foreach ($pk_columns as $pk_col => $pk_fmt) {
                                $where[$pk_col] = $row[$pk_col];
                                $where_format[] = $pk_fmt;
                            }

                            $affected = $wpdb->update(
                                $safe_table,
                                [$safe_col => $new_value],
                                $where,
                                ['%s'],
                                $where_format
                            );

                            // Invariant: a fully-specified primary key addresses exactly one row.
                            // More than one means the resolved key is not unique — the very
                            // condition that silently corrupted data before. Fail loudly instead.
                            if ($affected !== false && $affected > 1) {
                                return new WP_Error(
                                    'search_replace_ambiguous_key',
                                    sprintf(
                                        'Aborted: an update on `%s`.`%s` matched %d rows using key (%s), which must address exactly one. '
                                        . 'The resolved primary key is not unique — no further rows were changed. '
                                        . '%d change(s) had already been applied before this abort.',
                                        $safe_table,
                                        $safe_col,
                                        (int) $affected,
                                        implode(', ', array_keys($pk_columns)),
                                        $total_changes + $table_changes
                                    ),
                                    ['status' => 500]
                                );
                            }
                        }
                    }
                }
            }

            if ($table_changes > 0) {
                $table_results[$table] = $table_changes;
                $total_changes += $table_changes;
            }
        }

        return rest_ensure_response([
            'success'       => true,
            'dry_run'       => $dry_run,
            'search'        => $search,
            'replace'       => $replace,
            'total_changes' => $total_changes,
            'tables'        => $table_results,
            // Key columns actually used per table — makes a composite-PK schema auditable
            // from the migration log instead of only visible as corrupted data later.
            'tables_keys'   => $tables_keys,
            // Tables skipped for having no primary key (previously an invisible `continue`).
            'skipped_tables' => $skipped_tables,
            // Bare-domain email guard: whether it applied to this request, how many addresses
            // it held out of the replace, and how many values it refused to touch (PCRE bail).
            'email_guard'      => $this->sr_guard_emails,
            'emails_protected' => $this->sr_emails_protected,
            'guard_bailouts'   => $this->sr_guard_bailouts,
        ]);
    }

    /**
     * True when $s could be the domain half of an email address: at least one dot, and no
     * scheme, path, '@' or whitespace. "old.com" / "www.old.com" / "bücher.com" => true.
     * "https://old.com", "old.com/x", "a@old.com", "localhost", "old.com Inc" => false.
     *
     * Deliberately STRUCTURAL rather than a character-class hostname pattern: a class like
     * [A-Za-z0-9-] silently excludes internationalised domains, which would turn the guard OFF
     * for exactly the terms nobody thinks to test. Whitespace is the useful discriminator — a
     * term containing a space cannot be an email's domain half, so it needs no guard.
     */
    private function is_bare_host($s) {
        $s = (string) $s;
        if ($s === '' || strpos($s, '://') !== false || strpos($s, '/') !== false
            || strpos($s, '@') !== false || preg_match('/\s/', $s)) {
            return false;
        }
        return strpos($s, '.') !== false;
    }

    /**
     * The leaf replace. Identical to str_replace() unless the bare-domain email guard is on for
     * this request, in which case email-shaped runs containing the search term are held out of
     * the replace and put back afterwards.
     */
    private function sr_replace($search, $replace, $string) {
        $str = (string) $string;
        // Three spellings of '@' reach a stored address, and all three must be recognised or the
        // fast path below hands the value to a blind str_replace:
        //   '@'      plain
        //   '%40'    percent-encoded — WooCommerce order meta, form-plugin entries, gateway
        //            callback/return URLs, i.e. the wcpay neighbourhood this guard exists for
        //   '&#64;'  HTML entity — WP core's own antispambot() output, and what several builders
        //            and perf plugins store for obfuscated addresses ('&#x40;' is the hex form)
        // Double-encoded spellings (%2540) are deliberately out of scope.
        if (!$this->sr_guard_emails
            || (strpos($str, '@') === false && strpos($str, '%40') === false
                && stripos($str, '&#64;') === false && stripos($str, '&#x40;') === false)) {
            return str_replace($search, $replace, $string);
        }

        $held = [];
        $prefix = $this->sr_token_prefix;
        $masked = preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+(?:@|%40|&#64;|&#x40;)[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/i',
            function ($m) use (&$held, $search, $prefix) {
                // An address that doesn't contain the search term can't be touched anyway —
                // leave it inline so the replace still sees the surrounding text unchanged.
                if (strpos($m[0], $search) === false) {
                    return $m[0];
                }
                // The token carries no '.', and is_bare_host() guarantees the search term has
                // one, so the token can never itself be matched by the replace. The trailing
                // \x03 stops IMEG1 aliasing the front of IMEG10 during the strtr restore.
                $token = $prefix . count($held) . "\x03";
                $held[$token] = $m[0];
                return $token;
            },
            $str
        );

        // PCRE bailed (backtrack/recursion limit, or a pathological value). Fail CLOSED — leave
        // the value alone rather than run an unguarded replace over it. Leftover source-domain
        // residue is caught by the mandatory post-search-replace zero-residue audit; a rewritten
        // mailbox is caught by nothing. Counted, because this drops the value's NON-email
        // replacements too — without the counter the residue is unattributable.
        if ($masked === null) {
            $this->sr_guard_bailouts++;
            return $string;
        }

        if (!$held) {
            return str_replace($search, $replace, $masked);
        }

        $this->sr_emails_protected += count($held);
        return strtr(str_replace($search, $replace, $masked), $held);
    }

    /**
     * Search-replace that correctly handles serialized PHP data.
     */
    private function do_search_replace($search, $replace, $data) {
        // Try to unserialize
        $unserialized = @unserialize($data);
        if ($unserialized !== false || $data === 'b:0;') {
            // It's serialized data — recurse into it
            $replaced = $this->search_replace_recursive($search, $replace, $unserialized);
            return serialize($replaced);
        }

        // Try JSON
        $json = json_decode($data, true);
        if ($json !== null && (is_array($json) || is_object($json))) {
            $replaced = $this->search_replace_recursive($search, $replace, $json);
            return wp_json_encode($replaced);
        }

        // Plain string
        return $this->sr_replace($search, $replace, $data);
    }

    private function search_replace_recursive($search, $replace, $data) {
        if (is_string($data)) {
            return $this->sr_replace($search, $replace, $data);
        }
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $new_key = is_string($key) ? $this->sr_replace($search, $replace, $key) : $key;
                $result[$new_key] = $this->search_replace_recursive($search, $replace, $value);
            }
            return $result;
        }
        if (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->$key = $this->search_replace_recursive($search, $replace, $value);
            }
            return $data;
        }
        return $data;
    }

    // ─── Route: DB Export ───────────────────────────────────────

    public function route_db_export(WP_REST_Request $request) {
        global $wpdb;

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $gzip = (bool) $request->get_param('gzip');
        $tables_param = $request->get_param('tables'); // comma-separated or null for all

        // Get list of tables
        if ($tables_param) {
            $tables = array_map('trim', explode(',', $tables_param));
        } else {
            $tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($wpdb->prefix) . '%'));
        }

        if (empty($tables)) {
            return new WP_Error('no_tables', 'No tables found to export.', ['status' => 404]);
        }

        // Opt-in (v1.7.6): skip the DATA of regenerable/log tables to shrink huge dumps. We
        // still emit DROP + CREATE for them (so plugins that don't self-create won't fatal) —
        // only the rows are skipped. Enabled via skip_regenerable=1 (built-in suffix list) and/or
        // an explicit ignore_tables CSV. Default off → byte-identical to a normal export.
        $skip_set = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $request->get_param('ignore_tables')))) as $t) {
            // Accept suffix-only entries (e.g. "commentmeta") by prefixing — match keys are the
            // full prefixed table names from SHOW TABLES, so a bare suffix would otherwise no-op.
            if (strpos($t, $wpdb->prefix) !== 0) { $t = $wpdb->prefix . $t; }
            $skip_set[$t] = true;
        }
        $skipped_data = [];
        if ((bool) $request->get_param('skip_regenerable')) {
            $patterns = [
                '/^actionscheduler_/',  // Action Scheduler queue + logs (regenerated on demand)
                // Wordfence: ALLOWLIST of genuinely regenerable scan/traffic/log tables ONLY —
                // never wfconfig (license + firewall config) or wfls_* (Login Security config +
                // per-user 2FA secrets), which are not logs and would silently break the site.
                '/^wf(hits|crawlers|scanners|knownfilelist|filemods|reversecache|status|throttle|ips|locs|logins|notifications|issues|livetraffichuman|securityevents|snipcache|blockediplog|blocks7?)$/',
                '/^defender_/',         // WP Defender audit/lockout/scan logs
                '/^wpmailsmtp_/',       // WP Mail SMTP email log
                '/^redirection_404$/',  // Redirection 404 hit log
                '/^e_events$/',         // Elementor telemetry ONLY — never e_submissions* (form leads)
            ];
            foreach ($tables as $t) {
                $suffix = preg_replace('/^' . preg_quote($wpdb->prefix, '/') . '/', '', $t);
                foreach ($patterns as $re) {
                    if (preg_match($re, $suffix)) { $skip_set[$t] = true; break; }
                }
            }
        }

        // Build SQL dump — streaming write required for large databases (100MB+).
        // Unique suffix (see route_files_archive): two exports in the same second must not collide on
        // one filename, or a CDN can serve a stale dump for the second request (e.g. a full dump for an
        // ignore_tables=... request). Matters for the skip-tables UI's preview-vs-run pairs.
        $dump_file = INSTA_MIGRATE_TEMP_DIR . '/db-export-' . time() . '-' . substr(md5(uniqid('', true)), 0, 8) . '.sql';
        if (!file_exists(INSTA_MIGRATE_TEMP_DIR)) {
            wp_mkdir_p(INSTA_MIGRATE_TEMP_DIR);
        }

        // Open the output handle. When gzip is requested we compress AS WE WRITE (gzopen,
        // level 1) straight to the .gz — a single pass. The previous code wrote a full
        // uncompressed .sql, then re-read 100% of it and gzipped at level 9; that second
        // full pass plus max-level zlib was the dominant CPU cost on large DBs and routinely
        // blew the ~60s edge timeout (HTTP 524). Level 1 is intentional: SQL/serialized data
        // compresses poorly past it, and the import side auto-detects gzip by magic bytes, so
        // the level is invisible downstream. A plain .sql is still produced when gzip=0
        // (bridge/import paths that consume a local file_path).
        $write = null;
        if ($gzip) {
            $final_file = $dump_file . '.gz';
            // phpcs:ignore WordPress.WP.AlternativeFunctions -- Streaming gzip write of SQL dump; holding the whole dump in memory is not viable.
            $gz = gzopen($final_file, 'wb1');
            if (!$gz) {
                return new WP_Error('write_error', 'Cannot create gzip dump file.', ['status' => 500]);
            }
            $write = function ($s) use ($gz) { gzwrite($gz, $s); };
        } else {
            $final_file = $dump_file;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming write of SQL dump; WP_Filesystem->put_contents() would require holding entire dump in memory.
            $fh = fopen($dump_file, 'w');
            if (!$fh) {
                return new WP_Error('write_error', 'Cannot create dump file.', ['status' => 500]);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming write to SQL dump file; see fopen comment above.
            $write = function ($s) use ($fh) { fwrite($fh, $s); };
        }

        $write("-- InstaMigrate DB Export v2 (multi-row INSERT)\n");
        $write("-- Date: " . gmdate('Y-m-d H:i:s') . " UTC\n");
        $write("-- Site: " . get_site_url() . "\n\n");
        $write("SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        $write("SET foreign_key_checks = 0;\n");
        $write("SET unique_checks = 0;\n");
        $write("SET autocommit = 0;\n");
        $write("SET NAMES utf8mb4;\n\n");

        // Max bytes per multi-row INSERT statement (keep under max_allowed_packet, typically 16MB+)
        $max_insert_bytes = 1048576; // 1MB per statement — safe default

        foreach ($tables as $table) {
            // Sanitize table name (from SHOW TABLES — trusted DB metadata).
            $safe_table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

            // Get CREATE TABLE statement
            $create = $wpdb->get_row("SHOW CREATE TABLE `$safe_table`", ARRAY_A);
            if (!$create) continue;

            $create_sql = (isset($create['Create Table']) ? $create['Create Table'] : (isset($create['Create View']) ? $create['Create View'] : null));
            if (!$create_sql) continue;

            $write("DROP TABLE IF EXISTS `$safe_table`;\n");
            $write($create_sql . ";\n\n");

            // Regenerable/ignored table: keep the (empty) schema, skip the rows.
            if (isset($skip_set[$table])) {
                $write("-- data skipped (regenerable/ignored table)\n\n");
                $skipped_data[] = $safe_table;
                continue;
            }

            // Get column types to distinguish numeric vs text columns.
            // Numeric columns must NOT be hex-encoded because MySQL interprets
            // 0x31 as integer 49, not as string "1" → integer 1.
            // Text/blob columns use hex encoding for binary-safe export.
            $columns_info = $wpdb->get_results("SHOW COLUMNS FROM `$safe_table`", ARRAY_A);
            $numeric_columns = [];
            if ($columns_info) {
                foreach ($columns_info as $col) {
                    $type = strtolower($col['Type']);
                    if (preg_match('/^(tiny|small|medium|big)?int|^decimal|^float|^double|^numeric|^bit/', $type)) {
                        $numeric_columns[$col['Field']] = true;
                    }
                }
            }

            // Export data using multi-row INSERT for 10-50x faster imports.
            // Batches rows into: INSERT INTO `t` (...) VALUES (...),(...),(...);
            // Flushes when accumulated statement exceeds $max_insert_bytes.
            $offset = 0;
            $batch_size = 500;
            $columns_sql = null;
            $insert_prefix = null;
            $pending_values = [];
            $pending_bytes = 0;

            while (true) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `$safe_table` LIMIT %d OFFSET %d", $batch_size, $offset),
                    ARRAY_A
                );
                if (empty($rows)) break;

                // Build column list once from first row
                if ($columns_sql === null) {
                    $columns_sql = implode(', ', array_map(function($c) { return "`$c`"; }, array_keys($rows[0])));
                    $insert_prefix = "INSERT INTO `$safe_table` ($columns_sql) VALUES\n";
                }

                foreach ($rows as $row) {
                    $values = [];
                    foreach ($row as $column => $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } elseif (isset($numeric_columns[$column])) {
                            $values[] = $value;
                        } else {
                            if ($value === '') {
                                $values[] = "''";
                            } else {
                                $values[] = "0x" . bin2hex($value);
                            }
                        }
                    }
                    $row_sql = '(' . implode(', ', $values) . ')';
                    $row_bytes = strlen($row_sql);

                    // Flush if adding this row would exceed max statement size
                    if (!empty($pending_values) && ($pending_bytes + $row_bytes + 2) > $max_insert_bytes) {
                        $write($insert_prefix . implode(",\n", $pending_values) . ";\n");
                        $pending_values = [];
                        $pending_bytes = 0;
                    }

                    $pending_values[] = $row_sql;
                    $pending_bytes += $row_bytes + 2; // +2 for ",\n"
                }
                $offset += $batch_size;
            }

            // Flush remaining rows
            if (!empty($pending_values)) {
                $write($insert_prefix . implode(",\n", $pending_values) . ";\n");
            }
            $write("\n");
        }

        $write("COMMIT;\n");
        $write("SET unique_checks = 1;\n");
        $write("SET foreign_key_checks = 1;\n");
        $write("SET autocommit = 1;\n");

        // Close the handle opened above. The dump is already in its final form — .gz when
        // gzip was requested (compressed inline, single pass), or plain .sql otherwise — so
        // there is no second read-and-compress pass and no temporary .sql to clean up.
        if ($gzip) {
            gzclose($gz);
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($fh);
        }

        return rest_ensure_response([
            'success'    => true,
            'file'       => $final_file,
            'size'       => filesize($final_file),
            'tables'     => count($tables),
            'gzipped'    => $gzip,
            'skipped_data_tables' => $skipped_data,
        ]);
    }

    // ─── Route: Files Archive ───────────────────────────────────

    /**
     * Normalize the /files/archive `exclude` param into a clean list of wp-content-relative
     * path/glob patterns. Accepts a JSON array or a comma-separated string; trims, strips
     * surrounding slashes, drops empties + duplicates. Returns [] when nothing to exclude.
     */
    private function normalize_exclude($raw) {
        if (is_string($raw)) { $raw = explode(',', $raw); }
        if (!is_array($raw)) { return []; }
        $out = [];
        foreach ($raw as $p) {
            if (!is_string($p)) { continue; }
            $p = trim(trim($p), '/');
            if ($p !== '') { $out[$p] = true; }
        }
        return array_keys($out);
    }

    /**
     * Is this archive entry path (e.g. "uploads/2018/x.jpg", or a dir "plugins/foo") excluded?
     * A pattern matches when it equals the entry, is a parent directory of it, or matches it as a
     * glob (fnmatch). Used to prune both files and whole subtrees from /files/archive output.
     */
    private function is_excluded($entry, $exclude) {
        if (empty($exclude)) { return false; }
        foreach ($exclude as $p) {
            if ($entry === $p) { return true; }
            if (strpos($entry, $p . '/') === 0) { return true; } // entry is under directory $p
            if (fnmatch($p, $entry)) { return true; }            // glob (e.g. *.log, uploads/*/cache)
        }
        return false;
    }

    public function route_files_archive(WP_REST_Request $request) {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $source_dir = $request->get_param('source_dir');
        $format = $request->get_param('format') ?: 'tar.gz'; // tar.gz or zip
        // Optional exclude list (skip-files feature): wp-content-relative paths/globs to OMIT from the
        // archive. Accepts a JSON array or CSV string; matched against each entry's archive path in
        // is_excluded() (a matched directory prunes its whole subtree). Empty/absent → byte-identical
        // to a normal archive, so existing callers are unaffected.
        $exclude = $this->normalize_exclude($request->get_param('exclude'));

        if (!$source_dir || !is_dir($source_dir)) {
            return new WP_Error('no_source', "Source directory not found: $source_dir", ['status' => 400]);
        }

        if (!file_exists(INSTA_MIGRATE_TEMP_DIR)) {
            wp_mkdir_p(INSTA_MIGRATE_TEMP_DIR);
        }

        $basename = basename($source_dir);
        // Unique suffix (not just time()) so two archives of the SAME dir in the SAME second can't
        // collide on one filename — that yields a single /files/download URL a CDN may cache, then
        // serve a stale archive (e.g. a no-exclude build for an exclude request). The skip-files UI
        // makes such rapid same-dir pairs likely (preview vs. real run).
        $archive_name = $basename . '-' . time() . '-' . substr(md5(uniqid('', true)), 0, 8);

        if ($format === 'zip') {
            $archive_path = INSTA_MIGRATE_TEMP_DIR . "/$archive_name.zip";
            $zip = new ZipArchive();
            if ($zip->open($archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return new WP_Error('archive_failed', 'Cannot create zip archive.', ['status' => 500]);
            }
            $this->zip_add_dir($zip, $source_dir, $basename, $exclude);
            $zip->close();
        } else {
            $archive_path = INSTA_MIGRATE_TEMP_DIR . "/$archive_name.tar.gz";
            // PRIMARY: stream a ustar tar straight into a gzip sink — constant memory, single
            // pass. The old PharData buildFromDirectory()+compress() built the whole tar in
            // memory then re-read it to gzip (two full passes under memory_limit), which OOM'd
            // (a PHP fatal — uncatchable here) on large uploads/plugins dirs → hard 500.
            $long_paths = [];
            $stream_ok  = $this->stream_tar_gz($source_dir, $basename, $archive_path, $long_paths, $exclude);
            if ($stream_ok) {
                $method = 'stream';
            } elseif (!empty($exclude)) {
                // The PharData fallback below cannot honor an exclude list (buildFromDirectory takes
                // the whole tree). Failing loud beats silently shipping files the caller asked to skip
                // — which could be sensitive/large. The streaming writer only fails on a gzopen error,
                // so this is a rare, surfaceable condition.
                return new WP_Error('exclude_unsupported', 'Archive streaming failed and exclude cannot be honored by the fallback path.', ['status' => 500]);
            } else {
                // FALLBACK: old PharData path. Only reached if the streaming writer could not
                // open its output (e.g. gzopen failure) — small dirs still succeed here, so we
                // never regress the case PharData handled.
                $tar_path = INSTA_MIGRATE_TEMP_DIR . "/$archive_name.tar";
                try {
                    $phar = new PharData($tar_path);
                    $phar->buildFromDirectory(dirname($source_dir), '/^' . preg_quote(rtrim($source_dir, '/'), '/') . '/');
                    $phar->compress(Phar::GZ);
                    wp_delete_file($tar_path);
                    $archive_path = $tar_path . '.gz';
                    $method = 'phar';
                } catch (Exception $e) {
                    return new WP_Error('archive_failed', 'Archive creation failed: ' . $e->getMessage(), ['status' => 500]);
                }
            }
        }

        if (!file_exists($archive_path)) {
            return new WP_Error('archive_failed', 'Archive file was not created.', ['status' => 500]);
        }

        $response = [
            'success' => true,
            'file'    => $archive_path,
            'size'    => filesize($archive_path),
            'format'  => $format,
            'method'  => (isset($method) ? $method : 'zip'),
        ];
        if (!empty($exclude)) {
            $response['exclude_applied'] = array_values($exclude);
        }
        // Any entries whose path exceeded ustar's 255-char limit were NOT written to the tar
        // (a malformed/GNU entry would be unextractable by PharData on the dest). Report them so
        // the agent moves them via tar-split-longnames.sh + upload-file-list.sh. Empty = nothing skipped.
        if (!empty($long_paths)) {
            $response['long_paths'] = array_values($long_paths);
        }
        return rest_ensure_response($response);
    }

    /**
     * Stream a gzip-compressed ustar tar of $source_dir into $archive_path, one file at a time.
     * Peak memory ≈ a single 1 MB read buffer + zlib window — independent of file/dir size, and
     * single-pass (no separate compress() step). Entry names are stored relative to the parent of
     * $source_dir (so the archive contains "<basename>/...", matching the old buildFromDirectory).
     *
     * Symlinks are DEREFERENCED (the target's bytes are written) to match the old behavior and to
     * guarantee the content lands on the dest rather than a dangling link; a realpath visited-set
     * guards against symlink loops. Paths longer than ustar's 255-char name+prefix limit are not
     * written; their absolute paths are appended to &$long_paths for the per-file fallback.
     *
     * @return bool true on success; false only if the output gzip sink could not be opened
     *              (caller then falls back to PharData).
     */
    private function stream_tar_gz($source_dir, $basename, $archive_path, &$long_paths, $exclude = []) {
        @set_time_limit(0);
        $source_dir = rtrim($source_dir, '/');
        $gz = gzopen($archive_path, 'wb1'); // level 1: wall-time over ratio (media ~incompressible)
        if ($gz === false) {
            return false;
        }
        $visited = [];
        // Never descend into our own temp dir: when archiving e.g. uploads/, the output file
        // we're writing lives at uploads/instamigrate/<name>.tar.gz, so walking into it would
        // stream the half-written archive into itself → unbounded growth + timeout. (The old
        // `exclude` param was silently ignored; this is the real self-inclusion fix —
        // gotcha_files_archive_exclude_ignored.)
        $skip_real = realpath(INSTA_MIGRATE_TEMP_DIR);
        $this->tar_add_dir_recursive($gz, $source_dir, $basename, $long_paths, $visited, $skip_real, $exclude);
        gzwrite($gz, str_repeat("\0", 1024)); // two zero 512-blocks = end-of-archive marker
        gzclose($gz);
        return true;
    }

    /** Recurse a directory, writing each entry into the open gzip tar handle $gz. */
    private function tar_add_dir_recursive($gz, $dir, $entry_prefix, &$long_paths, &$visited, $skip_real = false, $exclude = []) {
        // Resolve loops: skip a directory whose realpath we've already entered.
        $real = realpath($dir);
        if ($real !== false) {
            if (isset($visited[$real])) {
                return;
            }
            $visited[$real] = true;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        // Directory entry itself (so empty dirs are preserved).
        $this->tar_write_header($gz, $entry_prefix . '/', 0, time(), 0755, '5', $long_paths);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full  = $dir . '/' . $item;
            $entry = $entry_prefix . '/' . $item;
            // Skip our own temp/working dir so the archive never includes itself.
            if ($skip_real !== false && realpath($full) === $skip_real) {
                continue;
            }
            // Caller-requested exclusion (skip-files feature): a matched directory prunes its subtree.
            if ($this->is_excluded($entry, $exclude)) {
                continue;
            }
            // Dereference symlinks to their target (file → stream bytes; dir → recurse).
            $is_dir = is_dir($full); // is_dir/is_file follow symlinks, which is what we want
            if ($is_dir) {
                $this->tar_add_dir_recursive($gz, $full, $entry, $long_paths, $visited, $skip_real, $exclude);
            } elseif (is_file($full)) {
                $this->tar_write_file($gz, $full, $entry, $long_paths);
            }
            // Other types (sockets, fifos, broken symlinks) are skipped — not migratable content.
        }
    }

    /** Write a single regular file: 512-byte header, streamed contents in 1 MB chunks, 512-pad. */
    private function tar_write_file($gz, $path, $entry, &$long_paths) {
        $size  = filesize($path);
        $mtime = filemtime($path);
        $mode  = @fileperms($path) & 0777;
        if ($mode === 0) {
            $mode = 0644;
        }
        if (!$this->tar_write_header($gz, $entry, $size, $mtime, $mode, '0', $long_paths)) {
            return; // long path skipped (recorded in $long_paths) — do NOT write its body
        }
        $in = @fopen($path, 'rb');
        if ($in === false) {
            // Header already written; emit a zero-filled body so the archive stays well-formed.
            $in = null;
        }
        $written = 0;
        if ($in !== null) {
            while (!feof($in)) {
                $buf = fread($in, 1 << 20); // 1 MB
                if ($buf === false || $buf === '') {
                    break;
                }
                gzwrite($gz, $buf);
                $written += strlen($buf);
            }
            fclose($in);
        }
        // Pad the body to a 512-byte boundary (use declared $size, not bytes read).
        if ($written < $size) {
            gzwrite($gz, str_repeat("\0", $size - $written)); // file shrank mid-read; keep size consistent
        }
        $pad = (512 - ($size % 512)) % 512;
        if ($pad) {
            gzwrite($gz, str_repeat("\0", $pad));
        }
    }

    /**
     * Build + write a 512-byte POSIX ustar header. Returns false (and records $entry in
     * &$long_paths) when the path can't fit ustar's name[100]+prefix[155] split (>255 chars or no
     * split point ≤100 in the tail) — the caller then skips the entry rather than emit a
     * GNU/pax record PharData may not extract on the dest.
     */
    private function tar_write_header($gz, $entry, $size, $mtime, $mode, $typeflag, &$long_paths) {
        $name   = $entry;
        $prefix = '';
        if (strlen($name) > 100) {
            // Split on a '/' so the tail (name) is ≤100 and the head (prefix) is ≤155.
            $split = strrpos(substr($name, 0, 101), '/');
            if ($split === false || strlen($name) - $split - 1 > 100 || $split > 155) {
                // Try the largest prefix ≤155 that leaves a name ≤100.
                $cut = strrpos(substr($name, 0, 156), '/');
                if ($cut === false || strlen($name) - $cut - 1 > 100) {
                    $long_paths[] = $entry;
                    return false;
                }
                $split = $cut;
            }
            $prefix = substr($name, 0, $split);
            $name   = substr($name, $split + 1);
            if (strlen($name) > 100 || strlen($prefix) > 155) {
                $long_paths[] = $entry;
                return false;
            }
        }

        $header  = pack('a100', $name);                                  // name
        $header .= pack('a8', sprintf('%07o', $mode & 0777) . "\0");     // mode
        $header .= pack('a8', sprintf('%07o', 0) . "\0");                // uid
        $header .= pack('a8', sprintf('%07o', 0) . "\0");                // gid
        $header .= pack('a12', sprintf('%011o', $size) . "\0");          // size
        $header .= pack('a12', sprintf('%011o', $mtime) . "\0");         // mtime
        $header .= str_repeat(' ', 8);                                   // chksum placeholder (spaces)
        $header .= pack('a1', $typeflag);                                // typeflag
        $header .= pack('a100', '');                                     // linkname
        $header .= pack('a6', 'ustar') . pack('a2', '00');               // magic + version
        $header .= pack('a32', '');                                      // uname
        $header .= pack('a32', '');                                      // gname
        $header .= pack('a8', '');                                       // devmajor
        $header .= pack('a8', '');                                       // devminor
        $header .= pack('a155', $prefix);                                // prefix
        $header .= str_repeat("\0", 12);                                 // pad to 512

        // Checksum = unsigned sum of all 512 header bytes (with chksum field as spaces),
        // written as a 6-digit octal value followed by NUL and a space.
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($header[$i]);
        }
        $chk = sprintf('%06o', $sum) . "\0 ";
        $header = substr($header, 0, 148) . $chk . substr($header, 156);

        gzwrite($gz, $header);
        return true;
    }

    private function zip_add_dir($zip, $dir, $prefix, $exclude = []) {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $dir . '/' . $item;
            $entry = $prefix . '/' . $item;
            // Caller-requested exclusion (skip-files feature): a matched directory prunes its subtree.
            if ($this->is_excluded($entry, $exclude)) continue;
            if (is_dir($full)) {
                $zip->addEmptyDir($entry);
                $this->zip_add_dir($zip, $full, $entry, $exclude);
            } else {
                $zip->addFile($full, $entry);
            }
        }
    }

    // ─── Route: Files List (lazy tree for the skip-files UI) ────

    /**
     * GET /files/list?path=<wp-content-relative>&depth=1 — list the immediate children of a
     * directory inside wp-content, with sizes, so the App/portal can render a "what to skip" tree.
     * Lazy by design (depth 1): the UI fetches one level at a time on expand, since a full recursive
     * walk of a large uploads tree is exactly what we must avoid blocking on.
     *
     * Security: `path` is wp-content-RELATIVE and resolved under WP_CONTENT_DIR with a realpath
     * confinement check, so this can never read outside wp-content (no absolute paths / traversal).
     * File sizes are exact (filesize); directory sizes use core recurse_dirsize() under an overall
     * time budget — a child that can't be measured in time reports size=null + size_pending=true
     * rather than hanging the request. Entry `path` values are themselves wp-content-relative, so the
     * client passes one straight back as `?path=` to drill in, and they match the exclude contract.
     */
    public function route_files_list(WP_REST_Request $request) {
        @set_time_limit(60);

        $rel_param   = (string) $request->get_param('path'); // wp-content-relative; '' = content root
        $content_real = realpath(WP_CONTENT_DIR);
        if ($content_real === false) {
            return new WP_Error('no_content_dir', 'wp-content directory could not be resolved.', ['status' => 500]);
        }
        $target = rtrim($content_real . '/' . ltrim($rel_param, '/'), '/');
        $real   = realpath($target);

        if ($real === false || !is_dir($real)) {
            return new WP_Error('not_found', 'Directory not found: ' . $rel_param, ['status' => 404]);
        }
        // Confine to wp-content (the only tree we migrate). Reject anything resolving outside it.
        if ($real !== $content_real && strpos($real, $content_real . '/') !== 0) {
            return new WP_Error('forbidden', 'Path is outside wp-content.', ['status' => 403]);
        }

        $items = @scandir($real);
        if ($items === false) {
            return new WP_Error('read_error', 'Cannot read directory.', ['status' => 500]);
        }

        if (!function_exists('recurse_dirsize')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $temp_real = realpath(INSTA_MIGRATE_TEMP_DIR);

        // wp-content-relative path of the listed dir ('' at the content root), used to build child paths.
        $rel_dir = ($real === $content_real) ? '' : ltrim(substr($real, strlen($content_real)), '/');

        // Overall budget so a directory full of huge subdirs can't hang the endpoint; children past
        // the budget report size_pending instead of being measured.
        $deadline = microtime(true) + 12.0;
        $entries = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $full = $real . '/' . $item;
            // Never surface our own temp/working dir as migratable content.
            if ($temp_real !== false && realpath($full) === $temp_real) { continue; }
            $rel = ($rel_dir === '') ? $item : $rel_dir . '/' . $item;

            if (is_dir($full)) {
                $size = null; $pending = true;
                $left = (int) ceil($deadline - microtime(true));
                if ($left > 0) {
                    $sz = recurse_dirsize($full, null, $left);
                    if ($sz !== false) { $size = (int) $sz; $pending = false; }
                }
                $entries[] = ['name' => $item, 'type' => 'dir', 'path' => $rel, 'size' => $size, 'size_pending' => $pending];
            } elseif (is_file($full)) {
                $entries[] = ['name' => $item, 'type' => 'file', 'path' => $rel, 'size' => (int) @filesize($full)];
            }
            // Other node types (sockets/fifos/broken symlinks) are not migratable content — skip.
        }

        // Dirs first, then by size desc (unknown sizes last) so the big, skippable items surface on top.
        usort($entries, function ($a, $b) {
            if ($a['type'] !== $b['type']) { return $a['type'] === 'dir' ? -1 : 1; }
            $bs = ($b['size'] === null ? -1 : $b['size']); $as = ($a['size'] === null ? -1 : $a['size']);
            return $bs < $as ? -1 : ($bs > $as ? 1 : 0);
        });

        return rest_ensure_response([
            'success' => true,
            'path'    => $rel_dir,
            'entries' => $entries,
        ]);
    }

    // ─── Route: Files Download ──────────────────────────────────

    public function route_files_download(WP_REST_Request $request) {
        $file_path = $request->get_param('path');

        if (!$file_path || !file_exists($file_path)) {
            return new WP_Error('not_found', "File not found: $file_path", ['status' => 404]);
        }

        if (is_dir($file_path)) {
            return new WP_Error('is_dir', 'Path is a directory. Use /files/archive to create an archive first.', ['status' => 400]);
        }

        $size = filesize($file_path);
        $mime = 'application/octet-stream';
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if ($ext === 'sql') $mime = 'application/sql';
        elseif ($ext === 'gz') $mime = 'application/gzip';
        elseif ($ext === 'zip') $mime = 'application/zip';

        // Stream the file directly
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Content-Length: ' . $size);
        header('X-Insta-Migrate-File: ' . $file_path);

        // Flush WP output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming large file (100MB+) to HTTP client; WP_Filesystem->get_contents() would load entire file into memory.
        readfile($file_path);
        exit;
    }

    // ─── Route: Cleanup ──────────────────────────────────────────

    public function route_cleanup(WP_REST_Request $request) {
        $cleaned = $this->rmdir_recursive(INSTA_MIGRATE_TEMP_DIR);
        wp_mkdir_p(INSTA_MIGRATE_TEMP_DIR);

        return rest_ensure_response([
            'success' => true,
            'message' => 'Temp directory cleaned.',
        ]);
    }

    // ─── File Concat (for chunked uploads) ──────────────────────

    /**
     * Concatenate multiple file parts into a single file.
     * Used after chunked uploads to reassemble large archives.
     *
     * POST /files/concat
     * Body: {"pattern": "/path/to/chunks/file.tar.gz.part-*", "output": "/path/to/file.tar.gz"}
     */
    public function route_files_concat(WP_REST_Request $request) {
        $pattern = $request->get_param('pattern');
        $output  = $request->get_param('output');

        if (!$pattern || !$output) {
            return new WP_Error('missing_params', 'pattern and output are required', ['status' => 400]);
        }

        // Validate paths are within the plugin's temp directory (in uploads)
        if (!file_exists(INSTA_MIGRATE_TEMP_DIR)) {
            wp_mkdir_p(INSTA_MIGRATE_TEMP_DIR);
        }
        $allowed_base = realpath(INSTA_MIGRATE_TEMP_DIR);
        if ($allowed_base === false) {
            return new WP_Error('temp_dir_error', 'Cannot resolve temp directory', ['status' => 500]);
        }
        $real_output_dir = realpath(dirname($output));
        if ($real_output_dir === false || strpos($real_output_dir, $allowed_base) !== 0) {
            return new WP_Error('invalid_path', 'Output path must be within the plugin temp directory (' . INSTA_MIGRATE_TEMP_DIR . ')', ['status' => 400]);
        }

        $parts = glob($pattern);
        if (empty($parts)) {
            return new WP_Error('no_parts', 'No files matched the pattern', ['status' => 404]);
        }

        sort($parts); // Ensure alphabetical order (part-aa, part-ab, etc.)

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming large file concatenation
        $out = fopen($output, 'wb');
        if (!$out) {
            return new WP_Error('write_error', 'Cannot create output file', ['status' => 500]);
        }

        $total_size = 0;
        foreach ($parts as $part) {
            // Validate each part is within the plugin temp directory
            $real_part = realpath($part);
            if ($real_part === false || strpos($real_part, $allowed_base) !== 0) {
                fclose($out);
                wp_delete_file($output);
                return new WP_Error('invalid_part', "Part file outside temp directory: $part", ['status' => 400]);
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming read
            $in = fopen($part, 'rb');
            if ($in) {
                while (!feof($in)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming write
                    $bytes = fwrite($out, fread($in, 8192));
                    $total_size += $bytes;
                }
                fclose($in);
            }
            // Clean up the chunk after concatenating
            wp_delete_file($part);
        }
        fclose($out);

        return rest_ensure_response([
            'success' => true,
            'output'  => $output,
            'size'    => $total_size,
            'parts'   => count($parts),
        ]);
    }

    // ─── File Search-Replace (for static CSS/JS files on disk) ──

    /**
     * Search-replace strings within files on disk.
     * Used after DB search-replace to fix static CSS/JS files (e.g., Elementor CSS).
     *
     * POST /files/search-replace
     * Body: {"directory": "/path/to/dir", "search": "old-domain.com", "replace": "new-domain.com",
     *        "extensions": ["css","js","json"], "dry_run": false}
     */
    public function route_files_search_replace(WP_REST_Request $request) {
        // Whole body guarded, matching the /plugins/*, /theme/* and /core/* handlers: this endpoint
        // walks the filesystem and runs third-party-supplied patterns, so an unguarded throw returns
        // a bare 500 with no JSON envelope — which a caller cannot tell apart from the site being down.
        try {
            $directory  = $request->get_param('directory');
            $search     = $request->get_param('search');
            $replace    = $request->get_param('replace');
            $dry_run    = (bool) $request->get_param('dry_run');

            // Accept a CSV string as well as an array. A bare string used to reach the array_map()
            // below and throw a TypeError on PHP 8 — an UNCAUGHT fatal, so the caller got a bare
            // "critical error" 500 with no JSON envelope, indistinguishable from the site being down.
            // Mirrors normalize_exclude()'s handling of the same JSON-array-or-CSV shape.
            $extensions = $request->get_param('extensions');
            if (is_string($extensions)) {
                $decoded = json_decode($extensions, true);
                $extensions = is_array($decoded) ? $decoded : explode(',', $extensions);
            }
            $extensions = array_filter(array_map('trim', (array) $extensions), 'strlen');
            if (empty($extensions)) {
                $extensions = ['css', 'js', 'json'];
            }

            if (!$directory || !$search || !$replace) {
                return new WP_Error('missing_params', 'directory, search, and replace are required', ['status' => 400]);
            }

            // Validate directory is within the uploads directory
            $uploads_basedir = wp_upload_dir()['basedir'];
            $real_uploads = realpath($uploads_basedir);
            $real_dir = realpath($directory);
            if ($real_dir === false || $real_uploads === false || strpos($real_dir, $real_uploads) !== 0) {
                return new WP_Error('invalid_path', 'Directory must be within the uploads directory', ['status' => 400]);
            }

            // Sanitize extensions
            $extensions = array_map(function ($ext) {
                return preg_replace('/[^a-zA-Z0-9]/', '', $ext);
            }, $extensions);

            $wp_filesystem = $this->init_filesystem();
            if (!$wp_filesystem) {
                return new WP_Error('filesystem_error', 'Cannot initialize WP_Filesystem.', ['status' => 500]);
            }

            $files_scanned = 0;
            $files_changed = 0;
            $total_replacements = 0;
            $changed_files = [];

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($real_dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $ext = strtolower($file->getExtension());
                if (!in_array($ext, $extensions, true)) {
                    continue;
                }

                $files_scanned++;
                $filepath = $file->getPathname();

                $content = $wp_filesystem->get_contents($filepath);
                if ($content === false) {
                    continue;
                }

                $count = substr_count($content, $search);
                if ($count > 0) {
                    $total_replacements += $count;
                    $files_changed++;
                    $changed_files[] = [
                        'file' => str_replace($real_dir . '/', '', $filepath),
                        'replacements' => $count,
                    ];

                    if (!$dry_run) {
                        $new_content = str_replace($search, $replace, $content);
                        $wp_filesystem->put_contents($filepath, $new_content, FS_CHMOD_FILE);
                    }
                }
            }

            return rest_ensure_response([
                'success'      => true,
                'dry_run'      => $dry_run,
                'search'       => $search,
                'replace'      => $replace,
                'files_scanned'    => $files_scanned,
                'files_changed'    => $files_changed,
                'total_replacements' => $total_replacements,
                'changed_files'    => $changed_files,
            ]);
        } catch (\Exception $e) {
            return new WP_Error('exception', $e->getMessage(), ['status' => 500]);
        } catch (\Throwable $e) {
            return new WP_Error('exception', $e->getMessage(), ['status' => 500]);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function has_wp_cli() {
        $paths = [
            '/usr/local/bin/wp',
            '/usr/bin/wp',
            '/bin/wp',
            '/usr/sbin/wp',
        ];
        foreach ($paths as $path) {
            if (@file_exists($path) && @is_executable($path)) {
                return true;
            }
        }
        return false;
    }

    private function rmdir_recursive($dir) {
        if (!file_exists($dir)) return true;
        if (!is_dir($dir)) {
            wp_delete_file($dir);
            return !file_exists($dir);
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rmdir_recursive($path);
            } else {
                wp_delete_file($path);
            }
        }

        $wp_filesystem = $this->init_filesystem();
        if ($wp_filesystem) {
            return $wp_filesystem->rmdir($dir);
        }
        return false;
    }

    public function install_activate_plugin(WP_REST_Request $request) {
        // Whole body guarded, matching the other /plugins/* and /core/* handlers: sanitize_url()
        // runs the `clean_url` filter, so even the validation can throw from third-party code. An
        // unguarded fatal returns a bare 500 with no JSON envelope, which the migration agent
        // cannot tell apart from the site being down.
        try {
            $plugin_url = $request->get_param('plugin_url');
            $plugin_url = empty( $plugin_url ) ? $plugin_url : sanitize_url( $plugin_url, array( 'https' ) );
            if ( empty( $plugin_url ) || ! filter_var( $plugin_url, FILTER_VALIDATE_URL ) ) {
                return new WP_Error( 'invalid_params', 'Invalid plugin URL ' . $plugin_url, [ 'status' => 400 ] );
            }

            if ( ! class_exists( 'WP_Upgrader' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }

            if ( ! class_exists( 'Plugin_Upgrader' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
            }

            if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
            }

            if ( ! function_exists( 'request_filesystem_credentials' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $skin = new \Automatic_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader( $skin );

            $result = $upgrader->install( $plugin_url, [
                'overwrite_package' => true,
            ] );

            if ( is_wp_error( $result ) ) {
                return new WP_Error( 'install_failed', $result->get_error_message(), [ 'status' => 500 ] );
            }
            if ( ! $result ) {
                return new WP_Error( 'install_failed', sprintf( esc_html( 'Installation failed! Please check minimum supported WordPress version of the %s' ), 'plugin' ), [ 'status' => 500 ] );
            }

            if ( ! function_exists( 'wp_clean_update_cache' ) || ! function_exists( 'wp_update_plugins' ) ) {
                require_once ABSPATH . 'wp-includes/update.php';
            }
            wp_clean_update_cache();
            wp_update_plugins();

            $plugin_file = $upgrader->plugin_info();
            if ( ! $plugin_file ) {
                return new WP_Error( 'plugin_file_unresolved', 'Failed to resolve plugin file after installation.', [ 'status' => 500 ] );
            }

            if ( ! function_exists( 'activate_plugin' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            // activate_plugin() executes the plugin's own top-level code and its activation hook,
            // so a broken or host-incompatible plugin throws from HERE — the most likely fatal in
            // this handler, and precisely the Newfold/hiive.cloud case documented in kb/host-quirks.md.
            $activated = activate_plugin( $plugin_file );
            if ( is_wp_error( $activated ) ) {
                return new WP_Error( 'activate_failed', $activated->get_error_message() . ' Failed to activate plugin ' . $plugin_file, [ 'status' => 500 ] );
            }

            return rest_ensure_response( [
                'success' => true,
                'message' => esc_html( 'Success!' ),
            ] );
        } catch (\Exception $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        } catch (\Throwable $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    /**
     * POST /cache/clear — clear the caches that hold stale state after a migration.
     *
     * Superset of TARGETS, not a blunt clear-everything: the caller names a `scope` and each target
     * reports its own outcome. One boolean would be useless here — finalize has to tell "this site
     * has no object cache" apart from "the flush failed", and a single success flag reads as the
     * former in both cases.
     *
     * Body: {"scope": ["object","builder", ...]}  (omitted → CACHE_CLEAR_DEFAULT_SCOPE)
     */
    public function cache_clear( WP_REST_Request $request ) {
        try {
            $scope = $request->get_param( 'scope' );

            if ( null === $scope || '' === $scope || [] === $scope ) {
                $scope = self::CACHE_CLEAR_DEFAULT_SCOPE;
            }
            // Accept a comma-separated string too: this gets called from shell with curl, where
            // building a JSON array is more ceremony than the caller should need.
            if ( is_string( $scope ) ) {
                $scope = array_map( 'trim', explode( ',', $scope ) );
            }
            if ( ! is_array( $scope ) ) {
                return new WP_Error(
                    'invalid_params',
                    'Provide scope as an array or comma-separated list of: ' . implode( ', ', self::CACHE_CLEAR_TARGETS ) . '.',
                    [ 'status' => 400 ]
                );
            }

            $scope = array_values( array_unique( array_map( 'sanitize_key', $scope ) ) );

            // Fail loudly on an unknown target rather than silently clearing a subset. A typo'd
            // scope that returns 200 would read to the caller as "cleared", and the thing they
            // asked for would never have run.
            $unknown = array_diff( $scope, self::CACHE_CLEAR_TARGETS );
            if ( ! empty( $unknown ) ) {
                return new WP_Error(
                    'invalid_params',
                    'Unknown cache target(s): ' . implode( ', ', $unknown ) . '. Valid: ' . implode( ', ', self::CACHE_CLEAR_TARGETS ) . '.',
                    [ 'status' => 400 ]
                );
            }

            $results = [];
            foreach ( $scope as $target ) {
                // One target's failure must not abort the rest — a site with a broken opcache
                // extension should still get its builder cache cleared.
                try {
                    switch ( $target ) {
                        case 'object':        $results['object']        = $this->clear_object_cache();   break;
                        case 'builder':       $results['builder']       = $this->clear_builder_caches(); break;
                        case 'rewrite':       $results['rewrite']       = $this->clear_rewrite_rules();  break;
                        case 'transients':    $results['transients']    = $this->clear_transients();     break;
                        case 'opcache':       $results['opcache']       = $this->clear_opcache();        break;
                        case 'plugin_caches': $results['plugin_caches'] = $this->clear_plugin_caches();  break;
                    }
                } catch (\Exception $e) {
                    $results[ $target ] = [ 'ok' => false, 'error' => $e->getMessage() ];
                } catch (\Throwable $e) {
                    $results[ $target ] = [ 'ok' => false, 'error' => $e->getMessage() ];
                }
            }

            // `ok` is the AGGREGATE of what ran. A skipped target is not a failure (the subsystem
            // was never here), so it does not drag the aggregate down.
            $failed = [];
            foreach ( $results as $target => $result ) {
                if ( empty( $result['ok'] ) && empty( $result['skipped'] ) ) {
                    $failed[] = $target;
                }
            }

            return rest_ensure_response( [
                'success' => true,
                'ok'      => empty( $failed ),
                'scope'   => $scope,
                'failed'  => $failed,
                'results' => $results,
            ] );
        } catch (\Exception $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        } catch (\Throwable $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    /**
     * Object cache — the drop-in's own flush, never a raw backend FLUSHALL.
     *
     * wp_cache_flush() routes through whatever drop-in is installed (Redis, Memcached, APCu), and
     * the maintained drop-ins scope it to THIS site's key prefix. Talking to the backend directly
     * would reach every tenant on a shared Redis, which is why this never does.
     */
    private function clear_object_cache() {
        if ( ! wp_using_ext_object_cache() ) {
            // No persistent backend: WP's in-process array cache dies with the request anyway, so
            // there is nothing durable to clear and nothing to report as done.
            return [ 'ok' => true, 'skipped' => true, 'reason' => 'no persistent object cache' ];
        }

        $flushed = wp_cache_flush();

        return [
            'ok'      => false !== $flushed,
            'method'  => 'wp_cache_flush',
            'backend' => $this->object_cache_backend(),
        ];
    }

    /** Best-effort name of the object-cache drop-in, for the caller's log. */
    private function object_cache_backend() {
        foreach ( [ 'Redis' => 'redis', 'Memcached' => 'memcached', 'APCu' => 'apcu' ] as $class => $name ) {
            if ( class_exists( $class ) ) {
                return $name;
            }
        }
        return 'unknown';
    }

    /**
     * Page-builder caches, driven by the CACHE_CLEAR_BUILDERS registry.
     *
     * Order matters: the builder's own clear function first, files only if none ran. The builder
     * knows what its cache holds and stays consistent after its own API; a recursive unlink is us
     * guessing at its layout, and a wrong guess deletes something it will not regenerate.
     */
    private function clear_builder_caches() {
        $cleared = [];
        $skipped = [];
        $errors  = [];

        foreach ( self::CACHE_CLEAR_BUILDERS as $slug => $spec ) {
            if ( ! $this->builder_is_present( $spec['guard'] ) ) {
                $skipped[] = $slug . ': not active';
                continue;
            }

            $detail = [ 'via' => [], 'dirs' => [], 'options' => [] ];

            foreach ( $spec['callables'] as $callable ) {
                if ( is_callable( $callable ) ) {
                    try {
                        call_user_func( $callable );
                        $detail['via'][] = $callable;
                    } catch (\Exception $e) {
                        // A builder's own clear function throwing is worth reporting, but the file
                        // fallback below can still do the job.
                        $errors[] = $slug . ': ' . $callable . '(): ' . $e->getMessage();
                    } catch (\Throwable $e) {
                        // A builder's own clear function throwing is worth reporting, but the file
                        // fallback below can still do the job.
                        $errors[] = $slug . ': ' . $callable . '(): ' . $e->getMessage();
                    }
                }
            }

            // Elementor's clear lives on an instance rather than a plain function, so it is called
            // by name here instead of sitting in the registry's `callables` list.
            if ( 'elementor' === $slug && class_exists( '\Elementor\Plugin' ) ) {
                try {
                    $elementor = \Elementor\Plugin::$instance;
                    if ( $elementor && isset( $elementor->files_manager ) ) {
                        $elementor->files_manager->clear_cache();
                        $detail['via'][] = 'Elementor\Plugin::files_manager->clear_cache';
                    }
                } catch (\Exception $e) {
                    $errors[] = 'elementor: clear_cache(): ' . $e->getMessage();
                } catch (\Throwable $e) {
                    $errors[] = 'elementor: clear_cache(): ' . $e->getMessage();
                }
            }

            // Files are the FALLBACK, but the trigger is the OBSERVED RESULT, not which path ran.
            //
            // "A callable ran, so the cache must be gone" is an assumption we cannot check for the
            // paid builders (Divi, Bricks, Breakdance, WPBakery cannot be installed on a test site,
            // so their clear functions are named from documentation, not verified). If one of those
            // names is wrong or its behaviour differs, is_callable() may still pass while the cache
            // survives — and skipping the unlink on that basis would leave the exact stale cache
            // this endpoint exists to remove. So: re-check the directory afterwards and unlink only
            // what is genuinely still there. A builder that cleared itself properly leaves nothing
            // to delete, making this a no-op on the happy path.
            foreach ( $spec['dirs'] as $rel ) {
                $result = $this->delete_wp_content_dir( $rel );
                if ( is_wp_error( $result ) ) {
                    $errors[] = $slug . ': ' . $rel . ': ' . $result->get_error_message();
                    continue;
                }
                if ( $result ) {
                    $detail['dirs'][] = $rel;
                    if ( ! empty( $detail['via'] ) ) {
                        // Worth surfacing: the builder's own API ran and yet its cache was still on
                        // disk. That means the callable we prefer is not doing what we believe.
                        $detail['note'] = 'own-api ran but cache remained; removed by fallback';
                    }
                }
            }

            // Generated-CSS options go regardless of which path ran: leaving them makes the builder
            // believe it has already generated the files we just deleted, so nothing regenerates.
            foreach ( $spec['options'] as $option ) {
                if ( false !== get_option( $option, false ) ) {
                    delete_option( $option );
                    $detail['options'][] = $option;
                }
            }

            $cleared[ $slug ] = $detail;
        }

        return [
            'ok'      => empty( $errors ),
            'skipped' => empty( $cleared ) ? true : false,
            'cleared' => $cleared,
            'passed'  => $skipped,
            'errors'  => $errors,
        ];
    }

    /**
     * Is this builder actually installed? Several independent signals, because a builder can ship
     * as a theme (Divi, Bricks) or a plugin (Elementor, Breakdance) and may not be loaded yet at
     * the moment a REST request runs.
     */
    private function builder_is_present( array $guard ) {
        foreach ( ( (isset($guard['class']) ? $guard['class'] : []) ) as $class ) {
            if ( class_exists( $class ) ) {
                return true;
            }
        }
        foreach ( ( (isset($guard['constant']) ? $guard['constant'] : []) ) as $constant ) {
            if ( defined( $constant ) ) {
                return true;
            }
        }
        foreach ( ( (isset($guard['plugin']) ? $guard['plugin'] : []) ) as $plugin_file ) {
            if ( in_array( $plugin_file, $this->active_plugin_files(), true ) ) {
                return true;
            }
        }

        if ( ! empty( $guard['theme'] ) ) {
            $theme  = wp_get_theme();
            $names  = [ $theme->get( 'Name' ), $theme->get_template(), $theme->get_stylesheet() ];
            // A child theme's PARENT is what carries the builder, so the template name is checked
            // alongside the stylesheet — "Divi Child" must still match Divi.
            $parent = $theme->parent();
            if ( $parent ) {
                $names[] = $parent->get( 'Name' );
            }
            foreach ( $guard['theme'] as $wanted ) {
                foreach ( array_filter( $names ) as $name ) {
                    if ( 0 === strcasecmp( $wanted, (string) $name ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Delete a directory addressed RELATIVE TO wp-content, refusing anything that resolves outside
     * it. The registry is ours, but this is the one place in the cache path that unlinks files, so
     * it re-derives the boundary from realpath() rather than trusting the caller or the constant.
     *
     * Returns true (deleted), false (was not there) or WP_Error.
     */
    private function delete_wp_content_dir( $relative ) {
        $content_root = realpath( WP_CONTENT_DIR );
        if ( ! $content_root ) {
            return new WP_Error( 'no_content_dir', 'Could not resolve WP_CONTENT_DIR.' );
        }

        $target = $content_root . '/' . ltrim( (string) $relative, '/' );

        // Refuse a symlinked cache dir OUTRIGHT, before resolving it. Checked first and separately
        // from the prefix test below because realpath() returns false when the link points somewhere
        // open_basedir hides — which would fall through to the "nothing there" branch and report a
        // clean no-op for a path we in fact refused to touch. A cache directory is never legitimately
        // a symlink for our purposes, so this needs no exception.
        if ( is_link( $target ) ) {
            return new WP_Error( 'symlinked_path', 'Refusing to follow a symlinked cache path outside wp-content: ' . $relative );
        }

        $real = realpath( $target );

        if ( ! $real ) {
            return false; // Nothing there — not an error, the cache simply does not exist yet.
        }
        // Prefix check on the RESOLVED path, so a symlinked cache dir pointing out of wp-content
        // is refused rather than followed.
        if ( 0 !== strpos( $real, $content_root . '/' ) ) {
            return new WP_Error( 'outside_wp_content', 'Refusing to delete a path outside wp-content: ' . $relative );
        }
        if ( ! is_dir( $real ) ) {
            return new WP_Error( 'not_a_directory', 'Not a directory: ' . $relative );
        }

        return (bool) $this->rmdir_recursive( $real );
    }

    /** Permalinks — the migrated rules can still describe the old site's structure. */
    private function clear_rewrite_rules() {
        flush_rewrite_rules( false );
        return [ 'ok' => true, 'method' => 'flush_rewrite_rules' ];
    }

    /**
     * Expired transients only. A blanket transient wipe would drop live plugin state (license
     * checks, API tokens) that nothing on the destination can re-fetch without the owner.
     */
    private function clear_transients() {
        if ( function_exists( 'delete_expired_transients' ) ) {
            delete_expired_transients( true );
            return [ 'ok' => true, 'method' => 'delete_expired_transients', 'note' => 'expired only; live transients kept' ];
        }
        return [ 'ok' => false, 'error' => 'delete_expired_transients() unavailable' ];
    }

    /** PHP opcode cache — stale after files land at new paths (managed hosts serve old code). */
    private function clear_opcache() {
        if ( ! function_exists( 'opcache_reset' ) ) {
            return [ 'ok' => true, 'skipped' => true, 'reason' => 'opcache not available' ];
        }
        $reset = @opcache_reset();
        return [ 'ok' => false !== $reset, 'method' => 'opcache_reset' ];
    }

    /**
     * Env-coupled plugin caches: caches keying on the OLD environment's ABSOLUTE paths, which never
     * self-heal because the plugin believes it already generated them. The WPO PDF Invoices Dompdf
     * font cache is the known case — it renders broken glyphs days after an otherwise clean
     * migration. Same registry idea as scripts/clear-stale-plugin-caches.sh, which can only emit an
     * owner TODO on a no-SSH site; here we can actually delete them.
     */
    private function clear_plugin_caches() {
        $upload  = wp_upload_dir();
        $basedir = isset( $upload['basedir'] ) ? $upload['basedir'] : '';
        if ( ! $basedir || ! is_dir( $basedir ) ) {
            return [ 'ok' => false, 'error' => 'could not resolve uploads basedir' ];
        }

        $registry = [
            'woocommerce-pdf-invoices-packing-slips' => [ 'glob' => 'wpo_wcpdf_*/fonts' ],
        ];

        $cleared = [];
        $errors  = [];
        $active  = $this->active_plugin_files();

        foreach ( $registry as $slug => $spec ) {
            $is_active = false;
            foreach ( $active as $plugin_file ) {
                if ( 0 === strpos( $plugin_file, $slug . '/' ) ) {
                    $is_active = true;
                    break;
                }
            }
            if ( ! $is_active ) {
                continue;
            }

            foreach ( (array) glob( $basedir . '/' . $spec['glob'], GLOB_ONLYDIR ) as $dir ) {
                if ( $this->rmdir_recursive( $dir ) ) {
                    $cleared[] = str_replace( $basedir . '/', '', $dir );
                } else {
                    $errors[] = 'could not remove ' . $dir;
                }
            }
        }

        return [
            'ok'      => empty( $errors ),
            'skipped' => ( empty( $cleared ) && empty( $errors ) ) ? true : false,
            'cleared' => $cleared,
            'errors'  => $errors,
        ];
    }

    /**
     * Plugin files this plugin must never target. Deactivating or deleting ourselves would sever
     * the only control channel on a no-SSH site. Covers both install layouts: the wp.org slug dir
     * (instamigrate/) and the zip-upload dir (insta-migrate/).
     */
    private function self_plugin_files() {
        return [ 'instamigrate/insta-migrate.php', 'insta-migrate/insta-migrate.php' ];
    }

    /**
     * Every ACTIVE plugin file on this install, including network-activated ones.
     *
     * get_option('active_plugins') is per-site and does NOT include network-activated plugins,
     * which live in the sitemeta option 'active_sitewide_plugins' (keyed by plugin file). Reading
     * only the former on multisite would let the all_except_self sweep report success while leaving
     * every network plugin loaded — the exact plugins that could fatal during a core update.
     *
     * (Deactivation itself already copes: deactivate_plugins() defaults $network_wide to null and
     * WP treats `false !== null` as "deactivate network-wide if the plugin is network-active", so
     * only the DISCOVERY of those plugins was missing.)
     */
    private function active_plugin_files() {
        $active = (array) get_option( 'active_plugins', [] );

        if ( is_multisite() ) {
            // Network plugins are the KEYS of active_sitewide_plugins (values are activation times).
            $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        }

        return array_values( array_unique( $active ) );
    }

    /**
     * Normalize the plugin_files request param for the /plugins/* endpoints: coerce to array,
     * sanitize, drop empties, and unconditionally exclude ourselves.
     *
     * @param bool $allow_all_except_self When true, an empty list combined with all_except_self
     *                                    expands to every ACTIVE plugin but ours. Offered only to
     *                                    deactivate_plugin() — a blanket delete is not a safe
     *                                    default, so delete_plugin() always requires an explicit list.
     * @return array|WP_Error Sanitized plugin files, or WP_Error when nothing is left to act on.
     */
    private function resolve_plugin_files( WP_REST_Request $request, $allow_all_except_self = false ) {
        $plugin_files = $request->get_param( 'plugin_files' );
        $plugin_files = is_array( $plugin_files ) ? $plugin_files : [ $plugin_files ];
        // all empty entries of array will be removed
        $plugin_files = array_filter( array_map( 'sanitize_text_field', (array) $plugin_files ) );

        // Whether the caller named plugins at all — captured BEFORE the self-filter so that a list
        // containing only InstaMigrate is treated as "you asked for something specific" (and ends as
        // a no-op error) rather than collapsing to empty and silently escalating into a full sweep.
        $named_plugins_explicitly = ! empty( $plugin_files );

        $plugin_files = array_diff( $plugin_files, $this->self_plugin_files() );

        if ( ! $named_plugins_explicitly && $allow_all_except_self && $request->get_param( 'all_except_self' ) ) {
            // active_plugin_files() (not get_option) so the sweep also covers network-activated
            // plugins on multisite — otherwise they stay loaded and the "all" is a lie.
            $plugin_files = array_diff( $this->active_plugin_files(), $this->self_plugin_files() );
        }

        if ( empty( $plugin_files ) ) {
            return new WP_Error(
                'invalid_params',
                $allow_all_except_self
                    ? 'Provide a non-empty plugin_files array, or set all_except_self to true.'
                    : 'Provide a non-empty plugin array.',
                [ 'status' => 400 ]
            );
        }

        return array_values( $plugin_files );
    }

    /**
     * Deactivate plugin files, loading the WP admin plugin API on demand.
     * Shared by deactivate_plugin() and delete_plugin() — delete_plugins() refuses active plugins,
     * so deletion is always deactivation plus an unlink.
     */
    private function deactivate_plugin_files( array $plugin_files ) {
        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins( $plugin_files );
    }

    public function delete_plugin( WP_REST_Request $request ) {
        try {
            $plugin_files = $this->resolve_plugin_files( $request );
            if ( is_wp_error( $plugin_files ) ) {
                return $plugin_files;
            }

            if ( ! function_exists( 'delete_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if ( ! function_exists( 'request_filesystem_credentials' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            // delete_plugins() requires plugins to be inactive — deactivate first.
            $this->deactivate_plugin_files( $plugin_files );

            $result = delete_plugins( $plugin_files );
            // Per WP docs:
            //   true     -> success
            //   null     -> filesystem credentials needed
            //   WP_Error -> deletion failure
            //   false    -> empty plugins array (guarded above)
            if ( is_wp_error( $result ) ) {
                return new WP_Error( 'delete_failed', $result->get_error_message(), [ 'status' => 500 ] );
            }
            if ( null === $result ) {
                return new WP_Error( 'fs_credentials_required', 'Filesystem credentials required to delete plugin(s).', [ 'status' => 500 ] );
            }
            if ( false === $result ) {
                return new WP_Error( 'empty_plugins', 'delete_plugins() received an empty plugins list.', [ 'status' => 500 ] );
            }

            return rest_ensure_response( [
                'success' => true,
                'message' => esc_html( 'Success!' ),
            ] );
        } catch (\Exception $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        } catch (\Throwable $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    /**
     * Deactivate plugins WITHOUT deleting them.
     *
     * The reversible counterpart to delete_plugin(). Used before raising a destination's WP core:
     * only ACTIVE plugins load, so deactivating is sufficient to stop a destination plugin from
     * fataling during the upgrade — while leaving its files on disk, so a migration that blocks or
     * fails afterwards can be undone. Deleting would strip the owner's site irreversibly.
     *
     * Returns the PRIOR active_plugins list so the caller can restore the exact previous state.
     */
    public function deactivate_plugin( WP_REST_Request $request ) {
        // Whole body guarded: resolve_plugin_files() reads options and a deactivation hook belonging
        // to any third-party plugin runs inside deactivate_plugin_files(), so a throw can originate
        // well outside our own code. An unguarded fatal returns a bare 500 with no JSON envelope,
        // which the migration agent cannot distinguish from the site being down.
        try {
            // Capture before any mutation so the caller gets a faithful restore point. Uses
            // active_plugin_files() so the restore point is complete on multisite — a list missing
            // the network-activated plugins could not actually restore the prior state.
            $previous_active = $this->active_plugin_files();

            $plugin_files = $this->resolve_plugin_files( $request, true );
            if ( is_wp_error( $plugin_files ) ) {
                return $plugin_files;
            }

            $this->deactivate_plugin_files( $plugin_files );

            return rest_ensure_response( [
                'success'                 => true,
                'requested'               => array_values( $plugin_files ),
                'previous_active_plugins' => array_values( $previous_active ),
                // Post-state read the same way as previous_active_plugins, so the two are directly
                // comparable (a mixed per-site/network pair would make the diff meaningless).
                'active_plugins'          => $this->active_plugin_files(),
                'message'                 => 'Deactivated ' . count( $plugin_files ) . ' plugin(s); files left on disk.',
            ] );
        } catch (\Exception $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        } catch (\Throwable $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    /**
     * Activate a theme, installing it from wordpress.org first when absent.
     *
     * Step 2 of the safe core-change procedure (deactivate plugins → switch to a wide-compatibility
     * theme → change core). WordPress bundles a theme matched to its own release, so the active
     * theme is frequently NEWER than a core version we are about to move to; it then calls core
     * functions that do not exist there and hard-fatals every route before WP boots. Twenty
     * Twenty-One is the usual target: it declares "Requires at least: 5.3", so one theme spans
     * WP 5.3 → current.
     *
     * Without this endpoint the procedure is not executable on the destinations that need it —
     * plugin-only destinations have no SSH, and several InstaWP hosts 404 `/wp-cli`.
     *
     * Returns the previous theme so the caller can restore it, mirroring deactivate_plugin().
     */
    public function theme_activate( WP_REST_Request $request ) {
        try {
            // Default to the safe theme so a bare POST performs step 2 of the procedure with no
            // body at all; an explicit stylesheet still overrides it.
            $stylesheet = sanitize_text_field( (string) $request->get_param( 'stylesheet' ) );
            if ( '' === $stylesheet ) {
                $stylesheet = self::SAFE_THEME;
            }

            // Install by default too: the default theme is only useful if it lands on a site that
            // has never had it. Pass install_if_missing=false to require it be present already.
            // filter_var, not a raw cast: query-string and form-encoded callers send STRINGS, and
            // (bool) "false" is true — the opposite of the request. Absent => default true.
            $install_if_missing = $request->get_param( 'install_if_missing' );
            $install_if_missing = ( null === $install_if_missing )
                ? true
                : filter_var( $install_if_missing, FILTER_VALIDATE_BOOLEAN );

            // Directory name only — never a path. Rejects traversal and slashes outright rather
            // than sanitising them away (sanitize_key() would also lowercase names like "Divi").
            if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $stylesheet ) ) {
                return new WP_Error(
                    'invalid_params',
                    'Provide a theme directory name, e.g. "twentytwentyone".',
                    [ 'status' => 400 ]
                );
            }

            $previous            = wp_get_theme();
            $previous_stylesheet = $previous->get_stylesheet();

            // OPT-IN, not default. The network-wide sweep existed to make every subsite safe before
            // a core change — but core changes are refused on multisite (see core_update), so no
            // in-product caller needs it. Defaulting it OFF keeps plain switch_theme semantics and
            // avoids running network code nothing exercises. Pass network_wide=true to opt in.
            // filter_var for the same reason as install_if_missing above: a strict `=== true` would
            // silently ignore a form-encoded `network_wide=true`, quietly performing a single-site
            // switch while the caller believes the whole network was swept.
            $network_wide = is_multisite()
                && filter_var( $request->get_param( 'network_wide' ), FILTER_VALIDATE_BOOLEAN );

            // Already active — no-op, reported the same shape as a real switch so callers can be
            // written without a special case. NOT taken for a network-wide run: this site having
            // the theme says nothing about the other subsites, which still need switching.
            if ( $previous_stylesheet === $stylesheet && ! $network_wide ) {
                return rest_ensure_response( [
                    'success'        => true,
                    'skipped'        => true,
                    'previous_theme' => $previous_stylesheet,
                    'current_theme'  => $previous_stylesheet,
                    'message'        => 'Theme is already active — no change made.',
                ] );
            }

            $theme     = wp_get_theme( $stylesheet );
            $installed = $theme->exists();

            if ( ! $installed && $install_if_missing ) {
                foreach ( [
                    'wp-admin/includes/class-wp-upgrader.php',
                    'wp-admin/includes/class-automatic-upgrader-skin.php',
                    'wp-admin/includes/file.php',
                    'wp-admin/includes/misc.php',
                    'wp-admin/includes/theme.php',
                ] as $core_include ) {
                    require_once ABSPATH . $core_include;
                }

                if ( ! class_exists( 'Theme_Upgrader' ) ) {
                    $theme_upgrader_file = ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
                    if ( file_exists( $theme_upgrader_file ) ) {
                        require_once $theme_upgrader_file;
                    }
                }
                if ( ! class_exists( 'Theme_Upgrader' ) ) {
                    return new WP_Error( 'install_failed', 'Theme_Upgrader is unavailable on this WordPress installation.', [ 'status' => 500 ] );
                }

                // Resolve the download link through the wp.org themes API rather than guessing a
                // URL, so we always get a version the API considers current for this install.
                $api = themes_api( 'theme_information', [ 'slug' => $stylesheet, 'fields' => [ 'sections' => false ] ] );
                if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
                    return new WP_Error(
                        'theme_unavailable',
                        'Theme "' . $stylesheet . '" was not found on wordpress.org.',
                        [ 'status' => 404 ]
                    );
                }

                $upgrader = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
                $result   = $upgrader->install( $api->download_link, [ 'overwrite_package' => false ] );

                if ( is_wp_error( $result ) ) {
                    return new WP_Error( 'install_failed', $result->get_error_message(), [ 'status' => 500 ] );
                }
                if ( null === $result ) {
                    return new WP_Error( 'fs_credentials_required', 'Filesystem credentials required to install a theme on this host.', [ 'status' => 500 ] );
                }

                // Re-read from disk; the object captured before the install is stale.
                $theme = wp_get_theme( $stylesheet );
            }

            if ( ! $theme->exists() ) {
                return new WP_Error(
                    'theme_not_found',
                    'Theme "' . $stylesheet . '" is not installed. Pass install_if_missing to fetch it from wordpress.org.',
                    [ 'status' => 404 ]
                );
            }

            $previous_themes   = [];
            $sites_switched    = 0;
            $restore_truncated = false;

            // Network-enable on ANY multisite switch, not only the network-wide sweep: a site
            // cannot use a theme absent from `allowedthemes`, so WordPress may revert it on the
            // next load. Scoping this to the sweep made the single-site path on multisite fail for
            // no reason. Enabling a theme network-wide does not activate it anywhere.
            if ( is_multisite() ) {
                if ( method_exists( 'WP_Theme', 'network_enable_theme' ) ) {
                    \WP_Theme::network_enable_theme( $stylesheet );
                } else {
                    $allowed                = (array) get_site_option( 'allowedthemes', [] );
                    $allowed[ $stylesheet ] = true;
                    update_site_option( 'allowedthemes', $allowed );
                }
            }

            if ( $network_wide ) {
                // WordPress core is SHARED across a network — one wp-includes for every subsite —
                // so a core change affects them all. Switching only the current site would leave
                // every other subsite on a theme that fatals against the new core.
                //
                // The per-subsite restore map is capped: on a large network an uncapped map is a
                // huge response body for a restore point that may never be read. sites_switched
                // remains exact, and the truncation is reported rather than silent.
                $max_restore_entries = 200;
                foreach ( get_sites( [ 'number' => 0, 'fields' => 'ids' ] ) as $blog_id ) {
                    switch_to_blog( $blog_id );
                    if ( count( $previous_themes ) < $max_restore_entries ) {
                        $previous_themes[ (string) $blog_id ] = wp_get_theme()->get_stylesheet();
                    } else {
                        $restore_truncated = true;
                    }
                    switch_theme( $stylesheet );
                    $sites_switched++;
                    restore_current_blog();
                }
            } else {
                switch_theme( $stylesheet );
                $previous_themes[ (string) get_current_blog_id() ] = $previous_stylesheet;
                $sites_switched = 1;
            }

            // switch_theme() has no return value, so confirm from the option rather than assuming.
            $now = wp_get_theme();
            if ( $now->get_stylesheet() !== $stylesheet ) {
                return new WP_Error(
                    'activate_failed',
                    'Switch did not take effect; active theme is still "' . $now->get_stylesheet() . '".',
                    [ 'status' => 500 ]
                );
            }

            return rest_ensure_response( [
                'success'        => true,
                'skipped'        => false,
                'installed'      => ! $installed,
                'previous_theme' => $previous_stylesheet,
                'current_theme'  => $now->get_stylesheet(),
                'is_multisite'   => is_multisite(),
                // Per-site restore points, keyed by blog_id (one entry on a single site).
                'previous_themes' => $previous_themes,
                'previous_themes_truncated' => $restore_truncated,
                'sites_switched'  => $sites_switched,
                // Advisory only: the caller may be activating this theme precisely because it is
                // about to move core, so a mismatch here is not an error.
                'theme_requires_wp' => $now->get( 'RequiresWP' ) ?: null,
                'message'        => 'Activated theme "' . $stylesheet . '" on ' . $sites_switched . ' site(s).',
            ] );
        } catch (\Exception $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        } catch (\Throwable $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    /**
     * Update WordPress core to a requested version.
     *
     * Exists so a migration can raise an OLDER destination up to the source's core version before
     * the DB import. The import force-injects the source's active_plugins, which bypasses WP's own
     * "Requires at least" gate — so a source plugin calling newer-core functions would hard-fatal on
     * an older destination at plugin load, 500ing every route before WP boots (unrecoverable when
     * the destination has no SSH).
     *
     * RAISE-ONLY: a request at or below the current version is a no-op ('skipped'), never a
     * downgrade. Enforced here rather than in the caller so the destructive direction isn't
     * reachable through this endpoint at all.
     */
    public function core_update( WP_REST_Request $request ) {
        // Whole body guarded. Even the "cheap" reads here run third-party filters (get_locale()
        // fires the `locale` filter), so a throw can originate outside our own code — and an
        // unguarded fatal returns a bare 500 with no JSON envelope, which the migration agent
        // cannot tell apart from the site being down.
        try {
            // POLICY: never change core on a network. One wp-includes is shared by every subsite,
            // so a core change is network-wide by construction and cannot be scoped, staged or
            // rolled back per site — every subsite's themes and plugins would have to be verified
            // against the new core first. Refused here rather than left to the caller, so the rule
            // holds no matter who calls the endpoint. Migrations requiring a core change must use a
            // single-site destination.
            if ( is_multisite() ) {
                return new WP_Error(
                    'multisite_unsupported',
                    'Core updates are not performed on multisite. Use a single-site destination.',
                    [ 'status' => 409 ]
                );
            }

            $requested_version = sanitize_text_field( (string) $request->get_param( 'version' ) );

            // Digits and dots only — this value is interpolated into the download URL below.
            if ( ! preg_match( '/^\d+(\.\d+){0,2}$/', $requested_version ) ) {
                return new WP_Error(
                    'invalid_params',
                    'Provide a numeric WordPress version, e.g. "7.0" or "6.9.4".',
                    [ 'status' => 400 ]
                );
            }

            $current_version = get_bloginfo( 'version' );

            // Raise-only. A destination already at or beyond the target needs no work, and a NEWER
            // destination core is harmless — the Requires-at-least gate only trips when it is older.
            if ( version_compare( $current_version, $requested_version, '>=' ) ) {
                return rest_ensure_response( [
                    'success'           => true,
                    'skipped'           => true,
                    'current_version'   => $current_version,
                    'requested_version' => $requested_version,
                    'message'           => 'Already at or above the requested version — no update performed.',
                ] );
            }

            $locale = sanitize_text_field( (string) $request->get_param( 'locale' ) );
            if ( empty( $locale ) ) {
                $locale = get_locale();
            }

            foreach ( [
                'wp-admin/includes/class-wp-upgrader.php',
                'wp-admin/includes/class-automatic-upgrader-skin.php',
                'wp-admin/includes/file.php',
                'wp-admin/includes/misc.php',
                'wp-admin/includes/update.php',
            ] as $core_include ) {
                require_once ABSPATH . $core_include;
            }

            // Core_Upgrader ships inside class-wp-upgrader.php on some versions and standalone on
            // others — only require the standalone file when the class is still missing.
            if ( ! class_exists( 'Core_Upgrader' ) ) {
                $core_upgrader_file = ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
                if ( file_exists( $core_upgrader_file ) ) {
                    require_once $core_upgrader_file;
                }
            }
            if ( ! class_exists( 'Core_Upgrader' ) ) {
                return new WP_Error(
                    'update_failed',
                    'Core_Upgrader is unavailable on this WordPress installation.',
                    [ 'status' => 500 ]
                );
            }

            // Core_Upgrader writes across ABSPATH, so a host needing FTP/SSH credentials cannot be
            // driven through this endpoint. Fail with a distinct code so the caller falls back to
            // blocking rather than reporting a generic failure.
            if ( ! $this->init_filesystem() || 'direct' !== get_filesystem_method() ) {
                return new WP_Error(
                    'fs_credentials_required',
                    'Filesystem credentials required — this host cannot update core over the REST API.',
                    [ 'status' => 500 ]
                );
            }

            // Prefer the official update object: it carries locale-correct and partial packages.
            wp_version_check( [], true );
            $update = find_core_update( $requested_version, $locale );

            // The API only offers current/adjacent releases, so a pinned older-or-specific version
            // often isn't listed. Fall back to the canonical release package for that exact version.
            if ( ! $update ) {
                $package = 'https://downloads.wordpress.org/release/wordpress-' . $requested_version . '.zip';

                $package_check = wp_safe_remote_head( $package, [ 'timeout' => 20, 'redirection' => 5 ] );
                if ( is_wp_error( $package_check ) || 200 !== (int) wp_remote_retrieve_response_code( $package_check ) ) {
                    return new WP_Error(
                        'version_unavailable',
                        'WordPress ' . $requested_version . ' is not available for download.',
                        [ 'status' => 404 ]
                    );
                }

                $update = (object) [
                    'response' => 'upgrade',
                    'current'  => $requested_version,
                    'version'  => $requested_version,
                    'download' => $package,
                    'locale'   => $locale,
                    'packages' => (object) [
                        'full'         => $package,
                        'no_content'   => false,
                        'new_bundled'  => false,
                        'partial'      => false,
                    ],
                ];
            }

            $upgrader = new \Core_Upgrader( new \Automatic_Upgrader_Skin() );
            $result   = $upgrader->upgrade( $update );

            if ( is_wp_error( $result ) ) {
                return new WP_Error( 'update_failed', $result->get_error_message(), [ 'status' => 500 ] );
            }
            if ( ! $result ) {
                return new WP_Error( 'update_failed', 'Core update did not complete.', [ 'status' => 500 ] );
            }
            // $wp_version is already loaded in this request, so re-read version.php from disk to
            // report what actually landed rather than the stale in-memory constant. Kept INSIDE the
            // guard: this file was just overwritten by the upgrade, so a truncated or partial write
            // would fatal here — and a fatal after a SUCCESSFUL core update is the worst outcome,
            // since the caller would see a 500 and assume nothing happened.
            //
            // NEVER fall back to echoing $requested_version here: current_version is the field
            // callers act on, so returning a version we did not read off disk would assert an
            // outcome we never confirmed. Unreadable => current_version null + verified false, so
            // the caller must re-read /status rather than trust a guess.
            $updated_version = null;
            $version_file    = ABSPATH . WPINC . '/version.php';
            if ( file_exists( $version_file ) && is_readable( $version_file ) ) {
                $wp_version = null;
                include $version_file;
                if ( ! empty( $wp_version ) ) {
                    $updated_version = $wp_version;
                }
            }
            return rest_ensure_response( [
                'success'           => true,
                'skipped'           => false,
                'verified'          => ( null !== $updated_version ),
                'previous_version'  => $current_version,
                'requested_version' => $requested_version,
                'current_version'   => $updated_version,
                'message'           => null !== $updated_version
                    ? 'WordPress core updated to ' . $updated_version . '.'
                    : 'Core update reported success, but the resulting version could not be read from disk — re-check /status.',
            ] );
        } catch (\Exception $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        } catch (\Throwable $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        }
    }

    /**
     * Record this migration on the site in the option instawp-connect already reads, so tools
     * built against the older connect-plugin flow keep working after a v4 (agent) migration.
     *
     * Shape is the connect plugin's (see instawp-connect class-instawp-ajax.php / the migration
     * REST controller): an array under `instawp_last_migration_details` carrying the group uuid,
     * the engine that ran the migration, and its status.
     *
     * Deliberately NOT autoloaded, matching Option::update_option()'s default over there — this is
     * read by tooling on demand, never on a front-end page load.
     */
    public function migration_details( WP_REST_Request $request ) {
        try {
            $migration_id = sanitize_text_field( (string) $request->get_param( 'migration_id' ) );
            $status       = sanitize_text_field( (string) $request->get_param( 'status' ) );
            $engine       = sanitize_text_field( (string) $request->get_param( 'engine' ) );

            if ( '' === $engine ) {
                $engine = self::MIGRATION_DETAILS_DEFAULT_ENGINE;
            }

            // Identifier only — no whitespace, no punctuation beyond what a uuid/slug needs. This
            // value is handed onward to other tooling, so it is validated rather than sanitised
            // into something the caller never sent.
            if ( ! preg_match( '/^[A-Za-z0-9._-]{1,191}$/', $migration_id ) ) {
                return new WP_Error(
                    'invalid_params',
                    'Provide migration_id as a uuid or slug (letters, digits, dot, underscore, hyphen).',
                    [ 'status' => 400 ]
                );
            }

            // Whitelisted rather than free-text: the status is what downstream tooling branches on,
            // and a typo'd value would read as "some status I do not recognise" instead of failing
            // loudly here, where the caller can still fix it.
            if ( ! in_array( $status, self::MIGRATION_DETAILS_STATUSES, true ) ) {
                return new WP_Error(
                    'invalid_params',
                    'Provide status as one of: ' . implode( ', ', self::MIGRATION_DETAILS_STATUSES ) . '.',
                    [ 'status' => 400 ]
                );
            }

            if ( ! preg_match( '/^[A-Za-z0-9._-]{1,32}$/', $engine ) ) {
                return new WP_Error(
                    'invalid_params',
                    'Provide engine as a short slug, e.g. "v4".',
                    [ 'status' => 400 ]
                );
            }

            // Merge over whatever is already stored instead of replacing it. A migration writes
            // this option more than once (initiated, then the terminal status), and the source
            // site's own record may be sitting here after the database import — so a status-only
            // update must not silently drop keys another writer put there.
            $existing = get_option( 'instawp_last_migration_details' );
            if ( ! is_array( $existing ) ) {
                $existing = [];
            }

            $details = array_merge( $existing, [
                'migrate_group_uuid' => $migration_id,
                'migrate_engine'     => $engine,
                'status'             => $status,
            ] );

            // update_option() returns false when the stored value is already identical, which is a
            // no-op and not a failure — so the outcome is confirmed by reading the option back
            // rather than by trusting the return value.
            update_option( 'instawp_last_migration_details', $details, false );

            $stored   = get_option( 'instawp_last_migration_details' );
            $verified = is_array( $stored )
                && isset( $stored['migrate_group_uuid'], $stored['migrate_engine'], $stored['status'] )
                && $stored['migrate_group_uuid'] === $migration_id
                && $stored['migrate_engine'] === $engine
                && $stored['status'] === $status;

            if ( ! $verified ) {
                return new WP_Error(
                    'update_failed',
                    'The migration details option did not read back with the values just written.',
                    [ 'status' => 500 ]
                );
            }

            return rest_ensure_response( [
                'success' => true,
                'option'  => 'instawp_last_migration_details',
                'details' => $stored,
            ] );
        } catch (\Exception $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        } catch (\Throwable $e) {
            return new WP_Error( 'exception', $e->getMessage(), [ 'status' => 500 ] );
        }
    }
}

// Boot the plugin
InstaMigrate::instance();
