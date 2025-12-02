<?php
/**
 * API: get_roles_api.php - FINAL CORRECTION
 *
 * Fetches the summary of logins grouped by role for a specific combined event.
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

try {
    if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
        throw new Exception('Database connection object ($conn) not available or failed to connect.');
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json_error('Invalid request method.', 405, $conn);
    }
    
    $input_data = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE || empty($input_data['event']) || empty($input_data['timeSlot'])) {
        send_json_error('Invalid input. Both "event" and "timeSlot" are required.', 400, $conn);
    }
    
    $event_name = $input_data['event'];
    $time_slot = $input_data['timeSlot'];
    
    // 🔥 NEW CRITICAL LINE: Combine Day and Time Slot exactly as they appear in the database
    $combined_event = $event_name . ' - ' . $time_slot;

    $sql = "
        SELECT
            ld.role,
            COUNT(lg.id) AS count
        FROM
            logs AS lg
        JOIN
            lookup_data AS ld ON lg.scanned_id = ld.nfc_key
        WHERE
            lg.user = ?  -- Now filtering the 'user' column by the combined string
        GROUP BY
            ld.role
        ORDER BY
            CASE
                WHEN ld.role = 'Staff' THEN 1
                WHEN ld.role = 'Student' THEN 2
                WHEN ld.role = 'Guest' THEN 3
                WHEN ld.role = 'VIP' THEN 4
                ELSE 99
            END;
    ";

    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        throw new Exception('MySQLi prepare failed: ' . $conn->error);
    }
    
    // Only one parameter: the combined event string
    if (!$stmt->bind_param("s", $combined_event)) {
        throw new Exception('Parameter binding failed: ' . $stmt->error);
    }
    
    if (!$stmt->execute()) {
        throw new Exception('Statement execution failed: ' . $stmt->error);
    }

    $stmt->bind_result($role, $count);

    $roles_data = [];
    
    while ($stmt->fetch()) {
        $roles_data[] = [
            'role' => $role,
            'count' => $count
        ];
    }
    
    $stmt->close();

    $total_logins = array_sum(array_column($roles_data, 'count'));

    echo json_encode([
        'status' => 'success',
        'total_logins' => $total_logins,
        'roles' => $roles_data
    ]);
    
} catch (Exception $e) {
    send_json_error('Server error: ' . $e->getMessage(), 500, $conn);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>