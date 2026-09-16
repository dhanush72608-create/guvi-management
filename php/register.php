<?php
header('Content-Type: application/json');
require 'C:/guvi-usermanagement/vendor/autoload.php';

$data = json_decode(file_get_contents("php://input"), true);

$name = $data['name'] ?? '';
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';
$age = $data['age'] ?? '';
$dob = $data['dob'] ?? '';
$contact = $data['contact'] ?? '';

if(empty($email) || empty($password) || empty($name)) {
    echo json_encode(["status" => "error", "message" => "Please fill in all required fields."]);
    exit;
}

// 1. MySQL Database Connection (XAMPP default password is empty "")
$mysqli = new mysqli("localhost", "root", "", "guvi_db");
if ($mysqli->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $mysqli->connect_error]);
    exit;
}

// Check if email already exists using Prepared Statements
$stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();
if($stmt->num_rows > 0) {
    echo json_encode(["status" => "error", "message" => "Email already registered."]);
    exit;
}
$stmt->close();

// Hash password
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

// Insert into MySQL using Prepared Statements
$stmt = $mysqli->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $name, $email, $hashedPassword);

if($stmt->execute()) {
    $userId = $stmt->insert_id;
    
    // 2. MongoDB Connection for User Profile details
    $mongoClient = new MongoDB\Client("mongodb://localhost:27017");
    $collection = $mongoClient->guvi_db->user_profiles;
    
    $collection->insertOne([
        'user_id' => $userId,
        'email' => $email,
        'age' => $age,
        'dob' => $dob,
        'contact' => $contact
    ]);

    echo json_encode(["status" => "success", "message" => "Registered successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Registration failed."]);
}

$stmt->close();
$mysqli->close();
?>