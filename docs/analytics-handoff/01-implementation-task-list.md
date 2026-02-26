# Resources Analytics Implementation Task List

This is the implementation order for building analytics in this plugin.

## Phase 0: Alignment And Inputs

1. Confirm MVP scope:
   - Include search, sharing, engagement, segment slicing, geography slicing, and exports.
2. Confirm data policies:
   - PII exclusions, retention windows, and access control.
3. Confirm path-based segmentation rules:
   - `staff`, `vincentian_volunteer`, `partner`, `unknown`.
4. Confirm geography seed list and owner:
   - Who approves adds/removals/disables.

## Phase 1: Data Foundation

1. Add schema and migration:
   - `wp_svdpr_analytics_events`
   - `wp_svdpr_analytics_event_geographies`
   - `wp_svdpr_analytics_snapshot_resources`
   - `wp_svdpr_geography_registry`
   - `wp_svdpr_shortcode_geography_map`
   - `wp_svdpr_analytics_rollup_daily`
2. Add plugin DB version bump and upgrade routine.
3. Add indexes for date, event type, segment, channel, geography joins.
4. Seed geography registry with initial conference list.

Deliverable:
- Tables created on activation/upgrade.
- Existing plugin behavior unchanged.

## Phase 2: Analytics Core Services

1. Create analytics service class for:
   - Event write API
   - Segment resolver from path/source URL
   - Geography resolver from request context
   - Query sanitization and hashing
2. Create geography registry service for:
   - CRUD + enable/disable + sort order
   - Auto-discovery upsert flow
3. Add privacy guardrails:
   - No raw email/phone/neighbor in analytics payloads.

Deliverable:
- Reusable analytics write path with unit-testable methods.

## Phase 3: Event Instrumentation

1. Instrument resource search/filter endpoint:
   - `search_executed`, plus zero/low-result flags.
2. Instrument frontend interactions:
   - Details toggle
   - Contact link clicks (phone/email/website)
   - Optional UX events (`load_more_clicked`, filter sheet open).
3. Instrument snapshot lifecycle:
   - Create, send attempted, send success/fail by channel.
   - Snapshot viewed from shared route.
4. Persist snapshot-resource mapping for "what was shared" analytics.

Deliverable:
- Event data flowing for primary user journeys.

## Phase 4: Geography Discovery + Admin Management

1. Add parser to discover shortcode `geography` values from posts/pages.
2. Build "Run discovery now" admin action.
3. Build geography registry admin UI:
   - Add/remove/disable/reorder.
   - Show source pages and last-seen data.
4. Add setting toggle for auto-discovery schedule.

Deliverable:
- Geography list stays current with shortcode usage and manual controls.

## Phase 5: Rollups, Trends, And Retention

1. Add daily rollup job (WP-Cron):
   - Aggregates by date, segment, geography, channel.
2. Add rebuild job for historical repair/backfill.
3. Add retention job:
   - Keep raw events for configured window, keep rollups longer.

Deliverable:
- Fast analytics queries on rollups with historical trend support.

## Phase 6: Analytics Dashboard

1. Add admin page: `Resources > Analytics`.
2. Add global filters:
   - Date range, segment, geography, channel.
3. Add modules:
   - KPI cards
   - Time trends
   - Top searches/filters
   - Sharing funnel + channel mix
   - Top shared resources
   - Geography summary table
4. Add empty-state and no-data handling.

Deliverable:
- Usable dashboard for operational and leadership questions.

## Phase 7: Exports

1. Add analytics export endpoint and nonce/capability checks.
2. Add CSV export for active dashboard slice.
3. Add XLSX export with multi-sheet output.
4. Add PDF export for executive snapshot layout.
5. Ensure all exports respect active filters.

Deliverable:
- Downloadable reports for geography/segment/date slices.

## Phase 8: QA, Backfill, And Launch

1. Execute QA matrix:
   - Segment correctness
   - Geography correctness
   - Event correctness
   - Export correctness
2. Backfill initial rollups from raw events.
3. Add operational logging and failure notices.
4. Launch behind feature flag if desired.

Deliverable:
- Production rollout with verified analytics integrity.

## Post-Launch Enhancements

1. Automated weekly/monthly email reports.
2. Anomaly alerts (search spikes, delivery failures, rising zero-results).
3. Partner-path segment activation once rollout begins.

