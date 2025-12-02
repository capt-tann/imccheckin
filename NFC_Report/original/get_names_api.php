<?php
/**
 * Detail Report API - get_names_api.php (Final Simplified Filter)
 * Fetches individual user details using ONLY the user column for both filters.
 * Assumes: The Role name is embedded within the Event Name string in the 'user' column.
 */
require_once 'db_config.php'; 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['event']) || !isset($data['timeSlot']) || !isset($data['role'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing event, timeSlot, or role parameter.']);
    exit();
}

try {
    // Aggressively clean and prepare search patterns
    $trim_pattern = '/^\s+|\s+$/u';
    $clean_event = trim(preg_replace($trim_pattern, '', $data['event']));
    $clean_time_slot = trim(preg_replace($trim_pattern, '', $data['timeSlot']));
    $clean_role = trim(preg_replace($trim_pattern, '', $data['role']));
    
    // Pattern: Match the full event name (expected in the 'user' column)
    $event_search_pattern = "%" . $clean_event . " - " . $clean_time_slot . "%";
    
    // Combined Filter: Search the 'user' column for BOTH the Event name AND the Role name.
    // Example: user LIKE '%Pre-IMC 2 Nov - Lunch%Staff%' (This catches records where the role is appended to the event name)
    $combined_search_pattern = "%" . $clean_event . " - " . $clean_time_slot . "%" . $clean_role . "%";


    // --- Query: Fetch details ---
    $sql = "
        SELECT 
            scanned_id,       /* User ID (NFC ID) */
            terminal_id,      /* Data we will use for Team/Group */
            timestamp
        FROM 
            logs
        WHERE 
            -- Filter 1 (Event/Time Slot): Match the full event string against the 'user' column 
            user LIKE ? 
            -- AND Filter 2 (Role): Match the role against the terminal_id (The previous successful structure)
            AND terminal_id LIKE ?
        ORDER BY 
            timestamp DESC
    ";

    $user_details = [];
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new Exception("SQL Prepare Failed: " . $conn->error);
    }

    // Bind both the event pattern and the role pattern to the correct columns
    $stmt->bind_param("ss", $event_search_pattern, $role_search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // *** DATA MAPPING: Applying the user/team name fix ***
    while ($row = $result->fetch_assoc()) {
        $checkin_time = (new DateTime($row['timestamp']))->format('Y-m-d H:i:s');
        
        $user_details[] = [
            // 1. User: Use the terminal_id column (as it holds the name/division like "Other divisions")
            $row['terminal_id'],    
            // 2. Team/Group: Use the scanned_id (since we don't have a team field)
            $row['scanned_id'],           
            // 3. Check-in Time
            $checkin_time         
        ];
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        'status' => 'success',
        'role' => $data['role'],
        'users' => $user_details
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