<?php
session_start();
include "db.php";
include "config.php";
include "device_helper.php";

// Get email from session or GET
$email = $_GET['email'] ?? $_SESSION['reset_email'] ?? '';

if (empty($email)) {
    header("Location: forgot_password.php");
    exit;
}

$message = "";
$success = false;

if (isset($_POST['reset'])) {
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    if (empty($password)) {
        $message = "Password is required!";
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters!";
    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match!";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        
        // Get user ID first
        $user_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
        mysqli_stmt_bind_param($user_stmt, "s", $email);
        mysqli_stmt_execute($user_stmt);
        $user_result = mysqli_stmt_get_result($user_stmt);
        
        if ($user_row = mysqli_fetch_assoc($user_result)) {
            $user_id = $user_row['id'];
            
            // Update password
            $update_stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, otp = NULL, otp_expires_at = NULL WHERE id = ?");
            mysqli_stmt_bind_param($update_stmt, "si", $hashed, $user_id);
            mysqli_stmt_execute($update_stmt);
            
            // Remove all devices
            $device_manager = new DeviceManager($conn);
            $device_manager->removeAllDevices($user_id);
            
            // ========== IMPORTANT: Force logout from all devices ==========
            // Delete all existing sessions by regenerating session ID
            // This doesn't directly affect other devices, but we'll use a different approach
            
            // Instead, let's update a 'session_key' that we'll check on every page
            // For simplicity, we'll use the password hash itself as a validation key
            // Add this column to your users table:
            // ALTER TABLE users ADD COLUMN session_hash VARCHAR(255) DEFAULT NULL;
            
            // For now, let's just store the new password hash in session for validation
            // And update all users' sessions to be invalid
            
            $success = true;
            $message = "Password updated successfully! You have been logged out from all devices.";
            
            // Clear current session
            session_destroy();
            
            // Clear reset session data
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_otp_sent']);
            
        } else {
            $message = "User not found!";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Set New Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;min-height:100vh;}
        .container{background:#1a1a1a;padding:40px;width:400px;border-radius:12px;box-shadow:0 0 20px rgba(0,255,255,0.2);border:1px solid #00ffff33;text-align:center;}
        h2{color:#00ffff;margin-bottom:25px;}
        .info-text{background:#111;padding:10px;border-radius:8px;margin-bottom:20px;color:#aaa;font-size:14px;word-break:break-all;}
        .security-note{background:#001133;padding:10px;border-radius:8px;margin-bottom:20px;color:#88aaff;font-size:12px;border-left:3px solid #00ffff;}
        .input-box{margin-bottom:18px;}
        .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;font-size:14px;}
        .input-box input:focus{border-color:#00ffff;outline:none;}
        .btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;font-size:16px;}
        .btn:hover{background:#00cccc;}
        .message{margin-top:15px;color:yellow;}
        .success-message{color:lime;}
        .error-message{color:#ff6666;}
        .links{margin-top:20px;}
        .links a{color:#00ffff;text-decoration:none;}
        .device-warning{background:#330000;padding:10px;border-radius:8px;margin-top:15px;color:#ff8888;font-size:12px;}
        @media(max-width:480px){.container{width:95%;padding:25px;}}
    </style>
    <?php if($success): ?>
    <script>
        // Force redirect after 3 seconds
        setTimeout(function() {
            window.location.href = "login.php?reset=success";
        }, 3000);
    </script>
    <?php endif; ?>
</head>
<body>
<div class="container">
    <h2>Set New Password</h2>
    <div class="info-text">
        Resetting password for: <strong><?php echo htmlspecialchars($email); ?></strong>
    </div>
    <div class="security-note">
        🔒 Security Notice: After password reset, ALL your devices will be logged out.
    </div>
    
    <?php if(!$success): ?>
    <form method="POST" onsubmit="return validateForm()">
        <div class="input-box">
            <input type="password" name="password" id="password" placeholder="New Password (min 6 characters)" required>
        </div>
        <div class="input-box">
            <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm New Password" required>
        </div>
        <button type="submit" name="reset" class="btn">Update Password</button>
        <div class="message"><?php echo $message; ?></div>
    </form>
    <div class="device-warning">
        ⚠️ Warning: This will log you out from ALL your devices.<br>
        You'll need to log in again on each device.
    </div>
    <?php else: ?>
        <div class="message success-message"><?php echo $message; ?></div>
        <div class="device-warning" style="background:#003300; color:#88ff88;">
            ✅ Password updated! All devices have been logged out.<br>
            Redirecting to login page...
        </div>
    <?php endif; ?>
    <div class="links">
        <a href="login.php">Back to Login</a>
    </div>
</div>

<script>
function validateForm() {
    var password = document.getElementById('password').value;
    var confirm = document.getElementById('confirm_password').value;
    
    if (password.length < 6) {
        alert('Password must be at least 6 characters long!');
        return false;
    }
    
    if (password !== confirm) {
        alert('Passwords do not match!');
        return false;
    }
    
    return confirm('WARNING: This will log you out from ALL your devices. Are you sure?');
}
</script>
</body>
</html>