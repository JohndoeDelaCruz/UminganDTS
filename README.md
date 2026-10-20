# Umingan Municipal Document Tracking System

Working Laravel 12 / React 19 / TypeScript / Inertia 2 / Tailwind 4 application for staff document registration, office handoffs, public status checking and reporting. Database: **XAMPP MariaDB, InnoDB, utf8mb4, strict mode**. Based on the 17 October 2026 v1.2 project brief, with the database changed to XAMPP by explicit user request.

## Zero-data installation

Migrations create schema only. No default accounts, offices, categories, documents or public codes. `DatabaseSeeder` is intentionally empty. Never run fixtures against the application database. The first administrator is created only by an explicitly invoked interactive command.

### Requirements

- PHP 8.2+ with PDO MySQL, mbstring, OpenSSL, fileinfo, DOM/XML, curl and zip.
- Composer 2; Node.js 22+ and npm.
- XAMPP with its MySQL service running (this workstation bundles MariaDB 10.4.32). Use `DB_CONNECTION=mariadb` and the service port, normally `3306`. No separate MySQL installation or launcher is required.
- A MariaDB migration account with schema DDL and `TRIGGER` privileges. With binary logging enabled, the DBA must permit creation of the append-only triggers (use a suitably privileged migration account, or explicitly enable `log_bin_trust_function_creators` for a dedicated local server). Runtime accounts should have only required table privileges, not DDL permissions.

### Set up

```powershell
composer install
npm ci
Copy-Item .env.example .env
php artisan key:generate
```

Create a **new blank database**, using your XAMPP database administrator's connection:

```sql
CREATE DATABASE umdts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'umdts_app'@'127.0.0.1' IDENTIFIED BY '<choose-a-strong-database-password>';
GRANT ALL PRIVILEGES ON umdts.* TO 'umdts_app'@'127.0.0.1';
```

These broad schema privileges are for local setup. Use a separate migration principal and a restricted runtime principal for deployment. Set the actual `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `APP_URL` in the ignored `.env` file. Never commit credentials.

```powershell
php artisan migrate
php artisan umdts:verify-empty
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Before account provisioning, public tracking returns a neutral no-match state and login explains that setup is pending. Operational tables remain empty.

The home page and `/login` use the PDF reference's staff sign-in layout: municipal identity and introduction on the left, a white sign-in card on the right, and a feature strip. Public document lookup is a separate page at `/track`. A neutral architectural illustration is used until approved municipal photography and a seal are supplied.

### Provision your real administrator

In another terminal, run:

```powershell
php artisan umdts:bootstrap-admin
```

Enter the actual administrator name and email, then a password of at least 12 characters with mixed case, a number and a symbol. Password entry is hidden. The command uses a database advisory lock and refuses a second bootstrap. It takes no password arguments, avoiding shell-history disclosure.

Sign in at `http://127.0.0.1:8000/login`, create the real departments and document categories, then edit your own account under **Staff accounts** to assign your office. You can then register your first real document. Staff accounts require an active office. Administrators without offices can complete setup but cannot register or route documents.

## This workstation

On 17 October 2026, the user explicitly requested onboarding Umingan's real departments and offices. The optional sourced import adds 24 local offices verified against the LGU's official directory and Citizen's Charter. It is never run by migrations or `DatabaseSeeder`. See [the office list, source links and naming decisions](docs/UMINGAN_OFFICES.md).

```powershell
php artisan umdts:import-umingan-offices          # Preview; no writes
php artisan umdts:import-umingan-offices --apply  # Explicitly add missing offices
```

Existing offices, including inactive entries and recognized naming variants, are preserved. Ambiguous matches or code conflicts stop the import without partial changes. Every new office receives a source-linked operator audit entry. This command creates office configuration only.

The user also approved 16 document category groupings on 17 October 2026. These have been added to this workstation's application database with processing targets left unset. See [the category list and usage notes](docs/DOCUMENT_CATEGORIES.md). This was explicit configuration, separate from migrations and default seeding; each category has an operator audit event.

The user subsequently requested working office test accounts without real employee names. There is now one clearly labeled staff test account per office, with 24 separate logins and unique passwords held in a private folder outside the web workspace. See [local test account details](docs/TEST_STAFF_ACCOUNTS.md). This explicit setup does not add automatic seeding or administrator privileges.

The application uses XAMPP's **MariaDB 10.4.32** at `127.0.0.1:3306`. Application database: `umdts`; isolated test database: `umdts_testing`. Each has a separate database account restricted to its own schema, with randomly generated passwords saved only in ignored local configuration. The PHP PDO MySQL extension supports the MariaDB connection.

After a restart, start **MySQL** in the XAMPP Control Panel, then run:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

The application remains at `http://127.0.0.1:8000`. The database is visible as `umdts` in XAMPP/phpMyAdmin. The earlier `scripts/start-local-mysql.ps1` launcher has been removed. Switching the database does not require changing how the Laravel development web server runs.

The existing administrator, audit history, password hashes, encryption key and sessions were transferred from the previous instance and verified against the source. Existing unrelated XAMPP databases were not overwritten. A pre-transfer SQL backup, original environment files and a verification manifest are retained privately under `%LOCALAPPDATA%\UMDTS\backups\switch-to-xampp-20261017-120051`. The old portable server is stopped; its files remain available as a recovery copy.

Workflow dates that must remain fixed use explicit `DATETIME` columns. This prevents MariaDB 10.4 from implicitly updating a record's receipt, transfer or credential-issue date when other fields change. The compatibility migration preserves existing values. Standard nullable completion/release and framework timestamp fields retain their normal behavior.

## Everyday workflow

1. Register a document in your office, optionally attaching a PDF, PNG or JPEG up to 10 MB. A transaction allocates a unique `UMG-YYYY-NNNNNN` reference and writes the first timeline event. Contacts are encrypted at rest.
2. Forward to another active office. Your office retains custody while the destination sees a pending inbox item. One pending transfer is enforced by both row locking and a unique generated-column index.
3. The destination acknowledges or rejects. A sender can cancel a pending transfer. Rejection/cancellation requires a reason and restores the prior processing status. Duplicate acknowledgment is rejected without creating another event.
4. To request corrections, open **Return for correction**, select the responsible office and explain the corrections needed. The previous sending office is selected when available. This creates a pending return handoff; custody stays with the sender until acknowledgment, then the receiving office becomes custodian with status **Returned**. Rejecting or cancelling restores the previous status. Resume **In progress** when correcting the record.
5. **Outgoing** retains every movement sent by your office, including acknowledged, rejected and cancelled handoffs. **Incoming → Received history** retains accepted movements after later forwarding. Filter by counterpart office, movement type and dates; outgoing also has a handoff-status filter. Incoming received dates use acknowledgment time; other handoff dates use sent time.
6. **Processing actions** shows the main next action: Start/Resume processing, Complete processing, or Release document. Open **More actions** for other permitted actions, including holds and cancellation. Each action updates the status and writes a standard timeline entry automatically; completion/release dates are recorded by the system. Holds, cancellation and reopening request a reason; release requests its method. **Add a note** is optional. Forwarding, returns, notes and **Citizen receipt** are collapsed by default; opening one section closes the previous one. **Print receipt** remains available at the top of the page. Only an administrator in the owning office can reopen a terminal record, with a reason. Opening a record does not complete or release it.
7. Registering a non-confidential document automatically generates an eight-character citizen access code, displayed in two groups of four. **Print receipt** includes the reference, tracking URL and code automatically. The current custodian can reprint the same code after refreshing or signing in again. Citizens can type it in either case, with or without spaces or the dash. Confidential documents never receive public codes. Existing long codes remain valid; existing records are not automatically enabled or rotated. Replacing a code invalidates the previous one, and public tracking can still be disabled.

Document history and audit logs are append-only in both Eloquent models and database triggers. Accounts and configuration are deactivated, not deleted. A busy office cannot be deactivated until active staff, open records and incoming pending handoffs are reassigned or resolved. Admins cannot demote or deactivate themselves.

Sidebar badges on **Documents**, **Incoming** and **Outgoing** show unseen activity, not total records. Documents counts accessible records changed by other staff; Incoming counts new pending/accepted arrivals; Outgoing counts sent records with new movements or outcomes recorded by other staff. Opening a tab clears only its badge, including when filters or history views are used. Read state persists per account, office and tab across sign-ins; prefetching and dashboard/detail visits do not mark tabs read. Own actions do not create unread badges. Counters refresh every 30 seconds while the page is visible and on window focus. Reading a tab does not acknowledge a document or change custody.

Public tracking uses a compact introduction and one wide panel, with receipt lookup beside the result on desktop. Long histories show the latest four updates first; **Earlier updates** and **Later updates** keep the entire history accessible without growing the page. A new lookup returns to the latest updates. Phones stack the form and result, and small or zoomed viewports can scroll naturally. The timeline has no separate inner card.

## Office work and My Work

Open a document held by your office and use **Claim document**, or expand **Assign someone else** to select an active colleague in the same office. The handler sees it in **My Work**, with search and Assigned / Working / Ready to forward filters. This records responsibility; colleagues retain the office's existing access and action permissions.

Choose Review document, Check requirements or Prepare response and click **Start office work**. Starting also moves a Received/Returned document into processing. Choose a review result and click **Finish office work**; additional result details are optional. Dates and permanent timeline entries are automatic. Finishing an office task leaves the overall document in progress so it can be forwarded. **Complete document** under the collapsed **Document actions** section is for final completion of the whole case.

Once a handler is assigned, finish the office task before ordinary forwarding or final completion. Hold and Return for correction remain available when work cannot proceed. Pending handoffs lock office work; acknowledgment closes the sender's assignment and the new office claims or assigns its own task. Rejected/cancelled handoffs retain the assignment. A later return, new task or administrator reopening preserves earlier work in the collapsed **Office work history**. Stale forms cannot overwrite newer assignments.

Existing records remain unassigned until staff choose a handler and keep their original workflow. My Work contains only current assignments in the user's active office, including paused work and finished work ready for the next action. Sent handoffs move to Outgoing; terminal documents leave the personal queue. Office task details, handler names and review notes remain internal. Service checklists remain deferred.

## Staff notifications

The top-bar bell shows a count only when there are unread alerts. An assignment notifies its handler; a new handoff or return for correction notifies active members of the receiving office. Claiming work yourself is quiet. Each alert shows the reference, the action needing attention, the time and **Open document**. Opening the bell does not mark anything read. Opening a notification marks only that recipient's alert read; **Mark all read** clears that account's current snapshot, leaving later arrivals unread. Read controls never accept, forward or change a document, and sidebar activity counters keep their separate read state.

The bell contains tasks that remain actionable. Reassigned/finished office work and resolved handoffs leave the list. A return stays available after acknowledgment, with updated instructions, until processing resumes or custody changes. Pending movements temporarily hide an unfinished assignment; rejection/cancellation restores it. Read alerts remain available while their tasks are still actionable. Ten alerts fit each page, with Older/Newer controls; the menu refreshes every 30 seconds on visible pages, on focus and when opened.

Alerts are personal and scoped to the account's current active office and document access. Their display uses fixed instructions and references, with correction details available inside the authorized record. Public tracking receives no notification feed. The migration starts empty and does not invent alerts for earlier actions. Notifications use the application database; no mail/SMS delivery, queue worker or extra service is needed.

## Access and reporting definitions

- Staff read records currently held by their active office, plus pending incoming handoffs. Administrators read ordinary internal records across offices. Confidential records require current custody or a pending incoming transfer, including for admins. An office's handoff history retains only its own sent/accepted movement summaries after custody changes: reference, counterpart office, movement type, handoff outcome and dates. Subject and detail links are available only while the user has current document access; historical summaries omit contacts, sender, description, notes, attachments and later movements.
- Only the current active office performs processing actions. An incoming office may acknowledge/reject before taking custody. Full document details, attachments, document lists, dashboard, reports and CSV retain the existing visibility policy. Handoff history is separately scoped to the account's active office, including for administrators; an administrator without an office has no office handoff history.
- Public results expose only reference, generic category, mapped high-level status, last update and a citizen progress timeline. The timeline shares the staff page's vertical-line/marker style and uses fixed public titles such as Document received, Processing started/resumed, Ready for release and Document released, with the original event timestamps displayed in Philippine Standard Time. Holds, returns, forwarding and receipt milestones remain visible; separate milestones are retained even when their mapped public status is the same. Staff notes, attachment/code activity and unknown internal event types are excluded. No names, subject, description, contacts, office names, internal notes or attachments are returned. Lookup is limited to 10 attempts/minute/IP; all page responses use no-store headers.
- New citizen codes use eight cryptographically random characters from an alphabet excluding 0, 1, I and O. Authentication uses a SHA-256 hash; a separate encrypted copy supports receipt reprints. Both token fields are hidden from ordinary model serialization. Only the current custodian receives the code on the authorized document detail page; lists, history, dashboards, public lookup, events and audit metadata omit it. Backups must include the matching APP_KEY for reprints. The user explicitly requested automatic issuance for new non-confidential records on 19 October 2026; it replaces the original opt-in default.
- Public lookup treats a valid receipt credential as authorization. It does not establish a person's identity. The municipality must approve category names and disclosure before enabling individual documents.
- Targets count calendar days in Asia/Manila; holds do not pause them. Store timestamps in UTC and display Philippine Standard Time. Categories without targets produce no assumed deadline. Target changes apply to new records.
- Report date filters use receipt date. Pending = received/awaiting acknowledgment; in progress = processing/on hold/returned; completed = completed/released. Cancelled records remain in totals. Overdue = open, non-cancelled and past a configured due date.
- Average completion time = elapsed calendar days from receipt to internal completion, among currently completed/released records with valid timestamps. Reopening clears the completion/release timestamps. CSV respects applied filters and neutralizes spreadsheet formula prefixes.

## Authentication and password recovery

Public registration is disabled. Administrators create staff accounts. Inactive accounts/departments lose access. Session cookies are encrypted, login attempts are throttled and Laravel CSRF protection covers mutations. Password changes rotate remember tokens and revoke other database sessions; password recovery revokes all database sessions.

Local email uses `MAIL_MAILER=log`: reset links are written to private `storage/logs/laravel.log` as a development preview. **No external email or SMS is delivered in this configuration.** Configure a real SMTP mailer before relying on email recovery in deployment. An operator with trusted server access can recover an existing account without SMTP:

```powershell
php artisan umdts:reset-password
```

It prompts for the email and new hidden password, invalidates database sessions and appends an operator recovery audit event.

## Verification

Create a separate disposable database ending in `_testing`, with a separate account granted access **only** to that database. Copy `.env.example` to the ignored `.env.testing`, set `APP_ENV=testing`, configure that database/user/password, and generate a separate key:

```powershell
php artisan key:generate --env=testing
php artisan test
npm run typecheck
npm run lint
npm run build
php vendor/bin/pint --test
```

The test base class checks the environment/database name **before** database-refresh hooks. Factories also enforce the isolated environment and name. Tests rebuild only the disposable test schema, then roll back their data. Do not point this connection at a database you want to retain.

Manual browser QA can explicitly run `php tests/Support/create-browser-fixture.php` after tests; it refuses any connection except the isolated testing database and creates one temporary QA administrator there. It writes the generated QA login to ignored `.runtime/browser-login.json`. To browse that database, run a separate server with `APP_ENV=testing`, `SESSION_DRIVER=database`, `SESSION_COOKIE=umdts_qa_session` and a different port, e.g. 8001. Browse QA at `http://localhost:8001` while the application uses `http://127.0.0.1:8000`; the different hostname also isolates CSRF cookies when the two databases use different encryption keys. Run the automated tests again afterwards to clear the disposable fixtures. No QA accounts belong in the application database.

## Deployment and operations

Point the web server's document root to **`public/`**, not the repository root. Deploy `composer.lock`/`package-lock.json`; install with `composer install --no-dev --optimize-autoloader`, `npm ci`, and `npm run build`. Configure real secrets, `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, SMTP if needed and writable private storage. Use a migration principal for `php artisan migrate --force`, then `php artisan optimize`. Supervise `php artisan queue:work --tries=3` for queued work. The current workflows execute synchronously; SMS and third-party integration are not simulated.

Attachments live in `storage/app/private/documents` and are delivered only through authorized download controllers. **Do not** expose them through `storage:link` or a public alias. Back up the database, private files and `APP_KEY` together; losing the key makes encrypted contacts/session payloads unreadable. Follow approved municipal retention policy; v1 does not automatically purge records.

Example backup with a privately secured database client option file (never a password on the command line):

```powershell
& C:\xampp\mysql\bin\mysqldump.exe --defaults-extra-file=C:\secure\umdts-backup.cnf --single-transaction --routines --triggers --result-file=C:\secure\backups\umdts.sql umdts
```

Back up private attachments to the same protected backup set. Restore into a **new empty recovery database**, with the matching key and files; do not overwrite a live database. In the MySQL client, select the recovery database and run `SOURCE C:/secure/backups/umdts.sql;`. Check login, authorized document lookup, history, decrypted contacts and private downloads before switching traffic. Test recovery regularly.

Production use still needs approved municipal branding, privacy/retention and public-disclosure decisions, validated Citizen's Charter processing targets, operational ownership and deployment review. No official seal or unverified building image is shipped. Online submissions, payments, electronic approvals, external SMS/email notifications and advanced retention automation remain outside v1.

See [implementation decisions and checklist](docs/IMPLEMENTATION.md).
