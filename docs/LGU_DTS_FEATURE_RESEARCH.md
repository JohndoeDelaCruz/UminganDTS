# LGU document tracking comparison and proposed additions

Reviewed 19 October 2026 for Umingan DTS. This is a sourced enhancement proposal, separate from the implemented-feature checklist and the remaining PDF requirements audit. No application behavior or operational records were changed during this research.

The strongest first addition identified was an **Office work** module: an assigned handler, a personal work queue and recorded processing steps within the current office. This recommendation is engineering judgment based on the references and the current code; the references do not establish Umingan's exact internal procedures. The user subsequently approved this first increment, which is now implemented without service checklists. The comparison below records the system at research time; the later additions remain proposals.

Implementation update, 20 October 2026: the user also approved a compact staff notification bell. Personal assignment, incoming-handoff and return-for-correction alerts are implemented with independent read state and authorized document links. Notifications retire when their task is resolved; they do not perform workflow actions. Recent office activity, time-in-office reports and the other later additions below remain proposals.

## What the references establish

| Reference | Evidence reviewed | How to use it |
| --- | --- | --- |
| [Surigao City DMSS: All Documents On-Process](https://surigaocity.gov.ph/system-manual/alldocuments.html) | The official manual includes an accountable person, office receipt date, process-finish date, process description and a Mine Only filter. | Direct evidence for handler responsibility and personal work views in an LGU system. |
| [Surigao City DMSS: Process](https://surigaocity.gov.ph/system-manual/process.html) | Staff name a process, start and finish it, optionally attach documents or request a return, then proceed to Bring Out. | Supports separating work performed inside an office from subsequent document movement. |
| [Surigao City DMSS: Office Documents](https://surigaocity.gov.ph/system-manual/officedocuments.html) | The manual documents physical filing locations, searchable records, printable registered lists and QR stickers. | Supports connecting the digital record to the actual paper file. These are documented features, not an independent usability or security assessment. |
| [Quezon City procurement specifications, PDF page 140](https://quezoncity.gov.ph/wp-content/uploads/2023/10/14.-OCMCAO-23-IT-1620-FINAL.pdf#page=140) | Requirements include document versioning, notifications/reminders, processing-time indicators and backlog/aging reports. [PDF page 144](https://quezoncity.gov.ph/wp-content/uploads/2023/10/14.-OCMCAO-23-IT-1620-FINAL.pdf#page=144) also specifies evaluation checklists in the business-application evaluation modules. | These are official procurement requirements, not proof that every feature has been deployed. The checklist context is a specific service, not all municipal correspondence. |
| [SLSU report on LGU Bontoc deployment](https://southernleytestateu.edu.ph/index.php/en/joomla/content/list-all-category/182-extension-activities/4055-digital-innovation-for-local-governance-slsu-enhances-lgu-bontoc-operations-with-document-tracking-system) | The university reports a student-developed DTS used by the Bontoc municipal office for recording and monitoring document transactions. | Evidence that a focused tracking application can be used operationally. The article does not establish detailed assignment, checklist or archive functionality. |
| [jkleorenz/lgu-document-tracking](https://github.com/jkleorenz/lgu-document-tracking) | Read the README and actual [routes](https://github.com/jkleorenz/lgu-document-tracking/blob/main/routes/web.php), [notification controller](https://github.com/jkleorenz/lgu-document-tracking/blob/main/app/Http/Controllers/NotificationController.php), [archive controller](https://github.com/jkleorenz/lgu-document-tracking/blob/main/app/Http/Controllers/ArchiveController.php) and [scanner controller](https://github.com/jkleorenz/lgu-document-tracking/blob/main/app/Http/Controllers/ScanController.php). The code contains notifications/read state, archive retrieval and QR processing. | Implementation reference only. It was not installed or executed, and official LGU adoption was not established. Its custody and access behavior should not replace Umingan DTS policies. |
| [BastiDood/doc-track](https://github.com/BastiDood/doc-track) | The README describes notifications, QR scanning, office/staff metrics and offline operations. It identifies the work as a UP Diliman software-engineering course project; the repository was archived when reviewed. | Design reference, not evidence of a deployed LGU service or a recommendation to replace the current stack. |

## Comparison with Umingan DTS before the Office work increment

The local comparison used the current workflow service, document/status models, controllers, migrations, React pages and requirements audit.

| Capability | Current system | Recommendation |
| --- | --- | --- |
| Office-to-office tracking, acknowledgment, returns and permanent history | Implemented, including pending handoff custody and historical sent/received lists. | Retain the existing behavior. |
| Attachments, citizen receipt codes and public progress | Implemented, with private file downloads and a restricted public projection. | Retain; do not broaden public disclosure. |
| Accountable handler and My work | Offices and action actors are recorded, but there is no persistent assigned handler or personal assigned-work queue. | **First:** add responsibility within the current office. |
| Office processing steps | Start/hold/complete actions currently operate on the overall document status. No separate office-work record exists. | **First:** record tasks/review outcomes and local start/finish times separately from final completion. |
| Review checklists | Categories have optional target days, but no service requirements model. | Add optional templates for specific services alongside office work; ordinary correspondence can remain checklist-free. |
| Actionable notifications | Sidebar counters indicate unseen activity; no document notification inbox exists. | **Next:** assignment, return and follow-up notices with direct links. Keep email/SMS outside this increment. |
| Office aging and bottlenecks | Overall targets, overdue counts and reports exist. No detailed per-office residence-time/workload view exists. | **Next:** display how long each record has been in its current office and aggregate processing times. |
| QR lookup and tracking forms | Receipts/reference search exist, but no document QR generation or scanner workflow. | **Later:** printable staff QR labels and a printable movement sheet. Scanning opens the authorized record; receipt remains an explicit action. |
| Attachment revisions | Multiple private uploads are retained, but there is no explicit relationship between an original file and its corrected versions. | **Later:** label revisions, identify the current version and preserve earlier copies. |
| Searchable archive and paper filing location | Terminal records remain searchable through current document views; no archive flag or cabinet/folder location model exists. | **Later:** archive filtering and optional physical filing locations. Archiving preserves records and history. |
| Batch handoffs | Handoffs are individual. | Optional after the core additions, for offices that move bundles; retain validation and audit history for every document. [Surigao's batch manual](https://surigaocity.gov.ph/system-manual/batchtransact.html) is a reference. |

These priorities describe usefulness and dependencies, not a claim that every referenced feature is mandatory for the original brief.

## First increment: Office work

Proposed staff actions:

1. Assign or claim an eligible document within the current office.
2. Start an office task, such as checking requirements or preparing a response.
3. Record a review result. Missing requirements can use the existing hold or return-for-correction workflow.
4. Finish the office's task, with the time and actor recorded automatically.
5. Forward the document when that office's work is done, or use final completion/release when the entire case is finished.

An illustrative journey is Accounting receives a document, assigns a reviewer, checks supporting papers, records its review as done and forwards it to Treasury. This is a proposed interaction pattern, not a verified Umingan finance procedure.

Key implementation boundaries:

- **Office work finished is separate from document completed.** Finishing one office's task must allow forwarding. The current final Completed state remains part of the overall completion/release workflow.
- Assignment records responsibility; it does not introduce new individual permissions. Existing role, custody, active-office and confidentiality rules continue to apply.
- Keep each office visit's tasks and handler changes in history. A later return or another visit must not overwrite the earlier work.
- Block incompatible work while a handoff is pending, using the existing transactional guards.
- Configure checklists per service/request type, not one compulsory checklist for every record in a broad category such as Financial / Payment. Do not invent Umingan's required documents or target periods.
- Office task names, review notes and handler names remain internal. Public tracking continues to use fixed safe progress labels.

## Suggested delivery order

1. **Office work:** handler, My Work and local task/review actions are implemented. Optional service checklists are deferred until actual service requirements are confirmed.
2. **Follow-up:** actionable in-app notifications, recent office activity and time-in-office reporting. Only mark deadlines overdue when a configured target exists; show elapsed time otherwise.
3. **Paper and correction support:** staff QR lookup, movement-sheet printing, attachment revisions and physical filing/archive tools.

Keep general chat, social feeds, staff activity scoring, AI document approval and offline custody-changing synchronization out of the near-term plan. These are not needed to deliver the above office-work flow. Advanced routing/individual permissions remain deferred as previously requested. Record retention/disposal configuration and the existing PDF/reporting/deployment gaps remain separate work items in the [requirements audit](PDF_REQUIREMENTS_AUDIT.md).

The first increment gives staff useful processing actions while reusing the existing custody, return, timeline and public-tracking foundations.
