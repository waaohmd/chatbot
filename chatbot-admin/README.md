# Chatbot Admin

Private localhost administration console for the Mustdohr chatbot.

This package contains only the admin interface and its local API route. It does
not include the Mustdohr marketing frontend or public chatbot assets.

## Run locally

```bash
npm install
npm run dev
```

Open [http://localhost:3010/local-admin](http://localhost:3010/local-admin).

On Windows, double-click `start-admin.bat` in the repository main directory for
a one-click launch. It installs dependencies on the first run, starts the local
server, and opens the admin console in your browser.

On macOS or Linux, run `../start-admin.sh` from the repository root. If needed,
make it executable first with `chmod +x start-admin.sh`.

The console can:

- connect to the private WordPress records endpoint with an existing records
  passkey; each connection remains active for 15 minutes;
- download chat records and Contact submissions to this computer;
- verify each local write, then clear only the downloaded WordPress IDs;
- keep timestamped JSON backups in the local data folder;
- view and export the local archive as CSV;
- manage chatbot settings, knowledge URLs, Q&A, analytics, and notification emails.

No SSH connection or server password is used. The connection key is held only
inside an HttpOnly local-admin session cookie and expires after 15 minutes.
Contact notification emails include the complete contact form and the linked
chat transcript.

Local archive files (created after the first connection):

- `data/chat-records.json`
- `data/contact-submissions.json`
- `data/backups/` (timestamped safety copies)

The `data/` folder is ignored by Git so private records are not committed.
