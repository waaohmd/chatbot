<?php
/**
 * WordPress uninstall handler for the canonical assistant package.
 * Records are intentionally preserved; administrators can archive/delete them
 * from the records screen before removing the plugin.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

foreach ([
    'mdh_chatbot_config',
    'mdh_guard_api_key',
    'mdh_answer_api_key',
    'mdh_last_ai_error',
    'mdh_chatbot_records_connection_key_hash',
    'mdh_chatbot_records_key_hashes',
    'mdh_chatbot_records_key',
    'mdh_chatbot_records_key_created_at',
    'mdh_chatbot_security_audit_enabled',
] as $option) {
    delete_option($option);
    delete_site_option($option);
}
