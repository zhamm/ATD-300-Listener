<?php
/**
 * Raw TCP listener for debugging.
 * Run this INSTEAD of listener.php to see exactly what the sensor sends.
 * Usage: php raw_listener.php
 */

$port = 8880;
$host = '0.0.0.0';

$server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    die("Could not create server: {$errstr} ({$errno})\n");
}

echo "Raw TCP listener on port {$port} - waiting for connections...\n\n";

while (true) {
    $client = @stream_socket_accept($server, 60);
    if (!$client) continue;

    $peer = stream_socket_get_name($client, true);
    echo "=== Connection from {$peer} at " . date('Y-m-d H:i:s') . " ===\n";

    stream_set_timeout($client, 5);
    $data = '';
    while (!feof($client)) {
        $chunk = fread($client, 8192);
        if ($chunk === false || $chunk === '') break;
        $data .= $chunk;
        $info = stream_get_meta_data($client);
        if ($info['timed_out']) break;
    }

    echo "--- Raw data (" . strlen($data) . " bytes) ---\n";
    echo $data . "\n";
    echo "--- Hex dump ---\n";
    echo chunk_split(bin2hex($data), 64, "\n") . "\n";
    echo "=== End ===\n\n";

    fclose($client);
}
