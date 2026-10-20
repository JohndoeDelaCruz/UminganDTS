# Local office test accounts

On 17 October 2026, the user explicitly requested working staff accounts without real employee names so they could try logging in to every office. This authorizes these clearly labeled test identities in the local application database.

One active **Municipal staff** account was created for each of the 24 configured active offices. Display names follow `Test Staff - <office name>`. Login identifiers follow `<lowercase office code>.staff@umdts.test`, for example `mayor.staff@umdts.test`, `accounting.staff@umdts.test`, and `mho-rhu1.staff@umdts.test`. These reserved test-domain identifiers do not represent employee email addresses.

Each account is assigned only to its corresponding office and has a unique randomly generated initial password. Password hashes are stored by the normal User model. The account creation events are recorded as `user.test_staff_created`, with an operator actor, office code and synthetic-identity purpose. No administrator privileges were granted and the existing administrator was preserved.

The private login list is stored outside the web workspace under `%LOCALAPPDATA%\UMDTS\staff-logins\<setup-folder>\staff-logins.txt`. Its folder permits access only to the current Windows user and SYSTEM. Passwords are not included in source files, audit metadata or this document. The same private folder contains the machine-readable login list and verification results.

Log in at `http://127.0.0.1:8000/login`. Sign out before trying another account. Each office account can register documents and process its office's records; it cannot manage staff accounts or administrative configuration. Replace or deactivate these test identities when onboarding real municipal personnel.

This explicit local setup does not change migrations, the empty default seeder, or the guards on test factories. No document records were created by this setup.

All 24 accounts passed actual HTTP sign-in, assigned-office validation, access to the document registration page, denial of administrator account management, and sign-out checks. Verification used separate temporary HTTP sessions and created no document records. A private verification report is saved beside the login list.
