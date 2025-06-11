<?php

require_once __DIR__ . '/../vendor/autoload.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

$query = $_GET['q'] ?? null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;

if (!$query) {
    http_response_code(400);
    echo json_encode([
        'error' => true,
        'message' => 'Missing query parameter (?q=...)'
    ]);
    return;
}

$spotify = new SpotifyLyricsApi\Spotify(getenv('SP_DC'));
$spotify->checkTokenExpire();

try {
    $results = $spotify->searchTrack($query, $limit);
    echo $results;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Internal error: ' . $e->getMessage()
    ]);
}
