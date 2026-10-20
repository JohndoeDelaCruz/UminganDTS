# Document categories

The user approved these 16 UMDTS category groupings on 17 October 2026. They were added to the XAMPP `umdts` database as active document categories, with all processing targets left unset.

| Code | Category | Examples |
| --- | --- | --- |
| CORR | General correspondence | Letters, invitations, general requests |
| MEMO | Memorandum / Office order | Internal instructions and issuances |
| ENDORSE | Endorsement / Referral | Documents forwarded for action |
| CERT | Certificate / Clearance request | Requests for certifications and clearances |
| PERMIT | Permit / License application | Permit applications and renewals |
| CIVIL | Civil registry transaction | Birth, marriage, death, correction and copy requests |
| PROC | Procurement / Supply | Purchase requests, quotations, purchase orders |
| FIN | Financial / Payment | Disbursement vouchers, reimbursements, billing |
| BUDGET | Budget / Appropriation | Funding requests and budget documents |
| HR | Personnel / HR | Leave applications, personnel requests, employment records |
| LEGIS | Ordinance / Resolution | Legislative proposals and approved issuances |
| CONTRACT | Contract / Agreement | Contracts, MOAs and MOUs |
| REPORT | Plan / Project / Report | Project proposals, plans and accomplishment reports |
| SERVICE | Service / Assistance request | Requests for municipal services or assistance |
| COMPLAINT | Complaint / Feedback | Complaints, grievances and suggestions |
| OTHER | Other documents | Documents requiring later classification |

These are application groupings selected by the user, informed by Umingan's [Citizen's Charter](https://elgu-umingan-pangasinan-news.e.gov.ph/citizen-charter). They are not represented as an official municipal classification list. The published [Civil Registry charter](https://drive.google.com/drive/folders/13lpNORGWI-cCcTrzF6YLxVvE3WkqD1-7) describes civil registry transactions; the [GSO charter](https://drive.google.com/drive/folders/1YKEd5X1IQaUdj-XhQRenvwxUFG9oH8iR) describes procurement documents.

Choose the most specific category when registering a document. A marriage-license application belongs under Civil registry transaction. Select the receiving office separately. Other documents is a fallback for records requiring later classification.

The system currently allows one calendar-day processing target per category. Different services within these broad categories have different timelines, including times stated in minutes or hours. No response times were guessed or rounded into category defaults. Unset targets create no assumed deadline; validated targets can be configured later in **Administration → Document categories**.

The reviewed list is preserved in `resources/data/document-categories.json`. This was an explicit configuration operation; migrations and default seeding still create no operational data. Each added category has an append-only `document_type.imported` operator audit event with its code, name, approval basis, reference URLs, manifest hash and null processing target. The before-import snapshot and verification result are retained in the ignored `.runtime` directory.
