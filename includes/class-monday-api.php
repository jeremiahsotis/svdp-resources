<?php
/**
 * Monday.com API Integration Class
 */

class Monday_Resources_API {

    public function __construct() {
        // Schedule the cron job (runs twice daily)
        if (!wp_next_scheduled('fetch_monday_resources')) {
            wp_schedule_event(time(), 'twicedaily', 'fetch_monday_resources');
        }
        add_action('fetch_monday_resources', array($this, 'fetch_monday_data'));
    }

    /**
     * Fetch data from Monday.com with pagination
     */
    public function fetch_monday_data() {
        $api_token = get_option('monday_api_token');
        $board_id = get_option('monday_board_id');

        if (empty($api_token) || empty($board_id)) {
            error_log('Monday.com API: Missing API token or board ID');
            return;
        }

        $all_items = array();
        $cursor = null;
        $has_more = true;

        while ($has_more) {
            $cursor_param = $cursor ? ', cursor: "' . $cursor . '"' : '';

            $query = '{
                boards(ids: ' . intval($board_id) . ') {
                    items_page(limit: 100' . $cursor_param . ') {
                        cursor
                        items {
                            name
                            column_values {
                                id
                                text
                                value
                            }
                        }
                    }
                }
            }';

            $response = wp_remote_post('https://api.monday.com/v2', array(
                'headers' => array(
                    'Authorization' => $api_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array('query' => $query)),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                error_log('Monday.com API Error: ' . $response->get_error_message());
                return;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($body['data']['boards'][0]['items_page']['items'])) {
                $items = $body['data']['boards'][0]['items_page']['items'];
                $all_items = array_merge($all_items, $items);

                // Check if there are more items to fetch
                $cursor = $body['data']['boards'][0]['items_page']['cursor'] ?? null;
                $has_more = !empty($cursor);
            } else {
                $has_more = false;
            }
        }

        if (!empty($all_items)) {
            // Store in transient (expires in 12 hours)
            set_transient('monday_resources', $all_items, 12 * HOUR_IN_SECONDS);
        }
    }

    /**
     * Get resources from cache or fetch new data
     */
    public function get_resources() {
        $items = get_transient('monday_resources');

        if (!$items) {
            $this->fetch_monday_data();
            $items = get_transient('monday_resources');
        }

        return $items ? $items : array();
    }

    /**
     * Convert URLs, phone numbers, and emails to clickable links
     */
    public static function format_column_value($column_value) {
        // Check if this is a Monday.com column with structured data
        if (!empty($column_value['value'])) {
            $value_data = json_decode($column_value['value'], true);

            // Handle link column type
            if (isset($value_data['url'])) {
                $url = $value_data['url'];
                $text = !empty($value_data['text']) ? $value_data['text'] : $url;
                return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($text) . '</a>';
            }

            // Handle phone column type
            if (isset($value_data['phone'])) {
                $phone = $value_data['phone'];
                $clean_phone = preg_replace('/[^0-9]/', '', $phone);

                if (strlen($clean_phone) == 10) {
                    $formatted = '(' . substr($clean_phone, 0, 3) . ') ' .
                                substr($clean_phone, 3, 3) . '-' .
                                substr($clean_phone, 6, 4);
                    return '<a href="tel:' . esc_attr($clean_phone) . '">' . esc_html($formatted) . '</a>';
                } elseif (strlen($clean_phone) == 11 && substr($clean_phone, 0, 1) == '1') {
                    $formatted = '(' . substr($clean_phone, 1, 3) . ') ' .
                                substr($clean_phone, 4, 3) . '-' .
                                substr($clean_phone, 7, 4);
                    return '<a href="tel:+' . esc_attr($clean_phone) . '">' . esc_html($formatted) . '</a>';
                } else {
                    return '<a href="tel:' . esc_attr($clean_phone) . '">' . esc_html($phone) . '</a>';
                }
            }

            // Handle email column type
            if (isset($value_data['email']) && isset($value_data['text'])) {
                $email = $value_data['email'];
                $text = $value_data['text'];
                return '<a href="mailto:' . esc_attr($email) . '">' . esc_html($text) . '</a>';
            }
        }

        // Get the text value
        $text = $column_value['text'];

        if (empty($text)) {
            return '';
        }

        // Convert phone numbers to clickable links and format them
        $text = preg_replace_callback(
            '/\b(\d{3})[.-]?(\d{3})[.-]?(\d{4})\b|\b\((\d{3})\)\s*(\d{3})[.-]?(\d{4})\b/',
            function($matches) {
                // Extract digits
                if (!empty($matches[1])) {
                    $clean = $matches[1] . $matches[2] . $matches[3];
                } else {
                    $clean = $matches[4] . $matches[5] . $matches[6];
                }

                $formatted = '(' . substr($clean, 0, 3) . ') ' .
                            substr($clean, 3, 3) . '-' .
                            substr($clean, 6, 4);

                return '<a href="tel:' . esc_attr($clean) . '">' . esc_html($formatted) . '</a>';
            },
            $text
        );

        // Convert email addresses to clickable links
        $text = preg_replace_callback(
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            function($matches) {
                $email = $matches[0];
                return '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
            },
            $text
        );

        // Convert URLs to clickable links
        $text = preg_replace_callback(
            '/\b(?:https?:\/\/|www\.)[^\s<]+/i',
            function($matches) {
                $url = $matches[0];
                $href = (strpos($url, 'www.') === 0) ? 'http://' . $url : $url;
                $href = rtrim($href, '.,;:!?)');

                return '<a href="' . esc_url($href) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a>';
            },
            $text
        );

        return $text;
    }
}
