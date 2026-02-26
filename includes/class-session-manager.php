<?php
/**
 * Session Manager Class
 *
 * Handles session tracking and response recording for questionnaires
 */

class Session_Manager {

    /**
     * Create new questionnaire session
     */
    public static function create_session($questionnaire_id, $conference, $user_id = null, $is_volunteer_assisted = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_sessions';

        // Generate unique session ID
        $session_id = self::generate_session_id();

        $data = array(
            'session_id' => $session_id,
            'questionnaire_id' => $questionnaire_id,
            'conference' => $conference,
            'user_id' => $user_id,
            'is_volunteer_assisted' => $is_volunteer_assisted ? 1 : 0,
            'status' => 'in_progress',
        );

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return false;
        }

        return $session_id;
    }

    /**
     * Get session by session ID
     */
    public static function get_session($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_sessions';

        $session = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE session_id = %s", $session_id),
            ARRAY_A
        );

        return $session;
    }

    /**
     * Get session by database ID
     */
    public static function get_session_by_id($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_sessions';

        $session = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id),
            ARRAY_A
        );

        return $session;
    }

    /**
     * Record a response to a question
     */
    public static function record_response($session_id, $question_id, $answer_data) {
        global $wpdb;

        // Get session database ID
        $session = self::get_session($session_id);
        if (!$session) {
            return false;
        }

        $responses_table = $wpdb->prefix . 'questionnaire_responses';

        $data = array(
            'session_id' => $session['id'],
            'question_id' => $question_id,
            'answer_option_id' => isset($answer_data['answer_option_id']) ? $answer_data['answer_option_id'] : null,
            'answer_text' => isset($answer_data['answer_text']) ? $answer_data['answer_text'] : null,
        );

        $result = $wpdb->insert($responses_table, $data);

        if ($result !== false) {
            // Update last activity timestamp
            self::update_last_activity($session_id);
        }

        return $result !== false;
    }

    /**
     * Complete a session
     */
    public static function complete_session($session_id, $outcome_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_sessions';

        $result = $wpdb->update(
            $table,
            array(
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
                'outcome_id' => $outcome_id,
            ),
            array('session_id' => $session_id)
        );

        return $result !== false;
    }

    /**
     * Mark session as abandoned
     */
    public static function abandon_session($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_sessions';

        $result = $wpdb->update(
            $table,
            array('status' => 'abandoned'),
            array('session_id' => $session_id)
        );

        return $result !== false;
    }

    /**
     * Update last activity timestamp
     */
    public static function update_last_activity($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_sessions';

        $wpdb->update(
            $table,
            array('last_activity_at' => current_time('mysql')),
            array('session_id' => $session_id)
        );
    }

    /**
     * Get session path (all questions and answers)
     */
    public static function get_session_path($session_id) {
        global $wpdb;

        $session = self::get_session($session_id);
        if (!$session) {
            return array();
        }

        $responses_table = $wpdb->prefix . 'questionnaire_responses';
        $questions_table = $wpdb->prefix . 'questionnaire_questions';
        $answers_table = $wpdb->prefix . 'questionnaire_answer_options';

        $sql = $wpdb->prepare(
            "SELECT
                r.id,
                r.question_id,
                r.answer_option_id,
                r.answer_text,
                r.answered_at,
                q.question_text,
                q.question_type,
                a.answer_text as selected_answer
            FROM $responses_table r
            LEFT JOIN $questions_table q ON r.question_id = q.id
            LEFT JOIN $answers_table a ON r.answer_option_id = a.id
            WHERE r.session_id = %d
            ORDER BY r.answered_at ASC",
            $session['id']
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Generate unique session ID
     */
    private static function generate_session_id() {
        return 'sess_' . wp_generate_password(32, false);
    }

    /**
     * Track resource view
     */
    public static function track_resource_view($session_id, $resource_id) {
        global $wpdb;

        $session = self::get_session($session_id);
        if (!$session) {
            return false;
        }

        $table = $wpdb->prefix . 'questionnaire_resource_views';

        $data = array(
            'session_id' => $session['id'],
            'resource_id' => $resource_id,
        );

        $result = $wpdb->insert($table, $data);

        return $result !== false;
    }
}
