import mysql from 'mysql2/promise';

// --- Configuration ---
// ideally, put these in a .env file on Vercel (Settings > Environment Variables)
const dbConfig = {
    host: process.env.DB_HOST || 'sql100.infinityfree.com',
    user: process.env.DB_USER || 'if0_40273776',
    password: process.env.DB_PASSWORD || 'TkOzeyDoBg',
    database: process.env.DB_NAME || 'if0_40273776_nfc_db',
    connectTimeout: 10000 // 10s timeout
};

export default async function handler(req, res) {
    // 1. CORS & Headers
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
    res.setHeader('Content-Type', 'application/json; charset=UTF-8');

    // Handle Preflight OPTIONS request
    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    let connection;

    try {
        // 2. Parse Inputs (Handles both POST body and GET query)
        // Vercel parses JSON body automatically into req.body
        const inputData = req.body || {};
        const queryData = req.query || {};

        const action = queryData.action || inputData.action || '';
        
        const scannedId = (inputData.nfc_id || queryData.nfc_id || '').trim();
        // In your PHP logic, you stored terminal_id in the 'user' column
        const terminalId = (inputData.terminal_id || queryData.terminal_id || 'UNKNOWN_TERMINAL').trim();

        // 3. Connect to Database
        connection = await mysql.createConnection(dbConfig);

        // 4. Initialize Database (Same logic as your PHP)
        await initializeDatabase(connection);

        // 5. Main Logic Switch
        switch (action) {
            case 'log':
                if (!scannedId) {
                    return res.status(400).json({ status: 'error', message: 'NFC ID required' });
                }

                // Get current time in Bangkok (UTC+7)
                const now = new Date();
                const offset = 7 * 60 * 60 * 1000;
                const bangkokTime = new Date(now.getTime() + offset).toISOString().slice(0, 19).replace('T', ' ');

                const [insertResult] = await connection.execute(
                    'INSERT INTO logs (timestamp, scanned_id, user) VALUES (?, ?, ?)',
                    [bangkokTime, scannedId, terminalId]
                );

                if (insertResult.affectedRows > 0) {
                    return res.status(200).json({ status: 'success', message: 'Logged successfully' });
                } else {
                    throw new Error('Insert failed');
                }

            case 'lookup':
                if (!scannedId) {
                    return res.status(400).json({ status: 'error', message: 'NFC ID required' });
                }

                // Check duplicates
                // Note: Your PHP logic set isDuplicate = true if count > 0
                const [dupRows] = await connection.execute(
                    'SELECT COUNT(id) AS count FROM logs WHERE scanned_id = ? AND user = ?',
                    [scannedId, terminalId]
                );
                const isDuplicate = dupRows[0].count > 0;

                // Perform Lookup
                const [lookupRows] = await connection.execute(
                    'SELECT nfc_key, name, details, role FROM lookup_data WHERE nfc_key = ?',
                    [scannedId]
                );

                if (lookupRows.length > 0) {
                    const row = lookupRows[0];
                    // Format matching your PHP response: [nfc_key, name, details, role]
                    const foundRow = [row.nfc_key, row.name, row.details, row.role];
                    const msg = isDuplicate 
                        ? `Already checked in at ${terminalId}` 
                        : `Check-in successful at ${terminalId}`;

                    return res.status(200).json({
                        status: 'success',
                        row: foundRow,
                        message: msg,
                        isDuplicate: isDuplicate,
                        terminal: terminalId
                    });
                } else {
                    return res.status(404).json({ status: 'not_found', message: 'ID not found' });
                }

            case 'logs':
                const [allLogs] = await connection.execute('SELECT * FROM logs ORDER BY timestamp DESC');
                return res.status(200).json({ status: 'success', logs: allLogs });

            case 'get_count':
                if (!terminalId || terminalId === 'UNKNOWN_TERMINAL') {
                    // Fail gracefully or strictly as per your PHP
                    return res.status(400).json({ status: 'error', message: 'Terminal ID missing' });
                }

                const [countRows] = await connection.execute(
                    'SELECT COUNT(id) AS total_count FROM logs WHERE user = ?',
                    [terminalId]
                );
                
                return res.status(200).json({
                    status: 'success',
                    total_count: countRows[0].total_count || 0
                });

            default:
                return res.status(400).json({ status: 'error', message: 'Invalid action' });
        }

    } catch (error) {
        console.error('Database/Server Error:', error);
        return res.status(500).json({
            status: 'error',
            message: 'Internal Server Error: ' + error.message
        });
    } finally {
        if (connection) {
            await connection.end();
        }
    }
}

// Helper: Initialize Tables (Ported from PHP)
async function initializeDatabase(conn) {
    // Create LOGS table
    await conn.execute(`
        CREATE TABLE IF NOT EXISTS logs (
            id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            timestamp DATETIME NOT NULL,
            scanned_id VARCHAR(255) NOT NULL,
            user VARCHAR(255),
            terminal_id VARCHAR(255)
        ) ENGINE=InnoDB;
    `);

    // Create LOOKUP table
    await conn.execute(`
        CREATE TABLE IF NOT EXISTS lookup_data (
            id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nfc_key VARCHAR(255) UNIQUE NOT NULL,
            name VARCHAR(255),
            details VARCHAR(255),
            role VARCHAR(255)
        ) ENGINE=InnoDB;
    `);

    // Check content
    const [rows] = await conn.execute('SELECT COUNT(*) AS count FROM lookup_data');
    if (rows[0].count == 0) {
        const initialData = [
            ['ID101', 'Alice Johnson', 'Department A'],
            ['ID202', 'Bob Smith', 'Department B'],
            ['ID303', 'Charlie Brown', 'Department C']
        ];
        for (const entry of initialData) {
            await conn.execute(
                'INSERT INTO lookup_data (nfc_key, name, details) VALUES (?, ?, ?)',
                entry
            );
        }
    }
}