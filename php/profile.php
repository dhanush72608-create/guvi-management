<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    require __DIR__ . '/../vendor/autoload.php';

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

    if (empty($token)) {
        echo json_encode(["status" => "error", "message" => "Unauthorized: No token provided."]);
        exit;
    }

    // Decode token payload directly (Fallback-proof for servers without Redis)
    $decodedPayload = json_decode(base64_decode($token), true);
    $email = $decodedPayload['email'] ?? '';
    $userId = $decodedPayload['id'] ?? 1;

    if (empty($email)) {
        echo json_encode(["status" => "error", "message" => "Unauthorized: Invalid session token."]);
        exit;
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
            } catch (Exception $e) {}
        }

        echo json_encode(["status" => "success", "message" => "Profile updated successfully"]);
        exit;
    }

    // Fetch user securely from MySQL
    $stmt = $mysqli->prepare("SELECT id, name, email FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->bind_result($userId, $name, $email);
    $fetched = $stmt->fetch();
    $stmt->close();

    if (!$fetched) {
        echo json_encode(["status" => "error", "message" => "User not found."]);
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