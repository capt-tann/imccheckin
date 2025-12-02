<?php
/**
 * Role Details API - role_details_api.php (REVERTED: Now showing scanned_id for USER)
 * Fetches individual user check-in details for a specific user_role and terminal_id.
 */
require_once 'db_config.php'; 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow cross-origin requests

// Get JSON data from POST request
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['terminal_id']) || !isset($data['user_role'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing terminal_id or user_role parameter.']);
    exit();
}

try {
    // Aggressively clean the input strings
    $trim_pattern = '/^\s+|\s+$/u';
    $terminal_id = preg_replace($trim_pattern, '', $data['terminal_id']);
    $user_role = preg_replace($trim_pattern, '', $data['user_role']);

    // --- FIX: Use LIKE %...% to match hidden characters around the main string ---
    // The search pattern for the event name (terminal_id)
    $terminal_search_pattern = "%" . $terminal_id . "%";
    
    // The search pattern for the specific role name
    $role_search_pattern = "%" . $user_role . "%";

    // --- Query to get individual logs for the specific role and terminal ---
    $sql = "
        SELECT 
            scanned_id,       /* <-- REVERTED: PULLING scanned_id FOR USER */
            user,             
            terminal_id,      /* <-- PULLING 'terminal_id' FOR TEAM/GROUP */
            timestamp
        FROM 
            logs
        WHERE 
            -- Match the full event name (terminal_id) against the 'user' column
            user LIKE ? 
            -- AND match the specific role name (user_role) against the 'user' column
            AND user LIKE ? 
        ORDER BY 
            timestamp DESC
    ";

    $user_details = [];
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new Exception("SQL Prepare Failed: " . $conn->error);
    }

    // Bind both the terminal search pattern and the role search pattern
    $stmt->bind_param("ss", $terminal_search_pattern, $role_search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // Data format: [Name (scanned_id), Team/Group (terminal_id), Check-in Time]
        $checkin_time = (new DateTime($row['timestamp']))->format('Y-m-d H:i:s');
        
        // --- CHANGE IS HERE: Use $row['scanned_id'] for the User column ---
        $user_details[] = [
            $row['scanned_id'],    // Reverted back to scanned_id
            $row['terminal_id'],   
            $checkin_time         
        ];
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        'status' => 'success',
        'user_role' => $user_role,
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