<?php
/**
 * Role Report API - get_roles_api.php (Corrected Grouping by terminal_id)
 * Fetches total scan counts grouped by Role (terminal_id) and sorts them based on a predefined list.
 */
require_once 'db_config.php'; 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['event']) || !isset($data['timeSlot'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing event or timeSlot parameter.']);
    exit();
}

try {
    // Aggressively clean and prepare search patterns
    $trim_pattern = '/^\s+|\s+$/u';
    $clean_event = trim(preg_replace($trim_pattern, '', $data['event']));
    $clean_time_slot = trim(preg_replace($trim_pattern, '', $data['timeSlot']));
    
    // Pattern: Match the full event name (expected in the 'user' column)
    $event_search_pattern = "%" . $clean_event . " - " . $clean_time_slot . "%";

    // --- Define the Custom Role Order ---
    $role_order = ['Staff', 'Competition', 'Activity'];
    $order_list_string = "'" . implode("','", $role_order) . "'";


    $sql = "
        SELECT 
            terminal_id AS role,       
            COUNT(*) AS count
        FROM 
            logs 
        WHERE 
            -- FILTER: Use 'user' column for the event name filter
            user LIKE ? 
        GROUP BY 
            -- GROUPING: Use 'terminal_id' column to group the roles
            role
        ORDER BY 
            FIELD(terminal_id, {$order_list_string}),
            role ASC
    ";

    // ... (rest of the database execution logic remains the same) ...

    // --- Execute Query ---
    $report_data = [];
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new Exception("SQL Prepare Failed: " . $conn->error);
    }

    $stmt->bind_param("s", $event_search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $report_data[] = [
            'role' => trim($row['role']),
            'count' => (int)$row['count']
        ];
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        'status' => 'success',
        'event' => $clean_event,
        'timeSlot' => $clean_time_slot,
        'total_logins' => array_sum(array_column($report_data, 'count')),
        'roles' => $report_data
    ]);

} catch (Exception $e) {
    if ($conn && !$conn->connect_error) {
        $conn->close();
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Internal Server Error: ' . $e->getMessage()
    ]);
}
?>