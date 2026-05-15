<?php
session_start();
include "db.php";
include "device_helper.php";
include "config.php";

$message = "";
$device_manager = new DeviceManager($conn);

// Check if password was just reset
if (isset($_GET['reset']) && $_GET['reset'] == 'success') {
    $message = "Password updated successfully! All your devices have been logged out. Please login again.";
}

// Check for device removal messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'password_changed') {
        $message = "Your password was changed. Please login again.";
    } elseif ($_GET['msg'] == 'device_removed') {
        $message = "Your device was removed from your account. Please login again.";
    } elseif ($_GET['msg'] == 'self_device_removed') {
        $message = "You removed your own device. Please login again.";
    } elseif ($_GET['msg'] == 'all_devices_removed') {
        $message = "All devices have been removed from your account. Please login again.";
    } elseif ($_GET['msg'] == 'session_expired') {
        $message = "Your session has expired. Please login again.";
    }
}

if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $pass = trim($_POST['password']);
    
    if (empty($email) || empty($pass)) {
        $message = "All fields are required!";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($pass, $user['password'])) {
                if ($user['is_verified'] == 0) {
                    $_SESSION['verify_notice'] = "Please verify your email first.";
                    header("Location: verify.php?email=" . urlencode($user['email']));
                    exit;
                }
                
                $device_check = $device_manager->checkDeviceAccess($user['id']);
                if (!$device_check['allowed']) {
                    $message = $device_check['message'];
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    
                    // Store password hash for validation on every page
                    $_SESSION['password_hash'] = $user['password'];
                    
                    header("Location: home.php");
                    exit;
                }
            } else {
                $message = "Wrong password!";
            }
        } else {
            $message = "Email not found!";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login - LiveNetTV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;height:100vh;}
        .login-container{background:#1a1a1a;padding:40px;width:380px;border-radius:12px;box-shadow:0 0 20px rgba(0,255,255,0.2);border:1px solid #00ffff33;}
        .login-container h2{text-align:center;margin-bottom:25px;color:#00ffff;}
        .input-box{margin-bottom:18px;}
        .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;font-size:14px;}
        .input-box input:focus{border-color:#00ffff;outline:none;}
        .login-btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;font-size:16px;}
        .login-btn:hover{background:#00cccc;}
        .message{margin-top:15px;text-align:center;color:yellow;font-size:14px;}
        .success-message{color:lime;background:#003300;padding:10px;border-radius:8px;}
        .error-message{color:#ff6666;}
        .links{margin-top:20px;text-align:center;}
        .links a{display:block;color:#00ffff;text-decoration:none;margin-top:8px;}
        .links a:hover{text-decoration:underline;}
        @media(max-width:420px){.login-container{width:90%;padding:25px;}}
    </style>
</head>
<body>
<div class="login-container">
    <h2>Member Login</h2>
    <form method="POST">
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" required autofocus>
        </div>
        <div class="input-box">
            <input type="password" name="password" placeholder="Password" required>
        </div>
        <button type="submit" name="login" class="login-btn">Login</button>
        <?php if($message): ?>
            <div class="message <?php echo strpos($message, 'successfully') !== false || strpos($message, 'updated') !== false ? 'success-message' : ''; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <div class="links">
            <a href="register.php">Create Account</a>
            <a href="forgot_password.php">Forgot Password?</a>
        </div>
    </form>
</div>
</body>
</html>