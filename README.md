# Mustdohr Chatbot WordPress Plugin

This repository contains only the Mustdohr Website Assistant WordPress plugin. The Mustdohr marketing frontend is not included.

## Automatic updates

The plugin checks the public [`update.json`](./update.json) manifest and uses the ZIP attached to the matching GitHub Release. WordPress administrators will see a normal plugin update notice when the manifest version is newer than the installed version.

To publish an update:

1. Update the plugin version in `mustdohr-site-assistant-v240/mustdohr-site-assistant.php`.
2. Create a ZIP whose top-level folder is `mustdohr-site-assistant-v240`.
3. Create a GitHub Release with a tag matching the version, such as `v2.5.0`, and upload the ZIP as `mustdohr-site-assistant-v240.zip`.
4. Update `update.json` with the new version and release download URL.

Each website keeps its own Gemini keys, settings, knowledge configuration, chat records, contacts, and cookies. Only plugin code is distributed through this repository.
