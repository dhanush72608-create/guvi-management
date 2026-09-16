<?php
header('Content-Type: application/json');
require 'C:/guvi-usermanagement/vendor/autoload.php';

$data = json_decode(file_get_contents("php://input"), true);
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

if(empty($email) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "All fields are required."]);
    exit;
}

$mysqli = new mysqli("localhost", "root", "", "guvi_db");
if ($mysqli->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed."]);
    exit;
}

// Use Prepared Statements to fetch user
$stmt = $mysqli->prepare("SELECT id, name, password FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if($stmt->num_rows === 1) {
    $stmt->bind_result($id, $name, $hashedPassword);
    $stmt->fetch();

    if(password_verify($password, $hashedPassword)) {
        // Generate unique session token
        $token = bin2hex(random_bytes(32));

        // Store session info in Redis backend
        $redis = new Predis\Client();
        $redis->setex("session:$token", 3600, json_encode(["id" => $id, "email" => $email]));

        echo json_encode(["status" => "success", "token" => $token]);
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid password."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "User not found."]);
}

$stmt->close();
$mysqli->close();
?>