import mysql from 'mysql2/promise';

// --- Configuration ---
const dbConfig = {
    // We use environment variables so you don't commit secrets to code
    host: process.env.DB_HOST,      // This will be your Public IP: 35.236.177.76
    user: process.env.DB_USER,      // Usually 'root'
    password: process.env.DB_PASSWORD, 
    database: process.env.DB_NAME,  // e.g., 'nfc_db'
    port: 3306,
    
    // Google Cloud SQL requires SSL for Public IP connections by default.
    // 'rejectUnauthorized: false' allows connection without downloading Google's CA certs manually.
    ssl: {
        rejectUnauthorized: false
    },
    
    // Cloud connections can have slight latency, so we increase the timeout
    connectTimeout: 20000 
};

export default async function handler(req, res) {
    // 1. CORS Headers (Allows your frontend to connect from anywhere)
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
    res.setHeader('Content-Type', 'application/json; charset=UTF-8');

    // Handle Preflight Request (Browser Check)
    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    let connection;

    try {
        // 2. Parse Inputs (Handles both JSON Body and URL Query parameters)
        // Vercel automatically parses JSON body into req.body
        const inputData = req.body || {};
        const queryData = req.query || {};

        // Merge inputs, prioritizing body, then query
        const action = inputData.action || queryData.action || '';
        const scannedId = (inputData.nfc_id || queryData.nfc_id || '').trim();
        const terminalId = (inputData.terminal_id || queryData.terminal_id || 'UNKNOWN_TERMINAL').trim();

        // 3. Connect to Google Cloud SQL
        connection = await mysql.createConnection(dbConfig);

        // 4. Main Logic Switch
        switch (action) {
            case 'log':
                if (!scannedId) {
                    return res.status(400).json({ status: 'error', message: 'NFC ID required' });
                }

                // Create Bangkok Time (UTC+7)
                const now = new Date();
                const bangkokTime = new Date(now.getTime() + (7 * 60 * 60 * 1000));

                const [insertResult] = await connection.execute(
                    'INSERT INTO logs (timestamp, scanned_id, user) VALUES (?, ?, ?)',
                    [bangkokTime, scannedId, terminalId]
                );

                return res.status(200).json({ status: 'success', message: 'Logged successfully' });

            case 'lookup':
                if (!scannedId) {
                    return res.status(400).json({ status: 'error', message: 'NFC ID required' });
                }

                // Check for duplicates at this specific terminal
                const [dupRows] = await connection.execute(
                    'SELECT COUNT(id) as count FROM logs WHERE scanned_id = ? AND user = ?',
                    [scannedId, terminalId]
                );
                const isDuplicate = dupRows[0].count > 0;

                // Lookup User details
                const [userRows] = await connection.execute(
                    'SELECT nfc_key, name, details, role FROM lookup_data WHERE nfc_key = ?',
                    [scannedId]
                );

                if (userRows.length > 0) {
                    const user = userRows[0];
                    return res.status(200).json({
                        status: 'success',
                        row: [user.nfc_key, user.name, user.details, user.role],
                        message: isDuplicate ? `Duplicate: ${terminalId}` : `Welcome: ${terminalId}`,
                        isDuplicate: isDuplicate,
                        terminal: terminalId
                    });
                } else {
                    return res.status(404).json({ status: 'not_found', message: 'ID not found' });
                }

            case 'get_count':
                const [countRows] = await connection.execute(
                    'SELECT COUNT(id) as total FROM logs WHERE user = ?',
                    [terminalId]
                );
                
                return res.status(200).json({
                    status: 'success',
                    total_count: countRows[0].total || 0
                });

            default:
                return res.status(400).json({ status: 'error', message: 'Invalid action: ' + action });
        }

    } catch (error) {
        console.error('Database Error:', error);
        return res.status(500).json({
            status: 'error',
            message: 'Server Error: ' + error.message
        });
    } finally {
        // Always close the connection to prevent leaks
        if (connection) {
            await connection.end();
        }
    }
}