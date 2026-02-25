<?php
// test-import.php
require_once('config.php');

echo "<h1>Test Weather Data Import</h1>";

$cities = ['london', 'paris', 'tokyo', 'new york', 'berlin', 'madrid'];

foreach ($cities as $city) {
    echo "<h2>Testing: $city</h2>";
    
    // Connect to database
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Try to fetch from OpenWeatherMap directly
    $api_key = '82c60edecec5bb766f2b11e155cc101a';
    $url = "https://api.openweathermap.org/data/2.5/weather?q=" . urlencode($city) . "&appid={$api_key}&units=metric";
    
    echo "URL: $url<br>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $http_code<br>";
    
    if ($http_code == 200) {
        $data = json_decode($response, true);
        echo "✅ API call successful!<br>";
        echo "City: " . $data['name'] . "<br>";
        echo "Temperature: " . $data['main']['temp'] . "°C<br>";
        echo "Weather: " . $data['weather'][0]['description'] . "<br>";
        
        // Try to insert into database
        $sql = "INSERT INTO myweather (city, weather_description, weather_temperature, weather_wind, humidity, feels_like, pressure, icon_code, country) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssddiddss", 
            $data['name'],
            $data['weather'][0]['description'],
            $data['main']['temp'],
            $data['wind']['speed'],
            $data['main']['humidity'],
            $data['main']['feels_like'],
            $data['main']['pressure'],
            $data['weather'][0]['icon'],
            $data['sys']['country']
        );
        
        if ($stmt->execute()) {
            echo "✅ Data inserted into database!<br>";
        } else {
            echo "❌ Database insert failed: " . $stmt->error . "<br>";
        }
        $stmt->close();
    } else {
        echo "❌ API call failed - City might not exist<br>";
        if ($http_code == 404) {
            echo "Error: City not found<br>";
        } else {
            echo "Error: " . $response . "<br>";
        }
    }
    
    $mysqli->close();
    echo "<hr>";
}
?>