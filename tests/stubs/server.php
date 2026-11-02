<?php

// Stub server. The file "mode" holds the status to answer ingest with; every
// request is logged so tests can see exactly what reached the wire.
$dir = getenv('STUB_DIR');
$mode = trim((string) @file_get_contents("{$dir}/mode")) ?: '202';
$raw = file_get_contents('php://input');
$path = strtok($_SERVER['REQUEST_URI'], '?');

file_put_contents("{$dir}/requests", json_encode([
    'path' => $_SERVER['REQUEST_URI'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'encoding' => $_SERVER['HTTP_CONTENT_ENCODING'] ?? '',
    'server' => $_SERVER['HTTP_X_WATCH_SERVER'] ?? '',
    'agent' => $_SERVER['HTTP_X_WATCH_AGENT_VERSION'] ?? '',
    'body' => str_starts_with($path, '/api/ingest') ? '' : $raw,
])."\n", FILE_APPEND);

if ($path === '/api/schedule-sync') {
    header('Content-Type: application/json');
    $tasks = json_decode($raw, true)['tasks'] ?? [];
    echo json_encode(['heartbeats' => array_combine(array_column($tasks, 'name'), array_map(fn ($i) => "ping{$i}", array_keys($tasks)))]);

    return;
}

if (str_starts_with($path, '/api/heartbeat/')) {
    http_response_code(200);

    return;
}

if ($path !== '/api/ingest') {
    echo 'outgoing ok';

    return;
}

file_put_contents("{$dir}/hits", json_encode([$_SERVER['HTTP_AUTHORIZATION'] ?? '', $_SERVER['HTTP_CONTENT_ENCODING'] ?? '', $mode])."\n", FILE_APPEND);
if ((int) $mode === 429) {
    header('Retry-After: 1');
}
http_response_code((int) $mode);
if ((int) $mode === 202) {
    file_put_contents("{$dir}/accepted-".hrtime(true), gzdecode($raw) ?: $raw);
}
