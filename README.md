# Monday.com Resources Integration Plugin

A WordPress plugin that integrates with Monday.com to display resource listings as searchable cards with filtering capabilities, issue reporting, and resource submission features.

## Features

- **Automatic Sync**: Fetches resources from Monday.com board twice daily
- **User-Friendly Instructions**: Trauma-informed and elderly-sensitive help text guides users through finding resources
- **Category Dropdown Filter**: Easy-to-use dropdown to browse resources by service type
- **Searchable Interface**: Frontend search with synonym support for better discoverability
- **Combined Filtering**: Use category dropdown and keyword search together for precise results
- **Resource Cards**: Clean, responsive card layout with expandable details
- **Shortcode Filtering**: Filter by geography and service type using shortcode attributes
- **Report Issues**: Allow users to report problems with resource listings
- **Submit Resources**: Let users submit new resources for review
- **Admin Dashboard**: Manage API settings, view issue reports, and review submissions
- **Same as Office Hours**: Automatically sync service/program hours with office hours
- **Save & Add Another**: Quick successive resource entry workflow
- **Duplicate Resources**: Copy existing resources to create similar entries
- **Fully Accessible**: Large text, clear labels, and helpful instructions for all users
- **Responsive Design**: Works on desktop, tablet, and mobile devices

## Installation

1. **Upload the Plugin**
   - Download or copy the `monday-resources-plugin` folder
   - Upload it to `/wp-content/plugins/` directory on your WordPress site
   - Or zip the folder and upload via WordPress admin (Plugins > Add New > Upload Plugin)

2. **Activate the Plugin**
   - Go to WordPress admin > Plugins
   - Find "Monday.com Resources Integration"
   - Click "Activate"

3. **Configure Settings**
   - Go to WordPress admin > Monday Resources > Settings
   - Enter your Monday.com API Token
   - Enter your Monday.com Board ID
   - Click "Save Changes"
   - Click "Sync Now" to manually fetch resources for the first time

## Getting Monday.com Credentials

### API Token
1. Log in to your Monday.com account
2. Click your avatar in the top right
3. Select "Developers" from the menu
4. Click "My Access Tokens" in the left sidebar
5. Click "Generate" or "Show" to create/view a token
6. Copy the token and paste it into the plugin settings

### Board ID
1. Open your board in Monday.com
2. Look at the URL in your browser
3. The board ID is the number at the end of the URL
   - Example: `https://example.monday.com/boards/1234567890` → Board ID is `1234567890`
4. Copy this number and paste it into the plugin settings

## Usage

### Basic Shortcode

Display all resources:
```
[monday_resources]
```

### Filtering by Geography

Display only resources for specific geographic areas:
```
[monday_resources geography="Mecklenburg"]
[monday_resources geography="Mecklenburg, Union"]
```

### Filtering by Service Type

Display only specific types of services:
```
[monday_resources service_type="Food Assistance"]
[monday_resources service_type="Food Assistance, Housing"]
```

### Combined Filtering

Combine both geography and service type filters:
```
[monday_resources geography="Mecklenburg" service_type="Food Assistance"]
```

## Column ID Mapping

The plugin expects these column IDs from your Monday.com board. Update the column IDs in `class-monday-shortcode.php` if your board uses different IDs:

**Always Visible Fields:**
- `dropdown_mkx1c4dt` - Primary Service Type
- `phone_mkx162rz` - Phone
- `dropdown_mkx1ngjf` - Target Population
- `color_mkx1nefv` - Income Requirements
- `link_mkx1957p` - Website

**Hidden Details (expandable):**
- `dropdown_mkx1nmep` - Organization/Agency
- `dropdown_mkxm3bt8` - Secondary Service Type
- `long_text_mkx17r67` - What They Provide
- `email_mkx1akhs` - Email
- `location_mkx11h7c` - Physical Address
- `long_text_mkx1xxn6` - How to Apply
- `long_text_mkx1wdjn` - Documents Required
- `text_mkx1tkrz` - Hours of Operation
- `color_mkx1kpkb` - Wait Time
- `text_mkx1knsa` - Residency Requirements
- `long_text_mkx1qaxq` - Other Eligibility Requirements
- `long_text_mkx1qsy5` - Eligibility Notes
- `dropdown_mkx1bndy` - Counties Served
- `long_text_mkx18jq8` - Notes & Tips
- `long_text_mkx1w8qc` - Last Verified

### How to Find Your Column IDs

1. Use Monday.com API explorer at `https://api.monday.com/v2/docs`
2. Run this query (replace YOUR_BOARD_ID):
```graphql
{
  boards(ids: YOUR_BOARD_ID) {
    columns {
      id
      title
      type
    }
  }
}
```
3. Match the column titles to the IDs returned
4. Update the arrays in `includes/class-monday-shortcode.php` (lines 143-167)

## Admin Features

### Settings Page
- Configure Monday.com API Token and Board ID
- Manual sync button to fetch latest data
- Located at: WordPress Admin > Monday Resources > Settings

### Issue Reports
- View all issue reports from users
- Update status (Pending, In Progress, Resolved)
- See reporter contact information
- Delete resolved issues
- Located at: WordPress Admin > Monday Resources > Issue Reports

### Resource Submissions
- Review new resources submitted by users
- Update status (Pending, Approved, Rejected)
- View all submission details
- Delete submissions
- Located at: WordPress Admin > Monday Resources > Resource Submissions

## Frontend Features

### User-Friendly Help Section
- **Trauma-Informed Design**: Welcoming, non-judgmental language that puts users at ease
- **Clear Instructions**: Step-by-step guidance on how to use the filters and search
- **Large, Readable Text**: Designed with elderly users in mind
- **No Assumptions**: Explains both category filtering and keyword search in simple terms
- **Reassuring Tone**: "Take your time - there's no rush"

### Category Dropdown Filter
- Browse all resources by service type (Food, Housing, Healthcare, etc.)
- Automatically populated from your Monday.com board data
- Works independently or combined with keyword search
- Clear "Show All Categories" option to reset
- Real-time filtering as you select

### Search Functionality
- Real-time search as you type
- Searches across all resource fields
- Synonym support for common terms (e.g., "rent" matches "eviction", "housing assistance")
- Works independently or combined with category filter
- Shows count of visible resources
- Helpful placeholder text with examples

### Combined Filtering
- Use both category dropdown AND keyword search together
- Example: Select "Food Assistance" category, then search for "seniors"
- Results update automatically
- Clear "no results" messages explain what filters are active

### Report an Issue
- Users can report problems with any resource
- Issue types: Incorrect Information, Outdated Information, Phone Number Wrong, Website/Link Broken, No Longer Exists, Other
- Optional contact information for follow-up
- Submitted reports appear in admin dashboard

### Submit a New Resource
- Users can submit new resources for review
- Required fields: Organization Name, Service Type, Description
- Optional fields: Contact info, website, address, counties served
- Submissions appear in admin dashboard for approval

## File Structure

```
monday-resources-plugin/
├── monday-resources-plugin.php     # Main plugin file
├── includes/
│   ├── class-monday-api.php        # Monday.com API integration
│   ├── class-monday-shortcode.php  # Shortcode display logic
│   ├── class-monday-admin.php      # Admin dashboard
│   └── class-monday-submissions.php # Handle reports and submissions
├── assets/
│   ├── css/
│   │   └── modal.css               # Modal styles
│   └── js/
│       └── frontend.js             # Frontend JavaScript
├── templates/
│   ├── report-issue-modal.php      # Report issue form
│   └── submit-resource-modal.php   # Submit resource form
└── README.md                        # This file
```

## Customization

### Styling

You can override the default styles by adding custom CSS to your theme:

```css
/* Change card colors */
.resource-card {
    background: #your-color;
    border: 1px solid #your-border-color;
}

/* Change button styles */
.submit-resource-btn {
    background-color: #your-color;
}

.resource-report-btn {
    background-color: #your-color;
}
```

### Search Synonyms

Edit the `synonymMap` object in `includes/class-monday-shortcode.php` (starting around line 443) to customize search synonyms.

## Troubleshooting

### Resources Not Showing

1. Verify API token and Board ID are correct in Settings
2. Click "Sync Now" button in Settings
3. Check that your Monday.com board has items
4. Ensure the board ID matches your actual board

### Column IDs Don't Match

1. Use the Monday.com API explorer to find your column IDs
2. Update the column ID mappings in `includes/class-monday-shortcode.php`
3. Look for the `$always_visible` and `$hidden_details` arrays

### Reports/Submissions Not Saving

1. Check database tables were created (look for `wp_monday_resource_issues` and `wp_monday_resource_submissions`)
2. Try deactivating and reactivating the plugin
3. Check WordPress debug log for errors

## Support

For issues or questions:
- Check the troubleshooting section above
- Review your Monday.com API credentials
- Ensure column IDs match your board structure

## Changelog

### Version 1.0.0
- Initial release
- Monday.com API integration
- Resource display with search and filtering
- Issue reporting feature
- Resource submission feature
- Admin dashboard for managing reports and submissions

## License

GPL v2 or later

## Credits

Developed for WordPress integration with Monday.com boards.
