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
     * Get questions with answer options in a single batch query
     * Eliminates N+1 problem by using batched queries instead of individual option queries
     */
    public static function get_questions_with_options($questionnaire_id) {
        global $wpdb;
        $questions_table = $wpdb->prefix . 'questionnaire_questions';
        $options_table = $wpdb->prefix . 'questionnaire_answer_options';

        // Query 1: Get all questions
        $questions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $questions_table
                 WHERE questionnaire_id = %d
                 ORDER BY sort_order ASC",
                $questionnaire_id
            ),
            ARRAY_A
        );

        if (empty($questions)) {
            return array();
        }

        // Query 2: Get ALL answer options in one batch
        $question_ids = array_column($questions, 'id');
        $placeholders = implode(',', array_fill(0, count($question_ids), '%d'));

        $all_options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $options_table
                 WHERE question_id IN ($placeholders)
                 ORDER BY question_id ASC, sort_order ASC",
                $question_ids
            ),
            ARRAY_A
        );

        // Group options by question_id
        $options_by_question = array();
        foreach ($all_options as $option) {
            $qid = $option['question_id'];
            if (!isset($options_by_question[$qid])) {
                $options_by_question[$qid] = array();
            }
            $options_by_question[$qid][] = $option;
        }

        // Attach options to questions
        foreach ($questions as &$question) {
            $question['answer_options'] = isset($options_by_question[$question['id']])
                ? $options_by_question[$question['id']]
                : array();
        }

        return $questions;
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

        // For multiple_choice and yes_no questions, get next step from answer option
        if ($answer_option_id) {
            $answer_table = $wpdb->prefix . 'questionnaire_answer_options';

            $answer = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT next_question_id, outcome_id FROM $answer_table WHERE id = %d",
                    $answer_option_id
                ),
                ARRAY_A
            );

            if ($answer) {
                if ($answer['outcome_id']) {
                    return array('type' => 'outcome', 'id' => $answer['outcome_id']);
                }

                if ($answer['next_question_id']) {
                    return array('type' => 'question', 'id' => $answer['next_question_id']);
                }
            }
        }

        // For text and info_only questions, get next step from question itself
        $question_table = $wpdb->prefix . 'questionnaire_questions';

        $question = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT next_question_id, outcome_id FROM $question_table WHERE id = %d",
                $question_id
            ),
            ARRAY_A
        );

        if ($question) {
            if ($question['outcome_id']) {
                return array('type' => 'outcome', 'id' => $question['outcome_id']);
            }

            if ($question['next_question_id']) {
                return array('type' => 'question', 'id' => $question['next_question_id']);
            }
        }

        return null;
    }
}
