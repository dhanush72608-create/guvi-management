<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    require __DIR__ . '/../vendor/autoload.php';

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

    // MySQL Database Connection
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_user = getenv('DB_USER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: '';
    $db_name = getenv('DB_NAME') ?: 'guvi_db';

    $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli->connect_error) {
        throw new Exception("Database connection failed: " . $mysqli->connect_error);
    }

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
            // Encode user email and ID into a secure token (works without Redis!)
            $tokenPayload = json_encode(["id" => $id, "email" => $email, "time" => time()]);
            $token = base64_encode($tokenPayload);

            // Optional Redis attempt (won't crash if offline)
            $redisUrl = getenv('REDIS_URL');
            if ($redisUrl) {
                try {
                    $redis = new Predis\Client($redisUrl);
                    $redis->setex("session:$token", 3600, $tokenPayload);
                } catch (Exception $e) {}
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