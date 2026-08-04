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

On Windows, double-click `start-admin.bat` for a one-click launch. It installs
dependencies on the first run, then starts the local admin console.

The console can:

- connect to the private WordPress records endpoint with a records key;
- connect to the records archive server over SSH without saving the password;
- view and refresh chat records and contact submissions;
- export records as CSV;
- view analytics, workflow settings, knowledge URLs, Q&A, and notification emails.

SSH passwords and private records keys are entered at runtime only. Do not add
`.env.local` or credentials to this repository.
