<?php
/**
 * Database Connection Test Script
 * Use this file to debug connectivity and credentials.
 */

// Include the configuration file
require_once 'db_config.php';

// Set headers for plain text output
header('Content-Type: text/plain');

echo "--- NFC Check-in Database Connection Test ---\n\n";

// The $conn object is available here if db_config.php did not exit on error.
if ($conn->connect_error) {
    echo "❌ CRITICAL FAILURE: Database connection failed during require_once.\n";
    echo "Error: " . $conn->connect_error . "\n";
    exit();
} else {
    echo "✅ SUCCESS: Database connection established.\n";
    echo "Connection Details:\n";
    echo "  Server: " . DB_SERVER . "\n";
    echo "  User: " . DB_USERNAME . "\n";
    echo "  Database: " . DB_NAME . "\n";
}

echo "\n------------------------------------------------\n";
echo "Step 2: Checking Data for 'Pre-IMC 2 Nov - Lunch'\n";
echo "------------------------------------------------\n";

$test_terminal_id = 'Pre-IMC 2 Nov - Lunch';
$total_scans = 0;

// Use the query from report_api.php to test the data fetch
$stmt_test = $conn->prepare("SELECT COUNT(*) FROM logs WHERE terminal_id = ?");

if ($stmt_test === false) {
    echo "❌ QUERY PREPARATION FAILED: Check if table 'logs' exists and is spelled correctly.\n";
    echo "MySQL Error: " . $conn->error . "\n";
} else {
    $stmt_test->bind_param("s", $test_terminal_id);
    $stmt_test->execute();
    $stmt_test->bind_result($total_scans);
    $stmt_test->fetch();
    $stmt_test->close();

    if ($total_scans > 0) {
        echo "🎉 SUCCESS: Found " . $total_scans . " records for terminal_id: '" . $test_terminal_id . "'\n";
        echo "The PHP code and database connection are working.\n";
        echo "The issue is likely in the front-end (index.html) or the report_api.php file placement.\n";
    } else {
        echo "⚠️ WARNING: Found 0 records for terminal_id: '" . $test_terminal_id . "'\n";
        echo "Double-check the terminal_id value in your database 'logs' table to ensure it matches exactly (case and spacing).\n";
    }
}

$conn->close();
echo "\n--- Test Complete ---\n";
?>
