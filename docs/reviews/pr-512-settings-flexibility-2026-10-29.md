# PR #512: switches, upgrades and verification

Update of 29 October 2026 on the #503–#512 stack (#502 excluded from this review). The eleven modules now have **18 saved controls**: ten in **Settings → Modules** and eight in **Settings → Loans**. The new controls reuse the labels, descriptions, accessibility and styling of the existing switches.

## Controls

| Where | Control | Behaviour |
| --- | --- | --- |
| Modules → Users | Card printing | Enables single and sheet PDFs. Print settings can be prepared while the module is off. |
| Modules → Users | Patron photos | Capture, display, change and deletion, independent of printing. Off, it also blocks the direct URLs and keeps photos out of the PDFs; stored files are kept. |
| Modules → Public services | Visitor log | Enables recording new visits. |
| Modules → Public services | Retention while the log is suspended | Applies retention while recording is off, also in catalogue mode. Starts off; the upgrade never starts a deletion. |
| Modules → Public services | Retention days | Editable while the log is off. `0` always prevents automatic deletion. |
| Loans → Patron rules | Patron type rules | Patron types, rules matrix, duration and limits per patron and material. Administrators can prepare the configuration while the module is off. |
| Loans → Patron rules | Consultation-only copies | Enforces the physical restriction even with patron type rules off, at the desk, on the site, in the app, in SQL allocation and integrations. Switching it recalculates the availability of the affected books at once. |
| Loans → Patron rules | Automatic card validity | Applies the patron type's duration to new registrations and approvals. Off, the stored expiry is kept; manual renewal stays available. |
| Loans → Patron rules | Card renewal base | Today (the previous behaviour) or a future existing expiry. An expiry already past starts again from today. |
| Loans → Calendar | Closures calendar | Suspends the calendar without deleting it. Administrators can prepare closures and holidays and preview them while it is off. Any preview year from 1900 to 2199. |
| Loans → Fines | Patron ledger | Manual charges, payments, receipts, waivers and balance. It can run with all three automations below off. |
| Loans → Fines | Automatic overdue fines | Requires the ledger, its own switch and a positive rate. Patron type and material rates cannot bypass it. |
| Loans → Fines | Debt block | Requires the ledger, its own switch and a positive threshold. It works without automatic fines. The reader record and the Mobile API show the block only when it is actually in force. |
| Loans → Fines | Mirror loss and damage penalties | Mirrors amounts already assessed, older ones included. The form shows how many amounts and the total. Switching it on reopens the idempotent scan. |
| Loans → Fines | Grace mode | Threshold, as before: past the grace period every day counts from the first. Or waiver: the grace days are always subtracted. |

The other controls stay in their existing sections: stocktake, MARC (UNIMARC import/export included), catalogue cards, suppliers, RDA types and CSV import. Stocktake without declaring losses, MARC duplicate handling, email invitations, optional fields and card contents keep requiring their own explicit choices.

Next to the inheritable fields the form explains what empty and zero mean: renewals forbidden, no limit, no cap, no grace, or disabled for that patron type. The rules summary and the inherited values of the matrix stay available.

## Upgrade guarantee

**If fines were not in use, the upgrade does not introduce them.**

- Each new split control inherits its former parent only while its preference is absent. A saved value, `0` included, is kept.
- New controls absent and ledger previously off: automatic fines, debt block and penalty mirroring are saved off, even with positive rates, configured patron types or older penalties.
- Switching on only the ledger later leaves the automations off. Rates and thresholds stay stored for a later explicit choice.
- The migration mirrors no penalty while the ledger or its control is off. Older amounts stay in `prestiti.sanzione`; existing movements are kept.
- Old save forms that do not post the new fields keep the preferences. Before the former parent changes, the inherited choices are frozen, so they do not follow a later re-activation by accident.
- Catalogue mode suspends circulation and keeps the saved switches. Deleting visits while the log is suspended needs its own explicit switch.
- The new grace and renewal options default to the previous behaviour. Existing loans keep their due date.

The switches and the new service rules are reserved to administrators and roles that may configure. Configuration access to a module that is off keeps authentication, CSRF and the role check; the operational routes stay gated.

## Verification

- **325 standalone PHP suites passed, 0 skipped, 0 failed**, on an isolated temporary MySQL database.
- **37 integration scenarios** in [`tests/stack-090-settings-flexibility.unit.php`](../../tests/stack-090-settings-flexibility.unit.php): the 25 requested, the upgrade regression and 11 more on the new controls.
- The full migration repeated with modules on, off, mixed, in catalogue mode and on the real 0.8.9 schema. The fixture is in the repository, so the check is mandatory in checkouts without history.
- A dedicated test with the ledger off, positive global and typed rates and older penalties: two migrations produce no charge and no re-activation. Switching on only the ledger then leaves the automations off.
- PHPStan 2.1.56 with the repository configuration: no errors.
- Migration, schema and plugin self-heal gates: passed.
- Registry of the 18 controls, form placement, route gates, seeds of the five locales and translations: verified.
- **25 browser tests passed**: saving the controls, keyboard, permissions, configuration while off, calendar, independent photos and consultation.
- Also **40 browser tests** on fines, typed/material rates and photos, and **22** on cards and patron rules, including the match between the confirmation date and renewal from a future expiry.
- Visual check at 1280 px and 390 px: no horizontal overflow in the new settings groups.

The previous PR description left the start of San Francesco open: the 4 October rule from 2026 is confirmed by the entry into force on 1 January 2026 of [law 151/2025, Gazzetta Ufficiale](https://www.gazzettaufficiale.it/eli/id/2025/10/10/25G00153/sg). The tests already tell 2025 from 2026.

The OpenAPI document also describes the three new capability flags (`automatic_fines`, `debt_block`, `consultation_only`) and states that the effective debt threshold is null while the block is off.

## CI on the switch changes: every workflow passed

Functional commit verified: `d9f36316b1ef39f409b5c3eff69e4c5b49613147`. All ten runs below succeeded. The commits right after it only update this report and the OpenAPI description of the flags; the full API document was checked as valid JSON.

| Workflow | Result | Run |
| --- | --- | --- |
| Code Quality | Passed | [40852924644](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40852924644) |
| Database Compatibility | Passed | [40852926691](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40852926691) |
| E2E Test Suite | Passed | [40852928999](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40852928999) |
| Test Database Migrations | Passed | [40852931140](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40852931140) |
| Real Upgrade (admin UI) | Passed | [40852933844](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40852933844) |
| Upgrade Smoke Test | Passed | [40852936293](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40852936293) |
| Deep Regression Gate | Passed | [40852939135](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40852939135) |
| Browser Compatibility and DAST | Passed | [40852941339](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40852941339) |
| Security and Supply Chain | Passed | [40852943720](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40852943720) |
| CodeQL Security Analysis | Passed | [40852947181](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40852947181) |

The database matrix passed all 325 suites on MySQL 8.0/8.4 and MariaDB 10.11/11.4. The 0.9.0 E2E passed **230 circulation** and **253 cataloguing tests**, besides the general application suite. The deep regression passed **4/4 browser groups** and **5/5 localised installs**.

## Workflows verified before this extension

The results below refer only to the commits named; they are not claimed for later changes.

| Workflow on the fixes `3960c194` | Result | Run |
| --- | --- | --- |
| Code Quality | Passed | [40830554173](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830554173) |
| E2E Test Suite | Passed | [40830557203](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830557203) |
| Browser Compatibility and DAST | Passed | [40830559955](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830559955) |
| Deep Regression Gate | Passed | [40830562699](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830562699) |
| Database Compatibility | Passed | [40830565681](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830565681) |
| Security and Supply Chain | Passed | [40830568665](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830568665) |
| Real Upgrade | Passed | [40830571304](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830571304) |
| Upgrade Smoke Test | Passed | [40830574075](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830574075) |
| Test Database Migrations | Passed | [40830576583](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830576583) |
| CodeQL | Passed | [40830579689](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40830579689) |

The earlier 26-scenario suite, commit `d72fa1d4`, passed [Code Quality](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40844504696) and the [MySQL 8.0/8.4 and MariaDB 10.11/11.4 matrix](https://github.com/fabiodalez-dev/Pinakes/actions/runs/40844508132).

## Further product options

Two proposals that widen the calendar model are still open: one-off openings by date and branch, and holiday presets inherited per branch. A summary of the pickups affected before new closures are applied, and editable starting profiles, are also still proposals. They need their own implementation and verification.
