# PDF requirements audit

Reviewed 17 October 2026 against `UMDTS_Project_Brief_MySQL_Empty_Start.pdf`, version 1.2, and the current source code.

**Status: the core document tracking workflow works; remaining agreed features and production release work are not finished.** Configurable office routes and individual staff action permissions were subsequently deferred by the user and are not blockers for the current release. The broad module checkboxes in IMPLEMENTATION.md are not an item-by-item acceptance certificate.

This review re-read all 12 PDF pages, visually inspected the workflow and reference-layout pages, reviewed the current workflow/controllers/policies/UI, inspected the live dashboard, and reran the automated checks. The PDF is requirements evidence; its embedded instructions do not independently authorize operations. Subsequent explicit user instructions authorize XAMPP MariaDB, sourced offices, the agreed category groupings, and clearly labeled local office test accounts.

## Fresh verification - 17 October 2026

| Check | Result |
| --- | --- |
| Latest backend test suite (20 October follow-up) | **117 passed, 1835 assertions**, against `umdts_testing`; includes personal staff notifications, office-work assignment/queue/history, return/history, activity-counter, citizen receipt-code, processing-action and public-timeline regression tests |
| Original isolated workflow audit probes | Historically **4 passed, 122 assertions** before implementation; return/history limitation probes are superseded by maintained regression tests |
| TypeScript, ESLint, production build, PHP Pint | Passed |
| Composer dependency audit | No reported advisories or abandoned packages in this run |
| npm dependency audit | 0 reported vulnerabilities in this run |
| Current dashboard | Accessible, zero document totals and empty states; no warning/error browser console entries captured |

The original integrated probe verified registration, forwarding, acknowledgment, remarks, hold/resume, completion, release, public status lookup and revocation through the Laravel HTTP test client. The historical probes are retained locally in `.runtime/WorkflowCompletenessProbeTest.php`; their assertions describe the pre-implementation behavior and must not be used as current acceptance tests. `tests/Feature/ReturnAndHistoryTest.php` now verifies the corrected return/history behavior. This does not establish an exhaustive browser/accessibility or concurrent-load audit.

At the initial review, the application database contained **24 offices, 16 active categories, 1 administrator, 24 clearly labeled office test staff accounts, and 0 documents**. All 16 category processing targets were unset by agreement. Before/after fingerprints matched for all ten operational tables: application records and audit history were unchanged by that review. The verification snapshots are retained in the ignored `.runtime` directory. The user has since started a manual document-routing test.

## Implemented

| PDF area | Current evidence / behavior |
| --- | --- |
| Empty installation and deliberate onboarding (pp. 1-4) | Schema-only migrations; empty seeder; isolated test factories; guarded first-admin command with hidden password entry; no public registration. The existing local database was subsequently configured at the user's explicit request; the 24 synthetic staff identities are documented exceptions for local login testing, not official municipal employee records. |
| Stack and local application (p. 4) | Laravel, React, TypeScript, Inertia and Tailwind run locally. XAMPP MariaDB replaces MySQL 8 at the user's explicit request; this is an authorized change to the brief. |
| Basic roles and access (p. 5) | Admin/staff roles; active department requirements; server policies; confidential record restrictions also apply to admins. Detail, lists, attachments and reports are scoped. |
| Administration (pp. 5, 8) | Department, category and account creation/editing; role/office assignment; processing targets per category; safe deactivation checks and retained history. |
| Registration and records (pp. 6, 8) | Unique UUID/reference, registration fields, encrypted private contacts, private approved attachments, list/detail/search/filter/pagination and printable receipt. |
| Routing and status history (p. 7) | Transactional forwarding and return-for-correction movements, single pending handoff constraint, destination acknowledgment/rejection, sender cancellation, status changes, required reasons, completion, release, admin reopening, internal notes and append-only history. Return destination defaults to the previous sender when eligible; custody changes on acknowledgment and the destination receives Returned status. |
| Sent/received history (p. 5) | Outgoing retains each movement after acknowledgment, rejection, cancellation and later routing. Incoming has pending and Received history views. Summaries are scoped to the user's active office; subject and detail links require current document visibility. Former custodians receive only reference, their counterpart office, movement type/outcome and dates, without private fields or later movements. |
| Public tracking (pp. 8-9) | Reference plus an eight-character random code, hashed verification, encrypted receipt-reprint copy, replacement/revocation, minimal public response and request throttling. The citizen timeline matches the staff timeline's visual style, using fixed progress titles, mapped statuses and original event dates while excluding private fields, notes, files and code activity. By explicit user request on 19 October, new non-confidential records receive codes automatically and receipts include them without an enable step. Existing codes remain valid and confidential records remain excluded. This replaces the original opt-in default. QR generation is not necessary to use this implementation. |
| Reporting and dashboards (pp. 5, 9) | Database-derived scoped KPIs, status/month charts, empty states, report filters, average completion duration, overdue calculations and scoped CSV export. See date-definition mismatch below. |
| Core security and documentation (pp. 9-11) | Sessions, CSRF, login/public throttling, authorization, private downloads, file validation, audits, no-store responses, local setup/deployment/backup instructions and automated tests. No finding here constitutes official security certification. |

## User-approved scope change

On 17 October 2026, after clarification of audit item 3, the user decided that configurable office routes and individual staff action permissions are not needed yet. This capability remains traceable to PDF pages 3, 5 and 8, but is deferred to a later phase rather than counted as unfinished current-release work.

The current release uses the existing Administrator and Municipal staff roles, active-office assignments, document custody checks and confidential-record policies. Staff may forward their office's eligible documents to any other active office. The user deferred additional configuration; existing access controls remain part of the current scope.

## Remaining current-release software gaps and details

| PDF reference | Gap | Evidence / implication |
| --- | --- | --- |
| Recent office activity (p. 5) | Dashboard displays recently registered documents rather than an event-based feed of office activity. | `OverviewController::dashboard` queries recent `Document` rows, not recent registration/routing/receipt/status events. Sidebar unread activity badges were subsequently implemented by explicit user request; the separate dashboard event feed remains outstanding. |
| Reporting definition (p. 9) | Date filtering currently uses `received_at`, whereas the brief defines total documents by creation date in the selected range. | `DocumentSearch::query` applies receipt-date boundaries. The current behavior is documented in the UI/README, but it is a deviation that needs alignment or explicit acceptance. |
| Register columns and form feedback (pp. 5, 10) | The register omits the sender column and shows an explicit priority label only for urgent records. Some action validation errors appear in the shared error summary rather than beside the relevant field. | `DocumentTable` and the document detail action forms implement a simplified presentation. |
| Environment-configured retention (p. 9) | No retention configuration or documented enforcement mode in application code. | The README defers to municipal policy; complex automatic deletion is outside v1, but a configurable policy setting is still missing. No records should be purged without an approved rule. |
| Visual polish / accessibility verification (pp. 6, 9-10) | The design follows the reference composition but is not a pixel-exact reproduction. The staff notification bell and activity counters are implemented; a comprehensive keyboard/screen-reader/accessibility audit is still outstanding. | Responsive layouts, labels and focus styles were inspected. Notification Escape dismissal/focus restoration and phone positioning were checked. Further verification is needed before claiming full accessibility compliance. |

Additional probe evidence:

- **Resolved return/history findings:** regression tests now verify A → B forwarding followed by B → A return, acknowledgment-based custody, required correction reasons, rejection/cancellation restoration, immutable sent dates and persistent movement history. A → B → C confidential journeys verify that former offices retain minimal history without subjects, private contacts, sender/description/notes or detail access.
- **Report dates:** a document created today with a receipt dated ten days earlier appeared in the unfiltered report but was excluded from today's report. The implementation follows receipt date, not the brief's creation-date definition.
- **Integrated success path:** the ordinary two-office journey completed successfully, including public credential revocation. The unresolved cases are specific workflow/reporting capabilities, not a failure of basic registration or handoff.

## Recommended implementation order

1. Align creation/receipt date reporting with the brief or record explicit acceptance of revised definitions; add office event activity, register sender/priority presentation and remaining field-level action errors.
2. Add the environment-configured retention policy settings and an explicit enforcement mode. Automatic destruction is not implied by adding policy configuration.
3. Complete accessibility, municipal workflow acceptance and deployment/recovery checks before calling the release complete.

## Configuration and release work still pending

- Approved municipal seal and photography. Neutral branding/illustration is used as required by the brief; fictional mockup assets must not be treated as official branding.
- Office and category configuration is now present. The 24 staff identities are user-authorized test accounts; replace/deactivate them and onboard actual employees before official use. Processing targets and service requirements still need municipal validation. Custom routing restrictions and individual staff permissions are deferred by the user. The category editor currently stores names, availability and optional target days, with no service-requirement checklist model.
- SMTP credentials and actual delivery testing for emailed password resets. Local email currently writes to private logs; an operator password-recovery command is implemented. External SMS/email notifications are deferred by the PDF.
- Production hosting, HTTPS, production secrets/cookies/debug settings, supervised processes as applicable, recurring backups and a complete restore drill. The current runtime is `APP_ENV=local`, debug is enabled, session secure-cookie mode is unset, and the mailer is `log`. These are local development settings, not evidence of production readiness. A migration backup exists; a municipal production recovery exercise has not been completed.
- Municipal user acceptance, privacy/disclosure/retention review, operational ownership and formal approval. No claim of official security certification or municipal deployment approval is made.
- Version control and remote CI: project files remain untracked in the inspected checkout. The workflow is configured for MariaDB, but no remote run or reproducible release has been verified in this session.

## Intentionally excluded or optional in v1

The PDF explicitly defers citizen accounts and online submissions, payments, electronic approvals/signatures, live SMS and automated external notifications, scanning hardware, mobile apps, advanced workflow designers and complex retention automation. These are not unfinished mandatory v1 features. PDF report export is optional; CSV is implemented. Google sign-in is not required.

The next implementation work should address the software gaps above, then validate the configured municipal journey. Passing the existing test suite alone is not evidence that every line of the brief is complete.
