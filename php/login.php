<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    // Use relative path for Composer autoload in root directory
    require __DIR__ . '/../vendor/autoload.php';

    // Support both JSON payloads and standard form-data ($_POST)
    $data = json_decode(file_get_contents("php://input"), true);
    if (empty($data)) {
        $data = $_POST;
    }

    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';

    if(empty($email) || empty($password)) {
        echo json_encode(["status" => "error", "message" => "All fields are required."]);
        exit;
    }

    // MySQL Database Connection using Environment Variables
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_user = getenv('DB_USER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: '';
    $db_name = getenv('DB_NAME') ?: 'guvi_db';

    $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli->connect_error) {
        throw new Exception("Database connection failed: " . $mysqli->connect_error);
    }

    // Use Prepared Statements to fetch user
    $stmt = $mysqli->prepare("SELECT id, name, password FROM users WHERE email = ?");
    if (!$stmt) {
        throw new Exception("MySQL Prepare Failed: " . $mysqli->error);
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows === 1) {
        $stmt->bind_result($id, $name, $hashedPassword);
        $stmt->fetch();

        if(password_verify($password, $hashedPassword)) {
            // Generate unique session token
            $token = bin2hex(random_bytes(32));

            // Optional Redis handling (falls back gracefully if REDIS_URL is not set)
            $redisUrl = getenv('REDIS_URL');
            if ($redisUrl) {
                $redis = new Predis\Client($redisUrl);
                $redis->setex("session:$token", 3600, json_encode(["id" => $id, "email" => $email]));
            }

            echo json_encode(["status" => "success", "token" => $token, "message" => "Login successful"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Invalid password."]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "User not found."]);
    }

    $stmt->close();
    $mysqli->close();

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Error: " . $e->getMessage()]);
}
?>