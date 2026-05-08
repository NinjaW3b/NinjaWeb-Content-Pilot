<?php
/**
 * Plugin Name: NinjaWeb Content Pilot
 * Plugin URI: https://ninjaweb.com.au
 * Description: AI-assisted content idea, queue, quality gate, and WordPress draft generation system for NinjaWeb.
 * Version: 0.1.0
 * Author: NinjaWeb
 * Author URI: https://ninjaweb.com.au
 * Text Domain: ninjaweb-content-pilot
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

final class NinjaWeb_Content_Pilot {
    const VERSION = '0.1.0';
    const OPTION_KEY = 'nwcp_settings';
    const NONCE_ACTION = 'nwcp_admin_action';
    const IDEAS_TABLE = 'nwcp_ideas';
    const LOGS_TABLE = 'nwcp_logs';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('nwcp_generate_next_draft', array($this, 'cron_generate_next_draft'));
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $ideas_table = $wpdb->prefix . self::IDEAS_TABLE;
        $logs_table = $wpdb->prefix . self::LOGS_TABLE;

        $sql_ideas = "CREATE TABLE {$ideas_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title TEXT NOT NULL,
            normalized_title VARCHAR(220) NOT NULL,
            bucket VARCHAR(120) NOT NULL DEFAULT '',
            target_keyword VARCHAR(220) NOT NULL DEFAULT '',
            search_intent VARCHAR(120) NOT NULL DEFAULT '',
            angle VARCHAR(220) NOT NULL DEFAULT '',
            internal_links TEXT NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'manual',
            status VARCHAR(40) NOT NULL DEFAULT 'idea',
            duplicate_status VARCHAR(40) NOT NULL DEFAULT 'fresh',
            duplicate_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            duplicate_note TEXT NULL,
            post_id BIGINT UNSIGNED NULL,
            raw_payload LONGTEXT NULL,
            quality_report LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY normalized_title (normalized_title),
            KEY bucket (bucket),
            KEY status (status),
            KEY duplicate_status (duplicate_status),
            KEY post_id (post_id)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$logs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY level (level),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql_ideas);
        dbDelta($sql_logs);

        $defaults = self::default_settings();
        $existing = get_option(self::OPTION_KEY, array());
        update_option(self::OPTION_KEY, wp_parse_args($existing, $defaults));

        if (!wp_next_scheduled('nwcp_generate_next_draft')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'nwcp_generate_next_draft');
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled('nwcp_generate_next_draft');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'nwcp_generate_next_draft');
        }
    }

    public static function default_settings() {
        return array(
            'openai_api_key' => '',
            'openai_model' => 'gpt-4.1-mini',
            'draft_status' => 'draft',
            'default_author' => get_current_user_id(),
            'generation_enabled' => 'manual',
            'ideas_per_run' => 20,
            'drafts_per_cron' => 1,
            'min_word_count' => 800,
            'similar_threshold' => 72,
            'duplicate_threshold' => 88,
            'brand_tone' => 'Professional, useful, technical but accessible, with a subtle NinjaWeb/ninja-grade tone. Avoid hype, filler, and em dashes.',
            'default_cta' => 'Need help building a faster, safer, or smarter web presence? Contact NinjaWeb for a custom setup.',
            'allowed_internal_links' => implode("\n", array(
                'https://ninjaweb.com.au',
                'https://ninjaweb.com.au/services/web-hosting/',
                'https://ninjaweb.com.au/services/managed-wordpress-hosting/',
                'https://ninjaweb.com.au/services/vps-hosting/',
                'https://ninjaweb.com.au/services/dedicated-server/',
                'https://ninjaweb.com.au/services/radio-hosting/',
                'https://ninjaweb.com.au/search-engine-optimisation-seo/',
                'https://ninjaweb.com.au/ai-automation/',
                'https://ninjaweb.com.au/app-development/',
                'https://ninjaweb.com.au/business-solutions/'
            )),
            'image_style' => '1920x1080 ninja-tech featured image, cinematic, professional, dark premium digital dojo style, no readable text in image.'
        );
    }

    public function register_admin_menu() {
        add_menu_page(
            __('NinjaWeb Content Pilot', 'ninjaweb-content-pilot'),
            __('Content Pilot', 'ninjaweb-content-pilot'),
            'manage_options',
            'ninjaweb-content-pilot',
            array($this, 'render_admin_page'),
            'dashicons-welcome-write-blog',
            58
        );
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'ninjaweb-content-pilot') === false) {
            return;
        }
        wp_enqueue_style('nwcp-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), self::VERSION);
    }

    private function settings() {
        return wp_parse_args(get_option(self::OPTION_KEY, array()), self::default_settings());
    }

    private function ideas_table() {
        global $wpdb;
        return $wpdb->prefix . self::IDEAS_TABLE;
    }

    private function logs_table() {
        global $wpdb;
        return $wpdb->prefix . self::LOGS_TABLE;
    }

    private function verify_request() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage Content Pilot.', 'ninjaweb-content-pilot'));
        }
        check_admin_referer(self::NONCE_ACTION, '_nwcp_nonce');
    }

    public function handle_admin_actions() {
        if (empty($_POST['nwcp_action'])) {
            return;
        }
        $this->verify_request();
        $action = sanitize_key(wp_unslash($_POST['nwcp_action']));

        switch ($action) {
            case 'save_settings':
                $this->action_save_settings();
                break;
            case 'import_titles':
                $this->action_import_titles();
                break;
            case 'generate_ideas':
                $this->action_generate_ideas();
                break;
            case 'idea_status':
                $this->action_update_idea_status();
                break;
            case 'generate_draft':
                $this->action_generate_draft();
                break;
            case 'run_duplicate_scan':
                $this->action_run_duplicate_scan();
                break;
        }
    }

    private function redirect($tab = 'dashboard', $message = '') {
        $url = admin_url('admin.php?page=ninjaweb-content-pilot&tab=' . sanitize_key($tab));
        if ($message !== '') {
            $url = add_query_arg('nwcp_message', rawurlencode($message), $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    private function action_save_settings() {
        $settings = $this->settings();
        $settings['openai_api_key'] = isset($_POST['openai_api_key']) ? sanitize_text_field(wp_unslash($_POST['openai_api_key'])) : '';
        $settings['openai_model'] = isset($_POST['openai_model']) ? sanitize_text_field(wp_unslash($_POST['openai_model'])) : 'gpt-4.1-mini';
        $settings['draft_status'] = isset($_POST['draft_status']) && in_array($_POST['draft_status'], array('draft', 'pending'), true) ? sanitize_key($_POST['draft_status']) : 'draft';
        $settings['default_author'] = isset($_POST['default_author']) ? absint($_POST['default_author']) : get_current_user_id();
        $settings['generation_enabled'] = isset($_POST['generation_enabled']) && $_POST['generation_enabled'] === 'cron' ? 'cron' : 'manual';
        $settings['ideas_per_run'] = isset($_POST['ideas_per_run']) ? max(1, min(50, absint($_POST['ideas_per_run']))) : 20;
        $settings['drafts_per_cron'] = isset($_POST['drafts_per_cron']) ? max(1, min(5, absint($_POST['drafts_per_cron']))) : 1;
        $settings['min_word_count'] = isset($_POST['min_word_count']) ? max(300, absint($_POST['min_word_count'])) : 800;
        $settings['similar_threshold'] = isset($_POST['similar_threshold']) ? max(40, min(99, absint($_POST['similar_threshold']))) : 72;
        $settings['duplicate_threshold'] = isset($_POST['duplicate_threshold']) ? max(50, min(100, absint($_POST['duplicate_threshold']))) : 88;
        $settings['brand_tone'] = isset($_POST['brand_tone']) ? sanitize_textarea_field(wp_unslash($_POST['brand_tone'])) : '';
        $settings['default_cta'] = isset($_POST['default_cta']) ? sanitize_textarea_field(wp_unslash($_POST['default_cta'])) : '';
        $settings['allowed_internal_links'] = isset($_POST['allowed_internal_links']) ? sanitize_textarea_field(wp_unslash($_POST['allowed_internal_links'])) : '';
        $settings['image_style'] = isset($_POST['image_style']) ? sanitize_textarea_field(wp_unslash($_POST['image_style'])) : '';
        update_option(self::OPTION_KEY, $settings);
        $this->log('info', 'Settings saved.');
        $this->redirect('settings', 'Settings saved.');
    }

    private function action_import_titles() {
        $bucket = isset($_POST['bucket']) ? sanitize_text_field(wp_unslash($_POST['bucket'])) : '';
        $titles = isset($_POST['titles']) ? wp_unslash($_POST['titles']) : '';
        $lines = preg_split('/\r\n|\r|\n/', $titles);
        $count = 0;
        foreach ($lines as $line) {
            $title = trim(wp_strip_all_tags($line));
            if ($title === '') {
                continue;
            }
            $this->insert_idea(array(
                'title' => $title,
                'bucket' => $bucket,
                'source' => 'manual',
                'status' => 'idea'
            ));
            $count++;
        }
        $this->log('info', 'Manual titles imported.', array('count' => $count, 'bucket' => $bucket));
        $this->redirect('ideas', $count . ' title(s) imported.');
    }

    private function action_generate_ideas() {
        $bucket = isset($_POST['bucket']) ? sanitize_text_field(wp_unslash($_POST['bucket'])) : '';
        $count = isset($_POST['count']) ? max(1, min(50, absint($_POST['count']))) : 20;
        $context = isset($_POST['context']) ? sanitize_textarea_field(wp_unslash($_POST['context'])) : '';
        $generated = $this->generate_title_ideas($bucket, $count, $context);
        if (is_wp_error($generated)) {
            $this->log('error', 'Idea generation failed.', array('error' => $generated->get_error_message()));
            $this->redirect('ideas', 'Idea generation failed: ' . $generated->get_error_message());
        }
        $inserted = 0;
        foreach ($generated as $idea) {
            if (empty($idea['title'])) {
                continue;
            }
            $this->insert_idea(array(
                'title' => sanitize_text_field($idea['title']),
                'bucket' => $bucket,
                'target_keyword' => isset($idea['target_keyword']) ? sanitize_text_field($idea['target_keyword']) : '',
                'search_intent' => isset($idea['search_intent']) ? sanitize_text_field($idea['search_intent']) : '',
                'angle' => isset($idea['angle']) ? sanitize_text_field($idea['angle']) : '',
                'internal_links' => isset($idea['internal_links']) ? wp_json_encode($idea['internal_links']) : '',
                'source' => 'ai',
                'status' => 'idea',
                'raw_payload' => wp_json_encode($idea)
            ));
            $inserted++;
        }
        $this->log('info', 'AI ideas generated.', array('count' => $inserted, 'bucket' => $bucket));
        $this->redirect('ideas', $inserted . ' idea(s) generated.');
    }

    private function action_update_idea_status() {
        global $wpdb;
        $id = isset($_POST['idea_id']) ? absint($_POST['idea_id']) : 0;
        $status = isset($_POST['new_status']) ? sanitize_key($_POST['new_status']) : '';
        $allowed = array('idea', 'approved', 'queued', 'rejected', 'blocked');
        if (!$id || !in_array($status, $allowed, true)) {
            $this->redirect('ideas', 'Invalid action.');
        }
        $wpdb->update(
            $this->ideas_table(),
            array('status' => $status, 'updated_at' => current_time('mysql')),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
        $this->log('info', 'Idea status updated.', array('id' => $id, 'status' => $status));
        $this->redirect($status === 'queued' ? 'queue' : 'ideas', 'Idea moved to ' . $status . '.');
    }

    private function action_generate_draft() {
        $id = isset($_POST['idea_id']) ? absint($_POST['idea_id']) : 0;
        if (!$id) {
            $this->redirect('queue', 'Missing idea ID.');
        }
        $result = $this->generate_draft_for_idea($id);
        if (is_wp_error($result)) {
            $this->redirect('queue', 'Draft generation failed: ' . $result->get_error_message());
        }
        $this->redirect('drafts', 'Draft created for review.');
    }

    private function action_run_duplicate_scan() {
        global $wpdb;
        $items = $wpdb->get_results("SELECT * FROM {$this->ideas_table()} ORDER BY id DESC LIMIT 500", ARRAY_A);
        $count = 0;
        foreach ($items as $item) {
            $dup = $this->detect_duplicate($item['title'], (int) $item['id']);
            $wpdb->update(
                $this->ideas_table(),
                array(
                    'duplicate_status' => $dup['status'],
                    'duplicate_score' => $dup['score'],
                    'duplicate_note' => $dup['note'],
                    'updated_at' => current_time('mysql')
                ),
                array('id' => (int) $item['id'])
            );
            $count++;
        }
        $this->log('info', 'Duplicate scan completed.', array('count' => $count));
        $this->redirect('ideas', 'Duplicate scan completed for ' . $count . ' item(s).');
    }

    private function insert_idea($data) {
        global $wpdb;
        $title = isset($data['title']) ? trim($data['title']) : '';
        if ($title === '') {
            return false;
        }
        $duplicate = $this->detect_duplicate($title);
        $now = current_time('mysql');
        $row = array(
            'title' => $title,
            'normalized_title' => $this->normalize_title($title),
            'bucket' => isset($data['bucket']) ? $data['bucket'] : '',
            'target_keyword' => isset($data['target_keyword']) ? $data['target_keyword'] : '',
            'search_intent' => isset($data['search_intent']) ? $data['search_intent'] : '',
            'angle' => isset($data['angle']) ? $data['angle'] : '',
            'internal_links' => isset($data['internal_links']) ? $data['internal_links'] : '',
            'source' => isset($data['source']) ? $data['source'] : 'manual',
            'status' => isset($data['status']) ? $data['status'] : 'idea',
            'duplicate_status' => $duplicate['status'],
            'duplicate_score' => $duplicate['score'],
            'duplicate_note' => $duplicate['note'],
            'raw_payload' => isset($data['raw_payload']) ? $data['raw_payload'] : '',
            'created_at' => $now,
            'updated_at' => $now
        );
        $wpdb->insert($this->ideas_table(), $row);
        return $wpdb->insert_id;
    }

    private function generate_title_ideas($bucket, $count, $context = '') {
        $settings = $this->settings();
        if (empty($settings['openai_api_key'])) {
            return new WP_Error('missing_api_key', 'OpenAI API key is missing. Add it under Settings first.');
        }
        $existing = $this->get_existing_titles_sample();
        $links = $settings['allowed_internal_links'];
        $prompt = "Generate {$count} fresh blog title ideas for the NinjaWeb website.\n\nBucket: {$bucket}\nExtra context: {$context}\n\nAvoid duplicates or near-duplicates of these existing titles/topics:\n{$existing}\n\nUse these internal service links where relevant:\n{$links}\n\nReturn strict JSON only as an array. Each item must have: title, target_keyword, search_intent, angle, internal_links. Do not include markdown.";
        $response = $this->call_openai($prompt, 'You are an expert SEO content strategist for an Australian web hosting, web development, and digital agency brand called NinjaWeb.');
        if (is_wp_error($response)) {
            return $response;
        }
        $data = $this->extract_json($response);
        if (!is_array($data)) {
            return new WP_Error('invalid_json', 'AI returned content, but it was not valid JSON.');
        }
        return $data;
    }

    private function generate_draft_for_idea($id) {
        global $wpdb;
        $settings = $this->settings();
        if (empty($settings['openai_api_key'])) {
            return new WP_Error('missing_api_key', 'OpenAI API key is missing.');
        }
        $idea = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->ideas_table()} WHERE id = %d", $id), ARRAY_A);
        if (!$idea) {
            return new WP_Error('missing_idea', 'Idea not found.');
        }
        if (!empty($idea['post_id'])) {
            return new WP_Error('already_created', 'This idea already has a WordPress draft.');
        }
        $wpdb->update($this->ideas_table(), array('status' => 'generating', 'updated_at' => current_time('mysql')), array('id' => $id));

        $links = $settings['allowed_internal_links'];
        $prompt = "Write a complete WordPress blog package for NinjaWeb.\n\nTitle: {$idea['title']}\nBucket: {$idea['bucket']}\nTarget keyword: {$idea['target_keyword']}\nAngle: {$idea['angle']}\n\nRules:\n- Minimum {$settings['min_word_count']} words.\n- Natural human tone.\n- Professional, technical but accessible.\n- Brand tone: {$settings['brand_tone']}\n- Subtle ninja/NinjaWeb style only where it feels natural.\n- Do not use em dashes. Use hyphens instead.\n- No competitor links.\n- Include relevant internal links from this approved list only:\n{$links}\n- Include a clear NinjaWeb CTA near the end: {$settings['default_cta']}\n- Return clean WordPress HTML only for content. No inline styles, no scripts, no meta tags.\n\nReturn strict JSON only with these keys:\ntitle, slug, seo_title, meta_description, excerpt, category, tags, content_html, image_prompt.\nDo not include markdown outside JSON.";

        $response = $this->call_openai($prompt, 'You are a senior SEO writer and WordPress editor for NinjaWeb. You produce polished, accurate, useful blog drafts.');
        if (is_wp_error($response)) {
            $wpdb->update($this->ideas_table(), array('status' => 'needs_fix', 'quality_report' => $response->get_error_message(), 'updated_at' => current_time('mysql')), array('id' => $id));
            return $response;
        }
        $package = $this->extract_json($response);
        if (!is_array($package) || empty($package['content_html'])) {
            $wpdb->update($this->ideas_table(), array('status' => 'needs_fix', 'quality_report' => 'AI response was not a valid article package.', 'raw_payload' => $response, 'updated_at' => current_time('mysql')), array('id' => $id));
            return new WP_Error('invalid_article_package', 'AI response was not a valid article package.');
        }

        $quality = $this->quality_gate($package, $settings);
        if (!$quality['passed']) {
            $wpdb->update($this->ideas_table(), array(
                'status' => 'needs_fix',
                'quality_report' => wp_json_encode($quality),
                'raw_payload' => wp_json_encode($package),
                'updated_at' => current_time('mysql')
            ), array('id' => $id));
            return new WP_Error('quality_gate_failed', 'Quality gate failed: ' . implode(', ', $quality['issues']));
        }

        $postarr = array(
            'post_title' => sanitize_text_field($package['title']),
            'post_name' => sanitize_title($package['slug']),
            'post_excerpt' => sanitize_text_field($package['excerpt']),
            'post_content' => wp_kses_post($package['content_html']),
            'post_status' => $settings['draft_status'],
            'post_type' => 'post',
            'post_author' => absint($settings['default_author']) ?: get_current_user_id()
        );
        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if (!empty($package['category'])) {
            $cat_id = $this->ensure_category($package['category']);
            if ($cat_id) {
                wp_set_post_categories($post_id, array($cat_id));
            }
        }
        if (!empty($package['tags']) && is_array($package['tags'])) {
            wp_set_post_tags($post_id, array_map('sanitize_text_field', $package['tags']), false);
        }

        update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($package['seo_title']));
        update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($package['meta_description']));
        update_post_meta($post_id, '_nwcp_idea_id', $id);
        update_post_meta($post_id, '_nwcp_image_prompt', sanitize_textarea_field($package['image_prompt']));
        update_post_meta($post_id, '_nwcp_quality_report', wp_json_encode($quality));

        $wpdb->update($this->ideas_table(), array(
            'status' => 'ready_review',
            'post_id' => $post_id,
            'quality_report' => wp_json_encode($quality),
            'raw_payload' => wp_json_encode($package),
            'updated_at' => current_time('mysql')
        ), array('id' => $id));

        $this->log('info', 'Draft created.', array('idea_id' => $id, 'post_id' => $post_id));
        return $post_id;
    }

    public function cron_generate_next_draft() {
        $settings = $this->settings();
        if ($settings['generation_enabled'] !== 'cron') {
            return;
        }
        global $wpdb;
        $limit = max(1, min(5, absint($settings['drafts_per_cron'])));
        $items = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$this->ideas_table()} WHERE status = %s AND duplicate_status != %s ORDER BY id ASC LIMIT %d", 'queued', 'duplicate', $limit), ARRAY_A);
        foreach ($items as $item) {
            $this->generate_draft_for_idea((int) $item['id']);
        }
    }

    private function call_openai($prompt, $system = '') {
        $settings = $this->settings();
        $api_key = $settings['openai_api_key'];
        $body = array(
            'model' => $settings['openai_model'],
            'input' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $prompt)
            ),
            'temperature' => 0.6
        );
        $response = wp_remote_post('https://api.openai.com/v1/responses', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => wp_json_encode($body),
            'timeout' => 90
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('openai_http_error', 'OpenAI API returned HTTP ' . $code . ': ' . $raw);
        }
        $decoded = json_decode($raw, true);
        if (isset($decoded['output_text'])) {
            return $decoded['output_text'];
        }
        if (!empty($decoded['output']) && is_array($decoded['output'])) {
            $text = '';
            foreach ($decoded['output'] as $output) {
                if (empty($output['content'])) {
                    continue;
                }
                foreach ($output['content'] as $content) {
                    if (isset($content['text'])) {
                        $text .= $content['text'];
                    }
                }
            }
            if ($text !== '') {
                return $text;
            }
        }
        return new WP_Error('openai_empty_response', 'OpenAI response did not contain usable text.');
    }

    private function extract_json($text) {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        if (preg_match('/```json\s*(.*?)\s*```/is', $text, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return null;
    }

    private function quality_gate($package, $settings) {
        $content = isset($package['content_html']) ? wp_strip_all_tags($package['content_html']) : '';
        $word_count = str_word_count($content);
        $issues = array();
        if ($word_count < (int) $settings['min_word_count']) {
            $issues[] = 'Word count below minimum (' . $word_count . '/' . (int) $settings['min_word_count'] . ')';
        }
        if (strpos($package['content_html'], '—') !== false) {
            $issues[] = 'Contains em dash';
        }
        if (stripos($package['content_html'], '<h2') === false) {
            $issues[] = 'Missing H2 sections';
        }
        if (empty($package['seo_title'])) {
            $issues[] = 'Missing SEO title';
        }
        if (empty($package['meta_description'])) {
            $issues[] = 'Missing meta description';
        }
        if (empty($package['image_prompt'])) {
            $issues[] = 'Missing image prompt';
        }
        $approved_links = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $settings['allowed_internal_links'])));
        $has_internal = false;
        foreach ($approved_links as $link) {
            if ($link && strpos($package['content_html'], $link) !== false) {
                $has_internal = true;
                break;
            }
        }
        if (!$has_internal) {
            $issues[] = 'No approved internal link found';
        }
        return array(
            'passed' => empty($issues),
            'issues' => $issues,
            'word_count' => $word_count,
            'checked_at' => current_time('mysql')
        );
    }

    private function ensure_category($name) {
        $name = sanitize_text_field($name);
        if ($name === '') {
            return 0;
        }
        $term = term_exists($name, 'category');
        if (!$term) {
            $term = wp_insert_term($name, 'category');
        }
        if (is_wp_error($term)) {
            return 0;
        }
        return is_array($term) ? (int) $term['term_id'] : (int) $term;
    }

    private function get_existing_titles_sample() {
        global $wpdb;
        $ideas = $wpdb->get_col("SELECT title FROM {$this->ideas_table()} ORDER BY id DESC LIMIT 200");
        $posts = $wpdb->get_col("SELECT post_title FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish','draft','pending','future') ORDER BY ID DESC LIMIT 200");
        $all = array_filter(array_merge((array) $ideas, (array) $posts));
        return implode("\n", array_slice($all, 0, 250));
    }

    private function detect_duplicate($title, $exclude_id = 0) {
        global $wpdb;
        $settings = $this->settings();
        $normalized = $this->normalize_title($title);
        $best_score = 0;
        $best_note = '';

        $ideas_sql = "SELECT id, title FROM {$this->ideas_table()} WHERE normalized_title != ''";
        if ($exclude_id) {
            $ideas_sql .= $wpdb->prepare(' AND id != %d', $exclude_id);
        }
        $ideas_sql .= ' ORDER BY id DESC LIMIT 500';
        $ideas = $wpdb->get_results($ideas_sql, ARRAY_A);
        foreach ($ideas as $idea) {
            $score = $this->similarity_score($normalized, $this->normalize_title($idea['title']));
            if ($score > $best_score) {
                $best_score = $score;
                $best_note = 'Similar to stored idea #' . (int) $idea['id'] . ': ' . $idea['title'];
            }
        }

        $posts = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status IN ('publish','draft','pending','future') ORDER BY ID DESC LIMIT 500", ARRAY_A);
        foreach ($posts as $post) {
            $score = $this->similarity_score($normalized, $this->normalize_title($post['post_title']));
            if ($score > $best_score) {
                $best_score = $score;
                $best_note = 'Similar to WordPress post #' . (int) $post['ID'] . ': ' . $post['post_title'];
            }
        }

        if ($best_score >= (float) $settings['duplicate_threshold']) {
            return array('status' => 'duplicate', 'score' => $best_score, 'note' => $best_note);
        }
        if ($best_score >= (float) $settings['similar_threshold']) {
            return array('status' => 'similar', 'score' => $best_score, 'note' => $best_note);
        }
        return array('status' => 'fresh', 'score' => $best_score, 'note' => $best_note);
    }

    private function normalize_title($title) {
        $title = strtolower(wp_strip_all_tags($title));
        $title = preg_replace('/[^a-z0-9\s]/', ' ', $title);
        $title = preg_replace('/\s+/', ' ', $title);
        return trim($title);
    }

    private function similarity_score($a, $b) {
        if ($a === '' || $b === '') {
            return 0;
        }
        if ($a === $b) {
            return 100;
        }
        similar_text($a, $b, $percent);
        $tokens_a = array_unique(array_filter(explode(' ', $a)));
        $tokens_b = array_unique(array_filter(explode(' ', $b)));
        $intersection = count(array_intersect($tokens_a, $tokens_b));
        $union = max(1, count(array_unique(array_merge($tokens_a, $tokens_b))));
        $jaccard = ($intersection / $union) * 100;
        return round(($percent * 0.55) + ($jaccard * 0.45), 2);
    }

    private function log($level, $message, $context = array()) {
        global $wpdb;
        $wpdb->insert($this->logs_table(), array(
            'level' => sanitize_key($level),
            'message' => sanitize_text_field($message),
            'context' => !empty($context) ? wp_json_encode($context) : null,
            'created_at' => current_time('mysql')
        ));
    }

    public function render_admin_page() {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $tabs = array(
            'dashboard' => 'Dashboard',
            'ideas' => 'Ideas',
            'queue' => 'Queue',
            'drafts' => 'Drafts',
            'settings' => 'Settings',
            'logs' => 'Logs'
        );
        echo '<div class="wrap nwcp-wrap">';
        echo '<div class="nwcp-hero"><div><h1>NinjaWeb Content Pilot</h1><p>AI-assisted idea generation, duplicate guard, article queue, quality gate, and draft creation for WordPress.</p></div><span class="nwcp-version">v' . esc_html(self::VERSION) . '</span></div>';
        if (!empty($_GET['nwcp_message'])) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(wp_unslash($_GET['nwcp_message'])) . '</p></div>';
        }
        echo '<nav class="nav-tab-wrapper nwcp-tabs">';
        foreach ($tabs as $key => $label) {
            $class = $tab === $key ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=ninjaweb-content-pilot&tab=' . $key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        switch ($tab) {
            case 'ideas':
                $this->render_ideas();
                break;
            case 'queue':
                $this->render_queue();
                break;
            case 'drafts':
                $this->render_drafts();
                break;
            case 'settings':
                $this->render_settings();
                break;
            case 'logs':
                $this->render_logs();
                break;
            default:
                $this->render_dashboard();
        }
        echo '</div>';
    }

    private function counts() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT status, COUNT(*) total FROM {$this->ideas_table()} GROUP BY status", ARRAY_A);
        $counts = array('idea' => 0, 'approved' => 0, 'queued' => 0, 'ready_review' => 0, 'needs_fix' => 0, 'rejected' => 0);
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }
        return $counts;
    }

    private function render_dashboard() {
        $counts = $this->counts();
        $settings = $this->settings();
        echo '<section class="nwcp-grid">';
        $cards = array(
            'Ideas' => $counts['idea'],
            'Approved' => $counts['approved'],
            'Queued' => $counts['queued'],
            'Ready Review' => $counts['ready_review'],
            'Needs Fix' => $counts['needs_fix'],
            'Rejected' => $counts['rejected']
        );
        foreach ($cards as $label => $value) {
            echo '<div class="nwcp-card"><span>' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong></div>';
        }
        echo '</section>';
        echo '<div class="nwcp-panel"><h2>Current mode</h2><p><strong>Generation:</strong> ' . esc_html($settings['generation_enabled']) . '</p><p><strong>OpenAI key:</strong> ' . (!empty($settings['openai_api_key']) ? 'Configured' : 'Missing') . '</p><p><strong>Next cron:</strong> ' . esc_html($this->next_cron_label()) . '</p><p>This baseline is intentionally approval-first. It creates drafts for review, not live posts.</p></div>';
    }

    private function next_cron_label() {
        $ts = wp_next_scheduled('nwcp_generate_next_draft');
        return $ts ? date_i18n('Y-m-d H:i:s', $ts) : 'Not scheduled';
    }

    private function render_ideas() {
        $settings = $this->settings();
        echo '<div class="nwcp-two-col">';
        echo '<div class="nwcp-panel"><h2>Generate title ideas</h2><form method="post">';
        wp_nonce_field(self::NONCE_ACTION, '_nwcp_nonce');
        echo '<input type="hidden" name="nwcp_action" value="generate_ideas">';
        echo '<label>Bucket <input type="text" name="bucket" placeholder="Servers, Radio Hosting, Digital Ronin" required></label>';
        echo '<label>How many <input type="number" name="count" value="' . esc_attr((string) $settings['ideas_per_run']) . '" min="1" max="50"></label>';
        echo '<label>Extra direction <textarea name="context" rows="4" placeholder="Example: focus on Australian small business owners, avoid basic beginner articles"></textarea></label>';
        echo '<button class="button button-primary">Generate Ideas</button>';
        echo '</form></div>';

        echo '<div class="nwcp-panel"><h2>Import your own titles</h2><form method="post">';
        wp_nonce_field(self::NONCE_ACTION, '_nwcp_nonce');
        echo '<input type="hidden" name="nwcp_action" value="import_titles">';
        echo '<label>Bucket <input type="text" name="bucket" placeholder="Servers"></label>';
        echo '<label>Titles <textarea name="titles" rows="7" placeholder="Paste one title per line"></textarea></label>';
        echo '<button class="button button-primary">Import Titles</button>';
        echo '</form></div>';
        echo '</div>';

        echo '<div class="nwcp-panel"><div class="nwcp-panel-head"><h2>Ideas Library</h2><form method="post">';
        wp_nonce_field(self::NONCE_ACTION, '_nwcp_nonce');
        echo '<input type="hidden" name="nwcp_action" value="run_duplicate_scan"><button class="button">Run Duplicate Scan</button></form></div>';
        $this->render_ideas_table(array('idea', 'approved', 'rejected', 'blocked'), 'ideas');
        echo '</div>';
    }

    private function render_queue() {
        echo '<div class="nwcp-panel"><h2>Article Queue</h2><p>Only queued items are eligible for draft generation. Cron processes one item at a time when enabled.</p>';
        $this->render_ideas_table(array('queued', 'generating', 'needs_fix'), 'queue');
        echo '</div>';
    }

    private function render_drafts() {
        echo '<div class="nwcp-panel"><h2>Drafts Ready For Review</h2>';
        $this->render_ideas_table(array('ready_review'), 'drafts');
        echo '</div>';
    }

    private function render_ideas_table($statuses, $context_tab) {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = $wpdb->prepare("SELECT * FROM {$this->ideas_table()} WHERE status IN ($placeholders) ORDER BY id DESC LIMIT 200", $statuses);
        $items = $wpdb->get_results($sql, ARRAY_A);
        if (empty($items)) {
            echo '<p>No items found.</p>';
            return;
        }
        echo '<table class="widefat striped nwcp-table"><thead><tr><th>Title</th><th>Bucket</th><th>Status</th><th>Duplicate</th><th>Keyword</th><th>Post</th><th>Actions</th></tr></thead><tbody>';
        foreach ($items as $item) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($item['title']) . '</strong><br><small>' . esc_html($item['angle']) . '</small></td>';
            echo '<td>' . esc_html($item['bucket']) . '</td>';
            echo '<td><span class="nwcp-pill">' . esc_html($item['status']) . '</span></td>';
            echo '<td><span class="nwcp-dup nwcp-dup-' . esc_attr($item['duplicate_status']) . '">' . esc_html($item['duplicate_status']) . ' ' . esc_html((string) $item['duplicate_score']) . '%</span><br><small>' . esc_html($item['duplicate_note']) . '</small></td>';
            echo '<td>' . esc_html($item['target_keyword']) . '</td>';
            echo '<td>' . (!empty($item['post_id']) ? '<a href="' . esc_url(get_edit_post_link((int) $item['post_id'])) . '">Edit draft</a>' : '-') . '</td>';
            echo '<td class="nwcp-actions">';
            $this->status_button($item['id'], 'approved', 'Approve');
            $this->status_button($item['id'], 'queued', 'Queue');
            $this->status_button($item['id'], 'rejected', 'Reject');
            if (in_array($item['status'], array('queued', 'needs_fix'), true) && $item['duplicate_status'] !== 'duplicate') {
                echo '<form method="post">';
                wp_nonce_field(self::NONCE_ACTION, '_nwcp_nonce');
                echo '<input type="hidden" name="nwcp_action" value="generate_draft"><input type="hidden" name="idea_id" value="' . esc_attr((string) $item['id']) . '"><button class="button button-primary">Generate Draft</button></form>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function status_button($id, $status, $label) {
        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION, '_nwcp_nonce');
        echo '<input type="hidden" name="nwcp_action" value="idea_status"><input type="hidden" name="idea_id" value="' . esc_attr((string) $id) . '"><input type="hidden" name="new_status" value="' . esc_attr($status) . '"><button class="button">' . esc_html($label) . '</button></form>';
    }

    private function render_settings() {
        $settings = $this->settings();
        $users = get_users(array('fields' => array('ID', 'display_name')));
        echo '<div class="nwcp-panel"><h2>Settings</h2><form method="post">';
        wp_nonce_field(self::NONCE_ACTION, '_nwcp_nonce');
        echo '<input type="hidden" name="nwcp_action" value="save_settings">';
        echo '<label>OpenAI API Key <input type="password" name="openai_api_key" value="' . esc_attr($settings['openai_api_key']) . '" autocomplete="off"></label>';
        echo '<label>OpenAI Model <input type="text" name="openai_model" value="' . esc_attr($settings['openai_model']) . '"></label>';
        echo '<label>Draft status <select name="draft_status"><option value="draft"' . selected($settings['draft_status'], 'draft', false) . '>Draft</option><option value="pending"' . selected($settings['draft_status'], 'pending', false) . '>Pending Review</option></select></label>';
        echo '<label>Default author <select name="default_author">';
        foreach ($users as $user) {
            echo '<option value="' . esc_attr((string) $user->ID) . '"' . selected((int) $settings['default_author'], (int) $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Generation mode <select name="generation_enabled"><option value="manual"' . selected($settings['generation_enabled'], 'manual', false) . '>Manual only</option><option value="cron"' . selected($settings['generation_enabled'], 'cron', false) . '>Cron enabled</option></select></label>';
        echo '<label>Ideas per run <input type="number" name="ideas_per_run" value="' . esc_attr((string) $settings['ideas_per_run']) . '" min="1" max="50"></label>';
        echo '<label>Drafts per cron <input type="number" name="drafts_per_cron" value="' . esc_attr((string) $settings['drafts_per_cron']) . '" min="1" max="5"></label>';
        echo '<label>Minimum word count <input type="number" name="min_word_count" value="' . esc_attr((string) $settings['min_word_count']) . '" min="300"></label>';
        echo '<label>Similar threshold <input type="number" name="similar_threshold" value="' . esc_attr((string) $settings['similar_threshold']) . '" min="40" max="99"></label>';
        echo '<label>Duplicate threshold <input type="number" name="duplicate_threshold" value="' . esc_attr((string) $settings['duplicate_threshold']) . '" min="50" max="100"></label>';
        echo '<label>Brand tone <textarea name="brand_tone" rows="4">' . esc_textarea($settings['brand_tone']) . '</textarea></label>';
        echo '<label>Default CTA <textarea name="default_cta" rows="3">' . esc_textarea($settings['default_cta']) . '</textarea></label>';
        echo '<label>Approved internal links <textarea name="allowed_internal_links" rows="8">' . esc_textarea($settings['allowed_internal_links']) . '</textarea></label>';
        echo '<label>Image style prompt <textarea name="image_style" rows="4">' . esc_textarea($settings['image_style']) . '</textarea></label>';
        echo '<button class="button button-primary">Save Settings</button>';
        echo '</form></div>';
    }

    private function render_logs() {
        global $wpdb;
        $logs = $wpdb->get_results("SELECT * FROM {$this->logs_table()} ORDER BY id DESC LIMIT 100", ARRAY_A);
        echo '<div class="nwcp-panel"><h2>Logs</h2>';
        if (empty($logs)) {
            echo '<p>No logs yet.</p></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Level</th><th>Message</th><th>Context</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            echo '<tr><td>' . esc_html($log['created_at']) . '</td><td>' . esc_html($log['level']) . '</td><td>' . esc_html($log['message']) . '</td><td><code>' . esc_html($log['context']) . '</code></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}

NinjaWeb_Content_Pilot::instance();
