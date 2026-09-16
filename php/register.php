<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    require __DIR__ . '/../vendor/autoload.php';

    // Support both JSON payloads and standard form-data ($_POST)
    $data = json_decode(file_get_contents("php://input"), true);
    if (empty($data)) {
        $data = $_POST;
    }

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

    // 1. MySQL Database Connection using Environment Variables
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_user = getenv('DB_USER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: '';
    $db_name = getenv('DB_NAME') ?: 'guvi_db';

    $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli->connect_error) {
        throw new Exception("MySQL Connection Failed: " . $mysqli->connect_error);
    }

    // Check if email already exists using Prepared Statements
    $stmt = $mysqli->prepare("SELECT id FROM users WHERE email = ?");
    if (!$stmt) {
        throw new Exception("MySQL Prepare Failed: " . $mysqli->error);
    }
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
        
        // 2. MongoDB Connection wrapped safely so auth errors never block registration
        $mongoUri = getenv('MONGO_URI');
        if ($mongoUri) {
            try {
                $mongoClient = new MongoDB\Client($mongoUri);
                $collection = $mongoClient->guvi_db->user_profiles;
                
                $collection->insertOne([
                    'user_id' => $userId,
                    'email' => $email,
                    'age' => $age,
                    'dob' => $dob,
                    'contact' => $contact
                ]);
            } catch (Exception $mongoEx) {
                // Silently bypass MongoDB failures so user account creation in MySQL succeeds!
            }
        }

        echo json_encode(["status" => "success", "message" => "Registered successfully"]);
    } else {
        throw new Exception("MySQL Insert Failed: " . $stmt->error);
    }

    if (isset($stmt)) $stmt->close();
    $mysqli->close();

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Error: " . $e->getMessage()]);
}
?>