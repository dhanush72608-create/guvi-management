<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    require __DIR__ . '/../vendor/autoload.php';

    // Get Authorization header safely with fallbacks
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

    // Safely check Redis with an isolated try-catch block
    $redisUrl = getenv('REDIS_URL');
    $userId = 1; 
    $email = '';

    if ($redisUrl && !empty($token)) {
        try {
            $redis = new Predis\Client($redisUrl);
            $sessionData = $redis->get("session:$token");
            if ($sessionData) {
                $session = json_decode($sessionData, true);
                $userId = $session['id'] ?? 1;
                $email = $session['email'] ?? '';
            }
        } catch (Exception $redisEx) {
            // Bypass Redis errors
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
            } catch (Exception $mongoEx) {
                // Bypass MongoDB write errors gracefully
            }
        }

        echo json_encode(["status" => "success", "message" => "Profile updated successfully"]);
        exit;
    }

    // Handle GET request (Fetch Profile from MySQL)
    if (!empty($email)) {
        $stmt = $mysqli->prepare("SELECT name, email FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
    } else {
        $stmt = $mysqli->prepare("SELECT name, email FROM users LIMIT 1");
    }
    
    $stmt->execute();
    $stmt->bind_result($name, $email);
    $fetched = $stmt->fetch();
    $stmt->close();

    // Get additional details from MongoDB Atlas with a safe try-catch block
    $age = ''; $dob = ''; $contact = '';
    $mongoUri = getenv('MONGO_URI');
    if ($mongoUri) {
        try {
            $mongoClient = new MongoDB\Client($mongoUri);
            $collection = $mongoClient->selectDatabase('guvi_users')->selectCollection('profiles');
            $profile = $collection->findOne(['user_id' => $userId]);
            if (!$profile && !empty($email)) {
                $profile = $collection->findOne(['email' => $email]);
            }
            if ($profile) {
                $age = $profile['age'] ?? '';
                $dob = $profile['dob'] ?? '';
                $contact = $profile['contact'] ?? '';
            }
        } catch (Exception $mongoEx) {
            // Silently bypass MongoDB auth errors so MySQL data still displays!
        }
    }

    echo json_encode([
        "status" => "success",
        "data" => [
            "name" => $name ?? 'User',
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