<?php
/**
 * Main Report API - report_api.php (Database Logic Restored with Error Handling)
 * Fetches total scan counts grouped by 'user' (user's role) 
 * for the selected terminal_id (event day/slot).
 */
require_once 'db_config.php'; 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow cross-origin requests

// Get JSON data from POST request
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['terminal_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing terminal_id parameter.']);
    exit();
}

try {
    // Aggressively clean the input string to match potential messy database content
    $trim_pattern = '/^\s+|\s+$/u';
    $terminal_id = preg_replace($trim_pattern, '', $data['terminal_id']);
    
    // *** FIX: Use LIKE %...% to match hidden characters around the main string ***
    $search_pattern = "%" . $terminal_id . "%";

    // --- Query 1: Get the total number of scans (all roles for this specific terminal) ---
    $total_scans = 0;
    // WE ARE NOW SEARCHING THE 'user' COLUMN FOR THE TERMINAL ID.
    $stmt_total = $conn->prepare("SELECT COUNT(*) FROM logs WHERE user LIKE ?"); 
    
    if ($stmt_total === false) {
         throw new Exception("SQL Prepare Failed (Total Count): " . $conn->error);
    }
    
    // Bind the search pattern
    $stmt_total->bind_param("s", $search_pattern);
    $stmt_total->execute();
    $stmt_total->bind_result($total_scans);
    $stmt_total->fetch();
    $stmt_total->close();


    // --- Query 2: Get the total scans grouped by user (which serves as the role in this context) ---
    $sql = "
        SELECT 
            user AS user_role,              
            COUNT(*) AS total_count
        FROM 
            logs 
        WHERE 
            user LIKE ? 
        GROUP BY 
            user_role
        ORDER BY 
            total_count DESC
    ";

    $report_data = [];
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new Exception("SQL Prepare Failed (Grouped Report): " . $conn->error);
    }

    // Bind the search pattern
    $stmt->bind_param("s", $search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $report_data[] = [
            // Note: The role shown is the 'user' column value from the logs table
            'user_role' => trim($row['user_role']),
            'total_count' => (int)$row['total_count']
        ];
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        'status' => 'success',
        'terminal_id' => $terminal_id,
        'total_scans' => (int)$total_scans,
        'report' => $report_data
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
