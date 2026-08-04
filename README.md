# Mustdohr Chatbot WordPress Plugin

This repository contains only the Mustdohr Website Assistant WordPress plugin. The Mustdohr marketing frontend is not included.

## Standalone website contact form

The plugin provides the shortcode `[mustdohr_contact_form]`. It uses the same
REST endpoint, visitor cookie, chat linking, source-page tracking, contact
notifications, and confirmation flow as the assistant's embedded form. The
plugin also renders it automatically on the public homepage unless that page
already contains the shortcode.

Standalone submissions are marked with `web-contact-form` as their trigger
source, while the source website remains the configured Mustdohr site label.

## Automatic updates

The plugin checks the public [`update.json`](./update.json) manifest and uses the ZIP attached to the matching GitHub Release. WordPress administrators will see a normal plugin update notice when the manifest version is newer than the installed version.

To publish an update:

1. Update the plugin version in `mustdohr-site-assistant-v240/mustdohr-site-assistant.php`.
2. Create a ZIP whose top-level folder is `mustdohr-site-assistant-v240`.
3. Create a GitHub Release with a tag matching the version, such as `v2.5.0`, and upload the ZIP as `mustdohr-site-assistant-v250.zip`.
4. Update `update.json` with the new version and release download URL.

Each website keeps its own Gemini keys, settings, knowledge configuration, chat records, contacts, and cookies. Only plugin code is distributed through this repository.

## Local chatbot admin

The standalone management console is in [`chatbot-admin`](./chatbot-admin). On
Windows, double-click [`start-admin.bat`](./start-admin.bat) in the repository
root. It starts the local admin server and opens
`http://localhost:3010/local-admin` in your browser.

## Integration points

- REST search endpoint: `/wp-json/mustdohr-search/v1/ask`
- REST AI endpoint: `/wp-json/mustdohr-search/v1/ai`
- Both endpoints accept `message` and optional `lang`.
- `mdh_chatbot_supported_languages` filters the allowed language codes.
- `mdh_chatbot_language` filters the selected language.
- `mdh_chatbot_storage_event` filters each event before storage.
- `mdh_chatbot_storage_handler` can return a callable database adapter.
- `mdh_chatbot_event` fires for each received question.

The plugin does not create a database table by default. A separate host plugin can attach a storage adapter without changing the chatbot code.

Example adapter:

```php
add_filter('mdh_chatbot_storage_handler', function () {
    return function ($event) {
        // Save $event to your own table, API, or database service.
    };
});
```
