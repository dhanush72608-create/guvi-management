<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    require __DIR__ . '/../vendor/autoload.php';

    // Get Authorization header safely
    $headers = [];
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
    }
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    $token = '';
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
    } elseif (isset($_GET['token'])) {
        $token = $_GET['token'];
    }

    $email = '';
    $userId = 1;

    // Try reading from Redis if available
    $redisUrl = getenv('REDIS_URL');
    if ($redisUrl && !empty($token)) {
        try {
            $redis = new Predis\Client($redisUrl);
            $sessionData = $redis->get("session:$token");
            if ($sessionData) {
                $session = json_decode($sessionData, true);
                $userId = $session['id'] ?? 1;
                $email = $session['email'] ?? '';
            }
        } catch (Exception $e) {
            // Fallback gracefully
        }
    }

    // MySQL Database Connection
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_user = getenv('DB_USER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: '';
    $db_name = getenv('DB_NAME') ?: 'guvi_db';

    $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli->connect_error) {
        echo json_encode(["status" => "error", "message" => "Database connection failed."]);
        exit;
    }

    // If Redis is offline, we can check if the token itself contains or maps to a user, 
    // or require a valid email. If $email is still empty, reject instead of using LIMIT 1!
    if (empty($email)) {
        echo json_encode(["status" => "error", "message" => "Unauthorized: Session expired or invalid."]);
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
            try {
                $mongoClient = new MongoDB\Client($mongoUri);
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
            } catch (Exception $e) {}
        }

        echo json_encode(["status" => "success", "message" => "Profile updated successfully"]);
        exit;
    }

    // Handle GET request (Fetch Profile securely by email)
    $stmt = $mysqli->prepare("SELECT id, name, email FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->bind_result($userId, $name, $email);
    $fetched = $stmt->fetch();
    $stmt->close();

    if (!$fetched) {
        echo json_encode(["status" => "error", "message" => "User record not found in database."]);
        exit;
    }

    // Get additional details from MongoDB Atlas safely
    $age = ''; $dob = ''; $contact = '';
    $mongoUri = getenv('MONGO_URI');
    if ($mongoUri) {
        try {
            $mongoClient = new MongoDB\Client($mongoUri);
            $collection = $mongoClient->selectDatabase('guvi_users')->selectCollection('profiles');
            $profile = $collection->findOne(['user_id' => $userId]);
            if (!$profile) {
                $profile = $collection->findOne(['email' => $email]);
            }
            if ($profile) {
                $age = $profile['age'] ?? '';
                $dob = $profile['dob'] ?? '';
                $contact = $profile['contact'] ?? '';
            }
        } catch (Exception $e) {}
    }

    echo json_encode([
        "status" => "success",
        "data" => [
            "name" => $name,
            "email" => $email,
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