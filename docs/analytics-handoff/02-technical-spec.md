# Resources Analytics Technical Specification

## 1) Purpose

Build an analytics module in the SVdP Resources plugin that gives deep insight into:

1. What users search for.
2. What resources are shared to neighbors.
3. How resources are shared (`print`, `email`, `text`).
4. How demand and behavior trends change over time.
5. How behavior differs by user segment and geography.

## 2) In-Scope

1. Event capture for search, filters, engagement, and snapshot sharing.
2. Path-based segment classification.
3. Geography classification based on shortcode `geography` attribute.
4. Geography registry with auto-discovery and manual management.
5. Analytics dashboard with date/segment/geography/channel filters.
6. Dashboard exports to PDF, CSV, XLSX for active filter slice.
7. Daily rollups and retention controls.

## 3) Out-Of-Scope (Initial Release)

1. Partner portal behavior beyond path-based segment support.
2. External BI warehouse integration.
3. Real-time streaming analytics.
4. Per-user profiling beyond aggregate reporting.

## 4) Current Plugin Touchpoints

Relevant existing files:

1. `/Users/jeremiahotis/Local Sites/svdp-resources/app/public/wp-content/plugins/svdp-resources/includes/class-monday-shortcode.php`
   - Parses shortcode attributes including `geography`.
   - Handles `filter_resources` AJAX.
2. `/Users/jeremiahotis/Local Sites/svdp-resources/app/public/wp-content/plugins/svdp-resources/assets/js/frontend.js`
   - Sends filter/search requests.
   - Handles snapshot actions and UI interactions.
3. `/Users/jeremiahotis/Local Sites/svdp-resources/app/public/wp-content/plugins/svdp-resources/includes/class-resource-snapshot-manager.php`
   - Snapshot create/send/view routes and delivery logic.
4. `/Users/jeremiahotis/Local Sites/svdp-resources/app/public/wp-content/plugins/svdp-resources/includes/class-monday-admin.php`
   - Admin page patterns and export endpoint examples.
5. `/Users/jeremiahotis/Local Sites/svdp-resources/app/public/wp-content/plugins/svdp-resources/includes/class-resource-exporter.php`
   - CSV/XLSX/PDF exporter utilities.

## 5) Segment Rules

Segment is derived from source path.

1. `staff`
   - Path contains `master-resource-list`.
2. `vincentian_volunteer`
   - Path equals `/district-resources` or starts with `/district-resources/`, excluding master list.
3. `partner`
   - Path starts with configurable partner prefix (default `/partner-resources/`).
4. `unknown`
   - Fallback when path is missing or unmatched.

Implementation notes:

1. Segment precedence: `staff` -> `vincentian_volunteer` -> `partner` -> `unknown`.
2. Store `segment_rule_version` in each event for future rule evolution.

## 6) Geography Model

### 6.1 Source of Truth

Geography values are managed in a registry table and can be:

1. Auto-discovered from shortcode `geography` values.
2. Manually added/removed/disabled/reordered by admin.

### 6.2 Initial Seed Values

1. Cathedral
2. Huntington
3. Our Lady
4. Queen of Angels
5. Sacred Heart - Warsaw
6. St Charles
7. St Elizabeth
8. St Francis
9. St Gaspar
10. St John the Baptist - New Haven
11. St Patrick
12. St Joseph
13. St Jude
14. St Louis
15. St Martin
16. St Mary - Avilla
17. St Mary - Decatur
18. St Mary - Fort Wayne
19. St Paul
20. St Peter
21. St Therese
22. St Vincent
23. Entire Fort Wayne District
24. All Fort Wayne Conferences

### 6.3 Assignment

1. A page/shortcode instance may map to multiple geographies.
2. Events can be tagged with one-or-many geographies.
3. Dashboard geography filter should support:
   - `All geographies`
   - Single geography value.

## 7) Data Schema

### 7.1 `wp_svdpr_analytics_events`

Purpose: immutable event stream.

Required columns:

1. `id` bigint PK auto.
2. `event_name` varchar(64) indexed.
3. `event_ts_utc` datetime indexed.
4. `event_date_local` date indexed.
5. `segment` varchar(32) indexed.
6. `segment_rule_version` varchar(16).
7. `source_path` varchar(255) indexed.
8. `source_url` text nullable.
9. `user_id` bigint nullable indexed.
10. `is_authenticated` tinyint(1).
11. `session_id_hash` char(64) indexed.
12. `resource_id` bigint nullable indexed.
13. `snapshot_id` bigint nullable indexed.
14. `channel` varchar(16) nullable indexed (`print`, `email`, `text`).
15. `query_text_sanitized` text nullable.
16. `query_hash` char(64) nullable indexed.
17. `result_count` int nullable.
18. `status` varchar(16) (`success`, `error`) indexed.
19. `error_code` varchar(64) nullable.
20. `meta_json` longtext nullable.
21. `created_at` datetime default current timestamp.

### 7.2 `wp_svdpr_analytics_event_geographies`

Purpose: many-to-many event -> geography tags.

Columns:

1. `id` bigint PK auto.
2. `event_id` bigint indexed.
3. `geography_slug` varchar(191) indexed.
4. Unique key (`event_id`, `geography_slug`).

### 7.3 `wp_svdpr_analytics_snapshot_resources`

Purpose: flatten snapshot contents for "what was shared".

Columns:

1. `id` bigint PK auto.
2. `snapshot_id` bigint indexed.
3. `snapshot_token` varchar(80) indexed.
4. `resource_id` bigint indexed.
5. `created_at` datetime indexed.
6. Unique key (`snapshot_id`, `resource_id`).

### 7.4 `wp_svdpr_geography_registry`

Purpose: editable geography catalog.

Columns:

1. `id` bigint PK auto.
2. `slug` varchar(191) unique indexed.
3. `label` varchar(255).
4. `is_active` tinyint(1) default 1.
5. `display_order` int default 0.
6. `source_type` varchar(32) (`seed`, `shortcode_discovery`, `manual`).
7. `created_at` datetime.
8. `updated_at` datetime.

### 7.5 `wp_svdpr_shortcode_geography_map`

Purpose: trace discovered shortcode geography values by content location.

Columns:

1. `id` bigint PK auto.
2. `post_id` bigint indexed.
3. `path` varchar(255) indexed.
4. `shortcode_hash` char(64) indexed.
5. `geography_slug` varchar(191) indexed.
6. `last_seen_at` datetime indexed.
7. Unique key (`post_id`, `shortcode_hash`, `geography_slug`).

### 7.6 `wp_svdpr_analytics_rollup_daily`

Purpose: fast daily dashboard queries.

Dimensions:

1. `rollup_date`
2. `segment`
3. `geography_slug` (nullable for all geography)
4. `channel` (nullable for all channel)

Metrics:

1. `search_count`
2. `unique_query_count`
3. `zero_result_count`
4. `low_result_count`
5. `detail_open_count`
6. `contact_click_count`
7. `snapshot_create_count`
8. `snapshot_send_attempt_count`
9. `snapshot_sent_count`
10. `snapshot_send_fail_count`
11. `snapshot_view_count`

## 8) Event Definitions

### 8.1 Search + Filter

1. `search_executed`
   - Trigger: `filter_resources` AJAX response.
   - Data: query, filters, result_count, page/per_page.
2. `search_zero_results`
   - Trigger: result_count = 0.
3. `search_low_results`
   - Trigger: result_count <= low-result threshold (configurable, default 3).
4. `filter_applied`
   - Trigger: filter set changed and request sent.

### 8.2 Engagement

1. `resource_detail_opened`
   - Trigger: user expands card details.
2. `resource_contact_clicked`
   - Trigger: click on phone/email/website links.
   - Data: contact_type in `meta_json`.

### 8.3 Snapshot Sharing

1. `snapshot_created`
   - Trigger: snapshot create success.
   - Data: snapshot_id/token, resource_count, channel intent.
2. `snapshot_send_attempted`
   - Trigger: send endpoint request for email/text/print.
3. `snapshot_sent`
   - Trigger: send success.
4. `snapshot_send_failed`
   - Trigger: send failure.
5. `snapshot_viewed`
   - Trigger: shared route page load.

## 9) Geography Discovery Requirements

1. Parse all published content with `[monday_resources ...]`.
2. Extract `geography` attribute when present.
3. Split comma-separated geography values.
4. Normalize into slugs and upsert registry entries.
5. Upsert path mapping rows in `shortcode_geography_map`.
6. Preserve existing manual geography entries.
7. Provide admin action: `Run discovery now`.
8. Optional daily cron when auto-discovery enabled.

## 10) Dashboard Requirements

Add `Resources > Analytics` page.

Global filters:

1. Date range (7, 30, 90, custom).
2. Segment (`all`, `staff`, `vincentian_volunteer`, `partner`).
3. Geography (`all` + active registry values).
4. Channel (`all`, `print`, `email`, `text`).

Modules:

1. KPI cards:
   - Searches, zero-result rate, snapshot sends, send success rate.
2. Trend chart:
   - Daily searches, shares, zero-results.
3. Top queries table.
4. Top filters/needs table (service area, services offered, population).
5. Channel mix chart.
6. Top shared resources table.
7. Geography summary table:
   - One row per active geography with representative metrics.

## 11) Export Requirements

Exports apply current dashboard filters.

1. CSV:
   - Tabular records for chosen module(s) and geography summary.
2. XLSX:
   - Sheets:
     - `Summary`
     - `Daily Trends`
     - `Top Queries`
     - `Top Shared Resources`
     - `Geography Summary`
3. PDF:
   - Executive layout with KPI cards, top findings, trend chart image/table, geography summary.

## 12) Privacy, Security, And Access

1. No PII in analytics tables:
   - Do not store raw email, phone, neighbor name.
2. Hash session identifiers with salt.
3. Sanitize query text before storage:
   - Strip email and phone patterns.
4. Capability checks:
   - View dashboard: configurable, default `svdp_manage_resources`.
   - Export: same or stricter.
   - Geography admin changes: `manage_options` by default.
5. All AJAX export endpoints require nonce validation.

## 13) Performance Requirements

1. Event write path should be lightweight and non-blocking.
2. Prefer rollup reads for dashboard views.
3. Add indexes for common filter combinations:
   - date + segment + event
   - event + geography
   - snapshot + resource
4. Avoid full table scans in dashboard queries.

## 14) Retention And Rebuild

1. Raw events retention default: 13 months (configurable).
2. Rollup retention default: indefinite.
3. Provide CLI/admin utility to rebuild rollups from raw events.
4. Use soft-disable for geography registry rows to preserve historical joins.

## 15) Acceptance Criteria

1. Geography slicing:
   - Selecting `Cathedral` in dashboard updates all modules and exports to that slice.
2. Segment slicing:
   - Staff and volunteer paths report into different segment buckets.
3. Search metrics:
   - Zero-result rate and low-result rate match raw search records.
4. Sharing metrics:
   - Channel mix and send success/fail reflect snapshot endpoints.
5. Shared-resource reporting:
   - Top shared resources align with snapshot-resource mapping.
6. Geography summary:
   - Main table includes every active geography with key metrics and trend delta.
7. Export parity:
   - CSV, XLSX, PDF values match on-screen filtered data.

## 16) Recommended New Classes

1. `includes/class-resource-analytics.php`
2. `includes/class-resource-analytics-dashboard.php`
3. `includes/class-resource-analytics-exporter.php`
4. `includes/class-resource-geography-registry.php`

Optional:

5. `assets/js/admin-analytics.js`

## 17) Rollout Plan

1. Ship schema + event capture first.
2. Verify event integrity in staging.
3. Enable dashboard with rollups after minimum data collection window.
4. Enable exports once dashboard module queries are stable.
5. Add partner path rule when partner rollout starts.

