<?php
header('Content-Type: application/json');
require 'C:/guvi-usermanagement/vendor/autoload.php';

// Get Authorization header
$headers = apache_request_headers();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    echo json_encode(["status" => "error", "message" => "No token provided."]);
    exit;
}

$token = $matches[1];

try {
    // Connect to Redis
    $redis = new Predis\Client();
    $sessionData = $redis->get("session:$token");

    if (!$sessionData) {
        echo json_encode(["status" => "error", "message" => "Invalid or expired session."]);
        exit;
    }

    $session = json_decode($sessionData, true);
    $userId = $session['id'];
    $email = $session['email'];

    $mysqli = new mysqli("localhost", "root", "", "guvi_db");
    if ($mysqli->connect_error) {
        echo json_encode(["status" => "error", "message" => "Database connection failed."]);
        exit;
    }

    // Handle POST request (Update Profile)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);
        $age = $data['age'] ?? '';
        $dob = $data['dob'] ?? '';
        $contact = $data['contact'] ?? '';

        $mongoClient = new MongoDB\Client("mongodb://localhost:27017");
        $collection = $mongoClient->guvi_db->user_profiles;

        $collection->updateOne(
            ['user_id' => $userId],
            ['$set' => [
                'age' => $age,
                'dob' => $dob,
                'contact' => $contact
            ]],
            ['upsert' => true]
        );

        echo json_encode(["status" => "success", "message" => "Profile updated successfully"]);
        exit;
    }

    // Handle GET request (Fetch Profile)
    // Get name and email from MySQL
    $stmt = $mysqli->prepare("SELECT name, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($name, $email);
    $stmt->fetch();
    $stmt->close();

    // Get additional details from MongoDB
    $mongoClient = new MongoDB\Client("mongodb://localhost:27017");
    $collection = $mongoClient->guvi_db->user_profiles;
    $profile = $collection->findOne(['user_id' => $userId]);

    echo json_encode([
        "status" => "success",
        "data" => [
            "name" => $name,
            "email" => $email,
            "age" => $profile['age'] ?? '',
            "dob" => $profile['dob'] ?? '',
            "contact" => $profile['contact'] ?? ''
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
}
?>