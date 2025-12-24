<?php
/**
 * Question Manager Class
 *
 * Handles CRUD operations for questionnaire questions and answer options
 */

class Question_Manager {

    /**
     * Get question by ID
     */
    public static function get_question($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_questions';

        $question = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id),
            ARRAY_A
        );

        return $question;
    }

    /**
     * Get all questions for a questionnaire
     */
    public static function get_questions_for_questionnaire($questionnaire_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_questions';

        $questions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE questionnaire_id = %d ORDER BY sort_order ASC",
                $questionnaire_id
            ),
            ARRAY_A
        );

        return $questions;
    }

    /**
     * Create new question
     */
    public static function create_question($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_questions';

        $defaults = array(
            'questionnaire_id' => 0,
            'question_text' => '',
            'question_type' => 'multiple_choice',
            'help_text' => '',
            'required' => 1,
            'sort_order' => 0,
        );

        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Update question
     */
    public static function update_question($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_questions';

        $result = $wpdb->update(
            $table,
            $data,
            array('id' => $id)
        );

        return $result !== false;
    }

    /**
     * Delete question
     */
    public static function delete_question($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_questions';

        return $wpdb->delete($table, array('id' => $id)) !== false;
    }

    /**
     * Get answer options for a question
     */
    public static function get_answer_options($question_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_answer_options';

        $options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE question_id = %d ORDER BY sort_order ASC",
                $question_id
            ),
            ARRAY_A
        );

        return $options;
    }

    /**
     * Create new answer option
     */
    public static function create_answer_option($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_answer_options';

        $defaults = array(
            'question_id' => 0,
            'answer_text' => '',
            'next_question_id' => null,
            'outcome_id' => null,
            'sort_order' => 0,
        );

        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Update answer option
     */
    public static function update_answer_option($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_answer_options';

        $result = $wpdb->update(
            $table,
            $data,
            array('id' => $id)
        );

        return $result !== false;
    }

    /**
     * Delete answer option
     */
    public static function delete_answer_option($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_answer_options';

        return $wpdb->delete($table, array('id' => $id)) !== false;
    }

    /**
     * Get next step (question or outcome) based on answer
     */
    public static function get_next_step($question_id, $answer_option_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'questionnaire_answer_options';

        $answer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT next_question_id, outcome_id FROM $table WHERE id = %d",
                $answer_option_id
            ),
            ARRAY_A
        );

        if (!$answer) {
            return null;
        }

        if ($answer['outcome_id']) {
            return array('type' => 'outcome', 'id' => $answer['outcome_id']);
        }

        if ($answer['next_question_id']) {
            return array('type' => 'question', 'id' => $answer['next_question_id']);
        }

        return null;
    }
}
