<?php
/**
 * API: get_names_api.php - FINAL CORRECTION
 *
 * Fetches the list of names and timestamps for a specific role and combined event string.
 * NOTE: The event and time slot are combined into one string stored in the 'user' column.
 */

header('Content-Type: application/json');

require 'db_config.php'; 

function send_json_error($message, $code = 400, $conn = null) {
    if ($conn && $conn instanceof mysqli) {
        $conn->close();
    }
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

if (!isset($conn) || $conn->connect_error) {
    send_json_error('Database connection object not available.', 500);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json_error('Invalid request method.', 405, $conn);
    }
    
    $input_data = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE || empty($input_data['event']) || empty($input_data['role']) || empty($input_data['timeSlot'])) {
        send_json_error('Invalid input. "event", "timeSlot", and "role" are required.', 400, $conn);
    }
    
    $event_name = $input_data['event'];
    $role_name = $input_data['role'];
    $time_slot = $input_data['timeSlot'];
    
    // 🔥 NEW CRITICAL LINE: Combine Day and Time Slot exactly as they appear in the database
    $combined_event = $event_name . ' - ' . $time_slot;

    $sql = "
        SELECT
            ld.name,
            DATE_FORMAT(lg.timestamp, '%H:%i:%s') AS timestamp 
        FROM
            logs AS lg
        JOIN
            lookup_data AS ld ON lg.scanned_id = ld.nfc_key
        WHERE
            lg.user = ? AND ld.role = ? -- Filtering by combined event and role
        ORDER BY
            lg.timestamp DESC;
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new Exception('SQL Prepare failed: ' . $conn->error);
    }

    // Two parameters: combined event string and the role name
    $stmt->bind_param('ss', $combined_event, $role_name);

    if (!$stmt->execute()) {
        throw new Exception('Statement execution failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();

    $users_data = $result->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    echo json_encode([
        'status' => 'success',
        'users' => $users_data
    ]);

} catch (Exception $e) {
    send_json_error('Server error: ' . $e->getMessage(), 500, $conn);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>