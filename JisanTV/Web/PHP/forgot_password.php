<?php
session_start();
include "db.php";
include "config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'src/Exception.php';
require 'src/PHPMailer.php';
require 'src/SMTP.php';

$message = "";
$success = false;
$email = "";

if (isset($_POST['send_otp'])) {
    $email = trim($_POST['email']);
    
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) == 1) {
        $user = mysqli_fetch_assoc($result);
        $otp = rand(100000, 999999);
        $otp_expires = date("Y-m-d H:i:s", strtotime("+" . OTP_EXPIRY_MINUTES . " minutes"));
        
        $update_stmt = mysqli_prepare($conn, "UPDATE users SET otp = ?, otp_expires_at = ? WHERE email = ?");
        mysqli_stmt_bind_param($update_stmt, "sss", $otp, $otp_expires, $email);
        mysqli_stmt_execute($update_stmt);
        
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($email);
            $mail->Subject = 'Password Reset OTP - Expires in ' . OTP_EXPIRY_MINUTES . ' Minutes';
            $mail->Body = "Your OTP to reset password is: $otp\n\nThis OTP will expire in " . OTP_EXPIRY_MINUTES . " minutes.\n\nEnter this code on the verification page to reset your password.";
            $mail->send();
            
            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_otp_sent'] = true;
            
            header("Location: reset_verify.php?email=" . urlencode($email));
            exit;
            
        } catch (Exception $e) {
            $message = "Failed to send OTP: " . $mail->ErrorInfo;
        }
    } else {
        $message = "Email not found!";
    }
}

if (isset($_SESSION['reset_email']) && empty($email)) {
    $email = $_SESSION['reset_email'];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;height:100vh;}
        .container{background:#1a1a1a;padding:40px;width:380px;border-radius:12px;box-shadow:0 0 20px rgba(0,255,255,0.2);border:1px solid #00ffff33;text-align:center;}
        h2{color:#00ffff;margin-bottom:25px;}
        .note{font-size:12px;color:#aaa;margin-bottom:15px;}
        .input-box{margin-bottom:18px;}
        .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;}
        .btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;}
        .btn:hover{background:#00cccc;}
        .message{margin-top:15px;color:yellow;}
        .links{margin-top:20px;}
        .links a{color:#00ffff;text-decoration:none;}
        @media(max-width:420px){.container{width:90%;padding:25px;}}
    </style>
</head>
<body>
<div class="container">
    <h2>Reset Password</h2>
    <div class="note">OTP will expire in <?php echo OTP_EXPIRY_MINUTES; ?> minutes</div>
    <form method="POST">
        <div class="input-box">
            <input type="email" name="email" placeholder="Email Address" value="<?php echo htmlspecialchars($email); ?>" required>
        </div>
        <button type="submit" name="send_otp" class="btn">Send OTP</button>
        <?php if($message): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>
    </form>
    <div class="links">
        <a href="login.php">Back to Login</a>
    </div>
</div>
</body>
</html>