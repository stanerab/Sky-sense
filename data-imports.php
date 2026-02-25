<?php
// data-imports.php - Enhanced version with better error handling and security

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 0 in production
ini_set('log_errors', 1);

// Check if city parameter exists
if (!isset($_GET['city']) || empty($_GET['city'])) {
    http_response_code(400);
    die(json_encode(['error' => 'City parameter is required']));
}

// Sanitize city name
$city = trim(strtolower($_GET['city']));
$city = preg_replace('/[^a-z\s-]/', '', $city); // Allow letters, spaces, hyphens

if (empty($city)) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid city name']));
}

// OpenWeatherMap API configuration
$api_key = '82c60edecec5bb766f2b11e155cc101a';
$url = "https://api.openweathermap.org/data/2.5/weather?q=" . urlencode($city) . "&appid={$api_key}&units=metric";

// Initialize cURL (better than file_get_contents for error handling)
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'Weather App/1.0'
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Handle cURL errors
if ($response === false) {
    error_log("cURL Error: " . curl_error($ch));
    http_response_code(500);
    die(json_encode(['error' => 'Failed to fetch weather data']));
}

// Handle HTTP errors
if ($http_code !== 200) {
    $error_message = ($http_code == 404) ? 'City not found' : 'Weather service unavailable';
    http_response_code($http_code);
    die(json_encode(['error' => $error_message]));
}

// Decode JSON response
$json = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON Error: " . json_last_error_msg());
    http_response_code(500);
    die(json_encode(['error' => 'Invalid response from weather service']));
}

// Extract weather data with null checks
try {
    $weather_data = [
        'city' => $json['name'] ?? $city,
        'weather_description' => $json['weather'][0]['description'] ?? 'unknown',
        'weather_temperature' => $json['main']['temp'] ?? 0,
        'weather_wind' => $json['wind']['speed'] ?? 0,
        'humidity' => $json['main']['humidity'] ?? 0,
        'feels_like' => $json['main']['feels_like'] ?? $json['main']['temp'] ?? 0,
        'pressure' => $json['main']['pressure'] ?? 0,
        'icon_code' => $json['weather'][0]['icon'] ?? '01d',  // FIXED: changed from 'icon' to 'icon_code'
        'weather_when' => date("Y-m-d H:i:s"),
        'country' => $json['sys']['country'] ?? '',
        'sunrise' => isset($json['sys']['sunrise']) ? date('H:i:s', $json['sys']['sunrise']) : null,
        'sunset' => isset($json['sys']['sunset']) ? date('H:i:s', $json['sys']['sunset']) : null
    ];
} catch (Exception $e) {
    error_log("Data extraction error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Failed to process weather data']));
}
// Check if database connection exists
if (!isset($mysqli) || $mysqli->connect_errno) {
    error_log("Database connection not available");
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed']));
}

// Use prepared statement for security
$sql_insert = "INSERT INTO myweather (
    city, 
    weather_description, 
    weather_temperature, 
    weather_wind, 
    humidity, 
    feels_like, 
    pressure, 
    icon_code,
    country,
    sunrise,
    sunset,
    weather_when
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $mysqli->prepare($sql_insert);

if (!$stmt) {
    error_log("Prepare failed: " . $mysqli->error);
    http_response_code(500);
    die(json_encode(['error' => 'Database error']));
}

// Bind parameters
// Extract variables first (PHP 8+ compatibility)
$city_val = $weather_data['city'];
$desc_val = $weather_data['weather_description'];
$temp_val = $weather_data['weather_temperature'];
$wind_val = $weather_data['weather_wind'];
$hum_val = $weather_data['humidity'];
$feels_val = $weather_data['feels_like'];
$pressure_val = $weather_data['pressure'];
$icon_val = $weather_data['icon_code'];  // Make sure this is 'icon_code' not 'icon'
$country_val = $weather_data['country'];
$sunrise_val = $weather_data['sunrise'];
$sunset_val = $weather_data['sunset'];
$when_val = $weather_data['weather_when'];

// Now bind using the variables
$stmt->bind_param(
    "ssddiddsssss", 
    $city_val,
    $desc_val,
    $temp_val,
    $wind_val,
    $hum_val,
    $feels_val,
    $pressure_val,
    $icon_val,
    $country_val,
    $sunrise_val,
    $sunset_val,
    $when_val
);

// Execute and check result
if (!$stmt->execute()) {
    error_log("Insert failed: " . $stmt->error);
    
    // Check for duplicate entry (if you have a unique constraint)
    if ($stmt->errno == 1062) { // Duplicate entry error
        // Update existing record instead
        $sql_update = "UPDATE myweather SET 
            weather_description = ?,
            weather_temperature = ?,
            weather_wind = ?,
            humidity = ?,
            feels_like = ?,
            pressure = ?,
            icon_code = ?,
            weather_when = ?
            WHERE city = ? AND DATE(weather_when) = CURDATE()";
            
        $update_stmt = $mysqli->prepare($sql_update);
        $update_stmt->bind_param(
            "sddiddsss",
            $weather_data['weather_description'],
            $weather_data['weather_temperature'],
            $weather_data['weather_wind'],
            $weather_data['humidity'],
            $weather_data['feels_like'],
            $weather_data['pressure'],
            $weather_data['icon'],
            $weather_data['weather_when'],
            $weather_data['city']
        );
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        http_response_code(500);
        die(json_encode(['error' => 'Failed to save weather data']));
    }
}

$stmt->close();

// Log success
error_log("Weather data imported successfully for: " . $weather_data['city']);

// Optional: Return the saved data
if (isset($_GET['return_data']) && $_GET['return_data'] == 'true') {
    echo json_encode([
        'success' => true,
        'data' => $weather_data,
        'message' => 'Weather data imported successfully'
    ]);
}

// If called directly (not included), return JSON
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'city' => $weather_data['city']]);
}
?>