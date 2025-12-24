# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a WordPress plugin that integrates with Monday.com to display community resources as searchable, filterable cards. The plugin is designed with trauma-informed care principles and accessibility for elderly users in mind.

## Recent Enhancements

### Resource Management Improvements (2025)

Three major features were added to streamline resource management:

1. **"Same as Office Hours" Feature**
   - Database field: `service_same_as_office` in `wp_resources` table
   - When checked, service hours continuously sync with office hours in real-time
   - State persists to database - checkbox stays checked on reload
   - JavaScript handles continuous sync via `syncOfficeToService()` function
   - Location: `class-monday-admin.php` line 1604 (checkbox), `admin-hours.js` lines 77-111

2. **"Save & Add Another" Button**
   - Secondary submit button next to "Add Resource"
   - Saves resource and redirects to blank Add Resource form
   - Detects button via `$_POST['save_and_new']`
   - Location: `class-monday-admin.php` lines 1767-1769 (button), 1851-1881 (handler)

3. **Duplicate Resource Functionality**
   - "Duplicate" link in All Resources actions column
   - Loads Add Resource form pre-filled with original data
   - Appends "(Copy)" to resource name
   - Uses `$has_data` variable to populate forms (both edit and duplicate)
   - Location: `class-monday-admin.php` line 493 (link), 1261-1292 (handler)

## Architecture

### Core Components

The plugin follows a class-based architecture with four main components:

1. **Monday_Resources_API** (`includes/class-monday-api.php`)
   - Handles all Monday.com GraphQL API interactions
   - Manages automatic sync via WordPress cron (runs twice daily)
   - Uses WordPress transients for 12-hour caching
   - Implements pagination to fetch all board items (100 items per request)
   - Formats column values (phone numbers, emails, URLs) into clickable links

2. **Monday_Resources_Shortcode** (`includes/class-monday-shortcode.php`)
   - Renders the `[monday_resources]` shortcode
   - Implements frontend filtering (category dropdown + keyword search with synonyms)
   - Maps Monday.com column IDs to display fields (lines 143-167)
   - Handles shortcode attributes for geography and service_type filtering
   - Contains synonym map for search enhancement (around line 443)

3. **Monday_Resources_Admin** (`includes/class-monday-admin.php`)
   - Provides WordPress admin interface with three pages: Settings, Issue Reports, Resource Submissions
   - Manages API credentials (API token and Board ID)
   - Displays and manages user-submitted issue reports and resource submissions
   - Provides manual "Sync Now" functionality

4. **Monday_Resources_Submissions** (`includes/class-monday-submissions.php`)
   - Handles AJAX endpoints for user submissions (issue reports and new resources)
   - Validates and sanitizes user input
   - Stores data in custom WordPress database tables

### Database Schema

On plugin activation, two tables are created:

- `wp_monday_resource_issues` - Stores user-reported issues with resources
- `wp_monday_resource_submissions` - Stores user-submitted new resources for review

### Frontend Architecture

- **JavaScript**: `assets/js/frontend.js` handles modal interactions, AJAX submissions, and filtering logic
- **CSS**: `assets/css/modal.css` styles the report/submit modals
- **Templates**: PHP templates in `templates/` directory render modal forms

## Monday.com Column ID Mapping

The plugin expects specific Monday.com column IDs. These are configured in `class-monday-shortcode.php` (lines 143-167):

**Always Visible Fields** (array starting ~line 143):
- `dropdown_mkx1c4dt` - Primary Service Type
- `phone_mkx162rz` - Phone
- `dropdown_mkx1ngjf` - Target Population
- `color_mkx1nefv` - Income Requirements
- `link_mkx1957p` - Website

**Hidden Details** (expandable, array starting ~line 154):
- `dropdown_mkx1nmep` - Organization/Agency
- `dropdown_mkxm3bt8` - Secondary Service Type
- `long_text_mkx17r67` - What They Provide
- `email_mkx1akhs` - Email
- `location_mkx11h7c` - Physical Address
- `long_text_mkx1xxn6` - How to Apply
- And more... (see README.md for complete list)

**Geography Column**: `dropdown_mkx1c3xe` - Used for geography filtering in shortcodes

## Common Development Tasks

### Testing Data Sync

The plugin syncs automatically twice daily, but you can test manually:

1. Go to WordPress Admin > Monday Resources > Settings
2. Click "Sync Now" button
3. Check browser console and WordPress debug.log for errors

### Updating Column IDs

If the Monday.com board structure changes:

1. Use Monday.com API explorer at `https://api.monday.com/v2/docs`
2. Run the GraphQL query in README.md (lines 120-130) to get current column IDs
3. Update the `$always_visible` and `$hidden_details` arrays in `includes/class-monday-shortcode.php`

### Troubleshooting JavaScript Not Loading

If the admin JavaScript (`admin-hours.js`) doesn't load on Add/Edit pages:

1. Check the hook names in `enqueue_admin_scripts()` (line 28)
2. Verify the page parameter fallback is working
3. Clear browser cache (JavaScript version is tied to MONDAY_RESOURCES_VERSION)
4. Check browser console for 404 errors

The script uses `$_GET['page']` as a fallback to ensure it loads even if hook names change.

### WordPress Environment

This plugin runs in a Local by Flywheel environment:
- WordPress installation path: `/Users/jeremiahotis/Local Sites/svdp-resources/app/public/`
- Plugin path: `/wp-content/plugins/monday-resources/`

No build process is required - this is vanilla PHP/JavaScript.

### Database Schema

**Main Tables:**
- `wp_resources` - Resource data and hours flags
  - New column: `service_same_as_office` (tinyint) - tracks if service hours match office hours
- `wp_resource_hours` - Individual hours entries (office/service hours by day)
- `wp_resource_verification_history` - Verification audit trail
- `wp_monday_resource_issues` - User-reported issues
- `wp_monday_resource_submissions` - User-submitted new resources

## Design Principles

- **Trauma-Informed**: Non-judgmental language, no assumptions about users' situations
- **Elderly-Friendly**: Large text (1.05em-1.4em), high contrast, clear labels
- **Accessible**: ARIA-friendly, keyboard navigation, mobile responsive
- **Privacy-First**: No required personal information for searching/viewing resources

## Key Features to Maintain

1. **Synonym Search**: Users searching "rent" should find "eviction" and "housing assistance" (defined in shortcode class)
2. **Combined Filtering**: Category dropdown + keyword search work together
3. **Real-time Updates**: Search and filtering happen without page reloads
4. **Automatic Link Formatting**: Phone numbers, emails, and URLs are automatically made clickable
5. **Graceful Degradation**: If API fails, transient cache serves stale data

## Important Notes

- The plugin uses WordPress transients, not custom cache tables
- All user-facing text is designed to be welcoming and accessible
- Column IDs are hardcoded and must match the Monday.com board structure
- AJAX requests use WordPress nonces for security
- The cron job uses WordPress's `twicedaily` schedule (not exactly 12 hours - varies by traffic)
