# Developer Readiness Checklist - Resources Analytics

Use this checklist before development starts and during implementation reviews.

## 1) Product Decisions (Required)

1. MVP scope is approved:
   - Search, sharing, engagement, segment/geography slicing, exports.
2. Non-goals are approved.
3. Success criteria are defined:
   - Example: improve zero-result visibility, understand top needs, sharing channel adoption.

## 2) Path And Segment Rules (Required)

1. Confirm staff path matching logic:
   - Includes `master-resource-list` anywhere in path.
2. Confirm volunteer path matching logic:
   - `/district-resources` and descendants excluding staff path.
3. Confirm partner path prefix:
   - Provide exact path now, even if rollout is later.
4. Confirm fallback behavior:
   - unmatched paths become `unknown`.

## 3) Geography Governance (Required)

1. Confirm initial geography seed list.
2. Confirm display label format:
   - Hyphen style, title casing, any abbreviations.
3. Assign owner for geography changes.
4. Define policy:
   - Add, disable, rename, remove.
5. Decide whether auto-discovery is enabled by default.
6. Decide discovery cadence:
   - Manual only, daily, or on publish/update hook.

## 4) Metrics And Thresholds (Required)

1. Define low-result threshold (default proposal: <= 3).
2. Define what counts as a search:
   - All filter requests vs query-text-only requests.
3. Define reporting windows:
   - Default 30 days? Week starts Monday or Sunday?
4. Define trend comparison basis:
   - Prior period of equal length.

## 5) Privacy And Compliance (Required)

1. Confirm prohibited fields in analytics:
   - Raw email, raw phone, neighbor name, free-form notes with PII.
2. Confirm query sanitization rules.
3. Confirm retention:
   - Raw events retention months.
   - Rollup retention period.
4. Confirm data deletion process for compliance requests (if needed).

## 6) Access Control (Required)

1. Confirm who can view dashboard.
2. Confirm who can export analytics.
3. Confirm who can manage geography registry.
4. Confirm if capability differs by environment.

## 7) UX Requirements (Required)

1. Dashboard sections are agreed.
2. Required visualizations are agreed.
3. Geography summary table columns are agreed.
4. Empty state and no-data behavior is agreed.
5. Performance expectation for dashboard load is agreed.

## 8) Export Requirements (Required)

1. Confirm CSV column sets.
2. Confirm XLSX sheet names and required fields.
3. Confirm PDF audience and layout:
   - Executive summary vs operational detail.
4. Confirm timezone/date formatting in exports.

## 9) Engineering Readiness (Required)

1. Migration process approved.
2. Rollback strategy approved.
3. Feature flag strategy decided.
4. Monitoring and logging plan approved.
5. Backfill plan approved:
   - Start date and rebuild logic.

## 10) QA/UAT Readiness (Required)

1. Test pages identified for each segment path.
2. Test pages identified for each geography grouping.
3. Known test searches with expected results documented.
4. Snapshot channel test setup ready:
   - Email deliverability test.
   - Twilio test credentials and test phone numbers.
5. Export validation script/checklist prepared.

## 11) Environment And Access (Required)

1. Staging environment mirrors production data shape.
2. WP admin access granted to developers and QA.
3. DB inspection access available for verification.
4. Mail and SMS integrations available or safely mocked.
5. Cron execution path confirmed in environment.

## 12) Launch Controls (Required)

1. Go-live date and freeze window set.
2. Feature enable sequence defined:
   - Capture -> dashboard -> exports.
3. Post-launch ownership assigned:
   - Incident response
   - Metric quality checks
   - Geography updates

## 13) Sign-Off Template

Use this section to capture formal approvals.

1. Product owner sign-off: ___
2. Engineering lead sign-off: ___
3. Data/privacy sign-off: ___
4. Operations sign-off: ___
5. QA lead sign-off: ___

