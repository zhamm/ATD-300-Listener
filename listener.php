<?php
require __DIR__ . '/vendor/autoload.php';

$port = 8880;
$dbDir = __DIR__ . '/db';

// ── Create db directory ───────────────────────────────────────────────

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

// ── SQLite3 setup ─────────────────────────────────────────────────────

$db = new SQLite3($dbDir . '/atd300.sqlite3');
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode=WAL');

$db->exec('CREATE TABLE IF NOT EXISTS measures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id INTEGER,
    laeq REAL,
    laeq_chan3 REAL,
    sensor_datetime TEXT,
    received_at TEXT,
    raw_json TEXT
)');

$db->exec('CREATE TABLE IF NOT EXISTS soh (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id INTEGER,
    status TEXT,
    temp TEXT,
    cpu_usage REAL,
    ram_usage REAL,
    disk_usage REAL,
    latitude REAL,
    longitude REAL,
    uptime REAL,
    fw_version TEXT,
    ip TEXT,
    orientation REAL,
    sensor_datetime TEXT,
    received_at TEXT,
    raw_json TEXT
)');

$db->exec('CREATE TABLE IF NOT EXISTS threats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id INTEGER,
    threat_type TEXT,
    probability REAL,
    azimuth REAL,
    elevation REAL,
    distance REAL,
    fw_version TEXT,
    nn_version TEXT,
    sensor_datetime TEXT,
    received_at TEXT,
    raw_json TEXT
)');

echo "[DB] SQLite database ready at db/atd300.sqlite3\n";

// ── Prepared statements (with schema validation) ─────────────────────

$sqlMeasure = 'INSERT INTO measures (device_id, laeq, laeq_chan3, sensor_datetime, received_at, raw_json) VALUES (:did, :laeq, :ch3, :sdt, :rat, :raw)';
$sqlSoh     = 'INSERT INTO soh (device_id, status, temp, cpu_usage, ram_usage, disk_usage, latitude, longitude, uptime, fw_version, ip, orientation, sensor_datetime, received_at, raw_json) VALUES (:did, :status, :temp, :cpu, :ram, :disk, :lat, :lon, :uptime, :fw, :ip, :orient, :sdt, :rat, :raw)';
$sqlThreat  = 'INSERT INTO threats (device_id, threat_type, probability, azimuth, elevation, distance, fw_version, nn_version, sensor_datetime, received_at, raw_json) VALUES (:did, :type, :prob, :az, :el, :dist, :fw, :nn, :sdt, :rat, :raw)';

// Try to prepare; if schema is outdated, drop and recreate the table
function safePrepare($db, $table, $createSql, $insertSql) {
    $stmt = $db->prepare($insertSql);
    if ($stmt !== false) return $stmt;

    echo "[DB] Schema mismatch on '{$table}' — recreating table...\n";
    $db->exec("DROP TABLE IF EXISTS {$table}");
    $db->exec($createSql);
    $stmt = $db->prepare($insertSql);
    if ($stmt === false) {
        die("[FATAL] Cannot prepare statement for {$table}: " . $db->lastErrorMsg() . "\n");
    }
    return $stmt;
}

$stmtMeasure = safePrepare($db, 'measures',
    'CREATE TABLE IF NOT EXISTS measures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id INTEGER, laeq REAL, laeq_chan3 REAL,
        sensor_datetime TEXT, received_at TEXT, raw_json TEXT
    )', $sqlMeasure);

$stmtSoh = safePrepare($db, 'soh',
    'CREATE TABLE IF NOT EXISTS soh (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id INTEGER, status TEXT, temp TEXT, cpu_usage REAL, ram_usage REAL,
        disk_usage REAL, latitude REAL, longitude REAL, uptime REAL, fw_version TEXT,
        ip TEXT, orientation REAL, sensor_datetime TEXT,
        received_at TEXT, raw_json TEXT
    )', $sqlSoh);

$stmtThreat = safePrepare($db, 'threats',
    'CREATE TABLE IF NOT EXISTS threats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id INTEGER, threat_type TEXT, probability REAL, azimuth REAL,
        elevation REAL, distance REAL, fw_version TEXT, nn_version TEXT,
        sensor_datetime TEXT, received_at TEXT, raw_json TEXT
    )', $sqlThreat);

// ── SSE clients ───────────────────────────────────────────────────────

$sseClients = [];

function broadcast($type, $deviceId, $json) {
    global $sseClients;
    $payload = json_encode([
        'type'      => $type,
        'timestamp' => date('Y-m-d H:i:s'),
        'device_id' => $deviceId,
        'data'      => $json,
    ]);
    $sse = "data: {$payload}\n\n";
    $dead = [];
    foreach ($sseClients as $id => $client) {
        try {
            $ok = $client->write($sse);
            if ($ok === false) $dead[] = $id;
        } catch (\Exception $e) {
            $dead[] = $id;
        }
    }
    foreach ($dead as $id) unset($sseClients[$id]);
}

// ── Store data in SQLite ──────────────────────────────────────────────

function storeMeasure($json) {
    global $stmtMeasure;
    $stmtMeasure->bindValue(':did',  $json['device_id'] ?? 0, SQLITE3_INTEGER);
    $stmtMeasure->bindValue(':laeq', $json['laeq'] ?? 0, SQLITE3_FLOAT);
    $stmtMeasure->bindValue(':ch3',  $json['laeq_chan3'] ?? 0, SQLITE3_FLOAT);
    $stmtMeasure->bindValue(':sdt',  $json['datetime'] ?? '', SQLITE3_TEXT);
    $stmtMeasure->bindValue(':rat',  date('Y-m-d H:i:s'), SQLITE3_TEXT);
    $stmtMeasure->bindValue(':raw',  json_encode($json), SQLITE3_TEXT);
    $stmtMeasure->execute();
    $stmtMeasure->reset();
}

function storeSoh($json) {
    global $stmtSoh;
    $stmtSoh->bindValue(':did',    $json['device_id'] ?? 0, SQLITE3_INTEGER);
    $stmtSoh->bindValue(':status', $json['status'] ?? '', SQLITE3_TEXT);
    $stmtSoh->bindValue(':temp',   $json['temp'] ?? '', SQLITE3_TEXT);
    $stmtSoh->bindValue(':cpu',    $json['CPU_usage'] ?? 0, SQLITE3_FLOAT);
    $stmtSoh->bindValue(':ram',    $json['RAM_usage'] ?? 0, SQLITE3_FLOAT);
    $stmtSoh->bindValue(':disk',   $json['disk_usage'] ?? 0, SQLITE3_FLOAT);
    $stmtSoh->bindValue(':lat',    $json['Pod_latitude'] ?? 0, SQLITE3_FLOAT);
    $stmtSoh->bindValue(':lon',    $json['Pod_longitude'] ?? 0, SQLITE3_FLOAT);
    $stmtSoh->bindValue(':uptime', $json['uptime'] ?? 0, SQLITE3_FLOAT);
    $stmtSoh->bindValue(':fw',     $json['fw_version'] ?? '', SQLITE3_TEXT);
    $stmtSoh->bindValue(':ip',     $json['ip'] ?? '', SQLITE3_TEXT);
    $stmtSoh->bindValue(':orient', $json['orientation'] ?? 0, SQLITE3_FLOAT);
    $stmtSoh->bindValue(':sdt',    $json['datetime'] ?? '', SQLITE3_TEXT);
    $stmtSoh->bindValue(':rat',    date('Y-m-d H:i:s'), SQLITE3_TEXT);
    $stmtSoh->bindValue(':raw',    json_encode($json), SQLITE3_TEXT);
    $stmtSoh->execute();
    $stmtSoh->reset();
}

function storeThreat($json) {
    global $stmtThreat;
    $stmtThreat->bindValue(':did',  $json['device_id'] ?? 0, SQLITE3_INTEGER);
    $stmtThreat->bindValue(':type', $json['threat'] ?? '', SQLITE3_TEXT);
    $stmtThreat->bindValue(':prob', $json['proba'] ?? 0, SQLITE3_FLOAT);
    $stmtThreat->bindValue(':az',   $json['azimut'] ?? 0, SQLITE3_FLOAT);
    $stmtThreat->bindValue(':el',   $json['elevation'] ?? 0, SQLITE3_FLOAT);
    $stmtThreat->bindValue(':dist', $json['distance'] ?? 0, SQLITE3_FLOAT);
    $stmtThreat->bindValue(':fw',   $json['fw_version'] ?? '', SQLITE3_TEXT);
    $stmtThreat->bindValue(':nn',   $json['nn_version'] ?? '', SQLITE3_TEXT);
    $stmtThreat->bindValue(':sdt',  $json['datetime'] ?? '', SQLITE3_TEXT);
    $stmtThreat->bindValue(':rat',  date('Y-m-d H:i:s'), SQLITE3_TEXT);
    $stmtThreat->bindValue(':raw',  json_encode($json), SQLITE3_TEXT);
    $stmtThreat->execute();
    $stmtThreat->reset();
}

// ── JSON API response helper ──────────────────────────────────────────

function sendJson($conn, $data) {
    $json = json_encode($data);
    $response  = "HTTP/1.1 200 OK\r\n";
    $response .= "Content-Type: application/json\r\n";
    $response .= "Content-Length: " . strlen($json) . "\r\n";
    $response .= "Access-Control-Allow-Origin: *\r\n";
    $response .= "Connection: close\r\n\r\n";
    $response .= $json;
    $conn->write($response);
    $conn->end();
}

function queryAll($sql) {
    global $db;
    $result = $db->query($sql);
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

// ── Process a complete HTTP request ───────────────────────────────────

function processRequest($conn, $headerBlock, $body) {
    global $sseClients;

    $requestLine = strtok($headerBlock, "\r\n");
    preg_match('/^(GET|POST|PUT|DELETE|OPTIONS)\s+(\S+)/i', $requestLine, $rm);
    $method = $rm[1] ?? '?';
    $fullPath = $rm[2] ?? '?';

    // Parse query string
    $pathParts = explode('?', $fullPath, 2);
    $path = $pathParts[0];
    $queryString = $pathParts[1] ?? '';
    parse_str($queryString, $query);

    $timestamp = date('Y-m-d H:i:s');
    $peer = $conn->getRemoteAddress();

    // ── SSE endpoint ──
    if ($method === 'GET' && $path === '/events') {
        echo "[SSE] {$timestamp} | Browser connected from {$peer}\n";
        $headers  = "HTTP/1.1 200 OK\r\n";
        $headers .= "Content-Type: text/event-stream\r\n";
        $headers .= "Cache-Control: no-cache\r\n";
        $headers .= "Connection: keep-alive\r\n";
        $headers .= "Access-Control-Allow-Origin: *\r\n";
        $headers .= "\r\n";
        $conn->write($headers);
        $connId = spl_object_id($conn);
        $sseClients[$connId] = $conn;
        $conn->on('close', function () use ($connId) {
            global $sseClients;
            unset($sseClients[$connId]);
            echo "[SSE] Browser disconnected\n";
        });
        return;
    }

    // ── API: Recent threats ──
    if ($method === 'GET' && $path === '/api/threats') {
        $limit = (int)($query['limit'] ?? 50);
        $rows = queryAll("SELECT * FROM threats ORDER BY id DESC LIMIT {$limit}");
        return sendJson($conn, $rows);
    }

    // ── API: Recent measures ──
    if ($method === 'GET' && $path === '/api/measures') {
        $limit = (int)($query['limit'] ?? 50);
        $rows = queryAll("SELECT * FROM measures ORDER BY id DESC LIMIT {$limit}");
        return sendJson($conn, $rows);
    }

    // ── API: Latest SOH ──
    if ($method === 'GET' && $path === '/api/soh') {
        $rows = queryAll("SELECT * FROM soh ORDER BY id DESC LIMIT 1");
        return sendJson($conn, $rows[0] ?? []);
    }

    // ── API: Stats ──
    if ($method === 'GET' && $path === '/api/stats') {
        $stats = [
            'total_measures' => queryAll("SELECT COUNT(*) as c FROM measures")[0]['c'] ?? 0,
            'total_threats'  => queryAll("SELECT COUNT(*) as c FROM threats")[0]['c'] ?? 0,
            'total_soh'      => queryAll("SELECT COUNT(*) as c FROM soh")[0]['c'] ?? 0,
        ];
        return sendJson($conn, $stats);
    }

    // ── Health check ──
    if ($method === 'GET' && $path === '/') {
        $html = "ATD-300 Listener OK";
        $response = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($html) . "\r\nConnection: close\r\n\r\n" . $html;
        $conn->write($response);
        $conn->end();
        return;
    }

    // ── Sensor POST endpoints ──
    echo "[HTTP] {$timestamp} | {$method} {$path} from {$peer}\n";

    $json = json_decode($body, true);
    if ($json) {
        $deviceId = $json['device_id'] ?? '?';
        $type = ltrim($path, '/');

        if ($type === 'measure') {
            $laeq = $json['laeq'] ?? '?';
            $dt   = $json['datetime'] ?? '';
            echo "  └─ [MEASURE] Device {$deviceId} | LAeq: {$laeq} dB | {$dt}\n";
            storeMeasure($json);
        } elseif ($type === 'soh') {
            $status = $json['status'] ?? '?';
            $temp   = $json['temp'] ?? '?';
            echo "  └─ [SOH] Device {$deviceId} | Status: {$status} | Temp: {$temp}°F\n";
            storeSoh($json);
        } elseif ($type === 'threat') {
            $threat = $json['threat'] ?? '?';
            $az = $json['azimut'] ?? '?';
            $el = $json['elevation'] ?? '?';
            $dist = $json['distance'] ?? '?';
            echo "  └─ [THREAT] Device {$deviceId} | {$threat} | Az: {$az}° El: {$el}° Dist: {$dist}m\n";
            storeThreat($json);
        } else {
            echo "  └─ [{$type}] {$body}\n";
        }

        broadcast($type, $deviceId, $json);
    } else {
        echo "  └─ [RAW] {$body}\n";
    }

    // Send HTTP 200 response
    $response  = "HTTP/1.1 200 OK\r\n";
    $response .= "Content-Type: application/json\r\n";
    $response .= "Content-Length: 15\r\n";
    $response .= "Connection: close\r\n\r\n";
    $response .= '{"status":"ok"}';
    $conn->write($response);
    $conn->end();
}

// ── React TCP server ──────────────────────────────────────────────────

$loop = React\EventLoop\Loop::get();
$server = new React\Socket\SocketServer("0.0.0.0:{$port}", [], $loop);

$server->on('connection', function (React\Socket\ConnectionInterface $conn) {
    $connId = spl_object_id($conn);
    $peer = $conn->getRemoteAddress();

    static $buffers = [];
    static $processed = [];
    $buffers[$connId] = '';
    $processed[$connId] = false;

    $conn->on('data', function ($data) use ($conn, $connId, $peer, &$buffers, &$processed) {
        if ($processed[$connId]) return;

        $buffers[$connId] .= $data;
        $buffer = $buffers[$connId];

        // Wait for end of HTTP headers
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) return;

        $headerBlock = substr($buffer, 0, $headerEnd);
        $bodyData    = substr($buffer, $headerEnd + 4);

        // Extract Content-Length
        $contentLength = 0;
        if (preg_match('/Content-Length:\s*(\d+)/i', $headerBlock, $m)) {
            $contentLength = (int)$m[1];
        }

        if (strlen($bodyData) < $contentLength) return;

        $body = substr($bodyData, 0, $contentLength);
        $processed[$connId] = true;

        try {
            processRequest($conn, $headerBlock, $body);
        } catch (\Exception $e) {
            echo "[ERROR] {$e->getMessage()}\n";
            $conn->end();
        }
    });

    $conn->on('close', function () use ($connId, &$buffers, &$processed) {
        unset($buffers[$connId], $processed[$connId]);
    });

    $conn->on('error', function (\Exception $e) use ($peer) {
        echo "[ERROR] {$peer}: {$e->getMessage()}\n";
    });
});

echo "=== ATD-300 Listener on port {$port} ===\n";
echo "  Sensor:    POST /measure | /soh | /threat\n";
echo "  Dashboard: GET  /events (SSE)\n";
echo "  API:       GET  /api/threats | /api/measures | /api/soh | /api/stats\n";
echo "  Database:  db/atd300.sqlite3\n";
echo "  Waiting for data...\n\n";

$loop->run();
