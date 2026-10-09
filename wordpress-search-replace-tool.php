<?php
/**
 * Plugin Name: WordPress Search & Replace Tool
 * Description: Search, count and safely replace URLs or text in WordPress posts, pages and post meta from the admin dashboard.
 * Version: 1.0.0
 * Author: Custom Tool
 * License: GPL-2.0-or-later
 * Text Domain: wpsrt
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WPSRT_Search_Replace_Tool {
    private const MENU_SLUG = 'wpsrt-search-replace';
    private const NONCE_ACTION = 'wpsrt_search_replace_action';
    private const NONCE_NAME = 'wpsrt_nonce';
    private const FLASH_PREFIX = 'wpsrt_flash_';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_request']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_admin_menu(): void {
        add_menu_page(
            'Search & Replace Tool',
            'Search & Replace',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page'],
            'dashicons-admin-tools',
            80
        );
    }

    public function enqueue_assets(string $hook_suffix): void {
        if ($hook_suffix !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style(
            'wpsrt-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'wpsrt-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            [],
            '1.0.0',
            true
        );
    }

    private function allowed_tables(): array {
        global $wpdb;

        return [
            'posts' => [
                'label' => $wpdb->posts,
                'columns' => [
                    'post_content' => 'Post/Page Content',
                    'post_title' => 'Post/Page Title',
                    'post_excerpt' => 'Post/Page Excerpt',
                ],
            ],
            'postmeta' => [
                'label' => $wpdb->postmeta,
                'columns' => [
                    'meta_value' => 'Custom Field / Meta Value',
                ],
            ],
        ];
    }

    private function allowed_statuses(): array {
        return [
            'all' => 'All statuses',
            'publish' => 'Published',
            'draft' => 'Draft',
            'pending' => 'Pending review',
            'private' => 'Private',
            'future' => 'Scheduled',
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

    private function get_text_input(string $key): string {
        return isset($_POST[$key]) ? trim(wp_unslash($_POST[$key])) : '';
    }

    private function get_key_input(string $key, string $default = ''): string {
        return isset($_POST[$key]) ? sanitize_key(wp_unslash($_POST[$key])) : $default;
    }

    private function get_int_input(string $key): int {
        return isset($_POST[$key]) ? absint($_POST[$key]) : 0;
    }

    private function validate_scope(array $state): void {
        $tables = $this->allowed_tables();
        $types = $this->public_post_types();
        $statuses = $this->allowed_statuses();

        if (!isset($tables[$state['selected_table']])) {
            wp_die('Invalid database table selection.');
        }

        if (!isset($tables[$state['selected_table']]['columns'][$state['selected_column']])) {
            wp_die('Invalid database field selection.');
        }

        if ($state['selected_post_type'] !== 'all' && !isset($types[$state['selected_post_type']])) {
            wp_die('Invalid content type selection.');
        }

        if (!isset($statuses[$state['selected_status']])) {
            wp_die('Invalid post status selection.');
        }

        if ($state['selected_post_id'] > 0) {
            $post = get_post($state['selected_post_id']);
            if (!$post) {
                wp_die('The selected page or post does not exist.');
            }
        }
    }

    public function handle_request(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (!isset($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== self::MENU_SLUG) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
                $state['message'] = 'Enter a URL or text to search.';
                $state['message_type'] = 'error';
            } else {
                $state['results'] = $this->search_database($state['search_text'], $state);
                if ($state['results']['rows'] > 0) {
                    $state['message'] = sprintf(
                        'Search completed. Found %d matching database row(s) and %d total occurrence(s).',
                        $state['results']['rows'],
                        $state['results']['occurrences']
                    );
                    $state['message_type'] = 'success';
                } else {
                    $state['message'] = 'No matching records were found in the selected location.';
                    $state['message_type'] = 'info';
                }
            }
        } elseif ($action === 'replace') {
            $state['old_text'] = $this->get_text_input('old_text');
            $state['new_text'] = $this->get_text_input('new_text');

            if ($state['old_text'] === '' || $state['new_text'] === '') {
                $state['message'] = 'Enter both the old URL/text and the new URL/text.';
                $state['message_type'] = 'error';
            } elseif ($state['old_text'] === $state['new_text']) {
                $state['message'] = 'The old and new values cannot be identical.';
                $state['message_type'] = 'error';
            } else {
                $before = $this->search_database($state['old_text'], $state);
                if ($before['rows'] === 0) {
                    $state['message'] = 'The old value was not found. Nothing was replaced.';
                    $state['message_type'] = 'info';
                } else {
                    $replace_result = $this->replace_database($state['old_text'], $state['new_text'], $state, $before);
                    if ($replace_result['updated_rows'] > 0) {
                        $state['message'] = sprintf(
                            'Replacement completed. Updated %d database row(s) and replaced %d occurrence(s).',
                            $replace_result['updated_rows'],
                            $replace_result['replaced_occurrences']
                        );
                        if (!empty($replace_result['failed_rows'])) {
                            $state['message'] .= ' Failed record IDs: ' . implode(', ', $replace_result['failed_rows']) . '.';
                        }
                        $state['message_type'] = 'success';
                        $state['search_text'] = $state['old_text'];
                        $state['results'] = $this->search_database($state['old_text'], $state);
                    } else {
                        $state['message'] = 'No database rows were updated.';
                        $state['message_type'] = 'error';
                    }
                }
            }
        }

        set_transient($this->flash_key(), $state, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&result=1'));
        exit;
    }

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
                    'title' => $row->post_title ?: '(No title)',
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
                    'title' => $row->post_title ?: '(No title)',
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

    private function safe_replace_value($value, string $old_text, string $new_text, int &$count): string {
        $count = 0;

        if (is_serialized($value)) {
            $decoded = maybe_unserialize($value);
            $decoded = $this->recursive_replace($decoded, $old_text, $new_text, $count);
            return maybe_serialize($decoded);
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
        if (is_object($value)) {
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
        ]);
    }

    private function load_state(): array {
        $state = $this->defaults();
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
            wp_die('You do not have permission to access this page.');
        }

        $state = $this->load_state();
        $tables = $this->allowed_tables();
        $statuses = $this->allowed_statuses();
        $types = $this->public_post_types();
        $items = $this->selectable_items();
        ?>
        <div class="wrap wpsrt-wrap">
            <h1>WordPress Search &amp; Replace Tool</h1>
            <p class="wpsrt-subtitle">Search, count and replace URLs or text in selected WordPress database locations.</p>

            <div class="notice notice-warning inline"><p><strong>Important:</strong> Create a full website backup before replacing anything. Use the search option first to review all matching records.</p></div>

            <?php if ($state['message'] !== '') : ?>
                <div class="notice notice-<?php echo esc_attr($state['message_type'] === 'error' ? 'error' : ($state['message_type'] === 'success' ? 'success' : 'info')); ?> is-dismissible"><p><strong><?php echo esc_html($state['message']); ?></strong></p></div>
            <?php endif; ?>

            <div class="wpsrt-card">
                <h2>1. Search and Count</h2>
                <p>Select where you want to search, then enter the URL or text.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                    <input type="hidden" name="tool_action" value="search">
                    <?php $this->render_scope_fields($state, $tables, $types, $statuses, $items, 'search'); ?>
                    <div class="wpsrt-field">
                        <label for="wpsrt-search-text">URL or Text to Search</label>
                        <input type="text" id="wpsrt-search-text" name="search_text" value="<?php echo esc_attr($state['search_text']); ?>" placeholder="https://.manchesteraesthetics.com/" required>
                    </div>
                    <button type="submit" class="button button-primary button-large">Search and Count</button>
                </form>
            </div>

            <div class="wpsrt-card">
                <h2>2. Find and Replace</h2>
                <p>The replacement only runs inside your selected table, field, content type, status and page/post.</p>
                <form method="post" class="wpsrt-replace-form" action="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                    <input type="hidden" name="tool_action" value="replace">
                    <?php $this->render_scope_fields($state, $tables, $types, $statuses, $items, 'replace'); ?>
                    <div class="wpsrt-grid">
                        <div class="wpsrt-field">
                            <label for="wpsrt-old-text">Old URL or Text</label>
                            <input type="text" id="wpsrt-old-text" name="old_text" value="<?php echo esc_attr($state['old_text']); ?>" placeholder="https://.manchesteraesthetics.com/" required>
                        </div>
                        <div class="wpsrt-field">
                            <label for="wpsrt-new-text">New URL or Text</label>
                            <input type="text" id="wpsrt-new-text" name="new_text" value="<?php echo esc_attr($state['new_text']); ?>" placeholder="https://www.manchesteraesthetics.com/" required>
                        </div>
                    </div>
                    <button type="submit" class="button button-primary button-large wpsrt-danger">Replace Selected Records</button>
                </form>
            </div>

            <?php if (!empty($state['results'])) : ?>
                <div class="wpsrt-card">
                    <h2>Search Results</h2>
                    <div class="wpsrt-stats">
                        <div><strong><?php echo esc_html($state['results']['rows']); ?></strong><span>Matching Database Rows</span></div>
                        <div><strong><?php echo esc_html($state['results']['occurrences']); ?></strong><span>Total Occurrences</span></div>
                    </div>

                    <?php if (!empty($state['results']['items'])) : ?>
                        <div class="wpsrt-table-wrap">
                            <table class="widefat striped">
                                <thead><tr><th>Record ID</th><th>Post/Page ID</th><th>Title</th><th>Type</th><th>Status</th><?php if ($state['selected_table'] === 'postmeta') : ?><th>Meta Key</th><?php endif; ?><th>Occurrences</th><th>Actions</th></tr></thead>
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
                                        <td><?php if ($item['edit_url']) : ?><a href="<?php echo esc_url($item['edit_url']); ?>" target="_blank" rel="noopener">Edit</a><?php endif; ?><?php if ($item['view_url']) : ?> | <a href="<?php echo esc_url($item['view_url']); ?>" target="_blank" rel="noopener">View</a><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else : ?>
                        <p>No remaining matches were found.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_scope_fields(array $state, array $tables, array $types, array $statuses, array $items, string $prefix): void {
        ?>
        <div class="wpsrt-scope">
            <h3>Search Location and Content Scope</h3>
            <div class="wpsrt-grid">
                <div class="wpsrt-field">
                    <label for="<?php echo esc_attr($prefix); ?>-table">Database Table</label>
                    <select id="<?php echo esc_attr($prefix); ?>-table" name="selected_table" class="wpsrt-table-selector">
                        <?php foreach ($tables as $key => $table) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($state['selected_table'], $key); ?>><?php echo esc_html($table['label']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="wpsrt-field">
                    <label for="<?php echo esc_attr($prefix); ?>-column">Database Field</label>
                    <select id="<?php echo esc_attr($prefix); ?>-column" name="selected_column" class="wpsrt-column-selector">
                        <option value="post_content" data-table="posts" <?php selected($state['selected_column'], 'post_content'); ?>>Post/Page Content</option>
                        <option value="post_title" data-table="posts" <?php selected($state['selected_column'], 'post_title'); ?>>Post/Page Title</option>
                        <option value="post_excerpt" data-table="posts" <?php selected($state['selected_column'], 'post_excerpt'); ?>>Post/Page Excerpt</option>
                        <option value="meta_value" data-table="postmeta" <?php selected($state['selected_column'], 'meta_value'); ?>>Custom Field / Meta Value</option>
                    </select>
                </div>
                <div class="wpsrt-field">
                    <label for="<?php echo esc_attr($prefix); ?>-post-type">Content Type</label>
                    <select id="<?php echo esc_attr($prefix); ?>-post-type" name="selected_post_type" class="wpsrt-post-type-selector">
                        <option value="all" <?php selected($state['selected_post_type'], 'all'); ?>>All Content Types</option>
                        <?php foreach ($types as $type) : ?><option value="<?php echo esc_attr($type->name); ?>" <?php selected($state['selected_post_type'], $type->name); ?>><?php echo esc_html($type->labels->singular_name); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="wpsrt-field">
                    <label for="<?php echo esc_attr($prefix); ?>-status">Post Status</label>
                    <select id="<?php echo esc_attr($prefix); ?>-status" name="selected_status" class="wpsrt-status-selector">
                        <?php foreach ($statuses as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($state['selected_status'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="wpsrt-field wpsrt-full">
                    <label for="<?php echo esc_attr($prefix); ?>-post-id">Specific Page or Post</label>
                    <select id="<?php echo esc_attr($prefix); ?>-post-id" name="selected_post_id" class="wpsrt-item-selector">
                        <option value="0">All pages/posts matching the selected filters</option>
                        <?php foreach ($items as $item) : ?>
                            <option value="<?php echo esc_attr($item->ID); ?>" data-post-type="<?php echo esc_attr($item->post_type); ?>" data-post-status="<?php echo esc_attr($item->post_status); ?>" <?php selected($state['selected_post_id'], $item->ID); ?>><?php echo esc_html(($item->post_title ?: '(No title)') . ' — ID ' . $item->ID . ' — ' . $item->post_type . ' — ' . $item->post_status); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Choose a specific item, or leave this set to all matching pages/posts.</p>
                </div>
            </div>
        </div>
        <?php
    }
}

new WPSRT_Search_Replace_Tool();
