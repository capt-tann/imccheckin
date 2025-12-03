import mysql from 'mysql2/promise';

// --- Configuration ---
const dbConfig = {
    host: process.env.DB_HOST,
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD, 
    database: process.env.DB_NAME,
    port: 3306,
    ssl: { rejectUnauthorized: false },
    connectTimeout: 20000 
};

export default async function handler(req, res) {
    // 1. CORS Headers
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
    res.setHeader('Content-Type', 'application/json; charset=UTF-8');

    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    let connection;

    try {
        // 2. Parse Inputs
        const inputData = req.body || {};
        const queryData = req.query || {};

        const action = inputData.action || queryData.action || '';
        
        // Scan Inputs
        const scannedId = (inputData.nfc_id || queryData.nfc_id || '').trim();
        const terminalId = (inputData.terminal_id || queryData.terminal_id || 'UNKNOWN_TERMINAL').trim();

        // Report Inputs
        const event = inputData.event || '';
        const timeSlot = inputData.timeSlot || '';
        const role = inputData.role || '';

        // 3. Connect to Database
        connection = await mysql.createConnection(dbConfig);

        // 4. Main Logic Switch
        switch (action) {
            // --- EXISTING SCANNING LOGIC ---
            case 'log':
                if (!scannedId) return res.status(400).json({ status: 'error', message: 'NFC ID required' });

                const now = new Date();
                const bangkokTime = new Date(now.getTime() + (7 * 60 * 60 * 1000));

                await connection.execute(
                    'INSERT INTO logs (timestamp, scanned_id, user) VALUES (?, ?, ?)',
                    [bangkokTime, scannedId, terminalId]
                );
                return res.status(200).json({ status: 'success', message: 'Logged successfully' });

            case 'lookup':
                if (!scannedId) return res.status(400).json({ status: 'error', message: 'NFC ID required' });

                const [dupRows] = await connection.execute(
                    'SELECT COUNT(id) as count FROM logs WHERE scanned_id = ? AND user = ?',
                    [scannedId, terminalId]
                );
                const isDuplicate = dupRows[0].count > 0;

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
                return res.status(200).json({ status: 'success', total_count: countRows[0].total || 0 });

            // --- NEW REPORTING LOGIC ---
            case 'get_report_roles':
                // Ported from get_roles_api.php
                if (!event || !timeSlot) {
                    return res.status(400).json({ status: 'error', message: 'Event and Time Slot required' });
                }

                // Combine exactly as they are stored in the DB (e.g. "IMC 4 Dec - Lunch")
                const combinedEventRoles = `${event} - ${timeSlot}`;

                const [roleData] = await connection.execute(`
                    SELECT 
                        ld.role, 
                        COUNT(lg.id) as count
                    FROM logs lg
                    JOIN lookup_data ld ON lg.scanned_id = ld.nfc_key
                    WHERE lg.user = ? 
                    GROUP BY ld.role
                    ORDER BY 
                        CASE 
                            WHEN ld.role = 'Staff' THEN 1
                            WHEN ld.role = 'Student' THEN 2
                            WHEN ld.role = 'Guest' THEN 3
                            WHEN ld.role = 'VIP' THEN 4
                            ELSE 99 
                        END ASC
                `, [combinedEventRoles]);

                const totalLogins = roleData.reduce((sum, row) => sum + row.count, 0);

                return res.status(200).json({
                    status: 'success',
                    total_logins: totalLogins,
                    roles: roleData
                });

            case 'get_report_users':
                // Ported from get_names_api.php
                if (!event || !timeSlot || !role) {
                    return res.status(400).json({ status: 'error', message: 'Event, Time Slot, and Role required' });
                }

                const combinedEventUsers = `${event} - ${timeSlot}`;

                const [usersData] = await connection.execute(`
                    SELECT 
                        ld.name, 
                        DATE_FORMAT(lg.timestamp, '%H:%i:%s') as timestamp,
                        ld.details as 'group'
                    FROM logs lg
                    JOIN lookup_data ld ON lg.scanned_id = ld.nfc_key
                    WHERE lg.user = ? AND ld.role = ?
                    ORDER BY lg.timestamp DESC
                `, [combinedEventUsers, role]);

                return res.status(200).json({
                    status: 'success',
                    users: usersData
                });

            default:
                return res.status(400).json({ status: 'error', message: 'Invalid action: ' + action });
        }

    } catch (error) {
        console.error('Database Error:', error);
        return res.status(500).json({ status: 'error', message: 'Server Error: ' + error.message });
    } finally {
        if (connection) await connection.end();
    }
}