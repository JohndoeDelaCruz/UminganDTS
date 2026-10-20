# Umingan departments and offices

Verified on **17 October 2026**, in response to the user's explicit request to research Umingan LGU and add departments and offices.

## Primary sources

- [Umingan Directory of Offices / Departments](https://elgu-umingan-pangasinan-news.e.gov.ph/directory): 30 published directory entries, including external agencies and service providers.
- [LGU Umingan Citizen's Charter](https://elgu-umingan-pangasinan-news.e.gov.ph/citizen-charter): 21 office/service-unit cards, linking to publicly shared charter folders.
- [RHU I charter folder](https://drive.google.com/drive/folders/1yHAF4VaYwD_CQS5ZmJjiuPbyJOH-3IyX): `RHU I.docx`, page 1, explicitly identifies both **Rural Health Unit I** and **Municipal Health Office** in its heading. They share one routing destination below.

The e-government pages loaded in a normal browser even though direct HTTP downloads returned 403. Names were checked against the rendered pages. The source pages do not establish an effective date for every entry; verification date means the date checked, rather than an assertion that each charter was revised in 2026.

## Imported local offices

Codes are UMDTS configuration identifiers, not a claim that every code is an officially adopted LGU abbreviation. `D + C` means the directory and charter both identify the office; `D` means the directory identifies it.

| Code | Routing destination | Source |
| --- | --- | --- |
| MAYOR | Office of the Mayor | D + C |
| SB | Sangguniang Bayan Office | D + C |
| HRMO | Human Resource and Management Office | D + C |
| MEO | Municipal Engineering Office | D + C |
| GSO | General Services Office | D + C |
| MPDO | Municipal Planning and Development Office | D + C |
| LIBRARY | Municipal Library | D + C |
| MCR | Municipal Civil Registry | D + C |
| ACCOUNTING | Municipal Accounting Office | D + C |
| MBO | Municipal Budget Office | D + C |
| MSWDO | Municipal Social Welfare and Development Office | D + C |
| MTO | Municipal Treasurer's Office | D + C |
| LDRRMO | Local Disaster Risk Reduction and Management Office | D + C |
| ASSESSOR | Municipal Assessor's Office | D + C |
| ADMIN | Office of the Municipal Administrator | D |
| MCDO | Municipal Cooperative Development Office | D + C |
| AGRICULTURE | Municipal Agriculture Office | D + C |
| MENRO | Municipal Environment and Natural Resources Office | D + C |
| MARKET | Market Management Office | D + C |
| MHO-RHU1 | Municipal Health Office / Rural Health Unit I | D + C |
| RHU2 | Rural Health Unit II | D + C |
| SLAUGHTERHOUSE | Slaughterhouse | D + C |
| BARANGAY | Office of the Barangay Affairs | D |
| MNAO | Municipal Nutrition Action Office | D |

## Naming and scope decisions

- The directory's full names take precedence over shortened charter card labels. For example, `Cooperative Office` maps to `Municipal Cooperative Development Office`, and `Public Market` maps to `Market Management Office`.
- The directory publishes `Human Resource and Management Office`; this spelling is retained. `Human Resource Management Office` is a recognized import alias.
- The charter uses `LDRRMO`, while the directory displays `Municipal Disaster Risk and Reduction Office`. The imported name expands LDRRMO to the standard **Local Disaster Risk Reduction and Management Office**; both published variants and MDRRMO are recognized aliases. This is a naming normalization, not an additional office.
- `Municipal Health Office` and `Rural Health Unit I` are consolidated based on the RHU I charter heading. RHU II remains a separate destination. The directory calls it `Rural Health Office II`; the charter calls it `Rural Health Unit II`.
- Dental and other services described inside a health-office charter are not automatically promoted to independent departments. Likewise, service counters and programs do not establish a separate routing office.
- Six additional directory entries are excluded from this local-office import: **4Ps Office**, **DILG Umingan Office**, **Philippine National Police - Umingan Station**, **Bureau of Fire Protection - Umingan Station**, **Umingan Community Hospital (Medicare)**, and **Pangasinan III Electric Cooperative (PANELCO III)**. These are listed separately as national-program, national-agency, hospital or utility contacts; listing them in a town directory does not establish a municipal department. They may be onboarded separately if external routing is approved.
- No standalone BPLO, PESO, OSCA, PDAO, municipal tourism office or municipal legal office is inferred from generic LGU templates. Additional offices can be added when an Umingan-specific source verifies them.

This is a sourced configuration list, not a certified organizational chart. The current schema treats a department, office and service unit as a routing destination and does not model reporting hierarchy.

## Import and audit

The reviewed manifest is `resources/data/umingan-offices.json`. Preview with `php artisan umdts:import-umingan-offices`; explicitly apply with `php artisan umdts:import-umingan-offices --apply`.

The command preserves existing records, including inactive offices. It recognizes naming aliases, refuses ambiguous duplicates or code conflicts, and adds only missing destinations in a transaction. Every inserted office receives an append-only `department.imported` audit event with the source URLs, verification date, name, code and manifest SHA-256. The actor is the system operator, with no staff account impersonation.

No staff accounts, personal contact details, document categories, processing targets or documents are imported. Migrations and default seeding remain empty of office data.

## Workstation result

The explicit import was applied to the XAMPP `umdts` database on 17 October 2026: **24 active offices** and **24 source-linked import audit events**. The existing administrator count stayed at 1; document and category counts stayed at 0. A second preview found no missing offices. Before-import configuration and post-import verification snapshots are retained in the ignored local `.runtime` directory.

Validation passed: 53 automated tests with 346 assertions, TypeScript, ESLint, PHP Pint, and the production asset build. The local application responds on port 8000.
