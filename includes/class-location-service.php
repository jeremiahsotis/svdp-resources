<?php
/**
 * Location Service Class
 *
 * Abstraction layer for Conference detection and location services
 * Integrates with WP Go Maps Pro when available, falls back to manual selection
 */

class Location_Service {

    /**
     * Get Conference from address using WP Go Maps integration
     *
     * @param string $address User-entered address
     * @return array|false Conference data or false if not found
     */
    public static function get_conference_from_address($address) {
        // Check if WP Go Maps integration is available
        if (class_exists('WPGMaps_Conference_Lookup')) {
            return WPGMaps_Conference_Lookup::lookup_conference_by_address($address);
        }

        // WP Go Maps not available - return error
        return array(
            'found' => false,
            'error' => 'wpgmaps_not_available',
            'message' => 'Address lookup is not available. Please select your Conference from the dropdown.'
        );
    }

    /**
     * Get all available Conferences
     *
     * @return array List of Conference names
     */
    public static function get_all_conferences() {
        // Try to get from WP Go Maps polygons first
        if (class_exists('WPGMaps_Conference_Lookup')) {
            $conferences = WPGMaps_Conference_Lookup::get_all_conference_names();
            if (!empty($conferences)) {
                return $conferences;
            }
        }

        // Fallback to existing resource_conference_options
        $conferences = get_option('resource_conference_options', array());

        if (empty($conferences)) {
            // Return default conferences if option doesn't exist
            $conferences = self::get_default_conferences();
        }

        // Ensure it's an array
        if (!is_array($conferences)) {
            $conferences = array($conferences);
        }

        // Sort alphabetically
        sort($conferences);

        return $conferences;
    }

    /**
     * Validate that a Conference name exists
     *
     * @param string $conference_name Conference name to validate
     * @return bool True if valid, false otherwise
     */
    public static function validate_conference($conference_name) {
        if (empty($conference_name)) {
            return false;
        }

        $all_conferences = self::get_all_conferences();

        return in_array($conference_name, $all_conferences, true);
    }

    /**
     * Get default Conference list
     *
     * @return array Default Conference names
     */
    private static function get_default_conferences() {
        return array(
            'All Fort Wayne Conferences',
            'Cathedral',
            'Entire Fort Wayne District',
            'Huntington',
            'Our Lady',
            'Queen of Angels',
            'Sacred Heart – Warsaw',
            'St Charles',
            'St Elizabeth',
            'St Francis',
            'St Gaspar',
            'St Henry',
            'St John the Baptist – New Haven',
            'St Joseph',
            'St Jude',
            'St Louis',
            'St Martin',
            'St Mary – Avilla',
            'St Mary – Decatur',
            'St Mary – Fort Wayne',
            'St Patrick',
            'St Paul',
            'St Peter',
            'St Therese',
            'St Vincent',
        );
    }

    /**
     * Check if WP Go Maps integration is available
     *
     * @return bool True if available, false otherwise
     */
    public static function is_wpgmaps_available() {
        return class_exists('WPGMaps_Conference_Lookup');
    }
}
