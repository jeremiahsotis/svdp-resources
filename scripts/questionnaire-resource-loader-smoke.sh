#!/usr/bin/env bash
set -euo pipefail

# One-command smoke test for questionnaire admin resource lazy loader endpoint.
#
# Usage:
#   ./scripts/questionnaire-resource-loader-smoke.sh
#   ./scripts/questionnaire-resource-loader-smoke.sh /abs/path/to/wp-root
#
# Optional env:
#   ADMIN_USER_ID=1

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
DEFAULT_WP_PATH="$(cd "${PLUGIN_ROOT}/../../.." && pwd)"
WP_PATH="${1:-${DEFAULT_WP_PATH}}"
ADMIN_USER_ID="${ADMIN_USER_ID:-1}"

pass() {
    echo "PASS: $1"
}

fail() {
    echo "FAIL: $1" >&2
    exit 1
}

run_wp() {
    wp "$@" --path="${WP_PATH}"
}

run_wp_retry() {
    local attempts=0
    local max_attempts=3
    local delay_seconds=1

    while true; do
        if run_wp "$@"; then
            return 0
        fi

        attempts=$((attempts + 1))
        if [[ ${attempts} -ge ${max_attempts} ]]; then
            return 1
        fi
        sleep "${delay_seconds}"
    done
}

echo "Running questionnaire resource loader smoke test"
echo "WP path: ${WP_PATH}"
echo "Plugin root: ${PLUGIN_ROOT}"
echo

command -v wp >/dev/null 2>&1 || fail "wp-cli is not installed or not in PATH"

run_wp_retry option get siteurl >/dev/null || fail "cannot access WordPress database/runtime from wp-cli"
pass "WordPress runtime reachable"

db_version="$(run_wp_retry option get monday_resources_db_version 2>/dev/null || true)"
if [[ -z "${db_version}" ]]; then
    fail "could not read monday_resources_db_version"
fi
pass "db version detected (${db_version})"

hook_registered="$(run_wp_retry eval "echo has_action('wp_ajax_get_resources_for_selection') ? '1' : '0';")"
[[ "${hook_registered}" == "1" ]] || fail "wp_ajax_get_resources_for_selection hook is missing"
pass "AJAX hook registered"

db_prefix="$(run_wp_retry db prefix)"
index_rows="$(run_wp_retry db query "SHOW INDEX FROM ${db_prefix}resources WHERE Key_name IN ('status','idx_status_primary_type');" --skip-column-names)"
if ! printf '%s\n' "${index_rows}" | grep -q "status"; then
    fail "resources.status index missing"
fi
if ! printf '%s\n' "${index_rows}" | grep -q "idx_status_primary_type"; then
    fail "resources.idx_status_primary_type index missing"
fi
pass "required resource indexes present"

admin_json="$(
    ADMIN_USER_ID="${ADMIN_USER_ID}" run_wp_retry eval '
    $admin_user_id = (int) getenv("ADMIN_USER_ID");
    if ($admin_user_id <= 0) {
        $admin_user_id = 1;
    }

    wp_set_current_user($admin_user_id);
    $_POST = array(
        "action" => "get_resources_for_selection",
        "nonce" => wp_create_nonce("questionnaire_admin_nonce"),
        "search" => "food",
        "page" => "1"
    );
    $_REQUEST = $_POST;

    ob_start();
    do_action("wp_ajax_get_resources_for_selection");
    echo ob_get_clean();
    '
)"

if [[ -z "${admin_json}" ]]; then
    fail "empty response from admin AJAX call"
fi

printf '%s' "${admin_json}" | php -r '
$raw = stream_get_contents(STDIN);
$json = json_decode($raw, true);
if (!is_array($json) || empty($json["success"])) {
    fwrite(STDERR, "admin call did not return success=true\n");
    exit(1);
}
if (!isset($json["data"]["pagination"]["has_more"])) {
    fwrite(STDERR, "admin call missing pagination payload\n");
    exit(1);
}
if (!isset($json["data"]["resources"]) || !is_array($json["data"]["resources"])) {
    fwrite(STDERR, "admin call missing resources payload\n");
    exit(1);
}
'
pass "admin AJAX path returns success JSON payload"

unauth_json="$(
    run_wp_retry eval '
    wp_set_current_user(0);
    $_POST = array(
        "action" => "get_resources_for_selection",
        "nonce" => wp_create_nonce("questionnaire_admin_nonce"),
        "search" => "food",
        "page" => "1"
    );
    $_REQUEST = $_POST;

    ob_start();
    do_action("wp_ajax_get_resources_for_selection");
    echo ob_get_clean();
    '
)"

if [[ -z "${unauth_json}" ]]; then
    fail "empty response from non-admin AJAX call"
fi

printf '%s' "${unauth_json}" | php -r '
$raw = stream_get_contents(STDIN);
$json = json_decode($raw, true);
if (!is_array($json) || !array_key_exists("success", $json) || $json["success"] !== false) {
    fwrite(STDERR, "non-admin call did not return success=false\n");
    exit(1);
}
$message = isset($json["data"]["message"]) ? (string) $json["data"]["message"] : "";
if ($message !== "Unauthorized access.") {
    fwrite(STDERR, "unexpected non-admin message: " . $message . "\n");
    exit(1);
}
'
pass "non-admin AJAX path denied as expected"

echo
echo "Smoke test completed successfully."
