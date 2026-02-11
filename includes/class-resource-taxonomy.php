<?php
/**
 * Canonical taxonomy model for Service Area, Services Offered, and Provider Type.
 */

class Resource_Taxonomy {

    const OPTION_SERVICE_AREAS = 'svdp_service_area_terms';
    const OPTION_SERVICES_OFFERED = 'svdp_services_offered_terms';
    const OPTION_PROVIDER_TYPES = 'svdp_provider_type_terms';

    /**
     * Canonical service areas (single-select, required).
     *
     * @return string[]
     */
    public static function get_service_area_labels() {
        return array(
            'Housing Providers',
            'Rent & Housing Assistance',
            'Shelter & Temporary Housing',
            'Utilities Assistance',
            'Food Assistance',
            'Clothing & Basic Needs',
            'Financial Assistance',
            'Transportation',
            'Mental Health Support',
            'Substance Use & Recovery',
            'Medical & Health Care',
            'Family & Children',
            'Domestic Violence & Safety',
            'Seniors & Aging Services',
            'Government & Public Assistance',
            'Legal & Advocacy',
            'Crisis & Emergency Services',
            'Employment & Education'
        );
    }

    /**
     * Canonical services offered (multi-select).
     *
     * @return string[]
     */
    public static function get_services_offered_labels() {
        return array(
            'Rent help',
            'Eviction prevention',
            'Housing stabilization',
            'Security deposit',
            'Temporary housing',
            'Electric assistance',
            'Gas assistance',
            'Water assistance',
            'Utility shutoff prevention',
            'Food pantry',
            'Meal site',
            'Grocery assistance',
            'Clothing closet',
            'Hygiene items',
            'Diapers',
            'Household goods',
            'Emergency funds',
            'One-time assistance',
            'Rides',
            'Bus passes',
            'Medical transportation',
            'Counseling',
            'Therapy',
            'Crisis line',
            'Emotional support',
            'Treatment referral',
            'Recovery support',
            'Harm reduction',
            'Recovery housing',
            'Medical clinic',
            'Insurance assistance',
            'Prescription assistance',
            'Primary care',
            'Childcare',
            'Parenting support',
            'Youth programs',
            'Emergency shelter',
            'Safety planning',
            'Crisis support',
            'Senior assistance',
            'Aging services',
            'Disability support',
            'SNAP assistance',
            'TANF assistance',
            'SSI/SSDI assistance',
            'Legal aid',
            'Tenant rights',
            'Benefits advocacy',
            '988 Lifeline',
            'Emergency hotline',
            'Job training',
            'Resume assistance',
            'Education programs',
            'Burial expense assistance',
            'Case management'
        );
    }

    /**
     * Canonical provider types (single-select, optional).
     *
     * @return string[]
     */
    public static function get_provider_type_labels() {
        return array(
            'Township Trustee',
            'Government Agency',
            'Nonprofit Organization',
            'Healthcare Provider',
            'Educational Institution',
            'Faith-Based Organization',
            'Housing Provider (Landlord / Property Manager)',
            'Private Business',
            'Coalition / Network'
        );
    }

    /**
     * Seed canonical option values.
     *
     * @return void
     */
    public static function seed_canonical_options() {
        update_option(self::OPTION_SERVICE_AREAS, self::build_term_map(self::get_service_area_labels()));
        update_option(self::OPTION_SERVICES_OFFERED, self::build_term_map(self::get_services_offered_labels()));
        update_option(self::OPTION_PROVIDER_TYPES, self::build_term_map(self::get_provider_type_labels()));
    }

    /**
     * Get service area term map (slug => label).
     *
     * @return array<string, string>
     */
    public static function get_service_area_terms() {
        return self::get_option_term_map(self::OPTION_SERVICE_AREAS, self::get_service_area_labels());
    }

    /**
     * Get services offered term map (slug => label).
     *
     * @return array<string, string>
     */
    public static function get_services_offered_terms() {
        return self::get_option_term_map(self::OPTION_SERVICES_OFFERED, self::get_services_offered_labels());
    }

    /**
     * Get provider type term map (slug => label).
     *
     * @return array<string, string>
     */
    public static function get_provider_type_terms() {
        return self::get_option_term_map(self::OPTION_PROVIDER_TYPES, self::get_provider_type_labels());
    }

    /**
     * Get service area label from slug.
     *
     * @param string $slug
     * @return string
     */
    public static function get_service_area_label($slug) {
        $terms = self::get_service_area_terms();
        return isset($terms[$slug]) ? $terms[$slug] : '';
    }

    /**
     * Get provider type label from slug.
     *
     * @param string $slug
     * @return string
     */
    public static function get_provider_type_label($slug) {
        $terms = self::get_provider_type_terms();
        return isset($terms[$slug]) ? $terms[$slug] : '';
    }

    /**
     * Convert services-offered pipe string to labels.
     *
     * @param string $pipe_slugs
     * @return string[]
     */
    public static function get_services_offered_labels_from_pipe($pipe_slugs) {
        $terms = self::get_services_offered_terms();
        $labels = array();
        foreach (self::parse_pipe_slugs($pipe_slugs) as $slug) {
            if (isset($terms[$slug])) {
                $labels[] = $terms[$slug];
            }
        }
        return $labels;
    }

    /**
     * Normalize arbitrary label/text into canonical slug.
     *
     * @param string $label
     * @return string
     */
    public static function normalize_slug($label) {
        $text = strtolower(trim((string) $label));
        $text = str_replace('&', ' and ', $text);
        $text = str_replace('/', ' ', $text);
        $text = str_replace('+', ' plus ', $text);
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/\s+/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim((string) $text, '-');
    }

    /**
     * Normalize and validate a service area slug.
     *
     * @param string $value
     * @return string
     */
    public static function normalize_service_area_slug($value) {
        return self::normalize_single_slug($value, self::get_service_area_terms());
    }

    /**
     * Normalize and validate a provider type slug.
     *
     * @param string $value
     * @return string
     */
    public static function normalize_provider_type_slug($value) {
        $terms = self::get_provider_type_terms();
        $normalized = self::normalize_single_slug($value, $terms);
        if ($normalized !== '') {
            return $normalized;
        }

        // Backward compatibility for earlier slug normalization.
        $legacy_slug = self::normalize_slug($value);
        if ($legacy_slug === 'housing-provider' && isset($terms['housing-provider-landlord-property-manager'])) {
            return 'housing-provider-landlord-property-manager';
        }

        return '';
    }

    /**
     * Normalize and validate multiple services offered slugs.
     *
     * @param array|string $values
     * @return string[]
     */
    public static function normalize_services_offered_slugs($values) {
        $allowed_terms = self::get_services_offered_terms();
        $normalized = array();
        $list = is_array($values) ? $values : array($values);

        foreach ($list as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $slug = self::normalize_slug($value);
            if (isset($allowed_terms[$slug])) {
                $normalized[$slug] = $slug;
            }
        }

        $result = array_values($normalized);
        sort($result);
        return $result;
    }

    /**
     * Parse pipe-delimited slug values with boundary pipes.
     *
     * @param string|null $pipe_value
     * @return string[]
     */
    public static function parse_pipe_slugs($pipe_value) {
        $value = trim((string) $pipe_value);
        if ($value === '') {
            return array();
        }

        $parts = array_filter(array_map('trim', explode('|', $value)));
        return array_values(array_unique($parts));
    }

    /**
     * Convert slug array to boundary-pipe format.
     *
     * @param string[] $slugs
     * @return string
     */
    public static function to_pipe_slug_string($slugs) {
        $slugs = array_values(array_filter(array_unique(array_map('trim', (array) $slugs))));
        if (empty($slugs)) {
            return '';
        }
        sort($slugs);
        return '|' . implode('|', $slugs) . '|';
    }

    /**
     * Normalize target-population array/string to lowercase tokens for filtering.
     *
     * @param array|string $values
     * @return string[]
     */
    public static function normalize_population_filters($values) {
        $list = is_array($values) ? $values : array($values);
        $normalized = array();

        foreach ($list as $value) {
            $value = strtolower(trim((string) $value));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Build associative slug=>label map from labels.
     *
     * @param string[] $labels
     * @return array<string, string>
     */
    private static function build_term_map($labels) {
        $map = array();
        foreach ($labels as $label) {
            $slug = self::normalize_slug($label);
            if ($slug !== '') {
                $map[$slug] = $label;
            }
        }
        return $map;
    }

    /**
     * Resolve map from option or canonical labels fallback.
     *
     * @param string $option_name
     * @param string[] $fallback_labels
     * @return array<string, string>
     */
    private static function get_option_term_map($option_name, $fallback_labels) {
        $fallback = self::build_term_map($fallback_labels);
        $terms = get_option($option_name, array());

        if (!is_array($terms) || empty($terms)) {
            return $fallback;
        }

        // Ensure shape is always slug=>label.
        $normalized = array();
        foreach ($terms as $key => $value) {
            if (is_int($key)) {
                $slug = self::normalize_slug($value);
                if ($slug !== '') {
                    $normalized[$slug] = (string) $value;
                }
                continue;
            }

            $slug = self::normalize_slug($key);
            if ($slug !== '') {
                $normalized[$slug] = (string) $value;
            }
        }

        return !empty($normalized) ? $normalized : $fallback;
    }

    /**
     * Normalize single-value taxonomy slug against allowed map.
     *
     * @param string $value
     * @param array<string, string> $allowed_terms
     * @return string
     */
    private static function normalize_single_slug($value, $allowed_terms) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $slug = self::normalize_slug($value);
        return isset($allowed_terms[$slug]) ? $slug : '';
    }
}
