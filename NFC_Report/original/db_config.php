<?php
/**
 * Database Configuration File
 * Used to establish a connection to the MySQL database.
 * Credentials are based on the infinityfree.me panel screenshot.
 */

// --- MANDATORY: UPDATE THIS SECTION ---
define('DB_SERVER', 'sql100.infinityfree.com');
define('DB_USERNAME', 'if0_40273776');
define('DB_PASSWORD', 'TkOzeyDoBg'); // <<-- REPLACE THIS!
define('DB_NAME', 'if0_40273776_nfc_db');
// ---------------------------------------

// Attempt to establish a connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // Return an error in JSON format for the front-end to handle
    header('Content-Type: application/json');
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}

// Set character set to UTF-8
$conn->set_charset("utf8mb4");
?>