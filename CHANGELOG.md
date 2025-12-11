# Changelog

All notable changes to the Monday.com Resources Integration Plugin.

## [1.1.0] - 2025-12-03

### Added - User Experience Enhancements

#### Trauma-Informed & Elderly-Sensitive Help Section
- Added comprehensive, welcoming instructions at the top of the resources page
- Clear, non-judgmental language that reassures users
- Large, readable text optimized for elderly users
- Step-by-step guidance on how to use filters and search
- Examples and helpful tips for finding resources
- Responsive design that works on all devices

#### Category Dropdown Filter
- New dropdown filter to browse resources by service type
- Automatically populated from Monday.com board data (Primary Service Type column)
- Options include: Food Assistance, Housing, Healthcare, and more
- "Show All Categories" option to reset the filter
- Real-time filtering as users select categories
- Works independently or combined with keyword search

#### Enhanced Filtering System
- Combined filtering: Use category dropdown AND keyword search together
- Intelligent filtering that shows results matching both criteria
- Improved "no results" messages that explain active filters
- Example: "No resources found matching category 'Food Assistance' and search 'seniors'"
- Results counter updates dynamically

#### Accessibility Improvements
- Larger font sizes for better readability
- Clear labels on all form elements
- Helpful placeholder text with examples
- High contrast colors for visibility
- Focus states on interactive elements
- Mobile-responsive design

### Technical Changes
- Added `data-category` attribute to resource cards for filtering
- Implemented `filterResources()` function to handle combined filtering
- Collected unique service types from resources for dropdown population
- Enhanced CSS with responsive breakpoints for mobile devices
- Updated JavaScript to handle both search and category filter events

---

## [1.0.0] - 2025-12-02

### Initial Release

#### Core Features
- Monday.com API integration
- Automatic resource syncing (twice daily)
- Shortcode display: `[monday_resources]`
- Keyword search with synonym support
- Expandable resource cards
- Shortcode filtering by geography and service type

#### User Interaction
- Report an Issue feature
- Submit a New Resource feature
- Modal forms with AJAX submission

#### Admin Dashboard
- Settings page for API configuration
- Issue Reports management page
- Resource Submissions review page
- Manual sync functionality

#### Technical Implementation
- WordPress plugin architecture
- Object-oriented PHP classes
- Database tables for issues and submissions
- AJAX handlers for form submissions
- Responsive CSS framework
- Vanilla JavaScript for frontend functionality
