<?php
// NO SPACES OR LINES BEFORE THIS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Origin');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "db.php";

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // If JSON decode failed, try regular POST
    if (!$input) {
        $input = $_POST;
    }
    
    $email = isset($input['email']) ? trim($input['email']) : '';
    $password = isset($input['password']) ? trim($input['password']) : '';
    
    if (empty($email) || empty($password)) {
        $response['message'] = "Email and password are required";
    } else {
        // Use prepared statement to prevent SQL injection
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Check if email is verified
                if ($user['is_verified'] == 1) {
                    // Check subscription
                    $uid = $user['id'];
                    $sub_stmt = $conn->prepare("
                        SELECT * FROM subscriptions 
                        WHERE user_id = ? AND status = 'active' AND expires_at >= CURDATE()
                    ");
                    $sub_stmt->bind_param("i", $uid);
                    $sub_stmt->execute();
                    $sub_result = $sub_stmt->get_result();
                    
                    $subscription_active = ($sub_result->num_rows > 0);
                    
                    $response['success'] = true;
                    $response['user_id'] = $user['id'];
                    $response['user_name'] = $user['name'];
                    $response['subscription_active'] = $subscription_active;
                    $response['message'] = "Login successful";
                    
                    $sub_stmt->close();
                } else {
                    $response['message'] = "Please verify your email first";
                }
            } else {
                $response['message'] = "Wrong password";
            }
        } else {
            $response['message'] = "Email not found";
        }
        $stmt->close();
    }
} else {
    $response['message'] = "Please use POST method";
}

echo json_encode($response);
?>