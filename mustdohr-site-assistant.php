<?php
/**
 * Plugin Name: Mustdohr Website Assistant
 * Description: Website search bar plus optional Gemini-powered public-content Q&A.
 * Version: 2.4.1
 * Author: Mustdohr
 * Text Domain: mustdohr-site-assistant
 */

if (!defined('ABSPATH')) exit;

define('MDH_SEARCH_VERSION', '2.4.1');
define('MDH_CHATBOT_REMOTE_RECORDS_URL', 'http://158.69.253.60:3030/api/chats');
define('MDH_GITHUB_REPOSITORY', 'waaohmd/chatbot');
define('MDH_GITHUB_SLUG', 'mustdohr-site-assistant-v240');
define('MDH_GITHUB_MANIFEST_URL', 'https://raw.githubusercontent.com/waaohmd/chatbot/main/update.json');
// The question limit is controlled from the private local admin. Set it to 0
// there when you want unlimited AI questions during testing.
define('MDH_CHATBOT_DISABLE_QUESTION_LIMIT', false);

add_action('plugins_loaded', function () {
    load_plugin_textdomain('mustdohr-site-assistant', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_action('admin_menu', function () {
    add_options_page('Mustdohr AI settings', 'Mustdohr AI', 'manage_options', 'mustdohr-ai', 'mdh_ai_settings_page');
    add_options_page('Mustdohr chat records', 'Mustdohr Chat Records', 'manage_options', 'mustdohr-chat-records', 'mdh_chatbot_records_page');
});

/**
 * Check the public GitHub manifest so WordPress can offer plugin updates.
 * Site-specific settings and stored records remain in WordPress.
 */
function mdh_github_update_manifest() {
    $response = wp_remote_get(MDH_GITHUB_MANIFEST_URL, ['timeout' => 5, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return [];
    $manifest = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($manifest) ? $manifest : [];
}

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (!is_object($transient) || empty($transient->checked)) return $transient;
    $manifest = mdh_github_update_manifest();
    $plugin = plugin_basename(__FILE__);
    if (!empty($manifest['version']) && version_compare((string) $manifest['version'], MDH_SEARCH_VERSION, '>') && !empty($manifest['download_url'])) {
        $transient->response[$plugin] = (object) [
            'slug' => MDH_GITHUB_SLUG,
            'plugin' => $plugin,
            'new_version' => sanitize_text_field($manifest['version']),
            'url' => esc_url_raw($manifest['homepage'] ?? 'https://github.com/' . MDH_GITHUB_REPOSITORY),
            'package' => esc_url_raw($manifest['download_url']),
        ];
    }
    return $transient;
});

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== MDH_GITHUB_SLUG) return $result;
    $manifest = mdh_github_update_manifest();
    if (empty($manifest['version'])) return $result;
    return (object) [
        'name' => 'Mustdohr Website Assistant',
        'slug' => MDH_GITHUB_SLUG,
        'version' => sanitize_text_field($manifest['version']),
        'author' => '<a href="https://github.com/waaohmd">Mustdohr</a>',
        'homepage' => esc_url_raw($manifest['homepage'] ?? 'https://github.com/' . MDH_GITHUB_REPOSITORY),
        'sections' => ['description' => wp_kses_post($manifest['description'] ?? 'Public website search and AI assistant for Mustdohr.')],
        'download_link' => esc_url_raw($manifest['download_url'] ?? ''),
        'requires' => '6.0',
        'tested' => '7.0',
    ];
}, 10, 3);

function mdh_chatbot_records_table() {
    global $wpdb;
    return $wpdb->prefix . 'mdh_chatbot_records';
}

function mdh_chatbot_contact_submissions_table() {
    global $wpdb;
    return $wpdb->prefix . 'mdh_chatbot_contact_submissions';
}

function mdh_chatbot_default_config() {
    return [
        'enabled' => true,
        'brand_name' => 'Mustdohr search',
        'welcome_message' => 'Search the public Mustdohr website and open the closest pages.',
        'ai_intro' => 'Ask AI to summarize public Mustdohr content. AI answers may be incomplete.',
        'faqs' => [
            ['question' => 'How does onboarding work?', 'answer' => 'Share the employee basics and Mustdohr runs the appropriate contracts, forms and training process.'],
            ['question' => 'What can employers view?', 'answer' => 'Employers can follow onboarding, compliance, employee information, documents, leave and payroll progress.'],
        ],
        'question_limit' => 10,
        'sensitive_keywords' => "competitor\nlowest price\nprofit\ncost structure\ncustomer complaint\ninternal information",
        'sensitive_reply' => 'I cannot help with that request. For information about Mustdohr services, please use our contact form.',
        'contact_mode' => 'embedded',
        'contact_url' => '',
        'notification_emails' => '',
        'knowledge_urls' => '',
        'excluded_urls' => '',
        'source_website' => 'Mustdohr',
        'contact_trigger_keywords' => "quote\npricing\nprice\npartnership\nsales\ncomplaint\nlegal\naccount",
        'contact_trigger_reply' => 'For this request, the fastest next step is to contact Mustdohr. Please share a few details and our team will follow up.',
        'no_answer_reply' => 'I could not confirm an answer from the public Mustdohr website. Please contact our team for help with this request.',
        'limit_reply' => 'You have reached the question limit for this visit. Please contact Mustdohr so our team can help.',
        'show_contact_for' => ['contact', 'unanswered', 'limit', 'sensitive'],
    ];
}

function mdh_chatbot_get_config() {
    $defaults = mdh_chatbot_default_config();
    $saved = get_option('mdh_chatbot_config', []);
    if (!is_array($saved)) $saved = [];
    $config = array_merge($defaults, $saved);
    $config['enabled'] = (bool) $config['enabled'];
    $config['question_limit'] = max(0, min(100, absint($config['question_limit'])));
    $config['faqs'] = is_array($config['faqs']) ? array_values($config['faqs']) : $defaults['faqs'];
    $config['show_contact_for'] = array_values(array_intersect((array) $config['show_contact_for'], ['contact', 'unanswered', 'limit', 'sensitive']));
    return $config;
}

function mdh_chatbot_sanitize_config($input) {
    $defaults = mdh_chatbot_default_config();
    if (!is_array($input)) $input = [];
    $faqs = [];
    foreach ((array) ($input['faqs'] ?? []) as $faq) {
        $question = sanitize_text_field(is_array($faq) ? ($faq['question'] ?? '') : '');
        $answer = sanitize_textarea_field(is_array($faq) ? ($faq['answer'] ?? '') : '');
        if ($question !== '' && $answer !== '') $faqs[] = ['question' => $question, 'answer' => $answer];
        if (count($faqs) >= 12) break;
    }
    return [
        'enabled' => !empty($input['enabled']),
        'brand_name' => sanitize_text_field($input['brand_name'] ?? $defaults['brand_name']),
        'welcome_message' => sanitize_textarea_field($input['welcome_message'] ?? $defaults['welcome_message']),
        'ai_intro' => sanitize_textarea_field($input['ai_intro'] ?? $defaults['ai_intro']),
        'faqs' => $faqs,
        'question_limit' => max(0, min(100, absint($input['question_limit'] ?? $defaults['question_limit']))),
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
    update_option('mdh_chatbot_records_version', '2.4.0', false);
}

function mdh_chatbot_maybe_install_records_table() {
    if (get_option('mdh_chatbot_records_version') !== '2.4.0') {
        mdh_chatbot_install_records_table();
    }
}
add_action('plugins_loaded', 'mdh_chatbot_maybe_install_records_table', 20);

function mdh_chatbot_log_response($mode, $question, $language, $request, $payload, $status = 'answered') {
    global $wpdb;
    $config = mdh_chatbot_get_config();
    $page_url = $request instanceof WP_REST_Request ? esc_url_raw($request->get_header('referer')) : '';
    if ($page_url === '') $page_url = home_url('/');
    $answer = is_array($payload) ? wp_strip_all_tags((string) ($payload['answer'] ?? '')) : wp_strip_all_tags((string) $payload);
    $screening = is_array($payload) ? sanitize_key((string) ($payload['screening'] ?? '')) : '';
    $trigger = is_array($payload) ? sanitize_key((string) ($payload['trigger_reason'] ?? '')) : '';
    $limit_reached = $status === 'limit_reached' || $trigger === 'limit';
    $sensitive = $screening === 'block' || $trigger === 'sensitive';
    $session_id = $request instanceof WP_REST_Request ? sanitize_text_field((string) $request->get_param('visitor_id')) : '';
    $wpdb->insert(mdh_chatbot_records_table(), [
        'mode' => sanitize_key($mode),
        'question' => sanitize_textarea_field($question),
        'answer' => $answer,
        'page_url' => $page_url,
        'language' => sanitize_key($language),
        'status' => sanitize_key($status),
        'source_website' => sanitize_text_field($config['source_website'] ?: (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'Mustdohr')),
        'session_id' => $session_id,
        'sensitive_blocked' => $sensitive ? 1 : 0,
        'question_limit_reached' => $limit_reached ? 1 : 0,
        'contact_submitted' => 0,
        'contact_trigger' => $trigger,
        'created_at' => current_time('mysql', true),
    ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s']);

    // Keep the private server archive in sync without delaying the public
    // website response. WordPress remains the live website record source.
    if ($mode === 'ai') {
        $visitor_id = $request instanceof WP_REST_Request ? sanitize_text_field((string) $request->get_param('visitor_id')) : '';
        wp_remote_post(MDH_CHATBOT_REMOTE_RECORDS_URL, [
            'timeout' => 3,
            'blocking' => false,
            'headers' => ['Content-Type' => 'application/json', 'X-Mustdohr-Source' => 'mustdohr.com'],
            'body' => wp_json_encode([
                'website' => $config['source_website'] ?: (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'Mustdohr'),
                'session_id' => $visitor_id,
                'visitor_message' => $question,
                'bot_reply' => $answer,
                'page_url' => $page_url,
                'sensitive_blocked' => $sensitive,
                'question_limit_reached' => $limit_reached,
                'contact_trigger' => $trigger,
            ]),
        ]);
    }

}

function mdh_chatbot_response($mode, $question, $language, $request, $payload, $status = 'answered') {
    mdh_chatbot_log_response($mode, $question, $language, $request, $payload, $status);
    return rest_ensure_response($payload);
}

function mdh_chatbot_error_response($mode, $question, $language, $request, $code, $message, $status) {
    mdh_chatbot_log_response($mode, $question, $language, $request, ['answer' => $message], 'failed');
    return new WP_Error($code, $message, ['status' => $status]);
}

add_filter('rest_post_dispatch', function ($response, $server, $request) {
    $route = $request instanceof WP_REST_Request ? $request->get_route() : '';
    // Only AI conversations are retained. Public search is intentionally
    // stateless: it has no question limit and does not create a chat record.
    if ($route !== '/mustdohr-search/v1/ai') {
        return $response;
    }

    $question = trim((string) $request->get_param('message'));
    if ($question === '') return $response;

    $mode = 'ai';
    $language = mdh_chatbot_get_language($request);
    $status = 'answered';
    $answer = '';
    $record_payload = [];

    if ($response instanceof WP_REST_Response) {
        $data = $response->get_data();
        $answer = is_array($data) ? (string) ($data['answer'] ?? $data['message'] ?? '') : '';
        $record_payload = is_array($data) ? $data : [];
        $status = $response->get_status() >= 400 ? 'failed' : 'answered';
        if (is_array($data) && ($data['code'] ?? '') === 'question_limit_reached') {
            $status = 'limit_reached';
            $data['trigger_reason'] = 'limit';
            $data['show_contact'] = in_array('limit', mdh_chatbot_get_config()['show_contact_for'], true);
            $response->set_data($data);
        }
    } elseif (is_wp_error($response)) {
        $answer = $response->get_error_message();
        $status = $response->get_error_code() === 'question_limit_reached' ? 'limit_reached' : 'failed';
        if ($status === 'limit_reached') $answer = mdh_chatbot_get_config()['limit_reply'];
    }

    $record_payload['answer'] = $answer;
    if ($status === 'limit_reached') $record_payload['trigger_reason'] = 'limit';
    mdh_chatbot_log_response($mode, $question, $language, $request, $record_payload, $status);
    return $response;
}, 10, 3);

function mdh_chatbot_records_page() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = mdh_chatbot_records_table();
    $records = $wpdb->get_results("SELECT id, mode, question, answer, page_url, language, status, source_website, sensitive_blocked, question_limit_reached, contact_submitted, contact_trigger, created_at FROM {$table} ORDER BY id DESC LIMIT 100");
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mdh_generate_records_key'])) {
        check_admin_referer('mdh_generate_records_key');
        update_option('mdh_chatbot_records_key', wp_generate_password(48, false, false), false);
        $records_key_message = 'A new private records key has been created. Copy it into the localhost admin page.';
    }
    $records_key = (string) get_option('mdh_chatbot_records_key', '');
    ?>
    <div class="wrap">
        <h1>Mustdohr chat records</h1>
        <p>Latest 100 public-website assistant interactions. Records include the source website, page, question, reply, workflow outcome, sensitive-question flag, question-limit flag and contact submission status.</p>
        <hr>
        <h2>Local admin connection</h2>
        <p>Use this key only in the private localhost admin page. It allows that page to read chat records, but cannot change WordPress or the website.</p>
        <?php if (!empty($records_key_message)) : ?><div class="notice notice-success"><p><?php echo esc_html($records_key_message); ?></p></div><?php endif; ?>
        <?php if ($records_key !== '') : ?>
            <input type="text" class="large-text code" readonly value="<?php echo esc_attr($records_key); ?>" onclick="this.select();">
        <?php else : ?>
            <p><em>No connection key has been created yet.</em></p>
        <?php endif; ?>
        <form method="post" style="margin: 12px 0 24px;">
            <?php wp_nonce_field('mdh_generate_records_key'); ?>
            <button type="submit" class="button button-secondary" name="mdh_generate_records_key" value="1"><?php echo $records_key !== '' ? 'Replace connection key' : 'Create connection key'; ?></button>
        </form>
        <table class="widefat striped">
            <thead><tr><th>Time (UTC)</th><th>Source</th><th>Question</th><th>Response</th><th>Workflow</th><th>Flags</th><th>Page</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($records) : foreach ($records as $record) : ?>
                <tr>
                    <td><?php echo esc_html($record->created_at); ?></td>
                    <td><?php echo esc_html($record->source_website ?: 'Mustdohr'); ?></td>
                    <td><?php echo esc_html(wp_trim_words($record->question, 22)); ?></td>
                    <td><?php echo esc_html(wp_trim_words($record->answer, 28)); ?></td>
                    <td><?php echo esc_html($record->contact_trigger ?: ($record->mode === 'ai' ? 'public answer' : $record->mode)); ?></td>
                    <td><?php echo esc_html(implode(' · ', array_filter([
                        $record->sensitive_blocked ? 'sensitive blocked' : '',
                        $record->question_limit_reached ? 'limit reached' : '',
                        $record->contact_submitted ? 'contact submitted' : '',
                    ])) ?: 'none'); ?></td>
                    <td><a href="<?php echo esc_url($record->page_url); ?>" target="_blank" rel="noopener">View page</a></td>
                    <td><?php echo esc_html($record->status); ?></td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="8">No visitor conversations have been recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

add_action('admin_init', function () {
    register_setting('mdh_ai_settings', 'mdh_gemini_api_key', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => '',
    ]);
    register_setting('mdh_ai_settings', 'mdh_guard_api_key', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => '',
    ]);
    register_setting('mdh_ai_settings', 'mdh_answer_api_key', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
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
        'default' => 'I cannot help with that request. For information about Mustdohr services, please use our contact form.',
    ]);
    register_setting('mdh_ai_settings', 'mdh_gemini_model', [
        'type' => 'string',
        'sanitize_callback' => 'mdh_sanitize_gemini_model',
        'default' => 'gemini-3.5-flash-lite',
    ]);
});

function mdh_sanitize_gemini_model($model) {
    $allowed = ['gemini-3.5-flash-lite', 'gemini-2.5-flash-lite'];
    return in_array($model, $allowed, true) ? $model : 'gemini-3.5-flash-lite';
}

function mdh_ai_settings_page() {
    if (!current_user_can('manage_options')) return;
    $last_error = get_option('mdh_last_ai_error', []);
    ?>
    <div class="wrap">
        <h1>Mustdohr AI settings</h1>
        <p>Each AI question is screened for compliance first. Only approved questions are then sent to the public-website answer model.</p>
        <form method="post" action="options.php">
            <?php settings_fields('mdh_ai_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mdh_guard_api_key">Compliance screening API key</label></th>
                    <td>
                        <input type="password" class="regular-text" id="mdh_guard_api_key" name="mdh_guard_api_key" value="<?php echo esc_attr(get_option('mdh_guard_api_key', '')); ?>" autocomplete="off">
                        <p class="description">Required. Gemini key used only for the first-layer allow, block, or contact decision.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mdh_answer_api_key">Website answer API key</label></th>
                    <td>
                        <input type="password" class="regular-text" id="mdh_answer_api_key" name="mdh_answer_api_key" value="<?php echo esc_attr(get_option('mdh_answer_api_key', '')); ?>" autocomplete="off">
                        <p class="description">Required. Gemini key used only after the question passes screening. You may use the same key as the screening layer.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mdh_blocked_keywords">Blocked keywords and phrases</label></th>
                    <td>
                        <textarea class="large-text" rows="7" id="mdh_blocked_keywords" name="mdh_blocked_keywords" placeholder="competitor\nlowest price\ninternal policy"><?php echo esc_textarea(get_option('mdh_blocked_keywords', '')); ?></textarea>
                        <p class="description">Optional. Add one word or phrase per line. These are blocked locally before either API is called.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="mdh_blocked_reply">Blocked-question reply</label></th>
                    <td><textarea class="large-text" rows="3" id="mdh_blocked_reply" name="mdh_blocked_reply"><?php echo esc_textarea(get_option('mdh_blocked_reply', 'I cannot help with that request. For information about Mustdohr services, please use our contact form.')); ?></textarea></td>
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
            </table>
            <?php submit_button('Save AI settings'); ?>
        </form>
        <?php if (is_array($last_error) && !empty($last_error['message'])) : ?>
            <hr>
            <h2>Last Gemini diagnostic</h2>
            <p><strong><?php echo esc_html(($last_error['time'] ?? '') . ' — ' . ($last_error['model'] ?? '') . ' — HTTP ' . ($last_error['status'] ?? '')); ?></strong></p>
            <p><?php echo esc_html($last_error['message']); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('mdh-search-assistant', plugin_dir_url(__FILE__) . 'assistant.css', [], MDH_SEARCH_VERSION);
    wp_enqueue_style('mdh-contact-assistant', plugin_dir_url(__FILE__) . 'contact.css', ['mdh-search-assistant'], MDH_SEARCH_VERSION);
    wp_enqueue_style('mdh-config-assistant', plugin_dir_url(__FILE__) . 'config.css', ['mdh-search-assistant'], MDH_SEARCH_VERSION);
    wp_enqueue_script('mdh-search-assistant', plugin_dir_url(__FILE__) . 'assistant.js', [], MDH_SEARCH_VERSION, true);
    wp_localize_script('mdh-search-assistant', 'MustdohrAssistant', [
        'endpoint' => esc_url_raw(rest_url('mustdohr-search/v1/ask')),
        'aiEndpoint' => esc_url_raw(rest_url('mustdohr-search/v1/ai')),
        'contactEndpoint' => esc_url_raw(rest_url('mustdohr-search/v1/contact')),
        'home' => esc_url_raw(home_url('/')),
        'language' => substr(determine_locale(), 0, 2),
        'config' => mdh_chatbot_public_config(),
    ]);
});

add_action('wp_footer', function () {
    if (!mdh_chatbot_get_config()['enabled']) return;
    $config = mdh_chatbot_get_config();
    ?>
    <div class="mdh-assistant" id="mdh-assistant">
        <button class="mdh-assistant-launch" type="button" aria-expanded="false">
            <span aria-hidden="true">⌕</span> Search Mustdohr
        </button>
        <section class="mdh-assistant-panel" hidden aria-label="Mustdohr website assistant">
            <header>
                <div>
                    <strong><?php echo esc_html($config['brand_name']); ?></strong>
                    <small>Public website content only</small>
                </div>
                <button class="mdh-assistant-close" type="button" aria-label="Close">×</button>
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
            <form>
                <label class="screen-reader-text" for="mdh-assistant-input">Search the Mustdohr website</label>
                <input id="mdh-assistant-input" maxlength="300" placeholder="Search Mustdohr" autocomplete="off" required>
                <button type="submit">Send</button>
            </form>
            <button class="mdh-assistant-contact-toggle" type="button" data-contact-toggle hidden><?php echo $config['contact_mode'] === 'link' ? 'Open contact form' : 'Continue with contact form'; ?></button>
            <form class="mdh-assistant-contact-form" data-contact-form hidden>
                <label>Name<input name="name" maxlength="160" required></label>
                <label>Company<input name="company" maxlength="190"></label>
                <label>Email<input name="email" type="email" maxlength="190" required></label>
                <label>Country / region<input name="country" maxlength="120"></label>
                <label>What do you need?<select name="request_type"><option>General enquiry</option><option>HR support</option><option>Onboarding</option><option>Payroll</option><option>Partnership</option></select></label>
                <label>Message<textarea name="message" maxlength="2000" required></textarea></label>
                <input class="mdh-honeypot" name="website" tabindex="-1" autocomplete="off">
                <button type="submit">Send enquiry</button>
            </form>
            <p class="mdh-assistant-contact-status" data-contact-status hidden aria-live="polite"></p>
            <p class="mdh-assistant-status"><span class="mdh-assistant-count">Public information only</span></p>
        </section>
    </div>
    <?php
});

add_action('rest_api_init', function () {
    register_rest_route('mustdohr-search/v1', '/ask', [
        'methods' => ['GET', 'POST'],
        'permission_callback' => '__return_true',
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
        'methods' => ['GET', 'POST'],
        'permission_callback' => '__return_true',
        'callback' => 'mdh_ai_answer',
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
        'permission_callback' => 'mdh_chatbot_records_permission',
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
    register_rest_route('mustdohr-search/v1', '/contact', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => 'mdh_chatbot_submit_contact',
    ]);
    register_rest_route('mustdohr-search/v1', '/contact-submissions', [
        'methods' => 'GET',
        'permission_callback' => 'mdh_chatbot_records_permission',
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
    register_rest_route('mustdohr-search/v1', '/config', [
        'methods' => 'GET',
        'permission_callback' => 'mdh_chatbot_records_permission',
        'callback' => function () { return rest_ensure_response(['config' => mdh_chatbot_get_config()]); },
    ]);
    register_rest_route('mustdohr-search/v1', '/config', [
        'methods' => 'POST',
        'permission_callback' => 'mdh_chatbot_records_permission',
        'callback' => function (WP_REST_Request $request) {
            $payload = $request->get_json_params();
            if (!is_array($payload)) $payload = $request->get_params();
            $config = mdh_chatbot_sanitize_config($payload);
            update_option('mdh_chatbot_config', $config, false);
            return rest_ensure_response(['ok' => true, 'config' => $config]);
        },
    ]);
});

function mdh_chatbot_records_permission(WP_REST_Request $request) {
    $expected_key = (string) get_option('mdh_chatbot_records_key', '');
    $provided_key = (string) $request->get_header('x-mustdohr-records-key');
    if ($expected_key === '' || $provided_key === '' || !hash_equals($expected_key, $provided_key)) {
        return new WP_Error('mdh_records_forbidden', 'A valid private records key is required.', ['status' => 403]);
    }
    return true;
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

function mdh_chatbot_submit_contact(WP_REST_Request $request) {
    $payload = $request->get_json_params();
    if (!is_array($payload)) $payload = $request->get_params();
    if (!empty($payload['website'])) {
        return rest_ensure_response(['ok' => true]);
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $rate_key = 'mdh_contact_rate_' . md5($ip);
    $attempts = (int) get_transient($rate_key);
    if ($attempts >= 5) {
        return new WP_Error('contact_rate_limited', 'Please wait a few minutes before submitting another request.', ['status' => 429]);
    }

    $name = sanitize_text_field($payload['name'] ?? '');
    $company = sanitize_text_field($payload['company'] ?? '');
    $email = sanitize_email($payload['email'] ?? '');
    $country = sanitize_text_field($payload['country'] ?? '');
    $request_type = sanitize_text_field($payload['request_type'] ?? 'General enquiry');
    $message = sanitize_textarea_field($payload['message'] ?? '');
    $page_url = esc_url_raw($payload['page_url'] ?? home_url('/'));
    $session_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($payload['visitor_id'] ?? ''));
    $trigger_reason = sanitize_key((string) ($payload['trigger_reason'] ?? 'manual'));
    $config = mdh_chatbot_get_config();
    $source_website = sanitize_text_field($payload['source_website'] ?? ($config['source_website'] ?: (wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'Mustdohr')));
    $chat_question = sanitize_textarea_field($payload['chat_question'] ?? '');
    $chat_transcript = sanitize_textarea_field($payload['chat_transcript'] ?? '');

    if ($name === '' || !is_email($email) || $message === '') {
        return new WP_Error('invalid_contact', 'Please enter your name, a valid email address and a short message.', ['status' => 400]);
    }

    global $wpdb;
    $chat_record_id = 0;
    $chat_record_count = 0;
    if ($session_id !== '') {
        $linked_chats = $wpdb->get_results($wpdb->prepare(
            "SELECT id, question, answer, created_at FROM " . mdh_chatbot_records_table() . " WHERE session_id = %s ORDER BY id ASC LIMIT 500",
            $session_id
        ));
        $chat_record_count = count($linked_chats);
        if ($linked_chats) {
            $latest_chat = end($linked_chats);
            $chat_record_id = (int) $latest_chat->id;
            if ($chat_question === '') $chat_question = (string) $latest_chat->question;
            $chat_lines = [];
            foreach ($linked_chats as $chat) {
                $chat_lines[] = '[' . $chat->created_at . '] Visitor: ' . $chat->question;
                $chat_lines[] = '[' . $chat->created_at . '] Assistant: ' . $chat->answer;
            }
            $chat_transcript = implode("\n", $chat_lines);
        }
    }
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
        return new WP_Error('contact_storage_failed', 'Your enquiry could not be saved. Please try again.', ['status' => 500]);
    }
    if ($session_id !== '') {
        $wpdb->update(mdh_chatbot_records_table(), ['contact_submitted' => 1], ['session_id' => $session_id], ['%d'], ['%s']);
    }
    set_transient($rate_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);
    mdh_chatbot_notify_contact(compact('name', 'company', 'email', 'country', 'request_type', 'message', 'page_url', 'session_id', 'trigger_reason', 'source_website', 'chat_record_id', 'chat_question', 'chat_transcript', 'chat_record_count'));

    return rest_ensure_response(['ok' => true, 'message' => 'Thank you. The Mustdohr team will be in touch.']);
}

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
    return 'Please use the Contact Mustdohr button below and our team will be in touch.';
}

function mdh_chatbot_should_show_contact($reason) {
    return in_array(sanitize_key($reason), mdh_chatbot_get_config()['show_contact_for'], true);
}

function mdh_chatbot_trigger_match($message) {
    $config = mdh_chatbot_get_config();
    $keywords = preg_split('/[\r\n,]+/', (string) $config['contact_trigger_keywords']);
    $haystack = strtolower($message);
    foreach ($keywords as $keyword) {
        $keyword = strtolower(trim($keyword));
        if ($keyword !== '' && strpos($haystack, $keyword) !== false) return $keyword;
    }
    return '';
}

function mdh_chatbot_sensitive_match($message) {
    $config = mdh_chatbot_get_config();
    $legacy = (string) get_option('mdh_blocked_keywords', '');
    $keywords = preg_split('/[\r\n,]+/', $config['sensitive_keywords'] . "\n" . $legacy);
    $haystack = strtolower($message);
    foreach ($keywords as $keyword) {
        $keyword = strtolower(trim($keyword));
        if ($keyword !== '' && strpos($haystack, $keyword) !== false) return $keyword;
    }
    return '';
}

function mdh_chatbot_check_question_limit(WP_REST_Request $request) {
    if (MDH_CHATBOT_DISABLE_QUESTION_LIMIT) return true;
    $config = mdh_chatbot_get_config();
    $limit = $config['question_limit'];
    if ($limit === 0) return true;
    $visitor = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->get_param('visitor_id'));
    if ($visitor === '') {
        $visitor = md5((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
    $key = 'mdh_question_count_' . substr($visitor, 0, 48);
    $count = (int) get_transient($key);
    if ($count >= $limit) {
        return new WP_Error('question_limit_reached', $config['limit_reply'] . ' ' . mdh_chatbot_contact_guidance(), [
            'status' => 429,
            'trigger_reason' => 'limit',
            'show_contact' => mdh_chatbot_should_show_contact('limit'),
        ]);
    }
    set_transient($key, $count + 1, DAY_IN_SECONDS);
    return true;
}

function mdh_chatbot_notify_contact($submission) {
    $config = mdh_chatbot_get_config();
    $recipients = array_filter(array_map('sanitize_email', preg_split('/[\s,;]+/', (string) $config['notification_emails'])));
    if (!$recipients) return;
    $subject = '[Mustdohr] New chatbot contact enquiry';
    $body = "A visitor submitted the Mustdohr chatbot contact form.\n\n";
    foreach (['name' => 'Name', 'company' => 'Company', 'email' => 'Email', 'country' => 'Country / region', 'request_type' => 'Request type', 'message' => 'Message', 'page_url' => 'Page', 'source_website' => 'Source website', 'trigger_reason' => 'Triggered by', 'chat_question' => 'Last chat question', 'chat_record_id' => 'Linked chat record ID', 'chat_record_count' => 'Linked chat records', 'chat_transcript' => 'Chat transcript'] as $key => $label) {
        $body .= $label . ': ' . ($submission[$key] ?? '') . "\n";
    }
    wp_mail($recipients, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
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
    $excluded = array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $config['excluded_urls'])));
    $urls = array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $config['knowledge_urls'])));
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
            : 'I found matching information on the public Mustdohr website.',
        'low_relevance' => $low_relevance,
        'results' => $results,
    ]);
}

function mdh_find_blocked_keyword($message) {
    $keywords = preg_split('/\r\n|\r|\n/', (string) get_option('mdh_blocked_keywords', ''));
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

    if (is_wp_error($response)) {
        return ['code' => 0, 'body' => [], 'error' => $response->get_error_message()];
    }
    return [
        'code' => (int) wp_remote_retrieve_response_code($response),
        'body' => json_decode(wp_remote_retrieve_body($response), true),
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

function mdh_screen_question($message, $api_key, $model) {
    $matched_keyword = mdh_find_blocked_keyword($message);
    if ($matched_keyword !== '') {
        return ['decision' => 'block', 'category' => 'custom_keyword'];
    }

    $prompt = 'You are the first-layer compliance classifier for Mustdohr\'s public website assistant. Classify the visitor question before it can be sent to an answer model. Block questions asking for competitor attacks or comparisons, confidential/internal information, employee/client/partner personal data, minimum pricing, cost structure, profit, security exploitation, illegal activity, or harmful instructions. Use contact for sales, pricing, partnership, complaint, legal, or account-specific requests. Allow ordinary questions about Mustdohr\'s public services, onboarding, policies, locations, payroll, benefits, or employment support. Return only valid JSON with exactly these keys: decision (allow, block, or contact) and category.\n\nQUESTION: ' . $message;
    $result = mdh_gemini_generate($api_key, $model, $prompt, 80);
    if ($result['code'] < 200 || $result['code'] >= 300) {
        $provider_message = $result['body']['error']['message'] ?? $result['error'] ?? 'Gemini screening failed.';
        mdh_record_ai_diagnostic('screening', $model, $result['code'] ?: 'network', $provider_message);
        return ['decision' => 'error'];
    }

    $raw = $result['body']['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $classification = mdh_extract_json_object($raw);
    $decision = strtolower((string) ($classification['decision'] ?? ''));
    if (!in_array($decision, ['allow', 'block', 'contact'], true)) {
        mdh_record_ai_diagnostic('screening', $model, $result['code'], 'Gemini returned an invalid screening decision.');
        return ['decision' => 'error'];
    }
    return ['decision' => $decision, 'category' => sanitize_key($classification['category'] ?? 'general')];
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

    mdh_chatbot_store_event([
        'type' => 'question_received',
        'mode' => 'ai',
        'message' => $message,
        'language' => $language,
        'site' => home_url('/'),
        'created_at' => current_time('mysql', true),
    ]);

    $screening_api_key = trim((string) get_option('mdh_guard_api_key', ''));
    $answer_api_key = trim((string) get_option('mdh_answer_api_key', ''));
    if ($screening_api_key === '' || $answer_api_key === '') {
        return rest_ensure_response([
            'answer' => 'AI mode is not configured yet. An administrator can add the Gemini API key in Settings → Mustdohr AI.',
            'configured' => false,
            'results' => [],
        ]);
    }

    $selected_model = mdh_sanitize_gemini_model(get_option('mdh_gemini_model', 'gemini-3.5-flash-lite'));
    $screening = mdh_screen_question($message, $screening_api_key, $selected_model);
    if ($screening['decision'] === 'error') {
        return new WP_Error('ai_screening_unavailable', 'The compliance check is temporarily unavailable. Please try again later.', ['status' => 503]);
    }
    if ($screening['decision'] === 'block' || $screening['decision'] === 'contact') {
        $blocked_reply = $screening['decision'] === 'contact'
            ? $config['contact_trigger_reply']
            : trim((string) get_option('mdh_blocked_reply', $config['sensitive_reply']));
        return rest_ensure_response([
            'answer' => $blocked_reply !== '' ? $blocked_reply : 'Please use the Mustdohr contact form for help with this request.',
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
        $context .= "TITLE: {$source['title']}\nURL: {$source['url']}\nCONTENT: {$source['text']}\n\n";
    }

    $prompt = "You are Mustdohr's public website assistant. Answer only from the public website excerpts below. Do not invent facts, pricing, competitors, private data, or internal information. If the excerpts do not answer the question, say you cannot confirm it and suggest the visitor use the website contact form. Keep the answer concise and include a relevant source URL when useful. Respond in language code {$language}.\n\nPUBLIC EXCERPTS:\n{$context}\nVISITOR QUESTION: {$message}";
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

            $response = wp_remote_post('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent', [
                'timeout' => 20,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $answer_api_key,
                ],
                'body' => wp_json_encode([
                    'contents' => [[
                        'parts' => [['text' => $prompt]],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => 250,
                    ],
                ]),
            ]);

            if (is_wp_error($response)) {
                update_option('mdh_last_ai_error', [
                    'time' => current_time('mysql'),
                    'model' => $model,
                    'status' => 'network',
                    'message' => sanitize_text_field($response->get_error_message()),
                ], false);
                return new WP_Error('ai_unavailable', 'The AI assistant cannot reach Gemini right now.', ['status' => 502]);
            }

            $final_code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
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
                'message' => sanitize_text_field($provider_message ?: 'Gemini returned an empty response.'),
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
                'Gemini has reached this project’s current request or quota limit. Please try again shortly.',
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
