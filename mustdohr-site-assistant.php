<?php
/**
 * Plugin Name: Mustdohr Website Assistant
 * Description: Website search bar plus optional Gemini-powered public-content Q&A.
 * Version: 2.9.29
 * Author: Mustdohr
 * Text Domain: mustdohr-site-assistant
 */

if (!defined('ABSPATH')) exit;

define('MDH_SEARCH_VERSION', '2.9.28');
define('MDH_RECORDS_SCHEMA_VERSION', '2.7.9');
define('MDH_GEMINI_GUARD_ENV', 'MUSTDOHR_GEMINI_GUARD_KEY');
define('MDH_GEMINI_ANSWER_ENV', 'MUSTDOHR_GEMINI_ANSWER_KEY');
define('MDH_GITHUB_REPOSITORY', 'waaohmd/chatbot');
define('MDH_GITHUB_SLUG', 'mustdohr-site-assistant');
define('MDH_PLUGIN_MAIN_FILE', 'mustdohr-site-assistant.php');
define('MDH_PLUGIN_CANONICAL_BASENAME', 'mustdohr-site-assistant/' . MDH_PLUGIN_MAIN_FILE);
// The question limit is controlled from the private local admin. Set it to 0
// there when you want unlimited AI questions during testing.
define('MDH_CHATBOT_DISABLE_QUESTION_LIMIT', false);
// Public endpoints are protected before any Gemini call or database write.
// These limits are intentionally conservative defaults and are independent of
// the visitor-facing question limit.
define('MDH_PUBLIC_RATE_WINDOW', 600);
define('MDH_PUBLIC_SEARCH_IP_LIMIT', 60);
define('MDH_PUBLIC_AI_IP_LIMIT', 20);
define('MDH_PUBLIC_CONTACT_IP_LIMIT', 5);
define('MDH_PUBLIC_SEARCH_SESSION_LIMIT', 90);
define('MDH_PUBLIC_AI_SESSION_LIMIT', 30);
define('MDH_PUBLIC_CONTACT_SESSION_LIMIT', 5);
define('MDH_PUBLIC_SEARCH_GLOBAL_LIMIT', 600);
define('MDH_PUBLIC_AI_GLOBAL_LIMIT', 300);
define('MDH_PUBLIC_CONTACT_GLOBAL_LIMIT', 50);
define('MDH_PUBLIC_CONTACT_EMAIL_LIMIT', 3);
define('MDH_PUBLIC_CONTACT_EMAIL_WINDOW', HOUR_IN_SECONDS);
define('MDH_PUBLIC_SEARCH_BODY_MAX_BYTES', 8192);
define('MDH_PUBLIC_AI_BODY_MAX_BYTES', 16384);
define('MDH_PUBLIC_CONTACT_BODY_MAX_BYTES', 32768);
define('MDH_RECORD_MAX_ROWS', 10000);
define('MDH_CONTACT_MAX_ROWS', 2000);
// A successful Turnstile check is remembered server-side for the visitor's
// session so AI conversations do not prompt on every message.
define('MDH_AI_CAPTCHA_TTL', DAY_IN_SECONDS);

add_action('plugins_loaded', function () {
    load_plugin_textdomain('mustdohr-site-assistant', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_action('admin_menu', function () {
    // Keep the existing slugs for backwards-compatible links, but use the
    // product-neutral Chatbot labels in the WordPress settings menu.
    add_options_page('Chatbot AI settings', 'Chatbot AI', 'manage_options', 'mustdohr-ai', 'mdh_ai_settings_page');
    add_options_page('Chatbot chat records', 'Chatbot Chat Records', 'manage_options', 'mustdohr-chat-records', 'mdh_chatbot_records_page');
    add_options_page('Chatbot security audit', 'Chatbot Security Audit', 'manage_options', 'mustdohr-security-audit', 'mdh_security_audit_page');
});

// Automatic GitHub updates are intentionally disabled. Older manifests used a
// versioned plugin folder, which could leave WordPress pointing at a PHP file
// that no longer existed after an update. Upload packages now always use the
// stable mustdohr-site-assistant/mustdohr-site-assistant.php identity.
function mdh_filter_legacy_update_paths($transient) {
    if (!is_object($transient)) return $transient;
    $current = plugin_basename(__FILE__);
    foreach (array_keys((array) ($transient->response ?? [])) as $plugin_file) {
        if ($plugin_file !== $current && preg_match('#^mustdohr-site-assistant[^/]*/(?:mustdohr-site-assistant/)?mustdohr-site-assistant\\.php$#i', (string) $plugin_file)) {
            unset($transient->response[$plugin_file]);
        }
    }
    return $transient;
}
add_filter('site_transient_update_plugins', 'mdh_filter_legacy_update_paths');
add_filter('pre_set_site_transient_update_plugins', 'mdh_filter_legacy_update_paths');

function mdh_remove_legacy_plugin_copies() {
    // Only the canonical flat install may remove older versioned copies.
    if (plugin_basename(__FILE__) !== MDH_PLUGIN_CANONICAL_BASENAME) return;
    if (!defined('WP_PLUGIN_DIR') || !is_dir(WP_PLUGIN_DIR)) return;

    $legacy_dirs = glob(trailingslashit(WP_PLUGIN_DIR) . 'mustdohr-site-assistant-*', GLOB_ONLYDIR);
    if (!$legacy_dirs) return;

    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
    global $wp_filesystem;
    foreach ($legacy_dirs as $legacy_dir) {
        $legacy_dir = untrailingslashit($legacy_dir);
        $legacy_main = trailingslashit($legacy_dir) . MDH_PLUGIN_MAIN_FILE;
        $nested_main = trailingslashit($legacy_dir) . 'mustdohr-site-assistant/' . MDH_PLUGIN_MAIN_FILE;
        $candidate = file_exists($legacy_main) ? $legacy_main : (file_exists($nested_main) ? $nested_main : '');
        if ($candidate === '') continue;

        $legacy_plugin = plugin_basename($candidate);
        $active = (array) get_option('active_plugins', []);
        if (in_array($legacy_plugin, $active, true)) {
            update_option('active_plugins', array_values(array_diff($active, [$legacy_plugin])), false);
        }

        if (is_object($wp_filesystem) && $wp_filesystem->exists($legacy_dir)) {
            $wp_filesystem->delete($legacy_dir, true);
        }
    }
    wp_clean_plugins_cache(true);
    delete_site_transient('update_plugins');
}

function mdh_plugin_activate() {
    delete_site_transient('update_plugins');
    wp_clean_plugins_cache(true);
    mdh_remove_legacy_plugin_copies();
}
register_activation_hook(__FILE__, 'mdh_plugin_activate');

function mdh_chatbot_records_table() {
    global $wpdb;
    return $wpdb->prefix . 'mdh_chatbot_records';
}

function mdh_chatbot_maybe_optimize_record_tables() {
    if (get_transient('mdh_records_tables_optimized')) return;
    global $wpdb;
    $records = mdh_chatbot_records_table();
    $contacts = mdh_chatbot_contact_submissions_table();
    $record_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$records}");
    $contact_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$contacts}");
    if ($record_count !== 0 || $contact_count !== 0) return;
    $wpdb->query("OPTIMIZE TABLE {$records}");
    $wpdb->query("OPTIMIZE TABLE {$contacts}");
    set_transient('mdh_records_tables_optimized', 1, HOUR_IN_SECONDS);
}

function mdh_chatbot_contact_submissions_table() {
    global $wpdb;
    return $wpdb->prefix . 'mdh_chatbot_contact_submissions';
}

function mdh_chatbot_security_audit_table() {
    global $wpdb;
    return $wpdb->prefix . 'mdh_chatbot_security_audit';
}

function mdh_chatbot_rate_limits_table() {
    global $wpdb;
    return $wpdb->prefix . 'mdh_chatbot_rate_limits';
}

function mdh_chatbot_env_value($names) {
    foreach ((array) $names as $name) {
        $name = (string) $name;
        if (defined($name)) {
            $value = constant($name);
            if (is_string($value) && trim($value) !== '') return trim($value);
        }
        $value = getenv($name);
        if ($value !== false && trim((string) $value) !== '') return trim((string) $value);
        if (isset($_SERVER[$name]) && trim((string) $_SERVER[$name]) !== '') return trim((string) $_SERVER[$name]);
    }
    return '';
}

function mdh_chatbot_gemini_credentials() {
    // These values intentionally come from WordPress Settings as plaintext options.
    // The environment variables remain a fallback for deployments that prefer them.
    $guard_option = trim((string) get_option('mdh_guard_api_key', ''));
    $answer_option = trim((string) get_option('mdh_answer_api_key', ''));
    $guard_env = mdh_chatbot_env_value([MDH_GEMINI_GUARD_ENV, 'MUSTDOHR_GEMINI_API_KEY']);
    $answer_env = mdh_chatbot_env_value([MDH_GEMINI_ANSWER_ENV, 'MUSTDOHR_GEMINI_API_KEY']);
    $guard = $guard_option !== '' ? $guard_option : $guard_env;
    $answer = $answer_option !== '' ? $answer_option : $answer_env;
    return [
        'guard' => $guard,
        'answer' => $answer,
        'guard_source' => $guard_option !== '' ? 'wordpress_settings' : ($guard_env !== '' ? 'environment' : 'missing'),
        'answer_source' => $answer_option !== '' ? 'wordpress_settings' : ($answer_env !== '' ? 'environment' : 'missing'),
    ];
}

function mdh_chatbot_security_recipients() {
    $config = mdh_chatbot_get_config();
    $configured = preg_split('/[\s,;]+/', (string) ($config['notification_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $recipients = array_values(array_unique(array_filter(array_map('sanitize_email', $configured))));
    return $recipients;
}

function mdh_chatbot_security_audit_event($event, $context = [], $notify = true) {
    global $wpdb;
    $user = wp_get_current_user();
    $actor = ($user instanceof WP_User && $user->exists()) ? $user->user_login : 'system';
    $payload = is_array($context) ? $context : [];
    $payload['actor'] = $actor;
    $payload['ip'] = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $payload['user_agent'] = mdh_chatbot_bounded_text($_SERVER['HTTP_USER_AGENT'] ?? '', 240);
    $created_at = current_time('mysql', true);
    $wpdb->insert(mdh_chatbot_security_audit_table(), [
        'event_type' => sanitize_key($event),
        'actor_id' => $user instanceof WP_User ? absint($user->ID) : 0,
        'actor_login' => mdh_chatbot_bounded_text($actor, 190),
        'context' => wp_json_encode($payload),
        'created_at' => $created_at,
    ], ['%s', '%d', '%s', '%s', '%s']);
    // Security alerts are email-only for connection-code rotation; all other events remain in the audit log.
    if (!$notify || sanitize_key($event) !== 'connection_code_rotated') return;
    $recipients = mdh_chatbot_security_recipients();
    if (!$recipients) return;
    $subject = '[Mustdohr security] ' . sanitize_text_field($event);
    $body = "A Mustdohr security event was recorded.\n\nEvent: " . sanitize_text_field($event) . "\nTime (UTC): " . $created_at . "\nContext: " . wp_json_encode($payload);
    wp_mail($recipients, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
}

function mdh_security_audit_plugin_editor_event() {
    if (!current_user_can('edit_plugins')) return;
    $is_save = $_SERVER['REQUEST_METHOD'] === 'POST' && sanitize_key($_REQUEST['action'] ?? '') === 'update';
    mdh_chatbot_security_audit_event($is_save ? 'plugin_editor_saved' : 'plugin_editor_opened', [
        'plugin' => sanitize_text_field(wp_unslash($_REQUEST['plugin'] ?? '')),
        'file' => sanitize_text_field(wp_unslash($_REQUEST['file'] ?? '')),
        'action' => sanitize_key($_REQUEST['action'] ?? 'edit'),
    ], true);
}
add_action('load-plugin-editor.php', 'mdh_security_audit_plugin_editor_event');

/**
 * Use the current WordPress site's host as the default source label.
 * This keeps one plugin ZIP portable across independently configured sites.
 */
function mdh_chatbot_default_source_website() {
    $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    return $host ? sanitize_text_field($host) : 'Website';
}

function mdh_chatbot_default_config() {
    return [
        'enabled' => true,
        'survey_enabled' => true,
        'contact_form_enabled' => true,
        'brand_name' => 'Website assistant',
        'welcome_message' => 'Search this public website and open the closest pages.',
        'ai_intro' => 'Ask AI to summarize public website content. AI answers may be incomplete.',
        'faqs' => [
            ['question' => 'How does onboarding work?', 'answer' => 'Share the employee basics and the service runs the appropriate contracts, forms and training process.'],
            ['question' => 'What can employers view?', 'answer' => 'Employers can follow onboarding, compliance, employee information, documents, leave and payroll progress.'],
        ],
        'question_limit' => 10,
        'sensitive_keywords' => "competitor
lowest price
profit
cost structure
customer complaint
internal information",
        'sensitive_reply' => 'I cannot help with that request. Please use the website contact form for assistance.',
        'contact_mode' => 'embedded',
        'contact_url' => '',
        // Use the configured contact-notification destination explicitly.
        // Never fall back to the WordPress administrator address, which may
        // belong to a different operator or a legacy site owner.
        'notification_emails' => '',
        'knowledge_urls' => '',
        'excluded_urls' => '',
        'source_website' => mdh_chatbot_default_source_website(),
        'contact_trigger_keywords' => "quote
pricing
price
partnership
sales
complaint
legal
account",
        'contact_trigger_reply' => 'For this request, the fastest next step is to contact our team. Please share a few details and we will follow up.',
        'no_answer_reply' => 'I could not confirm an answer from this public website. Please contact our team for help with this request.',
        'limit_reply' => 'You have reached the question limit for this visit. Please use the website contact form so our team can help.',
        'show_contact_for' => ['contact', 'unanswered', 'limit', 'sensitive'],
    ];
}

function mdh_chatbot_get_config() {
    $defaults = mdh_chatbot_default_config();
    $saved = get_option('mdh_chatbot_config', []);
    if (!is_array($saved)) $saved = [];
    $config = array_merge($defaults, $saved);
    $stored_survey = get_option('mdh_survey_enabled', null);
    if ($stored_survey !== null) $config['survey_enabled'] = (bool) $stored_survey;
    $stored_contact_form = get_option('mdh_contact_form_enabled', null);
    if ($stored_contact_form !== null) $config['contact_form_enabled'] = (bool) $stored_contact_form;
    $config['enabled'] = (bool) $config['enabled'];
    $config['survey_enabled'] = (bool) $config['survey_enabled'];
    $config['contact_form_enabled'] = (bool) $config['contact_form_enabled'];
    // A zero limit silently disabled protection in older versions. Keep the
    // site configurable while enforcing a non-zero server-side floor.
    $config['question_limit'] = max(1, min(100, absint($config['question_limit'])));
    // An empty destination stays empty until an administrator configures one.
    // This prevents notifications from being redirected to a legacy admin.
    $config['faqs'] = is_array($config['faqs']) ? array_values($config['faqs']) : $defaults['faqs'];
    $config['show_contact_for'] = array_values(array_intersect((array) $config['show_contact_for'], ['contact', 'unanswered', 'limit', 'sensitive']));
    return $config;
}

function mdh_chatbot_sanitize_config($input) {
    $defaults = mdh_chatbot_default_config();
    if (!is_array($input)) $input = [];
    $current = mdh_chatbot_get_config();
    $faqs = [];
    foreach ((array) ($input['faqs'] ?? []) as $faq) {
        $question = sanitize_text_field(is_array($faq) ? ($faq['question'] ?? '') : '');
        $answer = sanitize_textarea_field(is_array($faq) ? ($faq['answer'] ?? '') : '');
        if ($question !== '' && $answer !== '') $faqs[] = ['question' => $question, 'answer' => $answer];
        if (count($faqs) >= 12) break;
    }
    return [
        'enabled' => !empty($input['enabled']),
        'survey_enabled' => array_key_exists('survey_enabled', $input) ? !empty($input['survey_enabled']) : $defaults['survey_enabled'],
        'contact_form_enabled' => array_key_exists('contact_form_enabled', $input) ? !empty($input['contact_form_enabled']) : (bool) ($current['contact_form_enabled'] ?? $defaults['contact_form_enabled']),
        'brand_name' => sanitize_text_field($input['brand_name'] ?? $defaults['brand_name']),
        'welcome_message' => sanitize_textarea_field($input['welcome_message'] ?? $defaults['welcome_message']),
        'ai_intro' => sanitize_textarea_field($input['ai_intro'] ?? $defaults['ai_intro']),
        'faqs' => $faqs,
        'question_limit' => max(1, min(100, absint($input['question_limit'] ?? $defaults['question_limit']))),
        'sensitive_keywords' => sanitize_textarea_field($input['sensitive_keywords'] ?? $defaults['sensitive_keywords']),
        'sensitive_reply' => sanitize_textarea_field($input['sensitive_reply'] ?? $defaults['sensitive_reply']),
        'contact_mode' => ($input['contact_mode'] ?? 'embedded') === 'link' ? 'link' : 'embedded',
        'contact_url' => esc_url_raw($input['contact_url'] ?? ''),
        'notification_emails' => sanitize_textarea_field($input['notification_emails'] ?? ''),
        'knowledge_urls' => sanitize_textarea_field($input['knowledge_urls'] ?? ''),
        'excluded_urls' => sanitize_textarea_field($input['excluded_urls'] ?? ''),
        'source_website' => sanitize_text_field($input['source_website'] ?? $defaults['source_website']),
        'contact_trigger_keywords' => sanitize_textarea_field($input['contact_trigger_keywords'] ?? $defaults['contact_trigger_keywords']),
        'contact_trigger_reply' => sanitize_textarea_field($input['contact_trigger_reply'] ?? $defaults['contact_trigger_reply']),
        'no_answer_reply' => sanitize_textarea_field($input['no_answer_reply'] ?? $defaults['no_answer_reply']),
        'limit_reply' => sanitize_textarea_field($input['limit_reply'] ?? $defaults['limit_reply']),
        'show_contact_for' => array_values(array_intersect((array) ($input['show_contact_for'] ?? $defaults['show_contact_for']), ['contact', 'unanswered', 'limit', 'sensitive'])),
    ];
}

function mdh_chatbot_public_config() {
    $config = mdh_chatbot_get_config();
    return [
        'enabled' => $config['enabled'],
        'surveyEnabled' => $config['survey_enabled'],
        'contactFormEnabled' => $config['contact_form_enabled'],
        'sourceWebsite' => $config['source_website'],
        'brandName' => $config['brand_name'],
        'welcomeMessage' => $config['welcome_message'],
        'aiIntro' => $config['ai_intro'],
        'faqs' => $config['faqs'],
        'questionLimit' => $config['question_limit'],
        'sensitiveReply' => $config['sensitive_reply'],
        'contactMode' => $config['contact_mode'],
        'contactUrl' => $config['contact_url'],
        'showContactFor' => $config['show_contact_for'],
        'turnstileSiteKey' => mdh_chatbot_turnstile_site_key(),
    ];
}

function mdh_chatbot_install_records_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = mdh_chatbot_records_table();
    $charset_collate = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        mode varchar(16) NOT NULL,
        question longtext NOT NULL,
        answer longtext NOT NULL,
        page_url text NOT NULL,
        language varchar(12) NOT NULL,
        status varchar(24) NOT NULL,
        source_website varchar(120) NOT NULL DEFAULT '',
        session_id varchar(120) NOT NULL DEFAULT '',
        sensitive_blocked tinyint(1) NOT NULL DEFAULT 0,
        question_limit_reached tinyint(1) NOT NULL DEFAULT 0,
        contact_submitted tinyint(1) NOT NULL DEFAULT 0,
        contact_trigger varchar(80) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY created_at (created_at),
        KEY mode (mode)
    ) {$charset_collate};");
    $contacts_table = mdh_chatbot_contact_submissions_table();
    dbDelta("CREATE TABLE {$contacts_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(160) NOT NULL,
        company varchar(190) NOT NULL,
        email varchar(190) NOT NULL,
        country varchar(120) NOT NULL,
        request_type varchar(120) NOT NULL,
        message longtext NOT NULL,
        page_url text NOT NULL,
        session_id varchar(120) NOT NULL DEFAULT '',
        trigger_reason varchar(80) NOT NULL DEFAULT '',
        source_website varchar(120) NOT NULL DEFAULT '',
        chat_record_id bigint(20) unsigned NOT NULL DEFAULT 0,
        chat_question longtext NOT NULL,
        chat_transcript longtext NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY created_at (created_at),
        KEY email (email)
    ) {$charset_collate};");
    $audit_table = mdh_chatbot_security_audit_table();
    dbDelta("CREATE TABLE {$audit_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_type varchar(80) NOT NULL,
        actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
        actor_login varchar(190) NOT NULL DEFAULT '',
        context longtext NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY created_at (created_at),
        KEY event_type (event_type),
        KEY actor_id (actor_id)
    ) {$charset_collate};");
    $rate_table = mdh_chatbot_rate_limits_table();
    dbDelta("CREATE TABLE {$rate_table} (
        rate_key varchar(191) NOT NULL,
        hits bigint(20) unsigned NOT NULL DEFAULT 0,
        expires_at datetime NOT NULL,
        PRIMARY KEY  (rate_key),
        KEY expires_at (expires_at)
    ) {$charset_collate};");
    $saved_config = get_option('mdh_chatbot_config', []);
    if (is_array($saved_config) && isset($saved_config['question_limit']) && absint($saved_config['question_limit']) < 1) {
        $saved_config['question_limit'] = (int) mdh_chatbot_default_config()['question_limit'];
        update_option('mdh_chatbot_config', $saved_config, false);
    }
    update_option('mdh_chatbot_records_version', MDH_RECORDS_SCHEMA_VERSION, false);
}

function mdh_chatbot_maybe_install_records_table() {
    if (get_option('mdh_chatbot_records_version') !== MDH_RECORDS_SCHEMA_VERSION) {
        mdh_chatbot_install_records_table();
    }
}
add_action('plugins_loaded', 'mdh_chatbot_maybe_install_records_table', 20);

function mdh_chatbot_record_storage_error($operation, $table, $message = '') {
    $details = sanitize_text_field((string) $message);
    update_option('mdh_chatbot_last_storage_error', [
        'operation' => sanitize_key($operation),
        'table' => sanitize_text_field($table),
        'message' => $details !== '' ? $details : 'Unknown database error.',
        'time' => current_time('mysql', true),
    ], false);
}

function mdh_chatbot_storage_capacity($table, $max_rows, $operation) {
    global $wpdb;
    $cache_key = 'mdh_storage_capacity_' . md5((string) $table);
    $cached = get_transient($cache_key);
    $count = $cached === false ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") : (int) $cached;
    if ($cached === false) set_transient($cache_key, $count, 30);
    if ($count >= (int) $max_rows) {
        mdh_chatbot_record_storage_error($operation, $table, 'Storage capacity reached. Archive or delete older records before accepting more.');
        return new WP_Error('chatbot_storage_full', 'Storage is temporarily full. Please contact the site team.', ['status' => 503]);
    }
    return true;
}

/**
 * Show the latest private storage failure to administrators only. This keeps
 * public responses generic while making schema/permission problems diagnosable
 * from the WordPress records screen.
 */
function mdh_chatbot_storage_admin_notice() {
    if (!current_user_can('manage_options')) return;
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if (!in_array($page, ['mustdohr-chat-records', 'mustdohr-ai'], true)) return;
    global $wpdb;
    $error = get_option('mdh_chatbot_last_storage_error');
    if ((!is_array($error) || empty($error['message'])) && $page !== 'mustdohr-chat-records') return;
    if ($page === 'mustdohr-chat-records' && (!is_array($error) || empty($error['message']))) {
        $records_table = mdh_chatbot_records_table();
        $contacts_table = mdh_chatbot_contact_submissions_table();
        $records_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$records_table}");
        $contacts_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$contacts_table}");
        $schema_version = (string) get_option('mdh_chatbot_records_version', 'missing');
        printf(
            '<div class="notice notice-info"><p><strong>Chatbot storage status:</strong> %s records, %s contacts; schema %s.</p></div>',
            esc_html(number_format_i18n($records_count)),
            esc_html(number_format_i18n($contacts_count)),
            esc_html($schema_version)
        );
        return;
    }
    $operation = isset($error['operation']) ? sanitize_key((string) $error['operation']) : 'storage';
    $table = isset($error['table']) ? sanitize_text_field((string) $error['table']) : '';
    $time = isset($error['time']) ? sanitize_text_field((string) $error['time']) : '';
    printf(
        '<div class="notice notice-error"><p><strong>Chatbot %s storage error:</strong> %s%s%s</p></div>',
        esc_html($operation),
        esc_html((string) $error['message']),
        $table !== '' ? ' <code>' . esc_html($table) . '</code>' : '',
        $time !== '' ? ' <small>' . esc_html($time) . '</small>' : ''
    );
}
add_action('admin_notices', 'mdh_chatbot_storage_admin_notice');

function mdh_chatbot_log_response($mode, $question, $language, $request, $payload, $status = 'answered') {
    global $wpdb;
    $config = mdh_chatbot_get_config();
    $page_url = $request instanceof WP_REST_Request ? mdh_chatbot_safe_page_url($request) : home_url('/');
    $answer = is_array($payload) ? wp_strip_all_tags((string) ($payload['answer'] ?? '')) : wp_strip_all_tags((string) $payload);
    $screening = is_array($payload) ? sanitize_key((string) ($payload['screening'] ?? '')) : '';
    $trigger = is_array($payload) ? sanitize_key((string) ($payload['trigger_reason'] ?? '')) : '';
    $limit_reached = $status === 'limit_reached' || $trigger === 'limit';
    $sensitive = $screening === 'block' || $trigger === 'sensitive';
    $session_id = $request instanceof WP_REST_Request ? mdh_chatbot_session_id($request) : '';
    $request_id = $request instanceof WP_REST_Request
        ? mdh_chatbot_bounded_text($request->get_param('request_id') ?? '', 80)
        : '';
    $dedupe_key = '';
    if ($request_id !== '') {
        $dedupe_key = 'mdh_ai_log_' . hash_hmac('sha256', $mode . '|' . $session_id . '|' . $request_id, wp_salt('auth'));
        if (get_transient($dedupe_key)) return true;
    }
    $table = mdh_chatbot_records_table();
    if (is_wp_error(mdh_chatbot_storage_capacity($table, MDH_RECORD_MAX_ROWS, 'chat_record'))) return false;
    $inserted = $wpdb->insert($table, [
        'mode' => sanitize_key($mode),
        'question' => mdh_chatbot_bounded_bytes($question, 1200, true),
        'answer' => mdh_chatbot_bounded_bytes($answer, 16000, true),
        'page_url' => $page_url,
        'language' => mdh_chatbot_bounded_text($language, 12),
        'status' => sanitize_key($status),
        'source_website' => mdh_chatbot_bounded_text($config['source_website'] ?: (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'Mustdohr'), 120),
        'session_id' => $session_id,
        'sensitive_blocked' => $sensitive ? 1 : 0,
        'question_limit_reached' => $limit_reached ? 1 : 0,
        'contact_submitted' => 0,
        'contact_trigger' => $trigger,
        'created_at' => current_time('mysql', true),
    ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s']);

    if ($inserted === false) {
        mdh_chatbot_record_storage_error('chat_record', $table, $wpdb->last_error);
        return false;
    }
    if ($dedupe_key !== '') set_transient($dedupe_key, 1, 15 * MINUTE_IN_SECONDS);
    delete_option('mdh_chatbot_last_storage_error');
    return true;
}

function mdh_chatbot_cookie_session_id(WP_REST_Request $request) {
    $cookie_header = (string) $request->get_header('cookie');
    if (preg_match('/(?:^|;\\s*)mdh_chat_session=([A-Za-z0-9_-]{24,96})(?:;|$)/', $cookie_header, $match)) {
        return $match[1];
    }
    return '';
}

function mdh_chatbot_session_id(WP_REST_Request $request) {
    $cookie_session = mdh_chatbot_cookie_session_id($request);
    if ($cookie_session !== '') return $cookie_session;
    $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $agent = sanitize_text_field($request->get_header('user-agent'));
    return 'fallback-' . substr(hash_hmac('sha256', $ip . '|' . $agent, wp_salt('auth')), 0, 48);
}

/**
 * Public chatbot requests must originate from the website page and carry a
 * short-lived WordPress nonce. This is not a replacement for an edge WAF, but
 * it prevents arbitrary server-to-server posts from reaching the write paths.
 */
function mdh_chatbot_public_nonce_valid(WP_REST_Request $request) {
    $nonce = trim((string) $request->get_header('x-mdh-nonce'));
    if ($nonce === '') $nonce = trim((string) $request->get_param('_mdh_nonce'));
    if ($nonce === '') {
        $json = $request->get_json_params();
        if (is_array($json)) $nonce = trim((string) ($json['_mdh_nonce'] ?? ''));
    }
    if ($nonce === '') return false;
    if (wp_verify_nonce($nonce, 'mdh_public_request')) return true;

    // Public pages may be served from a full-page cache. A WordPress nonce
    // generated for an authenticated page would then be invalid for visitors,
    // so also accept a site-salt token valid for the current or previous day.
    $bucket = (int) floor(time() / DAY_IN_SECONDS);
    foreach ([$bucket, $bucket - 1] as $candidate) {
        $expected = hash_hmac('sha256', 'mdh_public_request|' . $candidate, wp_salt('auth'));
        if (hash_equals($expected, $nonce)) return true;
    }
    return false;
}

function mdh_chatbot_public_token() {
    $bucket = (int) floor(time() / DAY_IN_SECONDS);
    return hash_hmac('sha256', 'mdh_public_request|' . $bucket, wp_salt('auth'));
}

function mdh_chatbot_public_host_matches($url) {
    $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
    $home = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    if ($host === '' || $home === '') return false;
    if ($host === $home) return true;
    return preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $home);
}

/**
 * Increment a rate counter atomically in MySQL. INSERT ... ON DUPLICATE KEY
 * UPDATE removes the read/check/write race that allowed concurrent requests to
 * reuse the same remaining slot.
 */
function mdh_chatbot_atomic_rate_increment($scope, $identity, $limit, $window) {
    global $wpdb;
    $scope = sanitize_key($scope);
    $limit = max(1, (int) $limit);
    $window = max(1, (int) $window);
    $table = mdh_chatbot_rate_limits_table();
    $rate_key = hash_hmac('sha256', $scope . '|' . (string) $identity, wp_salt('auth'));
    $expires_at = gmdate('Y-m-d H:i:s', time() + $window);

    $updated = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (rate_key, hits, expires_at) VALUES (%s, 1, %s)
         ON DUPLICATE KEY UPDATE
            hits = IF(expires_at <= UTC_TIMESTAMP(), 1, hits + 1),
            expires_at = IF(expires_at <= UTC_TIMESTAMP(), VALUES(expires_at), expires_at)",
        $rate_key,
        $expires_at
    ));
    if ($updated === false) {
        mdh_chatbot_record_storage_error('rate_limit', $table, $wpdb->last_error);
        return new WP_Error('public_rate_unavailable', 'The request could not be checked. Please try again shortly.', ['status' => 503]);
    }

    $hits = (int) $wpdb->get_var($wpdb->prepare("SELECT hits FROM {$table} WHERE rate_key = %s", $rate_key));
    if (wp_rand(1, 100) === 1) {
        $wpdb->query("DELETE FROM {$table} WHERE expires_at < UTC_TIMESTAMP()");
    }
    if ($hits > $limit) {
        return new WP_Error('public_rate_limited', 'Please wait a few minutes before trying again.', ['status' => 429]);
    }
    return true;
}

function mdh_chatbot_public_rate_limit(WP_REST_Request $request, $scope) {
    $scope = sanitize_key($scope);
    $ip_limits = [
        'search' => (int) MDH_PUBLIC_SEARCH_IP_LIMIT,
        'ai' => (int) MDH_PUBLIC_AI_IP_LIMIT,
        'contact' => (int) MDH_PUBLIC_CONTACT_IP_LIMIT,
    ];
    $session_limits = [
        'search' => (int) MDH_PUBLIC_SEARCH_SESSION_LIMIT,
        'ai' => (int) MDH_PUBLIC_AI_SESSION_LIMIT,
        'contact' => (int) MDH_PUBLIC_CONTACT_SESSION_LIMIT,
    ];
    $global_limits = [
        'search' => (int) MDH_PUBLIC_SEARCH_GLOBAL_LIMIT,
        'ai' => (int) MDH_PUBLIC_AI_GLOBAL_LIMIT,
        'contact' => (int) MDH_PUBLIC_CONTACT_GLOBAL_LIMIT,
    ];
    if (!isset($ip_limits[$scope])) return true;

    $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $session = mdh_chatbot_session_id($request);
    foreach ([
        [$scope . '_ip', $ip, $ip_limits[$scope], MDH_PUBLIC_RATE_WINDOW],
        [$scope . '_session', $session, $session_limits[$scope], MDH_PUBLIC_RATE_WINDOW],
        [$scope . '_global', 'all', $global_limits[$scope], 60],
    ] as $layer) {
        $result = mdh_chatbot_atomic_rate_increment($layer[0], $layer[1], $layer[2], $layer[3]);
        if (is_wp_error($result)) return $result;
    }
    return true;
}

function mdh_chatbot_public_guard(WP_REST_Request $request, $scope) {
    if (strtoupper((string) $request->get_method()) !== 'POST') {
        return new WP_Error('public_method_not_allowed', 'This endpoint accepts POST requests only.', ['status' => 405]);
    }
    if (!mdh_chatbot_public_nonce_valid($request)) {
        return new WP_Error('public_nonce_required', 'A valid website session is required.', ['status' => 403]);
    }

    $origin = trim((string) $request->get_header('origin'));
    $referer = trim((string) $request->get_header('referer'));
    $source = $origin !== '' ? $origin : $referer;
    if ($source === '' || !mdh_chatbot_public_host_matches($source)) {
        return new WP_Error('public_origin_rejected', 'The request origin is not allowed.', ['status' => 403]);
    }

    $body_limits = [
        'search' => (int) MDH_PUBLIC_SEARCH_BODY_MAX_BYTES,
        'ai' => (int) MDH_PUBLIC_AI_BODY_MAX_BYTES,
        'contact' => (int) MDH_PUBLIC_CONTACT_BODY_MAX_BYTES,
    ];
    if (isset($body_limits[$scope]) && strlen((string) $request->get_body()) > $body_limits[$scope]) {
        return new WP_Error('public_payload_too_large', 'Please shorten the request before sending it.', ['status' => 413]);
    }

    return mdh_chatbot_public_rate_limit($request, $scope);
}

function mdh_chatbot_public_permission(WP_REST_Request $request, $scope) {
    return mdh_chatbot_public_guard($request, $scope);
}

/**
 * Turnstile is verified server-side. The secret never leaves WordPress, and
 * the response token is accepted only for the expected action and hostname.
 */
function mdh_chatbot_turnstile_site_key() {
    return trim((string) get_option('mdh_turnstile_site_key', ''));
}

function mdh_chatbot_turnstile_secret_key() {
    return trim((string) get_option('mdh_turnstile_secret_key', ''));
}

function mdh_chatbot_turnstile_token(WP_REST_Request $request) {
    $token = trim((string) $request->get_param('turnstile_token'));
    if ($token === '') $token = trim((string) $request->get_param('cf-turnstile-response'));
    if ($token === '') {
        $json = $request->get_json_params();
        if (is_array($json)) {
            $token = trim((string) ($json['turnstile_token'] ?? $json['cf-turnstile-response'] ?? ''));
        }
    }
    return $token;
}

function mdh_chatbot_verify_turnstile(WP_REST_Request $request, $expected_action) {
    $secret = mdh_chatbot_turnstile_secret_key();
    $site_key = mdh_chatbot_turnstile_site_key();
    $token = mdh_chatbot_turnstile_token($request);
    if ($secret === '' || $site_key === '') {
        return new WP_Error('captcha_not_configured', 'The security check is not configured yet.', ['status' => 503]);
    }
    if ($token === '' || strlen($token) > 4096) {
        return new WP_Error('captcha_required', 'Please complete the security check before continuing.', ['status' => 403]);
    }
    $remote_ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
    $body = ['secret' => $secret, 'response' => $token];
    if ($remote_ip !== '') $body['remoteip'] = $remote_ip;
    $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 8,
        'headers' => ['Accept' => 'application/json'],
        'body' => $body,
    ]);
    if (is_wp_error($response)) {
        return new WP_Error('captcha_unavailable', 'The security check is temporarily unavailable. Please try again.', ['status' => 503]);
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $result = json_decode(wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300 || !is_array($result) || empty($result['success'])) {
        return new WP_Error('captcha_failed', 'Please complete the security check and try again.', ['status' => 403]);
    }
    if (!empty($result['action']) && (string) $result['action'] !== (string) $expected_action) {
        return new WP_Error('captcha_failed', 'Please complete the security check and try again.', ['status' => 403]);
    }
    $expected_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $hostname = strtolower((string) ($result['hostname'] ?? ''));
    if ($hostname !== '' && $expected_host !== '' && $hostname !== $expected_host && preg_replace('/^www\./', '', $hostname) !== preg_replace('/^www\./', '', $expected_host)) {
        return new WP_Error('captcha_failed', 'Please complete the security check and try again.', ['status' => 403]);
    }
    return true;
}

function mdh_chatbot_ai_captcha_key(WP_REST_Request $request) {
    return 'mdh_ai_captcha_' . hash_hmac('sha256', mdh_chatbot_session_id($request), wp_salt('auth'));
}

/** Require Turnstile only for the first AI request in a visitor session. */
function mdh_chatbot_require_ai_captcha(WP_REST_Request $request) {
    if (get_transient(mdh_chatbot_ai_captcha_key($request))) return true;
    $verified = mdh_chatbot_verify_turnstile($request, 'chatbot');
    if (is_wp_error($verified)) return $verified;
    set_transient(mdh_chatbot_ai_captcha_key($request), 1, MDH_AI_CAPTCHA_TTL);
    return true;
}

function mdh_chatbot_safe_page_url(WP_REST_Request $request) {
    $referer = esc_url_raw($request->get_header('referer'));
    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $referer_host = strtolower((string) wp_parse_url($referer, PHP_URL_HOST));
    return ($referer !== '' && $referer_host === $home_host) ? $referer : home_url('/');
}

function mdh_chatbot_bounded_text($value, $limit, $textarea = false) {
    $clean = $textarea ? sanitize_textarea_field((string) $value) : sanitize_text_field((string) $value);
    return function_exists('mb_substr') ? mb_substr($clean, 0, $limit) : substr($clean, 0, $limit);
}

function mdh_chatbot_bounded_bytes($value, $limit, $textarea = false) {
    $clean = $textarea ? sanitize_textarea_field((string) $value) : sanitize_text_field((string) $value);
    $limit = max(0, (int) $limit);
    if (strlen($clean) <= $limit) return $clean;
    if (function_exists('mb_strcut')) return mb_strcut($clean, 0, $limit, 'UTF-8');
    return wp_check_invalid_utf8(substr($clean, 0, $limit), true);
}

function mdh_chatbot_set_session_cookie($response, WP_REST_Request $request) {
    if (!$response instanceof WP_REST_Response || mdh_chatbot_cookie_session_id($request) !== '') return $response;
    $session_id = mdh_chatbot_session_id($request);
    $response->header('Set-Cookie', 'mdh_chat_session=' . rawurlencode($session_id) . '; Path=/; Max-Age=31536000; HttpOnly; SameSite=Lax; Secure');
    return $response;
}

/**
 * Persist AI responses in the route callback itself. The previous implementation
 * depended on an exact rest_post_dispatch route string, so live installs could
 * answer correctly while silently skipping the chat-record insert.
 */
function mdh_chatbot_ai_answer_logged(WP_REST_Request $request) {
    $response = mdh_ai_answer($request);
    $question = trim((string) $request->get_param('message'));
    if ($question === '' || strlen($question) > 1200) return $response;

    // Rejected requests never become chat history. This includes invalid
    // payloads, rate/visit limits and present or future CAPTCHA failures.
    $non_recordable_codes = [
        'invalid_question',
        'question_limit_reached',
        'public_rate_limited',
        'public_rate_unavailable',
        'public_nonce_required',
        'public_origin_rejected',
        'public_method_not_allowed',
        'public_payload_too_large',
        'captcha_required',
        'captcha_failed',
        'captcha_unavailable',
        'captcha_not_configured',
        'turnstile_failed',
    ];
    if (is_wp_error($response) && in_array($response->get_error_code(), $non_recordable_codes, true)) {
        return $response;
    }

    $status = 'answered';
    $answer = '';
    $record_payload = [];
    if ($response instanceof WP_REST_Response) {
        $data = $response->get_data();
        $answer = is_array($data) ? (string) ($data['answer'] ?? $data['message'] ?? '') : '';
        $record_payload = is_array($data) ? $data : [];
        $status = $response->get_status() >= 400 ? 'failed' : 'answered';
        if ($response->get_status() >= 400) return $response;
    } elseif (is_wp_error($response)) {
        $answer = $response->get_error_message();
        $status = 'failed';
    }
    $record_payload['answer'] = $answer;
    mdh_chatbot_log_response('ai', $question, mdh_chatbot_get_language($request), $request, $record_payload, $status);
    return $response;
}

add_filter('rest_post_dispatch', function ($response, $server, $request) {
    $route = $request instanceof WP_REST_Request ? $request->get_route() : '';
    if (strpos($route, '/mustdohr-search/v1/records') === 0 || strpos($route, '/mustdohr-search/v1/contact-submissions') === 0) {
        if ($response instanceof WP_REST_Response) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
    }
    if (in_array($route, ['/mustdohr-search/v1/ai', '/mustdohr-search/v1/contact'], true)) {
        return mdh_chatbot_set_session_cookie($response, $request);
    }
    return $response;
}, 20, 3);
function mdh_chatbot_records_page() {
    if (!current_user_can('manage_options')) return;
    $new_connection_key = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mdh_generate_records_keys'])) {
        check_admin_referer('mdh_generate_records_key');
        $new_connection_key = mdh_chatbot_generate_connection_key();
        $records_key_message = 'A new private connection code was created. Copy it now; WordPress stores only its hash.';
    }
    $connection_key_exists = (bool) get_option('mdh_chatbot_records_connection_key_hash', '');
    ?>
    <div class="wrap">
        <h1>Chatbot Chat Records</h1>
        <div class="notice notice-info inline"><p><strong>Private archive mode.</strong> Visitor conversations and Contact submissions are not rendered in WordPress. They remain available only to the local admin after a valid connection code is presented over HTTPS.</p></div>
        <hr>
        <h2>Local admin connection</h2>
        <p>Generate one private connection code for the localhost admin. WordPress stores only a hash. The local session expires after 15 minutes, and records are cleared from WordPress only after the local archive confirms they were saved.</p>
        <?php if (!empty($records_key_message)) : ?><div class="notice notice-success"><p><?php echo esc_html($records_key_message); ?></p></div><?php endif; ?>
        <?php if ($new_connection_key) : ?>
            <p><label><strong>Connection code</strong><input type="text" class="large-text code" readonly value="<?php echo esc_attr($new_connection_key); ?>" onclick="this.select();"></label></p>
        <?php elseif ($connection_key_exists) : ?>
            <p><em>A connection code already exists. Generate a new one to rotate it; the previous code will stop working.</em></p>
        <?php else : ?>
            <p><em>No connection code has been created yet.</em></p>
        <?php endif; ?>
        <form method="post" style="margin: 12px 0 24px;">
            <?php wp_nonce_field('mdh_generate_records_key'); ?>
            <button type="submit" class="button button-secondary" name="mdh_generate_records_keys" value="1"><?php echo $connection_key_exists ? 'Rotate connection code' : 'Create connection code'; ?></button>
        </form>
        <ul>
            <li>Only the local admin can request records with the private connection code.</li>
            <li>Each request is uncached and records are removed from WordPress only after local archival succeeds.</li>
            <li>Connection failures are rate-limited and recorded in the security audit.</li>
        </ul>
    </div>
    <?php
}
function mdh_security_audit_page() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = mdh_chatbot_security_audit_table();
    $events = $wpdb->get_results("SELECT event_type, actor_id, actor_login, context, created_at FROM {$table} ORDER BY id DESC LIMIT 100");
    ?>
    <div class="wrap">
        <h1>Chatbot Security Audit</h1>
        <p>Security events are recorded without storing Gemini keys, connection codes or edited file contents. Alerts use the configured notification emails and fall back to the WordPress admin email.</p>
        <table class="widefat striped">
            <thead><tr><th>Time (UTC)</th><th>Event</th><th>Actor</th><th>Context</th></tr></thead>
            <tbody>
            <?php if ($events) : foreach ($events as $event) : ?>
                <tr>
                    <td><?php echo esc_html($event->created_at); ?></td>
                    <td><?php echo esc_html($event->event_type); ?></td>
                    <td><?php echo esc_html($event->actor_login . ' #' . absint($event->actor_id)); ?></td>
                    <td><code><?php echo esc_html(wp_json_encode(json_decode($event->context, true) ?: [])); ?></code></td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="4">No security events have been recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

add_action('admin_init', function () {
    register_setting('mdh_ai_settings', 'mdh_guard_api_key', [
        'type' => 'string',
        'sanitize_callback' => 'mdh_sanitize_plaintext_api_key',
        'default' => '',
    ]);
    register_setting('mdh_ai_settings', 'mdh_answer_api_key', [
        'type' => 'string',
        'sanitize_callback' => 'mdh_sanitize_plaintext_api_key',
        'default' => '',
    ]);
    register_setting('mdh_ai_settings', 'mdh_blocked_keywords', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
        'default' => '',
    ]);
    register_setting('mdh_ai_settings', 'mdh_blocked_reply', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
        'default' => 'I cannot help with that request. Please use the website contact form for assistance.',
    ]);
    register_setting('mdh_ai_settings', 'mdh_gemini_model', [
        'type' => 'string',
        'sanitize_callback' => 'mdh_sanitize_gemini_model',
        'default' => 'gemini-3.5-flash-lite',
    ]);
    register_setting('mdh_ai_settings', 'mdh_survey_enabled', [
        'type' => 'boolean',
        'sanitize_callback' => static function ($value) { return !empty($value); },
        'default' => true,
    ]);
    register_setting('mdh_ai_settings', 'mdh_contact_form_enabled', [
        'type' => 'boolean',
        'sanitize_callback' => static function ($value) { return !empty($value); },
        'default' => true,
    ]);
    register_setting('mdh_ai_settings', 'mdh_turnstile_site_key', [
        'type' => 'string',
        'sanitize_callback' => 'mdh_sanitize_plaintext_api_key',
        'default' => '',
    ]);
    register_setting('mdh_ai_settings', 'mdh_turnstile_secret_key', [
        'type' => 'string',
        'sanitize_callback' => 'mdh_sanitize_plaintext_api_key',
        'default' => '',
    ]);
});

function mdh_sanitize_plaintext_api_key($value) {
    return trim((string) wp_unslash($value));
}

function mdh_sanitize_gemini_model($model) {
    $allowed = ['gemini-3.5-flash-lite', 'gemini-2.5-flash-lite'];
    return in_array($model, $allowed, true) ? $model : 'gemini-3.5-flash-lite';
}

/** Detect an OpenRouter key without exposing its value. */
function mdh_is_openrouter_key($api_key) {
    return is_string($api_key) && strpos(trim($api_key), 'sk-or-') === 0;
}

/** Map the legacy Gemini setting to a current OpenRouter free model. */
function mdh_openrouter_model($model) {
    if (!$model || strpos((string) $model, 'gemini-') === 0) {
        // OpenRouter's free router keeps the integration working as free
        // model IDs rotate or are retired.
        return 'openrouter/free';
    }
    return sanitize_text_field($model);
}

function mdh_ai_settings_page() {
    if (!current_user_can('manage_options')) return;
    $credentials = mdh_chatbot_gemini_credentials();
    $config = mdh_chatbot_get_config();
    mdh_chatbot_security_audit_event('gemini_key_status_view', [
        'guard_source' => $credentials['guard_source'],
        'answer_source' => $credentials['answer_source'],
    ], true);
    $last_error = get_option('mdh_last_ai_error', []);
    ?>
    <div class="wrap">
        <h1>Chatbot AI Settings</h1>
        <p>Each AI question is screened for compliance first. Only approved questions are then sent to the public-website answer model.</p>
        <?php if ($credentials['guard_source'] === 'missing' || $credentials['answer_source'] === 'missing') : ?>
            <div class="notice notice-warning"><p>Enter both AI provider keys below. They are stored as plaintext WordPress settings for this site.</p></div>
        <?php else : ?>
            <div class="notice notice-success"><p>Both AI provider keys are configured. Values are stored as plaintext WordPress settings and are used only by the server.</p></div>
        <?php endif; ?>
        <?php if (mdh_chatbot_turnstile_site_key() === '' || mdh_chatbot_turnstile_secret_key() === '') : ?>
            <div class="notice notice-warning"><p>Enter the Cloudflare Turnstile site and secret keys below to enable the security check on public forms and the first AI message.</p></div>
        <?php else : ?>
            <div class="notice notice-success"><p>Cloudflare Turnstile is configured. Contact submissions are checked each time; AI is checked once per visitor session.</p></div>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields('mdh_ai_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mdh_guard_api_key">Compliance screening API key</label></th>
                    <td>
                        <input type="text" class="regular-text code" id="mdh_guard_api_key" name="mdh_guard_api_key" value="<?php echo esc_attr(get_option('mdh_guard_api_key', '')); ?>" autocomplete="off" spellcheck="false">
                        <p class="description">Status: <?php echo esc_html($credentials['guard_source'] === 'missing' ? 'Missing' : 'Configured'); ?>. Stored in this site's WordPress options as plaintext.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mdh_answer_api_key">Website answer API key</label></th>
                    <td>
                        <input type="text" class="regular-text code" id="mdh_answer_api_key" name="mdh_answer_api_key" value="<?php echo esc_attr(get_option('mdh_answer_api_key', '')); ?>" autocomplete="off" spellcheck="false">
                        <p class="description">Status: <?php echo esc_html($credentials['answer_source'] === 'missing' ? 'Missing' : 'Configured'); ?>. Stored in this site's WordPress options as plaintext.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mdh_turnstile_site_key">Cloudflare Turnstile site key</label></th>
                    <td>
                        <input type="text" class="regular-text code" id="mdh_turnstile_site_key" name="mdh_turnstile_site_key" value="<?php echo esc_attr(get_option('mdh_turnstile_site_key', '')); ?>" autocomplete="off" spellcheck="false">
                        <p class="description">Public widget key used to display the Cloudflare security check on the chatbot and contact form.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mdh_turnstile_secret_key">Cloudflare Turnstile secret key</label></th>
                    <td>
                        <input type="text" class="regular-text code" id="mdh_turnstile_secret_key" name="mdh_turnstile_secret_key" value="<?php echo esc_attr(get_option('mdh_turnstile_secret_key', '')); ?>" autocomplete="off" spellcheck="false">
                        <p class="description">Server-side verification key. It is never sent to visitors.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mdh_blocked_keywords">Blocked keywords and phrases</label></th>
                    <td>
                        <textarea class="large-text" rows="7" id="mdh_blocked_keywords" name="mdh_blocked_keywords" placeholder="competitor
lowest price
internal policy"><?php echo esc_textarea(get_option('mdh_blocked_keywords', '')); ?></textarea>
                        <p class="description">Optional. Add one word or phrase per line. These are blocked locally before either API is called.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mdh_blocked_reply">Blocked-question reply</label></th>
                    <td><textarea class="large-text" rows="3" id="mdh_blocked_reply" name="mdh_blocked_reply"><?php echo esc_textarea(get_option('mdh_blocked_reply', 'I cannot help with that request. Please use the website contact form for assistance.')); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="mdh_gemini_model">Gemini model</label></th>
                    <td>
                        <select id="mdh_gemini_model" name="mdh_gemini_model">
                            <?php $selected_model = mdh_sanitize_gemini_model(get_option('mdh_gemini_model', 'gemini-3.5-flash-lite')); ?>
                            <option value="gemini-3.5-flash-lite" <?php selected($selected_model, 'gemini-3.5-flash-lite'); ?>>Gemini 3.5 Flash-Lite (recommended)</option>
                            <option value="gemini-2.5-flash-lite" <?php selected($selected_model, 'gemini-2.5-flash-lite'); ?>>Gemini 2.5 Flash-Lite (fallback)</option>
                        </select>
                        <p class="description">Gemini 2.0 Flash has been shut down. This plugin now uses a current stable model.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mdh_survey_enabled">Bottom questionnaire</label></th>
                    <td>
                        <input type="hidden" name="mdh_survey_enabled" value="0">
                        <label><input type="checkbox" id="mdh_survey_enabled" name="mdh_survey_enabled" value="1" <?php checked(!empty($config['survey_enabled'])); ?>> Show the fixed Quick access questionnaire at the bottom of the public assistant.</label>
                        <p class="description">Turn this off to keep the search and AI assistant while hiding only the questionnaire.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mdh_contact_form_enabled">Public contact form</label></th>
                    <td>
                        <input type="hidden" name="mdh_contact_form_enabled" value="0">
                        <label><input type="checkbox" id="mdh_contact_form_enabled" name="mdh_contact_form_enabled" value="1" <?php checked(!empty($config['contact_form_enabled'])); ?>> Show the public CONTACT OUR TEAM form.</label>
                        <p class="description">Turn this off to hide the standalone contact form section while keeping the assistant available.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save AI settings'); ?>
        </form>
        <?php if (is_array($last_error) && !empty($last_error['message'])) : ?>
            <hr>
            <h2>Last Gemini diagnostic</h2>
            <p><strong><?php echo esc_html(($last_error['time'] ?? '') . ' �?' . ($last_error['model'] ?? '') . ' �?HTTP ' . ($last_error['status'] ?? '')); ?></strong></p>
            <p><?php echo esc_html($last_error['message']); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('mdh-search-assistant', plugin_dir_url(__FILE__) . 'assistant.css', [], MDH_SEARCH_VERSION);
    wp_enqueue_style('mdh-contact-assistant', plugin_dir_url(__FILE__) . 'contact.css', ['mdh-search-assistant'], MDH_SEARCH_VERSION);
    wp_enqueue_style('mdh-web-contact-form', plugin_dir_url(__FILE__) . 'contact-form.css', ['mdh-contact-assistant'], MDH_SEARCH_VERSION);
    wp_enqueue_style('mdh-config-assistant', plugin_dir_url(__FILE__) . 'config.css', ['mdh-search-assistant'], MDH_SEARCH_VERSION);
    wp_enqueue_script('mdh-search-assistant', plugin_dir_url(__FILE__) . 'assistant.js', [], MDH_SEARCH_VERSION, true);
    wp_enqueue_script('mdh-web-contact-form', plugin_dir_url(__FILE__) . 'contact-form.js', [], MDH_SEARCH_VERSION, true);
    if (mdh_chatbot_turnstile_site_key() !== '') {
        wp_enqueue_script('mdh-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, true);
    }
    wp_localize_script('mdh-search-assistant', 'MustdohrAssistant', [
        'endpoint' => esc_url_raw(rest_url('mustdohr-search/v1/ask')),
        'aiEndpoint' => esc_url_raw(rest_url('mustdohr-search/v1/ai')),
        'contactEndpoint' => esc_url_raw(rest_url('mustdohr-search/v1/contact')),
        'contactFallbackEndpoint' => esc_url_raw(add_query_arg('rest_route', '/mustdohr-search/v1/contact', home_url('/'))),
        'contactAjaxEndpoint' => esc_url_raw(admin_url('admin-ajax.php')),
        'contactPostEndpoint' => esc_url_raw(admin_url('admin-post.php')),
        'contactDirectEndpoint' => esc_url_raw(add_query_arg('mdh_chatbot_contact', '1', home_url('/'))),
        'home' => esc_url_raw(home_url('/')),
        'language' => substr(determine_locale(), 0, 2),
        'nonce' => mdh_chatbot_public_token(),
        'turnstileSiteKey' => mdh_chatbot_turnstile_site_key(),
        'config' => mdh_chatbot_public_config(),
    ]);
    wp_localize_script('mdh-web-contact-form', 'MustdohrContactFormConfig', [
        'endpoint' => esc_url_raw(rest_url('mustdohr-search/v1/contact')),
        'fallbackEndpoint' => esc_url_raw(add_query_arg('rest_route', '/mustdohr-search/v1/contact', home_url('/'))),
        'ajaxEndpoint' => esc_url_raw(admin_url('admin-ajax.php')),
        'postEndpoint' => esc_url_raw(admin_url('admin-post.php')),
        'directEndpoint' => esc_url_raw(add_query_arg('mdh_chatbot_contact', '1', home_url('/'))),
        'sourceWebsite' => mdh_chatbot_get_config()['source_website'] ?: (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'Mustdohr'),
        'nonce' => mdh_chatbot_public_token(),
        'turnstileSiteKey' => mdh_chatbot_turnstile_site_key(),
    ]);
});

/**
 * Render the same contact workflow outside the floating assistant.
 * The visitor cookie is shared with the assistant, so prior chat records are
 * linked automatically when the visitor submits this form.
 */
function mdh_chatbot_contact_form_shortcode() {
    $config = mdh_chatbot_get_config();
    if (empty($config['contact_form_enabled'])) return '';
    // Keep one stable anchor so Quick access can open this original form
    // without replacing it with a second form or losing its prefilled values.
    $id = 'mustdohr-contact';
    ob_start();
    ?>
    <section class="mdh-web-contact-form" id="<?php echo esc_attr($id); ?>" data-source-website="<?php echo esc_attr($config['source_website'] ?: (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'Website')); ?>">
        <div class="mdh-web-contact-form__intro">
            <span class="mdh-web-contact-form__eyebrow">CONTACT OUR TEAM</span>
            <h2>Tell us how we can help</h2>
            <p>Share a few details and our team will follow up. Your request can be linked to your recent assistant conversation.</p>
        </div>
        <div class="mdh-web-contact-form__cookie" data-web-contact-cookie hidden>
            <p>We use a small cookie to link your chat messages with this contact request.</p>
            <button type="button" data-web-contact-cookie-dismiss>Got it</button>
        </div>
        <form class="mdh-web-contact-form__fields" data-web-contact-submit>
            <label>Name<input name="name" maxlength="160" autocomplete="name" required></label>
            <label>Company<input name="company" maxlength="190" autocomplete="organization"></label>
            <label>Email<input name="email" type="email" maxlength="190" autocomplete="email" required></label>
            <label>Country / region<input name="country" maxlength="120" autocomplete="country-name"></label>
            <label>What do you need?<select name="request_type"><option>General enquiry</option><option>HR support</option><option>Onboarding</option><option>Payroll</option><option>Partnership</option></select></label>
            <label class="mdh-web-contact-form__wide">Message<textarea name="message" maxlength="2000" required></textarea></label>
            <?php if (mdh_chatbot_turnstile_site_key() !== '') : ?><div class="cf-turnstile mdh-turnstile" data-sitekey="<?php echo esc_attr(mdh_chatbot_turnstile_site_key()); ?>" data-action="contact"></div><?php endif; ?>
            <input class="mdh-honeypot" name="website" tabindex="-1" autocomplete="off">
            <button class="mdh-web-contact-form__submit" type="submit">Send enquiry</button>
        </form>
        <p class="mdh-web-contact-form__status" data-web-contact-status hidden aria-live="polite"></p>
    </section>
    <?php
    return ob_get_clean();
}
add_shortcode('mustdohr_contact_form', 'mdh_chatbot_contact_form_shortcode');

add_action('wp_footer', function () {
    if (!mdh_chatbot_get_config()['enabled']) return;
    $config = mdh_chatbot_get_config();
    ?>
    <div class="mdh-assistant" id="mdh-assistant">
        <button class="mdh-assistant-launch" type="button" aria-expanded="false">
            <span aria-hidden="true">+</span> Search
        </button>
        <section class="mdh-assistant-panel" hidden aria-label="Website assistant">
            <header>
                <div>
                    <small>Public website content only</small>
                </div>
                <button class="mdh-assistant-close" type="button" aria-label="Close">x</button>
            </header>
            <div class="mdh-assistant-cookie-notice" data-cookie-notice hidden>
                <p>We use a small cookie to link your chat messages with a contact request, so our team can see the full conversation.</p>
                <button type="button" data-cookie-dismiss>Got it</button>
            </div>
            <div class="mdh-assistant-messages" aria-live="polite">
                <div class="mdh-assistant-message assistant" data-search-intro><?php echo esc_html($config['welcome_message']); ?></div>
                <div class="mdh-assistant-message assistant" data-ai-intro hidden><?php echo esc_html($config['ai_intro']); ?></div>
            </div>
            <div class="mdh-assistant-faqs" data-faqs></div>
            <?php if (mdh_chatbot_turnstile_site_key() !== '') : ?>
            <div class="mdh-assistant-ai-captcha" data-ai-captcha hidden>
                <p class="mdh-assistant-ai-captcha__hint">Complete the security check once to start an AI conversation.</p>
                <div class="cf-turnstile" data-sitekey="<?php echo esc_attr(mdh_chatbot_turnstile_site_key()); ?>" data-action="chatbot" data-callback="mdhChatbotAiTurnstileCallback"></div>
            </div>
            <?php endif; ?>
            <?php if ($config['survey_enabled']) : ?>
            <button class="mdh-assistant-quick-toggle" type="button" data-quick-toggle>Quick access: find the right service</button>
            <?php endif; ?>
            <form class="mdh-assistant-search-form">
                <label class="screen-reader-text" for="mdh-assistant-input">Search this website</label>
                <input id="mdh-assistant-input" maxlength="300" placeholder="Search this website" autocomplete="off" required>
                <button type="submit">Send</button>
            </form>
            <?php if ($config['survey_enabled']) : ?>
            <section class="mdh-quick-access" data-quick-access hidden aria-label="Service finder">
                <div class="mdh-quick-access__intro"><strong>Find the right starting point</strong><span>Answer three quick questions and we will prepare your enquiry.</span></div>
                <form class="mdh-quick-access__form" data-quick-form>
                    <label>Which best describes you?<select name="role" required><option value="">Choose one</option><option value="employer">I am hiring or managing a team</option><option value="employee">I am joining a company</option><option value="exploring">I am exploring HR support</option></select></label>
                    <label>Where is the work based?<input name="country" maxlength="120" placeholder="Country or region" required></label>
                    <label>What do you need help with?<select name="service" required><option value="">Choose a service</option><option value="hiring">Hiring and onboarding</option><option value="payroll">Payroll and payments</option><option value="compliance">Compliance and policies</option><option value="employee-support">Employee support and benefits</option><option value="general">Something else</option></select></label>
                    <button type="submit">Prepare my enquiry</button>
                </form>
                <div class="mdh-quick-access__result" data-quick-result hidden></div>
            </section>
            <?php endif; ?>
            <button class="mdh-assistant-contact-toggle" type="button" data-contact-toggle hidden><?php echo $config['contact_mode'] === 'link' ? 'Open contact form' : 'Contact us'; ?></button>
            <form class="mdh-assistant-contact-form" data-contact-form hidden>
                <label>Name<input name="name" maxlength="160" required></label>
                <label>Company<input name="company" maxlength="190"></label>
                <label>Email<input name="email" type="email" maxlength="190" required></label>
                <label>Country / region<input name="country" maxlength="120"></label>
                <label>What do you need?<select name="request_type"><option>General enquiry</option><option>HR support</option><option>Onboarding</option><option>Payroll</option><option>Partnership</option></select></label>
                <label>Message<textarea name="message" maxlength="2000" required></textarea></label>
                <?php if (mdh_chatbot_turnstile_site_key() !== '') : ?><div class="cf-turnstile mdh-turnstile" data-sitekey="<?php echo esc_attr(mdh_chatbot_turnstile_site_key()); ?>" data-action="contact"></div><?php endif; ?>
                <input class="mdh-honeypot" name="website" tabindex="-1" autocomplete="off">
                <button type="submit">Send enquiry</button>
                <button type="button" class="mdh-assistant-contact-remove" data-contact-remove>Remove form</button>
            </form>
            <p class="mdh-assistant-contact-status" data-contact-status hidden aria-live="polite"></p>
            <p class="mdh-assistant-status"><span class="mdh-assistant-count">Public information only</span></p>
        </section>
    </div>
    <?php
});

// Make the standalone form available on the public homepage without requiring
// an editor change. The shortcode can also be placed on any other page.
add_action('wp_footer', function () {
    $config = mdh_chatbot_get_config();
    if (!$config['contact_form_enabled'] || !is_front_page()) return;
    global $post;
    if ($post instanceof WP_Post && has_shortcode((string) $post->post_content, 'mustdohr_contact_form')) return;
    echo mdh_chatbot_contact_form_shortcode();
});

add_action('rest_api_init', function () {
    register_rest_route('mustdohr-search/v1', '/ask', [
        'methods' => 'POST',
        'permission_callback' => function (WP_REST_Request $request) { return mdh_chatbot_public_permission($request, 'search'); },
        'callback' => 'mdh_search_answer',
        'args' => [
            'message' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'lang' => [
                'required' => false,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'en',
            ],
        ],
    ]);
    register_rest_route('mustdohr-search/v1', '/ai', [
        'methods' => 'POST',
        'permission_callback' => function (WP_REST_Request $request) { return mdh_chatbot_public_permission($request, 'ai'); },
        'callback' => 'mdh_chatbot_ai_answer_logged',
        'args' => [
            'message' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'lang' => [
                'required' => false,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'en',
            ],
        ],
    ]);
    register_rest_route('mustdohr-search/v1', '/records', [
        'methods' => 'GET',
        'permission_callback' => 'mdh_chatbot_records_read_permission',
        'callback' => 'mdh_chatbot_export_records',
        'args' => [
            'limit' => [
                'required' => false,
                'type' => 'integer',
                'default' => 250,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
    register_rest_route('mustdohr-search/v1', '/records/delete', [
        'methods' => 'POST',
        'permission_callback' => 'mdh_chatbot_records_delete_permission',
        'callback' => 'mdh_chatbot_delete_records',
    ]);
    register_rest_route('mustdohr-search/v1', '/records/export', [
        'methods' => 'GET',
        'permission_callback' => 'mdh_chatbot_records_export_permission',
        'callback' => 'mdh_chatbot_export_records',
    ]);
    register_rest_route('mustdohr-search/v1', '/contact', [
        'methods' => 'POST',
        'permission_callback' => function (WP_REST_Request $request) { return mdh_chatbot_public_permission($request, 'contact'); },
        'callback' => function (WP_REST_Request $request) { return mdh_chatbot_submit_contact($request, true); },
    ]);
    register_rest_route('mustdohr-search/v1', '/contact-submissions', [
        'methods' => 'GET',
        'permission_callback' => 'mdh_chatbot_records_read_permission',
        'callback' => 'mdh_chatbot_export_contact_submissions',
        'args' => [
            'limit' => [
                'required' => false,
                'type' => 'integer',
                'default' => 250,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
    register_rest_route('mustdohr-search/v1', '/contact-submissions/delete', [
        'methods' => 'POST',
        'permission_callback' => 'mdh_chatbot_records_delete_permission',
        'callback' => 'mdh_chatbot_delete_contact_submissions',
    ]);
    register_rest_route('mustdohr-search/v1', '/contact-submissions/export', [
        'methods' => 'GET',
        'permission_callback' => 'mdh_chatbot_records_export_permission',
        'callback' => 'mdh_chatbot_export_contact_submissions',
    ]);
    register_rest_route('mustdohr-search/v1', '/config', [
        'methods' => 'GET',
        'permission_callback' => 'mdh_chatbot_records_config_permission',
        'callback' => function () { return rest_ensure_response(['config' => mdh_chatbot_get_config()]); },
    ]);
    register_rest_route('mustdohr-search/v1', '/config', [
        'methods' => 'POST',
        'permission_callback' => 'mdh_chatbot_records_config_permission',
        'callback' => function (WP_REST_Request $request) {
            $payload = $request->get_json_params();
            if (!is_array($payload)) $payload = $request->get_params();
            $config = mdh_chatbot_sanitize_config($payload);
            update_option('mdh_chatbot_config', $config, false);
            update_option('mdh_survey_enabled', $config['survey_enabled'], false);
            update_option('mdh_contact_form_enabled', $config['contact_form_enabled'], false);
            return rest_ensure_response(['ok' => true, 'config' => $config]);
        },
    ]);
});

function mdh_chatbot_records_key_hash($key) {
    return hash_hmac('sha256', (string) $key, wp_salt('auth'));
}

function mdh_chatbot_get_records_keys() {
    $keys = get_option('mdh_chatbot_records_key_hashes', []);
    return is_array($keys) ? array_filter($keys, 'is_string') : [];
}

function mdh_chatbot_get_connection_key_hash() {
    return (string) get_option('mdh_chatbot_records_connection_key_hash', '');
}

function mdh_chatbot_generate_connection_key() {
    $key = wp_generate_password(64, false, false);
    update_option('mdh_chatbot_records_connection_key_hash', mdh_chatbot_records_key_hash($key), false);
    // Invalidate old multi-key and legacy bearer credentials during migration.
    delete_option('mdh_chatbot_records_key_hashes');
    delete_option('mdh_chatbot_records_key');
    mdh_chatbot_security_audit_event('connection_code_rotated', ['source' => 'connection_key_generator'], true);
    return $key;
}

function mdh_chatbot_generate_records_keys() {
    $keys = [];
    $hashes = [];
    foreach (['read', 'export', 'delete', 'config'] as $scope) {
        $key = wp_generate_password(64, false, false);
        $keys[$scope] = $key;
        $hashes[$scope] = mdh_chatbot_records_key_hash($key);
    }
    update_option('mdh_chatbot_records_key_hashes', $hashes, false);
    // Invalidate the old all-powerful bearer key during the migration.
    delete_option('mdh_chatbot_records_key');
    return $keys;
}

function mdh_chatbot_records_rate_key() {
    // Hash the network address so the limiter never stores a raw address.
    $address = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
    return 'mdh_record_auth_' . hash_hmac('sha256', $address, wp_salt('auth'));
}

function mdh_chatbot_records_rate_limit($accepted = false) {
    $key = mdh_chatbot_records_rate_key();
    if ($accepted) {
        delete_transient($key);
        return true;
    }
    $attempts = (int) get_transient($key);
    if ($attempts >= 5) {
        return new WP_Error('mdh_records_rate_limited', 'Too many connection attempts. Try again in 10 minutes.', ['status' => 429]);
    }
    set_transient($key, $attempts + 1, 10 * MINUTE_IN_SECONDS);
    return true;
}

function mdh_chatbot_records_permission_for_scope(WP_REST_Request $request, $scope) {
    $provided_key = trim((string) $request->get_header('x-mustdohr-records-key'));
    $connection_hash = mdh_chatbot_get_connection_key_hash();
    if ($connection_hash !== '' && $provided_key !== '' && hash_equals($connection_hash, mdh_chatbot_records_key_hash($provided_key))) {
        mdh_chatbot_records_rate_limit(true);
        return true;
    }
    $hashes = mdh_chatbot_get_records_keys();
    $legacy_scope = $scope;
    if ($scope === 'records:read' || $scope === 'contacts:read') $legacy_scope = 'read';
    if ($scope === 'records:export' || $scope === 'contacts:export') $legacy_scope = 'export';
    if ($scope === 'config:read' || $scope === 'config:write') $legacy_scope = 'config';
    if ($scope === 'records:delete' || $scope === 'contacts:delete') $legacy_scope = 'delete';
    $expected_hash = (string) ($hashes[$legacy_scope] ?? '');
    if ($expected_hash !== '' && $provided_key !== '' && hash_equals($expected_hash, mdh_chatbot_records_key_hash($provided_key))) {
        mdh_chatbot_records_rate_limit(true);
        return true;
    }
    $limited = mdh_chatbot_records_rate_limit(false);
    if (is_wp_error($limited)) return $limited;
    mdh_chatbot_security_audit_event('connection_code_rejected', ['scope' => sanitize_key($scope)], false);
    return new WP_Error('mdh_records_forbidden', 'A valid private connection code is required.', ['status' => 403]);
}
function mdh_chatbot_records_read_permission(WP_REST_Request $request) {
    return mdh_chatbot_records_permission_for_scope($request, strpos($request->get_route(), 'contact-submissions') !== false ? 'contacts:read' : 'records:read');
}

function mdh_chatbot_records_export_permission(WP_REST_Request $request) {
    return mdh_chatbot_records_permission_for_scope($request, strpos($request->get_route(), 'contact-submissions') !== false ? 'contacts:export' : 'records:export');
}

function mdh_chatbot_records_delete_permission(WP_REST_Request $request) {
    return mdh_chatbot_records_permission_for_scope($request, strpos($request->get_route(), 'contact-submissions') !== false ? 'contacts:delete' : 'records:delete');
}

function mdh_chatbot_records_config_permission(WP_REST_Request $request) {
    return mdh_chatbot_records_permission_for_scope($request, $request->get_method() === 'POST' ? 'config:write' : 'config:read');
}

function mdh_chatbot_export_records(WP_REST_Request $request) {
    global $wpdb;
    $limit = min(500, max(1, absint($request->get_param('limit') ?: 250)));
    $table = mdh_chatbot_records_table();
    $records = $wpdb->get_results($wpdb->prepare(
        "SELECT id, mode, question, answer, page_url, language, status, source_website, session_id, sensitive_blocked, question_limit_reached, contact_submitted, contact_trigger, created_at FROM {$table} ORDER BY id DESC LIMIT %d",
        $limit
    ), ARRAY_A);

    $export = array_map(function ($record) {
        return [
            'id' => (int) $record['id'],
            'website' => $record['source_website'] ?: (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'Mustdohr'),
            'session_id' => $record['session_id'] ?: ('wordpress-' . (int) $record['id']),
            'visitor_message' => $record['question'],
            'bot_reply' => $record['answer'],
            'page_url' => $record['page_url'],
            'language' => $record['language'],
            'status' => $record['status'],
            'mode' => $record['mode'],
            'sensitive_blocked' => (bool) $record['sensitive_blocked'],
            'question_limit_reached' => (bool) $record['question_limit_reached'],
            'contact_submitted' => (bool) $record['contact_submitted'],
            'contact_trigger' => $record['contact_trigger'],
            'created_at' => mysql_to_rfc3339($record['created_at']),
        ];
    }, $records);

    return rest_ensure_response(['records' => $export]);
}

/**
 * Remove only records that the local archive has already written. The local
 * admin sends exact IDs, so records created while a sync is running are not
 * touched and will be picked up on the next connection.
 */
function mdh_chatbot_delete_records(WP_REST_Request $request) {
    return mdh_chatbot_delete_record_ids(mdh_chatbot_records_table(), $request, 'records');
}

function mdh_chatbot_delete_contact_submissions(WP_REST_Request $request) {
    return mdh_chatbot_delete_record_ids(mdh_chatbot_contact_submissions_table(), $request, 'contact submissions');
}

function mdh_chatbot_delete_record_ids($table, WP_REST_Request $request, $label) {
    global $wpdb;
    $payload = $request->get_json_params();
    if (!is_array($payload)) $payload = $request->get_params();
    $ids = isset($payload['ids']) && is_array($payload['ids']) ? $payload['ids'] : [];
    $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
    if (!$ids) {
        return new WP_Error('mdh_delete_ids_required', 'No record IDs were supplied.', ['status' => 400]);
    }
    if (count($ids) > 500) {
        return new WP_Error('mdh_delete_batch_too_large', 'Delete requests are limited to 500 records.', ['status' => 400]);
    }
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids));
    if ($deleted === false) {
        return new WP_Error('mdh_delete_failed', 'The local archive was saved, but WordPress could not clear these ' . $label . '.', ['status' => 500]);
    }
    do_action('mdh_chatbot_records_deleted', $label, $ids, get_current_user_id());
    mdh_chatbot_maybe_optimize_record_tables();
    return rest_ensure_response(['ok' => true, 'deleted' => (int) $deleted, 'ids' => $ids]);
}

function mdh_chatbot_submit_contact(WP_REST_Request $request, $skip_public_guard = false) {
    if (!$skip_public_guard) {
        $public_guard = mdh_chatbot_public_guard($request, 'contact');
        if (is_wp_error($public_guard)) return $public_guard;
    }
    if (strlen((string) $request->get_body()) > MDH_PUBLIC_CONTACT_BODY_MAX_BYTES) {
        return new WP_Error('contact_payload_too_large', 'Please shorten the enquiry before sending it.', ['status' => 413]);
    }
    $captcha = mdh_chatbot_verify_turnstile($request, 'contact');
    if (is_wp_error($captcha)) return $captcha;
    $payload = $request->get_json_params();
    if (!is_array($payload)) $payload = $request->get_params();

    $session_id = mdh_chatbot_session_id($request);

    $name = mdh_chatbot_bounded_bytes($payload['name'] ?? '', 400);
    $company = mdh_chatbot_bounded_bytes($payload['company'] ?? '', 600);
    $email = sanitize_email(mdh_chatbot_bounded_bytes($payload['email'] ?? '', 254));
    $country = mdh_chatbot_bounded_bytes($payload['country'] ?? '', 400);
    $request_type = mdh_chatbot_bounded_bytes($payload['request_type'] ?? 'General enquiry', 480);
    $message = mdh_chatbot_bounded_bytes($payload['message'] ?? '', 10000, true);
    $page_url = mdh_chatbot_safe_page_url($request);
    $allowed_triggers = ['manual', 'quick_access', 'contact', 'unanswered', 'limit', 'sensitive', 'knowledge_gap', 'configured_keyword', 'screening_contact', 'website_contact_form'];
    $requested_trigger = sanitize_key((string) ($payload['trigger_reason'] ?? 'manual'));
    $trigger_reason = in_array($requested_trigger, $allowed_triggers, true) ? $requested_trigger : 'manual';
    $config = mdh_chatbot_get_config();
    // Website attribution and transcript are derived server-side; browser fields are ignored.
    $source_website = mdh_chatbot_bounded_text($config['source_website'] ?: (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'Mustdohr'), 120);
    $chat_question = '';
    $chat_transcript = '';

    if ($name === '' || !is_email($email) || $message === '') {
        return new WP_Error('invalid_contact', 'Please enter your name, a valid email address and a short message.', ['status' => 400]);
    }

    // Make contact writes idempotent for a short window. This protects against
    // browser retries, proxy retries, and legacy fallback endpoints submitting
    // the same form more than once after the first write already succeeded.
    $dedupe_bucket = (string) floor(time() / (10 * MINUTE_IN_SECONDS));
    $dedupe_fingerprint = hash_hmac('sha256', implode('|', [
        $session_id,
        strtolower($email),
        $name,
        $company,
        $country,
        $request_type,
        $message,
        $dedupe_bucket,
    ]), wp_salt('auth'));
    $dedupe_key = 'mdh_contact_dedupe_' . $dedupe_fingerprint;
    $existing_submission = get_transient($dedupe_key);
    if (is_array($existing_submission) && !empty($existing_submission['id'])) {
        $notification_sent = !empty($existing_submission['notification_sent']);
        return rest_ensure_response([
            'ok' => true,
            'duplicate' => true,
            'notification_sent' => $notification_sent,
            'message' => $notification_sent
                ? 'Your enquiry was already received. Our team will be in touch.'
                : 'Your enquiry was already saved, but the email notification could not be sent. Please contact us directly if needed.',
        ]);
    }

    $email_rate = mdh_chatbot_atomic_rate_increment(
        'contact_email',
        strtolower($email),
        MDH_PUBLIC_CONTACT_EMAIL_LIMIT,
        MDH_PUBLIC_CONTACT_EMAIL_WINDOW
    );
    if (is_wp_error($email_rate)) {
        if ($email_rate->get_error_code() !== 'public_rate_limited') return $email_rate;
        return new WP_Error('contact_rate_limited', 'Please wait before submitting another request.', ['status' => 429]);
    }

    global $wpdb;
    $chat_record_id = 0;
    $chat_record_count = 0;
    if ($session_id !== '') {
        $linked_chats = $wpdb->get_results($wpdb->prepare(
            "SELECT id, question, answer, created_at FROM " . mdh_chatbot_records_table() . " WHERE session_id = %s ORDER BY id DESC LIMIT 20",
            $session_id
        ));
        $linked_chats = array_reverse($linked_chats ?: []);
        $chat_record_count = count($linked_chats);
        if ($linked_chats) {
            $latest_chat = end($linked_chats);
            $chat_record_id = (int) $latest_chat->id;
            if ($chat_question === '') $chat_question = (string) $latest_chat->question;
            $chat_lines = [];
            foreach ($linked_chats as $chat) {
                $chat_lines[] = 'USER: ' . trim((string) $chat->question);
                $chat_lines[] = 'AI: ' . trim((string) $chat->answer);
            }
            $chat_transcript = mdh_chatbot_bounded_bytes(implode("\n", $chat_lines), 32000, true);
        }
    }
    $capacity = mdh_chatbot_storage_capacity(mdh_chatbot_contact_submissions_table(), MDH_CONTACT_MAX_ROWS, 'contact');
    if (is_wp_error($capacity)) return $capacity;
    $saved = $wpdb->insert(mdh_chatbot_contact_submissions_table(), [
        'name' => $name,
        'company' => $company,
        'email' => $email,
        'country' => $country,
        'request_type' => $request_type,
        'message' => $message,
        'page_url' => $page_url,
        'session_id' => $session_id,
        'trigger_reason' => $trigger_reason,
        'source_website' => $source_website,
        'chat_record_id' => $chat_record_id,
        'chat_question' => $chat_question,
        'chat_transcript' => $chat_transcript,
        'created_at' => current_time('mysql', true),
    ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
    if ($saved === false) {
        mdh_chatbot_record_storage_error('contact', mdh_chatbot_contact_submissions_table(), $wpdb->last_error);
        return new WP_Error('contact_storage_failed', 'Your enquiry could not be saved. Please try again.', ['status' => 500]);
    }
    delete_option('mdh_chatbot_last_storage_error');
    set_transient($dedupe_key, [
        'id' => (int) $wpdb->insert_id,
        'notification_sent' => false,
    ], 10 * MINUTE_IN_SECONDS);
    if ($session_id !== '') {
        $wpdb->update(mdh_chatbot_records_table(), ['contact_submitted' => 1], ['session_id' => $session_id], ['%d'], ['%s']);
    }
    $notification = mdh_chatbot_notify_contact(compact('name', 'company', 'email', 'country', 'request_type', 'message', 'page_url', 'session_id', 'trigger_reason', 'source_website', 'chat_record_id', 'chat_question', 'chat_transcript', 'chat_record_count'));

    if (empty($notification['sent'])) {
        return new WP_Error('contact_notification_failed', 'Your enquiry was saved, but the email notification could not be sent. Please try again or contact us directly.', ['status' => 502, 'notification_sent' => false]);
    }
    set_transient($dedupe_key, [
        'id' => (int) $wpdb->insert_id,
        'notification_sent' => true,
    ], 10 * MINUTE_IN_SECONDS);
    return rest_ensure_response(['ok' => true, 'notification_sent' => true, 'message' => 'Thank you. Our team will be in touch.']);
}

/**
 * Contact fallback for hosts where the WordPress REST rewrite route returns an HTML 404.
 * The admin-ajax endpoint is available even when pretty permalinks are disabled.
 */
function mdh_chatbot_ajax_submit_contact() {
    $content_length = absint($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($content_length > MDH_PUBLIC_CONTACT_BODY_MAX_BYTES) {
        wp_send_json_error(['message' => 'Please shorten the enquiry before sending it.'], 413);
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, MDH_PUBLIC_CONTACT_BODY_MAX_BYTES + 1);
    if (strlen($raw) > MDH_PUBLIC_CONTACT_BODY_MAX_BYTES) {
        wp_send_json_error(['message' => 'Please shorten the enquiry before sending it.'], 413);
    }
    $request = new WP_REST_Request('POST', '/mustdohr-search/v1/contact');
    $payload = $_POST;
    if (!is_array($payload) || !$payload) {
        $decoded = json_decode((string) $raw, true);
        $payload = is_array($decoded) ? $decoded : [];
    }
    $request->set_body($raw);
    $request->set_body_params(is_array($payload) ? $payload : []);
    $request->set_header('referer', sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'] ?? '')));
    $request->set_header('user-agent', sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')));
    $request->set_header('origin', sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN'] ?? '')));
    $request->set_header('x-mdh-nonce', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_MDH_NONCE'] ?? ($payload['_mdh_nonce'] ?? ''))));
    $response = mdh_chatbot_submit_contact($request);
    if (is_wp_error($response)) {
        $data = $response->get_error_data();
        $status = is_array($data) && !empty($data['status']) ? (int) $data['status'] : 400;
        wp_send_json_error(['message' => $response->get_error_message()], $status);
    }
    if ($response instanceof WP_REST_Response) {
        wp_send_json($response->get_data(), $response->get_status());
    }
    wp_send_json_success(['message' => 'Thank you. Our team will be in touch.']);
}
add_action('wp_ajax_mdh_chatbot_submit_contact', 'mdh_chatbot_ajax_submit_contact');
add_action('wp_ajax_nopriv_mdh_chatbot_submit_contact', 'mdh_chatbot_ajax_submit_contact');
add_action('admin_post_mdh_chatbot_submit_contact', 'mdh_chatbot_ajax_submit_contact');
add_action('admin_post_nopriv_mdh_chatbot_submit_contact', 'mdh_chatbot_ajax_submit_contact');

/**
 * Last-resort public POST bridge for hosts that block both REST rewrites and
 * WordPress admin endpoints. It reuses the same validation, rate limiting,
 * storage and email path as the REST endpoint and always returns JSON.
 */
function mdh_chatbot_direct_submit_contact() {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') return;
    if (!isset($_GET['mdh_chatbot_contact']) || (string) $_GET['mdh_chatbot_contact'] !== '1') return;
    mdh_chatbot_ajax_submit_contact();
}
add_action('template_redirect', 'mdh_chatbot_direct_submit_contact', 0);

function mdh_chatbot_export_contact_submissions(WP_REST_Request $request) {
    global $wpdb;
    $limit = min(500, max(1, absint($request->get_param('limit') ?: 250)));
    $table = mdh_chatbot_contact_submissions_table();
    $records_table = mdh_chatbot_records_table();
    $records = $wpdb->get_results($wpdb->prepare(
        "SELECT c.id, c.name, c.company, c.email, c.country, c.request_type, c.message, c.page_url, c.session_id, c.trigger_reason, c.source_website, c.chat_record_id, c.chat_question, c.chat_transcript, (SELECT COUNT(*) FROM {$records_table} r WHERE r.session_id = c.session_id) AS chat_record_count, c.created_at FROM {$table} c ORDER BY c.id DESC LIMIT %d",
        $limit
    ), ARRAY_A);
    foreach ($records as &$record) {
        $record['id'] = (int) $record['id'];
        $record['chat_record_id'] = (int) $record['chat_record_id'];
        $record['chat_record_count'] = (int) $record['chat_record_count'];
        $record['source_website'] = $record['source_website'] ?: (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'Mustdohr');
        $record['created_at'] = mysql_to_rfc3339($record['created_at']);
    }
    return rest_ensure_response(['submissions' => $records]);
}

function mdh_chatbot_contact_guidance() {
    $config = mdh_chatbot_get_config();
    if ($config['contact_mode'] === 'link' && $config['contact_url'] !== '') {
        return 'Please use our contact form: ' . $config['contact_url'];
    }
    return 'Please use the contact button below and our team will be in touch.';
}

function mdh_chatbot_should_show_contact($reason) {
    return in_array(sanitize_key($reason), mdh_chatbot_get_config()['show_contact_for'], true);
}

function mdh_chatbot_trigger_match($message) {
    $config = mdh_chatbot_get_config();
    $keywords = preg_split('/[\r\n,]+/', (string) $config['contact_trigger_keywords']);
    $haystack = mdh_chatbot_normalize_policy_text($message);
    foreach ($keywords as $keyword) {
        $keyword = mdh_chatbot_normalize_policy_text($keyword);
        if ($keyword !== '' && mdh_chatbot_policy_phrase_matches($haystack, $keyword)) return $keyword;
    }
    return '';
}

function mdh_chatbot_normalize_policy_text($text) {
    $text = remove_accents(wp_strip_all_tags((string) $text));
    if (function_exists('normalizer_normalize')) $text = normalizer_normalize($text, Normalizer::FORM_KC);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function mdh_chatbot_policy_phrase_matches($haystack, $needle) {
    return strpos(' ' . trim($haystack) . ' ', ' ' . trim($needle) . ' ') !== false;
}
function mdh_chatbot_sensitive_match($message) {
    $config = mdh_chatbot_get_config();
    $legacy = (string) get_option('mdh_blocked_keywords', '');
    $builtins = "cheaper than\nmore affordable than\nminimum price\nlowest price\nprofit margin\nprivate data\npassword\nexploit\nhack";
    $keywords = preg_split('/[\r\n,]+/', $builtins . "\n" . $config['sensitive_keywords'] . "\n" . $legacy);
    $haystack = mdh_chatbot_normalize_policy_text($message);
    foreach ($keywords as $keyword) {
        $keyword = mdh_chatbot_normalize_policy_text($keyword);
        if ($keyword !== '' && mdh_chatbot_policy_phrase_matches($haystack, $keyword)) return $keyword;
    }
    return '';
}
function mdh_chatbot_check_question_limit(WP_REST_Request $request) {
    if (MDH_CHATBOT_DISABLE_QUESTION_LIMIT) return true;
    $config = mdh_chatbot_get_config();
    $limit = max(1, (int) $config['question_limit']);
    // Never trust a browser-supplied visitor_id for quota accounting.
    $visitor = mdh_chatbot_session_id($request);
    $counted = mdh_chatbot_atomic_rate_increment('ai_daily_question', $visitor, $limit, DAY_IN_SECONDS);
    if (is_wp_error($counted)) {
        if ($counted->get_error_code() !== 'public_rate_limited') return $counted;
        return new WP_Error('question_limit_reached', $config['limit_reply'] . ' ' . mdh_chatbot_contact_guidance(), [
            'status' => 429,
            'trigger_reason' => 'limit',
            'show_contact' => mdh_chatbot_should_show_contact('limit'),
        ]);
    }
    return true;
}

function mdh_chatbot_notify_contact($submission) {
    $config = mdh_chatbot_get_config();
    $configured = preg_split('/[\s,;]+/', (string) ($config['notification_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $recipients = array_values(array_unique(array_filter(array_map('sanitize_email', $configured))));
    if (!$recipients) {
        error_log('[Mustdohr] Contact notification skipped: no valid recipient configured.');
        return ['sent' => false, 'reason' => 'no_recipient'];
    }
    $subject = '[Mustdohr] New contact enquiry with chat transcript';
    $escape = static function ($value) {
        return esc_html((string) ($value ?? ''));
    };
    $fields = [
        'name' => 'Name',
        'company' => 'Company',
        'email' => 'Email',
        'country' => 'Country / region',
        'request_type' => 'Request type',
        'message' => 'Message',
        'source_website' => 'Source website',
        'page_url' => 'Page',
        'trigger_reason' => 'Triggered by',
        'session_id' => 'Session ID',
        'chat_record_id' => 'Linked chat record ID',
        'chat_record_count' => 'Linked chat records',
    ];
    $body = '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f7fb;color:#172033;font-family:Arial,Helvetica,sans-serif;line-height:1.5;">';
    $body .= '<div style="max-width:760px;margin:0 auto;background:#ffffff;border:1px solid #dbe3ef;border-radius:12px;padding:24px;">';
    $body .= '<h2 style="margin:0 0 6px;font-size:20px;">New website contact enquiry</h2>';
    $body .= '<p style="margin:0 0 20px;color:#5b667a;">A visitor submitted the website contact form.</p>';
    $body .= '<h3 style="margin:0 0 8px;font-size:15px;text-transform:uppercase;letter-spacing:.08em;color:#315f9f;">Contact form</h3>';
    $body .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;border:1px solid #dbe3ef;">';
    foreach ($fields as $key => $label) {
        $value = $escape($submission[$key] ?? '');
        $value = nl2br($value);
        $body .= '<tr><th align="left" valign="top" width="190" style="padding:10px 12px;border-bottom:1px solid #e8edf4;background:#f7f9fc;color:#42516a;font-size:13px;font-weight:700;">' . $escape($label) . '</th><td valign="top" style="padding:10px 12px;border-bottom:1px solid #e8edf4;color:#172033;font-size:14px;word-break:break-word;">' . ($value !== '' ? $value : '&mdash;') . '</td></tr>';
    }
    $body .= '</table>';
    $transcript = trim((string) ($submission['chat_transcript'] ?? ''));
    $body .= '<h3 style="margin:24px 0 8px;font-size:15px;text-transform:uppercase;letter-spacing:.08em;color:#315f9f;">Chat transcript</h3>';
    $body .= $transcript !== ''
        ? '<pre style="margin:0;padding:14px;background:#f7f9fc;border:1px solid #dbe3ef;border-radius:8px;white-space:pre-wrap;font:14px/1.55 Arial,Helvetica,sans-serif;color:#172033;">' . $escape($transcript) . '</pre>'
        : '<p style="margin:0;color:#5b667a;">No linked chat transcript.</p>';
    $body .= '</div></body></html>';
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    if (!empty($submission['email']) && is_email($submission['email'])) {
        $headers[] = 'Reply-To: ' . sanitize_email($submission['email']);
    }
    $sent = wp_mail($recipients, $subject, $body, $headers);
    if (!$sent) {
        error_log('[Mustdohr] Contact notification failed for ' . count($recipients) . ' recipient(s). Check the configured WordPress mailer.');
        return ['sent' => false, 'reason' => 'wp_mail_failed'];
    }
    return ['sent' => true, 'recipients' => count($recipients)];
}

function mdh_search_tokens($query) {
    $query = strtolower(remove_accents(wp_strip_all_tags($query)));
    $parts = preg_split('/[^a-z0-9]+/', $query);
    $stop = [
        'a','about','an','and','any','are','as','at','be','been','being','but','by',
        'can','could','did','do','does','find','for','from','get','give','has','have',
        'help','how','i','if','in','information','into','is','it','its','looking','may',
        'me','mentions','might','my','of','on','or','please','search','show','tell','than',
        'that','the','their','them','then','there','these','they','this','those','to','us',
        'was','we','were','what','when','where','which','who','why','will','with','would',
        'you','your'
    ];
    return array_values(array_unique(array_filter($parts, function ($part) use ($stop) {
        return strlen($part) > 1 && !in_array($part, $stop, true);
    })));
}

function mdh_search_curated_knowledge() {
    $home = home_url('/');
    $items = [
        [
            'title' => 'Mustdohr virtual HR for small teams',
            'text' => 'Mustdohr gives small companies practical HR support without requiring the owner to understand complex HR regulations. Owners provide basic employee information and track progress while Mustdohr manages the appropriate onboarding process.',
            'url' => $home,
        ],
        [
            'title' => 'How onboarding works',
            'text' => 'Send the employee name and basic information. Mustdohr runs the right contracts, tax forms and statutory training playbook for the location and role. Employers can then follow progress in one clear view.',
            'url' => $home . '#how',
        ],
        [
            'title' => 'Hiring and onboarding in the USA',
            'text' => 'For a company hiring a person in the USA, Mustdohr can organize the new hire process: collect basic information, prepare the appropriate employment agreement and tax forms, assign required training, and track onboarding progress. Share the role and US location with Mustdohr for a tailored process. This is general information, not legal or tax advice.',
            'url' => $home . '#how',
        ],
        [
            'title' => 'Employer view',
            'text' => 'Employers can see company-level onboarding progress, compliance, employee information, documents, leave, payroll statements and the actions requiring their attention.',
            'url' => $home . '#roles',
        ],
        [
            'title' => 'Employee view',
            'text' => 'Employees get a private workspace for onboarding, contracts, leave, payslips and benefits information.',
            'url' => $home . '#roles',
        ],
        [
            'title' => 'About Mustdohr',
            'text' => 'Mustdohr makes everyday HR feel clear, calm and human. It combines practical tools, thoughtful guidance and dependable support for growing companies.',
            'url' => $home . '#about',
        ],
        [
            'title' => 'About NNRoad',
            'text' => 'NNRoad provides global employer of record, global payroll and workforce support services for companies hiring and managing people across countries.',
            'url' => 'https://nnroad.com/',
        ],
        [
            'title' => 'Test documents reference library',
            'text' => 'The Test Documents are fictional sample HR records for testing search, preview, categorisation, onboarding, payroll, policy, benefits, compliance, contracts, training and document workflows. They contain no real employee data.',
            'url' => $home . '#test-documents',
        ],
    ];

    $front_page = trailingslashit(get_stylesheet_directory()) . 'front-page.php';
    if (is_readable($front_page)) {
        $source = file_get_contents($front_page);
        if (preg_match('/\\$test_document_stories\\s*=\\s*\\[(.*?)\\];/s', $source, $block)) {
            preg_match_all("/'([^']+)'/", $block[1], $stories);
            foreach ($stories[1] as $index => $story) {
                $items[] = [
                    'title' => 'Sample people operations record ' . ($index + 1),
                    'text' => html_entity_decode($story, ENT_QUOTES, 'UTF-8'),
                    'url' => $home . '#test-documents',
                ];
            }
        }
    }
    $config = mdh_chatbot_get_config();
    $excluded = array_filter(array_map('trim', preg_split('/[\r
]+/', (string) $config['excluded_urls'])));
    $urls = array_filter(array_map('trim', preg_split('/[\r
]+/', (string) $config['knowledge_urls'])));
    $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    foreach (array_slice($urls, 0, 20) as $url) {
        if (!wp_http_validate_url($url) || wp_parse_url($url, PHP_URL_HOST) !== $site_host || in_array($url, $excluded, true)) continue;
        $post_id = url_to_postid($url);
        if (!$post_id || get_post_status($post_id) !== 'publish') continue;
        $post = get_post($post_id);
        if (!$post) continue;
        $items[] = [
            'title' => get_the_title($post),
            'text' => wp_trim_words(wp_strip_all_tags($post->post_content), 280),
            'url' => get_permalink($post),
        ];
    }
    return $items;
}

function mdh_search_words($text) {
    return array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower(remove_accents($text)))));
}

function mdh_search_word_similarity($query_word, $candidate_word) {
    if ($query_word === $candidate_word) return 100;
    similar_text($query_word, $candidate_word, $similarity);
    return $similarity;
}

function mdh_search_matching_words($text, $token) {
    $matches = 0;
    foreach (mdh_search_words($text) as $word) {
        if (mdh_search_word_similarity($token, $word) >= 80) {
            $matches++;
        }
    }
    return $matches;
}

function mdh_search_score($item, $tokens) {
    $score = 0;
    foreach ($tokens as $token) {
        $score += mdh_search_matching_words($item['title'], $token) * 5;
        $score += min(3, mdh_search_matching_words($item['text'], $token));
    }
    return $score;
}

/**
 * Storage integration point. By default this is a no-op, so the plugin does
 * not create a database table. A host plugin can persist events by attaching
 * to mdh_chatbot_event or by returning a callable from the storage filter.
 */
function mdh_chatbot_store_event($event) {
    $event = apply_filters('mdh_chatbot_storage_event', $event);
    $handler = apply_filters('mdh_chatbot_storage_handler', null, $event);
    if (is_callable($handler)) {
        call_user_func($handler, $event);
    }
    do_action('mdh_chatbot_event', $event);
}

function mdh_chatbot_get_language(WP_REST_Request $request) {
    $requested = strtolower((string) $request->get_param('lang'));
    $supported = apply_filters('mdh_chatbot_supported_languages', ['en']);
    if (!in_array($requested, $supported, true)) {
        $requested = 'en';
    }
    return apply_filters('mdh_chatbot_language', $requested, $request);
}

function mdh_search_answer(WP_REST_Request $request) {
    $message = trim((string) $request->get_param('message'));
    $language = mdh_chatbot_get_language($request);
    $config = mdh_chatbot_get_config();
    if (!$config['enabled']) {
        return new WP_Error('assistant_disabled', 'The website assistant is currently unavailable.', ['status' => 503]);
    }
    if ($message === '' || strlen($message) > 300) {
        return new WP_Error('invalid_question', 'Please enter a shorter question.', ['status' => 400]);
    }
    if (mdh_chatbot_sensitive_match($message) !== '') {
        return rest_ensure_response(['answer' => $config['sensitive_reply'] . ' ' . mdh_chatbot_contact_guidance(), 'results' => [], 'screening' => 'block']);
    }

    $tokens = mdh_search_tokens($message);
    if (!$tokens) {
        return rest_ensure_response([
            'answer' => 'Please include a topic such as onboarding, employer, employee, payroll, benefits, compliance or test documents.',
            'results' => [],
        ]);
    }

    $ranked = [];
    foreach (mdh_search_curated_knowledge() as $item) {
        $score = mdh_search_score($item, $tokens);
        if ($score > 0) {
            $item['score'] = $score;
            $ranked[] = $item;
        }
    }

    $query = new WP_Query([
        's' => $message,
        'post_type' => ['page', 'post'],
        'post_status' => 'publish',
        'posts_per_page' => 5,
        'no_found_rows' => true,
    ]);
    foreach ($query->posts as $post) {
        $item = [
            'title' => get_the_title($post),
            'text' => wp_trim_words(wp_strip_all_tags($post->post_content), 45),
            'url' => get_permalink($post),
        ];
        $item['score'] = mdh_search_score($item, $tokens);
        if ($item['score'] > 0) {
            $ranked[] = $item;
        }
    }

    usort($ranked, function ($a, $b) { return $b['score'] <=> $a['score']; });
    $top_score = isset($ranked[0]['score']) ? (int) $ranked[0]['score'] : 0;
    // Show the warning only when none of the meaningful query words appears in
    // the public knowledge. A single valid keyword is enough to keep normal
    // free-form questions out of the low-relevance state.
    $low_relevance = $top_score === 0;
    $seen = [];
    $results = [];
    foreach ($ranked as $item) {
        $key = $item['title'] . '|' . $item['url'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $results[] = [
            'title' => $item['title'],
            'snippet' => wp_trim_words($item['text'], 42),
            'url' => esc_url_raw($item['url']),
        ];
        if (count($results) >= 4) break;
    }

    if (!$results) {
        return rest_ensure_response([
            'answer' => 'Less relevant information: I could not find a full-word or close spelling match.',
            'low_relevance' => true,
            'results' => [],
        ]);
    }

    return rest_ensure_response([
        'answer' => $low_relevance
            ? 'Less relevant information: these are the closest public website matches, but they may not directly answer your question.'
            : 'I found matching information on the public website.',
        'low_relevance' => $low_relevance,
        'results' => $results,
    ]);
}

function mdh_find_blocked_keyword($message) {
    $keywords = preg_split('/\r
|\r|
/', (string) get_option('mdh_blocked_keywords', ''));
    $message = strtolower(remove_accents($message));
    foreach ($keywords as $keyword) {
        $keyword = strtolower(trim(remove_accents($keyword)));
        if ($keyword !== '' && strpos($message, $keyword) !== false) {
            return $keyword;
        }
    }
    return '';
}

function mdh_record_ai_diagnostic($layer, $model, $status, $message) {
    update_option('mdh_last_ai_error', [
        'time' => current_time('mysql'),
        'layer' => $layer,
        'model' => $model,
        'status' => $status,
        'message' => sanitize_text_field($message),
    ], false);
}

function mdh_gemini_generate($api_key, $model, $prompt, $max_tokens) {
    $openrouter = mdh_is_openrouter_key($api_key);
    if ($openrouter) {
        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . trim($api_key),
                'HTTP-Referer' => home_url('/'),
                'X-Title' => get_bloginfo('name'),
            ],
            'body' => wp_json_encode([
                'model' => mdh_openrouter_model($model),
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0,
                'max_tokens' => $max_tokens,
            ]),
        ]);
    } else {
        $response = wp_remote_post('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent', [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $api_key,
            ],
            'body' => wp_json_encode([
                'contents' => [[
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'temperature' => 0,
                    'maxOutputTokens' => $max_tokens,
                ],
            ]),
        ]);
    }

    if (is_wp_error($response)) {
        return ['code' => 0, 'body' => [], 'error' => $response->get_error_message()];
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    // Normalize OpenRouter's chat-completions shape so screening and answer
    // code can continue to use the existing provider-neutral response path.
    if ($openrouter && isset($body['choices'][0]['message']['content'])) {
        $body['candidates'] = [[
            'content' => ['parts' => [['text' => (string) $body['choices'][0]['message']['content']]]],
        ]];
    }
    return [
        'code' => (int) wp_remote_retrieve_response_code($response),
        'body' => is_array($body) ? $body : [],
        'error' => '',
    ];
}

function mdh_extract_json_object($text) {
    $text = trim((string) $text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end < $start) return [];
    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($decoded) ? $decoded : [];
}

function mdh_chatbot_screening_fallback($message) {
    $text = mdh_chatbot_normalize_policy_text($message);
    $sensitive = '/\\b(competitor|lowest price|profit|cost structure|customer complaint|internal information|confidential|private data|personal data|password|secret|api key|security exploit|hack|bypass|evade|illegal)\\b/i';
    if ($text === '' || preg_match($sensitive, $text)) {
        return ['decision' => 'contact', 'category' => 'screening_fallback_sensitive'];
    }
    return ['decision' => 'allow', 'category' => 'screening_fallback'];
}

function mdh_screen_question($message, $api_key, $model) {
    $matched_keyword = mdh_find_blocked_keyword($message);
    if ($matched_keyword !== '') {
        return ['decision' => 'block', 'category' => 'custom_keyword'];
    }

    $prompt = 'You are the first-layer compliance classifier for this public website assistant. Classify the visitor question before it can be sent to an answer model. Block questions asking for competitor attacks or comparisons, confidential/internal information, employee/client/partner personal data, minimum pricing, cost structure, profit, security exploitation, illegal activity, or harmful instructions. Use contact for sales, pricing, partnership, complaint, legal, or account-specific requests. Allow ordinary questions about the website\'s public services, onboarding, policies, locations, payroll, benefits, employment support, and practical how-to questions about hiring or onboarding a person in a stated country. A normal hiring question is not automatically legal advice or a sensitive request; allow it unless it asks for evasion, confidential data, harmful conduct, or a specific legal conclusion. Return only valid JSON with exactly these keys: decision (allow, block, or contact) and category.

QUESTION: ' . $message;
    $result = mdh_gemini_generate($api_key, $model, $prompt, 80);
    if ($result['code'] < 200 || $result['code'] >= 300) {
        $provider_message = $result['body']['error']['message'] ?? $result['error'] ?? 'AI provider screening failed.';
        mdh_record_ai_diagnostic('screening', $model, $result['code'] ?: 'network', $provider_message);
        return mdh_chatbot_screening_fallback($message);
    }

    $raw = $result['body']['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $classification = mdh_extract_json_object($raw);
    $decision = strtolower((string) ($classification['decision'] ?? ''));
    if (!in_array($decision, ['allow', 'block', 'contact'], true)) {
        mdh_record_ai_diagnostic('screening', $model, $result['code'], 'AI provider returned an invalid screening decision.');
        return mdh_chatbot_screening_fallback($message);
    }
    return ['decision' => $decision, 'category' => sanitize_key($classification['category'] ?? 'general')];
}

function mdh_chatbot_general_hiring_intent($message) {
    $text = mdh_chatbot_normalize_policy_text($message);
    if ($text === '' || !preg_match('/\b(hire|hiring|employ|employment|onboard|onboarding|new hire)\b/', $text)) return false;
    if (preg_match('/\b(avoid|evade|bypass|illegal|under the table|fake|exploit|hack)\b/', $text)) return false;
    return (bool) preg_match('/\b(how|what|where|steps|process|person|people|employee|team|usa|us|united states|country|location)\b/', $text);
}

function mdh_ai_answer(WP_REST_Request $request) {
    $message = trim((string) $request->get_param('message'));
    $language = mdh_chatbot_get_language($request);
    $config = mdh_chatbot_get_config();
    if (!$config['enabled']) {
        return new WP_Error('assistant_disabled', 'The website assistant is currently unavailable.', ['status' => 503]);
    }
    if ($message === '' || strlen($message) > 300) {
        return new WP_Error('invalid_question', 'Please enter a shorter question.', ['status' => 400]);
    }
    // Turnstile is required once when an AI conversation starts. A successful
    // verification is remembered server-side for the visitor session, so later
    // messages in the same session do not prompt again.
    $captcha = mdh_chatbot_require_ai_captcha($request);
    if (is_wp_error($captcha)) return $captcha;
    if (mdh_chatbot_sensitive_match($message) !== '') {
        return rest_ensure_response([
            'answer' => $config['sensitive_reply'] . ' ' . mdh_chatbot_contact_guidance(),
            'configured' => true,
            'screening' => 'block',
            'trigger_reason' => 'sensitive',
            'show_contact' => mdh_chatbot_should_show_contact('sensitive'),
            'results' => [],
        ]);
    }
    $limit = mdh_chatbot_check_question_limit($request);
    if (is_wp_error($limit)) return $limit;
    $contact_keyword = mdh_chatbot_trigger_match($message);
    if ($contact_keyword !== '') {
        return rest_ensure_response([
            'answer' => $config['contact_trigger_reply'] . ' ' . mdh_chatbot_contact_guidance(),
            'configured' => true,
            'screening' => 'contact',
            'trigger_reason' => 'configured_keyword',
            'matched_keyword' => $contact_keyword,
            'show_contact' => mdh_chatbot_should_show_contact('contact'),
            'results' => [],
        ]);
    }

// Public NNRoad facts are allowed only after the same sensitive and limit
    // checks used by every other AI request.
    if (preg_match('/\b(?:who|what|tell me about)\b.{0,40}\bnnroad\b/i', $message)) {
        return rest_ensure_response([
            'answer' => 'NNRoad provides global employer of record, global payroll and workforce support services for companies hiring and managing people across countries. Source: https://nnroad.com/',
            'configured' => true,
            'screening' => 'allow',
            'trigger_reason' => 'public_index',
            'results' => [[
                'title' => 'About NNRoad',
                'snippet' => 'NNRoad provides global employer of record, global payroll and workforce support services for companies hiring and managing people across countries.',
                'url' => 'https://nnroad.com/',
            ]],
        ]);
    }

    mdh_chatbot_store_event([
        'type' => 'question_received',
        'mode' => 'ai',
        'message' => $message,
        'language' => $language,
        'site' => home_url('/'),
        'created_at' => current_time('mysql', true),
    ]);

    $credentials = mdh_chatbot_gemini_credentials();
    $screening_api_key = $credentials['guard'];
    $answer_api_key = $credentials['answer'];
    if ($screening_api_key === '' || $answer_api_key === '') {
        return rest_ensure_response([
            'answer' => 'AI mode is not configured yet. An administrator must set the server environment variables for Gemini in the assistant settings.',
            'configured' => false,
            'results' => [],
        ]);
    }

    $selected_model = mdh_sanitize_gemini_model(get_option('mdh_gemini_model', 'gemini-3.5-flash-lite'));
    $screening = mdh_screen_question($message, $screening_api_key, $selected_model);
    if ($screening['decision'] === 'error') {
        return new WP_Error('ai_screening_unavailable', 'The compliance check is temporarily unavailable. Please try again later.', ['status' => 503]);
    }
    if ($screening['decision'] === 'contact' && mdh_chatbot_general_hiring_intent($message)) {
        $screening['decision'] = 'allow';
        $screening['category'] = 'general_hiring';
    }
    if ($screening['decision'] === 'block' || $screening['decision'] === 'contact') {
        $blocked_reply = $screening['decision'] === 'contact'
            ? $config['contact_trigger_reply']
            : trim((string) get_option('mdh_blocked_reply', $config['sensitive_reply']));
        return rest_ensure_response([
            'answer' => $blocked_reply !== '' ? $blocked_reply : 'Please use the website contact form for help with this request.',
            'configured' => true,
            'screening' => $screening['decision'],
            'trigger_reason' => $screening['decision'] === 'contact' ? ($screening['category'] ?: 'screening_contact') : 'sensitive',
            'show_contact' => mdh_chatbot_should_show_contact($screening['decision'] === 'contact' ? 'contact' : 'sensitive'),
            'results' => [],
        ]);
    }

    $tokens = mdh_search_tokens($message);
    $knowledge = mdh_search_curated_knowledge();
    $ranked = [];
    foreach ($knowledge as $item) {
        $item['score'] = mdh_search_score($item, $tokens);
        $ranked[] = $item;
    }
    usort($ranked, function ($a, $b) { return $b['score'] <=> $a['score']; });
    $sources = array_slice($ranked, 0, 3);
    if (!$sources || (int) ($sources[0]['score'] ?? 0) === 0) {
        return rest_ensure_response([
            'answer' => $config['no_answer_reply'] . ' ' . mdh_chatbot_contact_guidance(),
            'configured' => true,
            'screening' => 'contact',
            'trigger_reason' => 'knowledge_gap',
            'show_contact' => mdh_chatbot_should_show_contact('unanswered'),
            'results' => [],
        ]);
    }
    $context = '';
    foreach ($sources as $source) {
        $context .= "TITLE: {$source['title']}
URL: {$source['url']}
CONTENT: {$source['text']}

";
    }

    $prompt = "You are the public website assistant. Answer only from the public website excerpts below. Do not invent facts, pricing, competitors, private data, or internal information. For an ordinary hiring or onboarding question, provide a helpful high-level process supported by the excerpts, clearly distinguish general guidance from legal or tax advice, and suggest contacting the website team for a tailored process. If the excerpts do not answer the question, say you cannot confirm it and suggest the visitor use the website contact form. Keep the answer concise and include a relevant source URL when useful. Respond in language code {$language}.

PUBLIC EXCERPTS:
{$context}
VISITOR QUESTION: {$message}";
    $selected_model = mdh_sanitize_gemini_model(get_option('mdh_gemini_model', 'gemini-3.5-flash-lite'));
    $models = array_values(array_unique([$selected_model, 'gemini-2.5-flash-lite']));
    $answer = '';
    $final_code = 0;

    foreach ($models as $model) {
        $attempts = $model === $selected_model ? 2 : 1;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                sleep(1);
            }

            $result = mdh_gemini_generate($answer_api_key, $model, $prompt, 250);
            if ($result['code'] === 0 && $result['error'] !== '') {
                update_option('mdh_last_ai_error', [
                    'time' => current_time('mysql'),
                    'model' => $model,
                    'status' => 'network',
                    'message' => sanitize_text_field($result['error']),
                ], false);
                return new WP_Error('ai_unavailable', 'The AI assistant cannot reach the configured provider right now.', ['status' => 502]);
            }

            $final_code = (int) $result['code'];
            $body = is_array($result['body']) ? $result['body'] : [];
            $answer = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $provider_message = $body['error']['message'] ?? '';

            if ($final_code < 400 && $answer !== '') {
                delete_option('mdh_last_ai_error');
                break 2;
            }

            update_option('mdh_last_ai_error', [
                'time' => current_time('mysql'),
                'model' => $model,
                'status' => $final_code,
                'message' => sanitize_text_field($provider_message ?: 'The configured AI provider returned an empty response.'),
            ], false);

            if ($final_code !== 429) {
                break;
            }
        }
    }

    if ($answer === '') {
        if ($final_code === 429) {
            return new WP_Error(
                'ai_rate_limited',
                'The configured AI provider has reached a request or quota limit. Please try again shortly.',
                ['status' => 429]
            );
        }
        return new WP_Error('ai_error', 'The AI assistant could not complete that answer.', ['status' => 502]);
    }

    return rest_ensure_response([
        'answer' => wp_strip_all_tags($answer),
        'configured' => true,
        'results' => array_map(function ($item) {
            return [
                'title' => $item['title'],
                'snippet' => wp_trim_words($item['text'], 38),
                'url' => esc_url_raw($item['url']),
            ];
        }, array_slice($sources, 0, 3)),
    ]);
}


// Refresh the plugin catalog so removed legacy copies disappear after deletion.
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    if (function_exists('wp_clean_plugins_cache')) wp_clean_plugins_cache(true);
    if (function_exists('wp_cache_delete')) wp_cache_delete('plugins', 'plugins');
}, 1);
