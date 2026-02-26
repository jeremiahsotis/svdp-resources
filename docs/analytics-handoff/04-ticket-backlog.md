# Resources Analytics Ticket Backlog

This backlog is structured for sprint planning. Each story includes acceptance criteria and definition of done.

## Epic A: Schema And Core Analytics Services

### Story A1: Add analytics database tables and migration path

Scope:

1. Add analytics tables and indexes.
2. Update DB schema version and migration flow.
3. Seed initial geography list.

Acceptance criteria:

1. Fresh install creates all analytics tables.
2. Existing install migrates safely without data loss.
3. Re-running migration is idempotent.

Definition of done:

1. Code merged with migration tests/manual verification.
2. Migration notes documented.

### Story A2: Build analytics service class

Scope:

1. Event writer API.
2. Segment resolver.
3. Query sanitizer/hash helper.
4. Geography tagging helper.

Acceptance criteria:

1. Service can write event with optional dimensions.
2. Segment classification follows approved rules.
3. Query text is sanitized before persistence.

Definition of done:

1. Service used by at least one integration point.
2. Basic unit tests or deterministic manual checks completed.

## Epic B: Geography Registry And Discovery

### Story B1: Create geography registry admin UI

Scope:

1. List geographies with status and order.
2. Add manual geography.
3. Disable/enable geography.
4. Reorder geographies.

Acceptance criteria:

1. Admin can add and disable without DB edits.
2. Disabled geographies remain available for historical reporting joins.

Definition of done:

1. Capability and nonce checks in place.
2. UI validated on desktop and mobile admin.

### Story B2: Implement shortcode geography discovery

Scope:

1. Parse shortcode `geography` from content.
2. Upsert registry and mapping table.
3. Add "Run discovery now" action.
4. Optional auto-discovery scheduler.

Acceptance criteria:

1. New shortcode geography appears in registry after discovery run.
2. Mapping table links geography to source post/path.

Definition of done:

1. Discovery is idempotent.
2. Error handling and admin notice implemented.

## Epic C: Event Instrumentation

### Story C1: Track search and filter analytics

Scope:

1. Instrument `filter_resources` endpoint.
2. Capture query/filter/result_count/page.
3. Emit zero-result and low-result events.

Acceptance criteria:

1. Search events written for filter requests.
2. Zero-result and low-result counts are accurate.

Definition of done:

1. Events include segment and geography tags.
2. No user-facing latency regression beyond agreed threshold.

### Story C2: Track resource engagement analytics

Scope:

1. Track details expansion.
2. Track phone/email/website click events.

Acceptance criteria:

1. Detail opens increment event counts.
2. Contact clicks include contact type in metadata.

Definition of done:

1. Frontend instrumentation handles missing elements safely.
2. No JS console errors in normal flow.

### Story C3: Track snapshot lifecycle analytics

Scope:

1. Track create/send attempt/send success/send fail/view events.
2. Persist snapshot-resource mapping.

Acceptance criteria:

1. Channel mix report can be derived from captured data.
2. Top shared resources report can be derived from snapshot-resource map.

Definition of done:

1. Error scenarios produce fail events with non-PII error codes.
2. Existing snapshot functionality remains intact.

## Epic D: Rollups And Retention

### Story D1: Add daily rollup job

Scope:

1. Aggregate events into daily rollups by segment/geography/channel.
2. Store computed metrics in rollup table.

Acceptance criteria:

1. Dashboard can read from rollups for standard time windows.
2. Rollup rerun for same day updates deterministically.

Definition of done:

1. Cron schedule registered.
2. Job logging for failures added.

### Story D2: Add retention and rebuild utilities

Scope:

1. Raw event retention cleanup.
2. Rollup rebuild command/action.

Acceptance criteria:

1. Old raw events are cleaned according to configured retention.
2. Rebuild can repopulate rollups from raw events.

Definition of done:

1. Retention is configurable.
2. Safety checks prevent accidental full-data wipe.

## Epic E: Analytics Dashboard

### Story E1: Build analytics admin page shell and filter controls

Scope:

1. New admin submenu `Resources > Analytics`.
2. Global filters (date, segment, geography, channel).

Acceptance criteria:

1. Filter state drives data queries.
2. Geography dropdown uses active registry values.

Definition of done:

1. Permissions enforced.
2. No-data state implemented.

### Story E2: Build KPI and trend modules

Scope:

1. KPI cards.
2. Daily trend chart/table.
3. Top queries and top filters sections.

Acceptance criteria:

1. Values match rollup/raw source calculations.
2. Date filters update all modules consistently.

Definition of done:

1. Performance acceptable with production-like dataset.
2. UI reviewed for readability.

### Story E3: Build sharing and geography modules

Scope:

1. Channel mix and delivery performance.
2. Top shared resources.
3. Geography summary table with representative metrics.

Acceptance criteria:

1. Geography table includes all active geographies.
2. Selecting a geography updates all dashboard modules.

Definition of done:

1. Trend delta fields computed consistently.
2. Sorting and paging behavior verified.

## Epic F: Exports

### Story F1: CSV and XLSX analytics exports

Scope:

1. Add analytics export endpoint.
2. Export filtered dashboard data to CSV/XLSX.

Acceptance criteria:

1. Export respects active filter slice.
2. XLSX contains required sheets.

Definition of done:

1. Nonce and capability checks implemented.
2. File naming includes date range and geography/segment when selected.

### Story F2: PDF executive export

Scope:

1. Render KPI summary, trends, top findings, geography table in PDF.

Acceptance criteria:

1. PDF content aligns with dashboard filter state.
2. PDF is readable for leadership use.

Definition of done:

1. Page-break handling validated.
2. Error handling for empty datasets implemented.

## Epic G: QA, UAT, And Launch

### Story G1: End-to-end QA and data validation

Scope:

1. Validate event capture paths.
2. Validate segmentation and geography joins.
3. Validate dashboard and export parity.

Acceptance criteria:

1. Test matrix passes for staff and volunteer paths.
2. Geography slicing verified for at least 5 representative values and all-values mode.

Definition of done:

1. QA report completed with pass/fail and remediation notes.

### Story G2: Production rollout and monitoring

Scope:

1. Enable analytics in production.
2. Monitor event volume, cron status, export stability.

Acceptance criteria:

1. No critical errors post-launch.
2. Baseline dashboard available within agreed data latency window.

Definition of done:

1. Runbook and owner handoff complete.
2. Post-launch review scheduled.

