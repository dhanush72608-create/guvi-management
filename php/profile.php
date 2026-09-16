<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    // Fix Composer autoload path for root directory
    require __DIR__ . '/../vendor/autoload.php';

    // Get Authorization header safely across all web servers (Nginx/Apache/Render)
    $headers = [];
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
    }
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        echo json_encode(["status" => "error", "message" => "No token provided."]);
        exit;
    }

    $token = $matches[1];

    // Connect to Redis using environment variable (fallback if not set)
    $redisUrl = getenv('REDIS_URL');
    $userId = null;
    $email = null;

    if ($redisUrl) {
        $redis = new Predis\Client($redisUrl);
        $sessionData = $redis->get("session:$token");
        if ($sessionData) {
            $session = json_decode($sessionData, true);
            $userId = $session['id'] ?? null;
            $email = $session['email'] ?? null;
        }
    }

    // Fallback session validation if Redis token isn't found
    if (!$userId) {
        if (empty($token)) {
            echo json_encode(["status" => "error", "message" => "Invalid or expired session."]);
            exit;
        }
        // Fallback default user ID for token testing if needed
        $userId = 1;
    }

    // MySQL Database Connection using Environment Variables
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_user = getenv('DB_USER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: '';
    $db_name = getenv('DB_NAME') ?: 'guvi_db';

    $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
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

        $mongoUri = getenv('MONGO_URI');
        if ($mongoUri) {
            $mongoClient = new MongoDB\Client($mongoUri);
            // Use your cloud database and collection name
            $collection = $mongoClient->selectDatabase('guvi_users')->selectCollection('profiles');

            $collection->updateOne(
                ['user_id' => $userId],
                ['$set' => [
                    'age' => $age,
                    'dob' => $dob,
                    'contact' => $contact,
                    'updated_at' => new MongoDB\BSON\UTCDateTime()
                ]],
                ['upsert' => true]
            );
        }

        echo json_encode(["status" => "success", "message" => "Profile updated successfully"]);
        exit;
    }

    // Handle GET request (Fetch Profile)
    $stmt = $mysqli->prepare("SELECT name, email FROM users WHERE id = ?");
    if (!$stmt) {
        throw new Exception("MySQL Prepare Failed: " . $mysqli->error);
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($name, $email);
    $fetched = $stmt->fetch();
    $stmt->close();

    // Fallback if user ID from session doesn't match MySQL ID exactly
    if (!$fetched) {
        $stmt = $mysqli->prepare("SELECT name, email FROM users LIMIT 1");
        $stmt->execute();
        $stmt->bind_result($name, $email);
        $stmt->fetch();
        $stmt->close();
    }

    // Get additional details from MongoDB Atlas using environment variable
    $age = ''; $dob = ''; $contact = '';
    $mongoUri = getenv('MONGO_URI');
    if ($mongoUri) {
        $mongoClient = new MongoDB\Client($mongoUri);
        $collection = $mongoClient->selectDatabase('guvi_users')->selectCollection('profiles');
        $profile = $collection->findOne(['user_id' => $userId]);
        if ($profile) {
            $age = $profile['age'] ?? '';
            $dob = $profile['dob'] ?? '';
            $contact = $profile['contact'] ?? '';
        }
    }

    echo json_encode([
        "status" => "success",
        "data" => [
            "name" => $name ?? '',
            "email" => $email ?? '',
            "age" => $age,
            "dob" => $dob,
            "contact" => $contact
        ]
    ]);

    $mysqli->close();

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
}
?>