<?php
// myapi2.php
require_once('config.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Get and validate city
$city = isset($_GET['city']) ? trim(strtolower($_GET['city'])) : 'croydon';
$city = preg_replace('/[^a-z\s-]/', '', $city);

if (empty($city)) {
    http_response_code(400);
    echo json_encode(['error' => 'City name is required']);
    exit();
}

// Connect to database
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Check cache first
$sql = "SELECT * FROM myweather 
        WHERE city = ? 
        AND weather_when >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ORDER BY weather_when DESC LIMIT 1";

$stmt = $mysqli->prepare($sql);
$cache_seconds = CACHE_SECONDS;
$stmt->bind_param("si", $city, $cache_seconds);
$stmt->execute();
$result = $stmt->get_result();

// If no cache, import fresh data
if ($result->num_rows == 0) {
    include('data-imports.php');
    
    // Query again after import
    $stmt->execute();
    $result = $stmt->get_result();
}

// Return data
if ($row = $result->fetch_assoc()) {
    $response = [
        'city' => $row['city'],
        'weather_description' => $row['weather_description'],
        'weather_temperature' => round($row['weather_temperature'], 1),
        'weather_wind' => round($row['weather_wind'], 1),
        'humidity' => $row['humidity'],
        'feels_like' => round($row['feels_like'] ?? $row['weather_temperature'], 1),
        'pressure' => $row['pressure'],
        'icon' => $row['icon_code'],
        'country' => $row['country'],
        'weather_when' => $row['weather_when']
    ];
    
    echo json_encode($response);
} else {
    echo json_encode(['error' => 'No weather data available']);
}

$stmt->close();
$mysqli->close();
// NO CLOSING PHP TAG HERE - IMPORTANT!