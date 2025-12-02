<?php
// 1. FIXED: Suppress HTML error output so it doesn't break your JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 2. FIXED: Set Timezone to prevent PHP Warning (HTML) on shared hosting
date_default_timezone_set('Asia/Bangkok'); 

// Set headers for CORS and JSON response
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS"); // Added OPTIONS
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle Preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- MySQL Connection Details ---
define('DB_SERVER', 'sql100.infinityfree.com');
define('DB_USERNAME', 'if0_40273776');
define('DB_PASSWORD', 'TkOzeyDoBg');
define('DB_NAME', 'if0_40273776_nfc_db');

function getDbConnection() {
    // Suppress connection errors (@) to handle them manually
    $conn = @new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        // Return JSON error instead of die() text
        echo json_encode(["status" => "error", "message" => "Connection failed: " . $conn->connect_error]);
        exit();
    }
    return $conn;
}

function initializeDatabase($conn) {
    // Create LOGS table
    $sql_logs = "CREATE TABLE IF NOT EXISTS logs (
            id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            timestamp DATETIME NOT NULL,
            scanned_id VARCHAR(255) NOT NULL,
            user VARCHAR(255),
            terminal_id VARCHAR(255)
        ) ENGINE=InnoDB;";
    $conn->query($sql_logs);

    // Create LOOKUP table
    $sql_lookup = "CREATE TABLE IF NOT EXISTS lookup_data (
            id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nfc_key VARCHAR(255) UNIQUE NOT NULL,
            name VARCHAR(255),
            details VARCHAR(255),
            role VARCHAR(255)
        ) ENGINE=InnoDB;";
    $conn->query($sql_lookup);

    // Populate data safely
    $check_sql = "SELECT COUNT(*) AS count FROM lookup_data";
    $result = $conn->query($check_sql);
    
    // 3. FIXED: Check if query succeeded before fetching
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['count'] == 0) {
            $stmt = $conn->prepare("INSERT INTO lookup_data (nfc_key, name, details) VALUES (?, ?, ?)");
            if ($stmt) {
                $data = [
                    ['ID101', 'Alice Johnson', 'Department A'],
                    ['ID202', 'Bob Smith', 'Department B'],
                    ['ID303', 'Charlie Brown', 'Department C']
                ];
                foreach ($data as $entry) {
                    $stmt->bind_param("sss", $entry[0], $entry[1], $entry[2]);
                    $stmt->execute();
                }
                $stmt->close();
            }
        }
    }
}

// --- Main Logic ---

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 4. FIXED: Handle both JSON Body (POST) and URL Parameters (GET)
// This ensures 'get_count' works even if sent as a simple GET request
$inputJSON = file_get_contents("php://input");
$inputData = json_decode($inputJSON, true);

$scannedId = '';
$terminalId = 'UNKNOWN_TERMINAL';

if (!empty($inputData)) {
    // Data came from JSON Body (POST)
    $scannedId = isset($inputData['nfc_id']) ? trim($inputData['nfc_id']) : '';
    $terminalId = isset($inputData['terminal_id']) ? trim($inputData['terminal_id']) : 'UNKNOWN_TERMINAL';
} else {
    // Data might be in URL Parameters (GET)
    $scannedId = isset($_GET['nfc_id']) ? trim($_GET['nfc_id']) : '';
    $terminalId = isset($_GET['terminal_id']) ? trim($_GET['terminal_id']) : 'UNKNOWN_TERMINAL';
}

// Connect
$conn = getDbConnection();
initializeDatabase($conn);

switch ($action) {
    case 'log':
        if (empty($scannedId)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "NFC ID required"]);
            break;
        }

        $timestamp = date('Y-m-d H:i:s');
        // Note: You are storing terminalId in the 'user' column. 
        $stmt = $conn->prepare("INSERT INTO logs (timestamp, scanned_id, user) VALUES (?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("sss", $timestamp, $scannedId, $terminalId);
            if ($stmt->execute()) {
                echo json_encode(["status" => "success", "message" => "Logged successfully"]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Insert failed: " . $stmt->error]);
            }
            $stmt->close();
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Prepare failed: " . $conn->error]);
        }
        break;

    case 'lookup':
        if (empty($scannedId)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "NFC ID required"]);
            break;
        }

        // Check duplicates
        $is_duplicate = false;
        $stmt_check = $conn->prepare("SELECT COUNT(id) AS count FROM logs WHERE scanned_id = ? AND user = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("ss", $scannedId, $terminalId);
            $stmt_check->execute();
            $res = $stmt_check->get_result();
            $row = $res->fetch_assoc();
            if ($row['count'] > 0) { // If count > 0, they have been here before (changed from > 1)
                 $is_duplicate = true;
            }
            $stmt_check->close();
        }

        // Perform Lookup
        $stmt = $conn->prepare("SELECT nfc_key, name, details, role FROM lookup_data WHERE nfc_key = ?");
        if ($stmt) {
            $stmt->bind_param("s", $scannedId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $foundRow = [$row['nfc_key'], $row['name'], $row['details'], $row['role']];
                $msg = $is_duplicate ? "Already checked in at $terminalId" : "Check-in successful at $terminalId";
                
                echo json_encode([
                    "status" => "success",
                    "row" => $foundRow,
                    "message" => $msg,
                    "isDuplicate" => $is_duplicate,
                    "terminal" => $terminalId
                ]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "not_found", "message" => "ID not found"]);
            }
            $stmt->close();
        }
        break;

    case 'logs':
        $result = $conn->query("SELECT * FROM logs ORDER BY timestamp DESC");
        $logs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }
        }
        echo json_encode(["status" => "success", "logs" => $logs]);
        break;

    case 'get_count':
        // 5. FIXED: Logic for getting count
        if (empty($terminalId) || $terminalId == 'UNKNOWN_TERMINAL') {
             // If terminal ID is missing, just return 0 or global count, don't error
             // Or strict:
             http_response_code(400);
             echo json_encode(["status" => "error", "message" => "Terminal ID missing"]);
             break;
        }

        $stmt = $conn->prepare("SELECT COUNT(id) AS total_count FROM logs WHERE user = ?");
        if ($stmt) {
            $stmt->bind_param("s", $terminalId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            echo json_encode([
                "status" => "success",
                "total_count" => (int)($row['total_count'] ?? 0)
            ]);
            $stmt->close();
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Query failed"]);
        }
        break;

    default:
        // 6. FIXED: Moved default to the end
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid action"]);
        break;
}

$conn->close();
?>