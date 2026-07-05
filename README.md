# Mobility Workflow Platform

Web application for managing outgoing academic mobility processes (Europe/ Outside Europe). It guides students, coordinators, staff and admins through submitting, reviewing, approving and generating the official documents required for a mobility process (Learning Agreements, PEF, declarations, certificates, etc.).

## Features

- **Fenix SSO login** - authentication via IST Fénix OAuth2, with role-based access (`STUDENT`, `COORDINATOR`, `STAFF`, `ADMIN`).
- **Student flow** - submit mobility process data, upload files (e.g. TOR, English certificate), request edits, track process status.
- **Coordinator flow** - review submitted processes and equivalences, approve/request changes, manage processes.
- **Staff flow** - manage degrees, documents, and final credit recognition aproval.
- **Admin flow** - manage all the app, can act like any other role
- **Document generation** - fills official PDF templates (Learning Agreement, PEF, PEI, declarations, nomination, English certificate) with submitted data and signatures.
- **Email notifications** - sends status update emails (changes requested, approved, completed) via PHPMailer.

## Tech stack

- **Frontend**: static HTML/CSS/JS, [pdf-lib](https://pdf-lib.js.org/)-style utilities for filling PDF forms client-side.
- **Backend**: PHP (PDO/MySQL) under [server/](server/).
- **Email**: [PHPMailer](server/PHPMailer/).
- **Auth**: OAuth2 against IST Fénix.

## Project structure

```
├── *.html               # Pages per role (dashboards, process forms, admin panels)
├── scripts/              # Client-side JS (auth, PDF filling, shared utils)
├── styles/                # Light/dark stylesheets
├── templates/             # Blank PDF templates used for document generation
├── assets/                # Fonts, images, favicon
└── server/                # PHP backend
    ├── config.php          # App config, loads OAuth secrets from an external file
    ├── configdb.php         # Loads DB credentials from an external file
    ├── db.php, helpers.php  # DB connection + shared helpers
    ├── login.php, callback.php, logout.php, whoami.php, role.php  # Auth
    ├── process.php, final_process.php  # Core mobility process logic
    ├── admin_*.php          # Admin management endpoints
    ├── send_*_email.php     # Transactional emails
    └── PHPMailer/           # Vendored dependency (not committed, see .gitignore)
```

## Configuration & secrets

No credentials are stored in this repository. `server/config.php` and `server/configdb.php` require external secrets files that must be provisioned on the server (outside the webroot / git tree)

## Dependencies

PHPMailer is required but intentionally excluded from version control (see `.gitignore`). Install it via Composer before deploying:

```bash
composer require phpmailer/phpmailer
```

or vendor it manually under `server/PHPMailer/`.
