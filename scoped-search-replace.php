<?php
/**
 * Plugin Name:       Scoped Search & Replace
 * Plugin URI:        https://github.com/Munish86/WordPress-Search-Replace-Tool
 * Description:       Search, count and safely replace URLs or text in posts, pages and post meta, limited to the table, field, content type, status and post you choose.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Munish
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       scoped-search-replace
 *
 * @package Scoped_Search_Replace
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SCOPEDSR_VERSION', '1.1.0');
define('SCOPEDSR_FILE', __FILE__);

final class Scoped_Search_Replace {
    private const MENU_SLUG = 'scoped-search-replace';
    private const NONCE_ACTION = 'scopedsr_search_replace_action';
    private const NONCE_NAME = 'scopedsr_nonce';
    private const FLASH_PREFIX = 'scopedsr_flash_';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_request']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('plugin_action_links_' . plugin_basename(SCOPEDSR_FILE), [$this, 'add_action_links']);
    }

    public function register_admin_menu(): void {
        add_menu_page(
            __('Scoped Search & Replace', 'scoped-search-replace'),
            __('Search & Replace', 'scoped-search-replace'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page'],
            'dashicons-admin-tools',
            80
        );
    }

    public function add_action_links(array $links): array {
        $tool_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)),
            esc_html__('Open tool', 'scoped-search-replace')
        );
        array_unshift($links, $tool_link);
        return $links;
    }

    public function enqueue_assets(string $hook_suffix): void {
        if ($hook_suffix !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style(
            'scopedsr-admin',
            plugin_dir_url(SCOPEDSR_FILE) . 'assets/admin.css',
            [],
            SCOPEDSR_VERSION
        );

        wp_enqueue_script(
            'scopedsr-admin',
            plugin_dir_url(SCOPEDSR_FILE) . 'assets/admin.js',
            [],
            SCOPEDSR_VERSION,
            true
        );

        wp_localize_script('scopedsr-admin', 'scopedsrL10n', [
            'review' => __('Please review the replacement:', 'scoped-search-replace'),
            'oldValue' => __('Old value:', 'scoped-search-replace'),
            'newValue' => __('New value:', 'scoped-search-replace'),
            'table' => __('Table:', 'scoped-search-replace'),
            'field' => __('Field:', 'scoped-search-replace'),
            'scope' => __('Scope:', 'scoped-search-replace'),
            'proceed' => __('Create a backup before continuing. Do you want to proceed?', 'scoped-search-replace'),
        ]);
    }

    private function allowed_tables(): array {
        global $wpdb;

        return [
            'posts' => [
                'label' => $wpdb->posts,
                'columns' => [
                    'post_content' => __('Post/Page Content', 'scoped-search-replace'),
                    'post_title' => __('Post/Page Title', 'scoped-search-replace'),
                    'post_excerpt' => __('Post/Page Excerpt', 'scoped-search-replace'),
                ],
            ],
            'postmeta' => [
                'label' => $wpdb->postmeta,
                'columns' => [
                    'meta_value' => __('Custom Field / Meta Value', 'scoped-search-replace'),
                ],
            ],
        ];
    }

    private function allowed_statuses(): array {
        return [
            'all' => __('All statuses', 'scoped-search-replace'),
            'publish' => __('Published', 'scoped-search-replace'),
            'draft' => __('Draft', 'scoped-search-replace'),
            'pending' => __('Pending review', 'scoped-search-replace'),
            'private' => __('Private', 'scoped-search-replace'),
            'future' => __('Scheduled', 'scoped-search-replace'),
        ];
    }

    private function public_post_types(): array {
        $types = get_post_types(['public' => true], 'objects');
        unset($types['attachment']);
        return $types;
    }

    private function defaults(): array {
        return [
            'message' => '',
            'message_type' => '',
            'results' => [],
            'search_text' => '',
            'old_text' => '',
            'new_text' => '',
            'selected_table' => 'posts',
            'selected_column' => 'post_content',
            'selected_post_type' => 'post',
            'selected_post_id' => 0,
            'selected_status' => 'publish',
        ];
    }

    private function flash_key(): string {
        return self::FLASH_PREFIX . get_current_user_id();
    }

    // The input helpers below run only after check_admin_referer() in handle_request().
    private function get_text_input(string $key): string {
        // The exact text is needed (URLs, HTML fragments), so it is not sanitized. It is only used
        // in prepared queries / str_replace and is escaped on output.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        return isset($_POST[$key]) ? trim(wp_unslash($_POST[$key])) : '';
    }

    private function get_key_input(string $key, string $default = ''): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return isset($_POST[$key]) ? sanitize_key(wp_unslash($_POST[$key])) : $default;
    }

    private function get_int_input(string $key): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return isset($_POST[$key]) ? absint(wp_unslash($_POST[$key])) : 0;
    }

    private function validate_scope(array $state): void {
        $tables = $this->allowed_tables();
        $types = $this->public_post_types();
        $statuses = $this->allowed_statuses();

        if (!isset($tables[$state['selected_table']])) {
            wp_die(esc_html__('Invalid database table selection.', 'scoped-search-replace'));
        }

        if (!isset($tables[$state['selected_table']]['columns'][$state['selected_column']])) {
            wp_die(esc_html__('Invalid database field selection.', 'scoped-search-replace'));
        }

        if ($state['selected_post_type'] !== 'all' && !isset($types[$state['selected_post_type']])) {
            wp_die(esc_html__('Invalid content type selection.', 'scoped-search-replace'));
        }

        if (!isset($statuses[$state['selected_status']])) {
            wp_die(esc_html__('Invalid post status selection.', 'scoped-search-replace'));
        }

        if ($state['selected_post_id'] > 0) {
            $post = get_post($state['selected_post_id']);
            if (!$post) {
                wp_die(esc_html__('The selected page or post does not exist.', 'scoped-search-replace'));
            }
        }
    }

    public function handle_request(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only routes the request; the nonce is checked below.
        if (!isset($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== self::MENU_SLUG) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($method !== 'POST') {
            return;
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $state = $this->defaults();
        $state['selected_table'] = $this->get_key_input('selected_table', 'posts');
        $state['selected_column'] = $this->get_key_input('selected_column', 'post_content');
        $state['selected_post_type'] = $this->get_key_input('selected_post_type', 'post');
        $state['selected_post_id'] = $this->get_int_input('selected_post_id');
        $state['selected_status'] = $this->get_key_input('selected_status', 'publish');

        $this->validate_scope($state);

        $action = $this->get_key_input('tool_action');

        if ($action === 'search') {
            $state['search_text'] = $this->get_text_input('search_text');

            if ($state['search_text'] === '') {
                $state['message'] = __('Enter a URL or text to search.', 'scoped-search-replace');
                $state['message_type'] = 'error';
            } else {
                $state['results'] = $this->search_database($state['search_text'], $state);
                if ($state['results']['rows'] > 0) {
                    $state['message'] = sprintf(
                        /* translators: 1: number of matching database rows, 2: total number of occurrences. */
                        __('Search completed. Found %1$d matching database row(s) and %2$d total occurrence(s).', 'scoped-search-replace'),
                        $state['results']['rows'],
                        $state['results']['occurrences']
                    );
                    $state['message_type'] = 'success';
                } else {
                    $state['message'] = __('No matching records were found in the selected location.', 'scoped-search-replace');
                    $state['message_type'] = 'info';
                }
            }
        } elseif ($action === 'replace') {
            $state['old_text'] = $this->get_text_input('old_text');
            $state['new_text'] = $this->get_text_input('new_text');

            if ($state['old_text'] === '' || $state['new_text'] === '') {
                $state['message'] = __('Enter both the old URL/text and the new URL/text.', 'scoped-search-replace');
                $state['message_type'] = 'error';
            } elseif ($state['old_text'] === $state['new_text']) {
                $state['message'] = __('The old and new values cannot be identical.', 'scoped-search-replace');
                $state['message_type'] = 'error';
            } else {
                $before = $this->search_database($state['old_text'], $state);
                if ($before['rows'] === 0) {
                    $state['message'] = __('The old value was not found. Nothing was replaced.', 'scoped-search-replace');
                    $state['message_type'] = 'info';
                } else {
                    $replace_result = $this->replace_database($state['old_text'], $state['new_text'], $state, $before);
                    if ($replace_result['updated_rows'] > 0) {
                        $state['message'] = sprintf(
                            /* translators: 1: number of updated database rows, 2: number of replaced occurrences. */
                            __('Replacement completed. Updated %1$d database row(s) and replaced %2$d occurrence(s).', 'scoped-search-replace'),
                            $replace_result['updated_rows'],
                            $replace_result['replaced_occurrences']
                        );
                        $state['message_type'] = 'success';
                        $state['search_text'] = $state['old_text'];
                        $state['results'] = $this->search_database($state['old_text'], $state);
                    } else {
                        $state['message'] = __('No database rows were updated.', 'scoped-search-replace');
                        $state['message_type'] = 'error';
                    }
                    if (!empty($replace_result['failed_rows'])) {
                        $state['message'] .= ' ' . sprintf(
                            /* translators: %s: comma-separated list of database record IDs. */
                            __('Failed or skipped record IDs: %s.', 'scoped-search-replace'),
                            implode(', ', $replace_result['failed_rows'])
                        );
                    }
                }
            }
        }

        set_transient($this->flash_key(), $state, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&result=1'));
        exit;
    }

    // $column is always one of the whitelisted names from allowed_tables() (checked in validate_scope()),
    // so it is safe to place in the SQL. All values go through $wpdb->prepare(). Results must be live
    // (no caching) because they are about to be changed.
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private function search_database(string $search_text, array $state): array {
        global $wpdb;

        $result = ['rows' => 0, 'occurrences' => 0, 'items' => []];
        $like = '%' . $wpdb->esc_like($search_text) . '%';
        $column = $state['selected_column'];

        if ($state['selected_table'] === 'posts') {
            $conditions = ["{$column} LIKE %s"];
            $parameters = [$like];

            if ($state['selected_post_type'] !== 'all') {
                $conditions[] = 'post_type = %s';
                $parameters[] = $state['selected_post_type'];
            }
            if ($state['selected_status'] !== 'all') {
                $conditions[] = 'post_status = %s';
                $parameters[] = $state['selected_status'];
            }
            if ($state['selected_post_id'] > 0) {
                $conditions[] = 'ID = %d';
                $parameters[] = $state['selected_post_id'];
            }

            $sql = "SELECT ID, post_title, post_type, post_status, {$column} AS searched_value FROM {$wpdb->posts} WHERE " . implode(' AND ', $conditions) . ' ORDER BY ID DESC';
            $rows = $wpdb->get_results($wpdb->prepare($sql, $parameters));

            foreach ($rows as $row) {
                $count = substr_count((string) $row->searched_value, $search_text);
                if ($count < 1) {
                    continue;
                }
                $result['occurrences'] += $count;
                $result['items'][] = [
                    'record_id' => (int) $row->ID,
                    'post_id' => (int) $row->ID,
                    'title' => $row->post_title ?: __('(No title)', 'scoped-search-replace'),
                    'post_type' => $row->post_type,
                    'post_status' => $row->post_status,
                    'meta_key' => '',
                    'occurrences' => $count,
                    'edit_url' => get_edit_post_link($row->ID, ''),
                    'view_url' => get_permalink($row->ID),
                ];
            }
        } else {
            $conditions = ['pm.meta_value LIKE %s'];
            $parameters = [$like];

            if ($state['selected_post_type'] !== 'all') {
                $conditions[] = 'p.post_type = %s';
                $parameters[] = $state['selected_post_type'];
            }
            if ($state['selected_status'] !== 'all') {
                $conditions[] = 'p.post_status = %s';
                $parameters[] = $state['selected_status'];
            }
            if ($state['selected_post_id'] > 0) {
                $conditions[] = 'pm.post_id = %d';
                $parameters[] = $state['selected_post_id'];
            }

            $sql = "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value AS searched_value, p.post_title, p.post_type, p.post_status FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE " . implode(' AND ', $conditions) . ' ORDER BY pm.meta_id DESC';
            $rows = $wpdb->get_results($wpdb->prepare($sql, $parameters));

            foreach ($rows as $row) {
                $count = substr_count((string) $row->searched_value, $search_text);
                if ($count < 1) {
                    continue;
                }
                $result['occurrences'] += $count;
                $result['items'][] = [
                    'record_id' => (int) $row->meta_id,
                    'post_id' => (int) $row->post_id,
                    'title' => $row->post_title ?: __('(No title)', 'scoped-search-replace'),
                    'post_type' => $row->post_type,
                    'post_status' => $row->post_status,
                    'meta_key' => $row->meta_key,
                    'occurrences' => $count,
                    'edit_url' => get_edit_post_link($row->post_id, ''),
                    'view_url' => get_permalink($row->post_id),
                ];
            }
        }

        $result['rows'] = count($result['items']);
        return $result;
    }

    private function replace_database(string $old_text, string $new_text, array $state, array $search_results): array {
        global $wpdb;

        $output = ['updated_rows' => 0, 'replaced_occurrences' => 0, 'failed_rows' => []];
        $column = $state['selected_column'];

        foreach ($search_results['items'] as $item) {
            $record_id = (int) $item['record_id'];
            $post_id = (int) $item['post_id'];

            if ($state['selected_table'] === 'posts') {
                $current = $wpdb->get_var($wpdb->prepare("SELECT {$column} FROM {$wpdb->posts} WHERE ID = %d", $record_id));
            } else {
                $current = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $record_id));
            }

            if ($current === null) {
                $output['failed_rows'][] = $record_id;
                continue;
            }

            $count = 0;
            $updated = $this->safe_replace_value($current, $old_text, $new_text, $count);
            if ($updated === null) {
                // Broken serialized data: leave the row untouched rather than corrupt it further.
                $output['failed_rows'][] = $record_id;
                continue;
            }
            if ($count < 1) {
                continue;
            }

            if ($state['selected_table'] === 'posts') {
                $saved = $wpdb->update($wpdb->posts, [$column => $updated], ['ID' => $record_id], ['%s'], ['%d']);
            } else {
                $saved = $wpdb->update($wpdb->postmeta, ['meta_value' => $updated], ['meta_id' => $record_id], ['%s'], ['%d']);
            }

            if ($saved === false) {
                $output['failed_rows'][] = $record_id;
                continue;
            }

            clean_post_cache($post_id);
            $output['updated_rows']++;
            $output['replaced_occurrences'] += $count;
        }

        return $output;
    }
    // phpcs:enable

    /**
     * Replaces text in a stored value. Serialized values are unserialized, replaced recursively and
     * serialized again, so string lengths stay correct. Returns null when serialized data cannot be read.
     */
    private function safe_replace_value($value, string $old_text, string $new_text, int &$count): ?string {
        $count = 0;

        if (is_serialized($value)) {
            $decoded = maybe_unserialize($value);
            if ($decoded === false && trim($value) !== 'b:0;') {
                return null;
            }
            $decoded = $this->recursive_replace($decoded, $old_text, $new_text, $count);
            if ($count < 1) {
                return (string) $value;
            }
            // serialize(), not maybe_serialize(): a serialized plain string must be serialized again too.
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
            return serialize($decoded);
        }

        return str_replace($old_text, $new_text, (string) $value, $count);
    }

    private function recursive_replace($value, string $old_text, string $new_text, int &$count) {
        if (is_string($value)) {
            $local = 0;
            $value = str_replace($old_text, $new_text, $value, $local);
            $count += $local;
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->recursive_replace($item, $old_text, $new_text, $count);
            }
            return $value;
        }
        if (is_object($value) && !($value instanceof __PHP_Incomplete_Class)) {
            foreach ($value as $key => $item) {
                $value->{$key} = $this->recursive_replace($item, $old_text, $new_text, $count);
            }
        }
        return $value;
    }

    private function selectable_items(): array {
        return get_posts([
            'post_type' => array_keys($this->public_post_types()),
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
    }

    private function load_state(): array {
        $state = $this->defaults();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows this user's own stored result.
        if (isset($_GET['result']) && sanitize_key(wp_unslash($_GET['result'])) === '1') {
            $flash = get_transient($this->flash_key());
            if (is_array($flash)) {
                $state = array_merge($state, $flash);
                delete_transient($this->flash_key());
            }
        }
        return $state;
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'scoped-search-replace'));
        }

        $state = $this->load_state();
        $tables = $this->allowed_tables();
        $statuses = $this->allowed_statuses();
        $types = $this->public_post_types();
        $items = $this->selectable_items();
        $form_url = admin_url('admin.php?page=' . self::MENU_SLUG);
        ?>
        <div class="wrap scopedsr-wrap">
            <h1><?php esc_html_e('Scoped Search & Replace', 'scoped-search-replace'); ?></h1>
            <p class="scopedsr-subtitle"><?php esc_html_e('Search, count and replace URLs or text in selected WordPress database locations.', 'scoped-search-replace'); ?></p>

            <div class="notice notice-warning inline"><p><strong><?php esc_html_e('Important:', 'scoped-search-replace'); ?></strong> <?php esc_html_e('Create a full website backup before replacing anything. Use the search option first to review all matching records.', 'scoped-search-replace'); ?></p></div>

            <?php if ($state['message'] !== '') : ?>
                <div class="notice notice-<?php echo esc_attr($state['message_type'] === 'error' ? 'error' : ($state['message_type'] === 'success' ? 'success' : 'info')); ?> is-dismissible"><p><strong><?php echo esc_html($state['message']); ?></strong></p></div>
            <?php endif; ?>

            <div class="scopedsr-card">
                <h2><?php esc_html_e('1. Search and Count', 'scoped-search-replace'); ?></h2>
                <p><?php esc_html_e('Select where you want to search, then enter the URL or text.', 'scoped-search-replace'); ?></p>
                <form method="post" action="<?php echo esc_url($form_url); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                    <input type="hidden" name="tool_action" value="search">
                    <?php $this->render_scope_fields($state, $tables, $types, $statuses, $items, 'search'); ?>
                    <div class="scopedsr-field">
                        <label for="scopedsr-search-text"><?php esc_html_e('URL or Text to Search', 'scoped-search-replace'); ?></label>
                        <input type="text" id="scopedsr-search-text" name="search_text" value="<?php echo esc_attr($state['search_text']); ?>" placeholder="https://old.example.com/" required>
                    </div>
                    <button type="submit" class="button button-primary button-large"><?php esc_html_e('Search and Count', 'scoped-search-replace'); ?></button>
                </form>
            </div>

            <div class="scopedsr-card">
                <h2><?php esc_html_e('2. Find and Replace', 'scoped-search-replace'); ?></h2>
                <p><?php esc_html_e('The replacement only runs inside your selected table, field, content type, status and page/post.', 'scoped-search-replace'); ?></p>
                <form method="post" class="scopedsr-replace-form" action="<?php echo esc_url($form_url); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                    <input type="hidden" name="tool_action" value="replace">
                    <?php $this->render_scope_fields($state, $tables, $types, $statuses, $items, 'replace'); ?>
                    <div class="scopedsr-grid">
                        <div class="scopedsr-field">
                            <label for="scopedsr-old-text"><?php esc_html_e('Old URL or Text', 'scoped-search-replace'); ?></label>
                            <input type="text" id="scopedsr-old-text" name="old_text" value="<?php echo esc_attr($state['old_text']); ?>" placeholder="https://old.example.com/" required>
                        </div>
                        <div class="scopedsr-field">
                            <label for="scopedsr-new-text"><?php esc_html_e('New URL or Text', 'scoped-search-replace'); ?></label>
                            <input type="text" id="scopedsr-new-text" name="new_text" value="<?php echo esc_attr($state['new_text']); ?>" placeholder="https://www.example.com/" required>
                        </div>
                    </div>
                    <button type="submit" class="button button-primary button-large scopedsr-danger"><?php esc_html_e('Replace Selected Records', 'scoped-search-replace'); ?></button>
                </form>
            </div>

            <?php if (!empty($state['results'])) : ?>
                <div class="scopedsr-card">
                    <h2><?php esc_html_e('Search Results', 'scoped-search-replace'); ?></h2>
                    <div class="scopedsr-stats">
                        <div><strong><?php echo esc_html($state['results']['rows']); ?></strong><span><?php esc_html_e('Matching Database Rows', 'scoped-search-replace'); ?></span></div>
                        <div><strong><?php echo esc_html($state['results']['occurrences']); ?></strong><span><?php esc_html_e('Total Occurrences', 'scoped-search-replace'); ?></span></div>
                    </div>

                    <?php if (!empty($state['results']['items'])) : ?>
                        <div class="scopedsr-table-wrap">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Record ID', 'scoped-search-replace'); ?></th>
                                        <th><?php esc_html_e('Post/Page ID', 'scoped-search-replace'); ?></th>
                                        <th><?php esc_html_e('Title', 'scoped-search-replace'); ?></th>
                                        <th><?php esc_html_e('Type', 'scoped-search-replace'); ?></th>
                                        <th><?php esc_html_e('Status', 'scoped-search-replace'); ?></th>
                                        <?php if ($state['selected_table'] === 'postmeta') : ?><th><?php esc_html_e('Meta Key', 'scoped-search-replace'); ?></th><?php endif; ?>
                                        <th><?php esc_html_e('Occurrences', 'scoped-search-replace'); ?></th>
                                        <th><?php esc_html_e('Actions', 'scoped-search-replace'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($state['results']['items'] as $item) : ?>
                                    <tr>
                                        <td><?php echo esc_html($item['record_id']); ?></td>
                                        <td><?php echo esc_html($item['post_id']); ?></td>
                                        <td><?php echo esc_html($item['title']); ?></td>
                                        <td><?php echo esc_html($item['post_type']); ?></td>
                                        <td><?php echo esc_html($item['post_status']); ?></td>
                                        <?php if ($state['selected_table'] === 'postmeta') : ?><td><code><?php echo esc_html($item['meta_key']); ?></code></td><?php endif; ?>
                                        <td><?php echo esc_html($item['occurrences']); ?></td>
                                        <td><?php if ($item['edit_url']) : ?><a href="<?php echo esc_url($item['edit_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Edit', 'scoped-search-replace'); ?></a><?php endif; ?><?php if ($item['view_url']) : ?> | <a href="<?php echo esc_url($item['view_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'scoped-search-replace'); ?></a><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else : ?>
                        <p><?php esc_html_e('No remaining matches were found.', 'scoped-search-replace'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_scope_fields(array $state, array $tables, array $types, array $statuses, array $items, string $prefix): void {
        ?>
        <div class="scopedsr-scope">
            <h3><?php esc_html_e('Search Location and Content Scope', 'scoped-search-replace'); ?></h3>
            <div class="scopedsr-grid">
                <div class="scopedsr-field">
                    <label for="<?php echo esc_attr($prefix); ?>-table"><?php esc_html_e('Database Table', 'scoped-search-replace'); ?></label>
                    <select id="<?php echo esc_attr($prefix); ?>-table" name="selected_table" class="scopedsr-table-selector">
                        <?php foreach ($tables as $key => $table) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($state['selected_table'], $key); ?>><?php echo esc_html($table['label']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="scopedsr-field">
                    <label for="<?php echo esc_attr($prefix); ?>-column"><?php esc_html_e('Database Field', 'scoped-search-replace'); ?></label>
                    <select id="<?php echo esc_attr($prefix); ?>-column" name="selected_column" class="scopedsr-column-selector">
                        <?php foreach ($tables as $table_key => $table) : ?>
                            <?php foreach ($table['columns'] as $column_key => $column_label) : ?>
                                <option value="<?php echo esc_attr($column_key); ?>" data-table="<?php echo esc_attr($table_key); ?>" <?php selected($state['selected_column'], $column_key); ?>><?php echo esc_html($column_label); ?></option>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="scopedsr-field">
                    <label for="<?php echo esc_attr($prefix); ?>-post-type"><?php esc_html_e('Content Type', 'scoped-search-replace'); ?></label>
                    <select id="<?php echo esc_attr($prefix); ?>-post-type" name="selected_post_type" class="scopedsr-post-type-selector">
                        <option value="all" <?php selected($state['selected_post_type'], 'all'); ?>><?php esc_html_e('All Content Types', 'scoped-search-replace'); ?></option>
                        <?php foreach ($types as $type) : ?><option value="<?php echo esc_attr($type->name); ?>" <?php selected($state['selected_post_type'], $type->name); ?>><?php echo esc_html($type->labels->singular_name); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="scopedsr-field">
                    <label for="<?php echo esc_attr($prefix); ?>-status"><?php esc_html_e('Post Status', 'scoped-search-replace'); ?></label>
                    <select id="<?php echo esc_attr($prefix); ?>-status" name="selected_status" class="scopedsr-status-selector">
                        <?php foreach ($statuses as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($state['selected_status'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="scopedsr-field scopedsr-full">
                    <label for="<?php echo esc_attr($prefix); ?>-post-id"><?php esc_html_e('Specific Page or Post', 'scoped-search-replace'); ?></label>
                    <select id="<?php echo esc_attr($prefix); ?>-post-id" name="selected_post_id" class="scopedsr-item-selector">
                        <option value="0"><?php esc_html_e('All pages/posts matching the selected filters', 'scoped-search-replace'); ?></option>
                        <?php foreach ($items as $item) : ?>
                            <option value="<?php echo esc_attr($item->ID); ?>" data-post-type="<?php echo esc_attr($item->post_type); ?>" data-post-status="<?php echo esc_attr($item->post_status); ?>" <?php selected($state['selected_post_id'], $item->ID); ?>><?php echo esc_html(($item->post_title ?: __('(No title)', 'scoped-search-replace')) . ' — ID ' . $item->ID . ' — ' . $item->post_type . ' — ' . $item->post_status); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Choose a specific item, or leave this set to all matching pages/posts.', 'scoped-search-replace'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }
}

new Scoped_Search_Replace();
