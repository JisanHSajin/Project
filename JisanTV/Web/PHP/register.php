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

if (isset($_POST['register'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $pass = trim($_POST['password']);
    
    if (empty($name) || empty($email) || empty($pass)) {
        $message = "All fields are required!";
    } else {
        $check = mysqli_query($conn, "SELECT * FROM users WHERE email='$email'");
        if (mysqli_num_rows($check) > 0) {
            $message = "Email already registered!";
        } else {
            $hashed = password_hash($pass, PASSWORD_DEFAULT);
            $otp = rand(100000, 999999);
            $otp_expires = date("Y-m-d H:i:s", strtotime("+" . OTP_EXPIRY_MINUTES . " minutes"));
            
            $stmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, otp, otp_expires_at, is_verified) VALUES (?, ?, ?, ?, ?, 0)");
            mysqli_stmt_bind_param($stmt, "sssss", $name, $email, $hashed, $otp, $otp_expires);
            
            if (mysqli_stmt_execute($stmt)) {
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
                    $mail->Subject = 'Verify Your Email - OTP Expires in ' . OTP_EXPIRY_MINUTES . ' Minutes';
                    $mail->Body = "Hello $name,\n\nYour OTP is: $otp\n\nThis OTP will expire in " . OTP_EXPIRY_MINUTES . " minutes.\n\nEnter this code to verify your account.";
                    $mail->send();
                    header("Location: verify.php?email=" . urlencode($email));
                    exit;
                } catch (Exception $e) {
                    $message = "Registration failed: " . $mail->ErrorInfo;
                }
            } else {
                $message = "Registration failed. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register - LiveNetTV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;height:100vh;}
        .register-container{background:#1a1a1a;padding:40px;width:380px;border-radius:12px;box-shadow:0 0 20px rgba(0,255,255,0.2);border:1px solid #00ffff33;}
        .register-container h2{text-align:center;margin-bottom:25px;color:#00ffff;}
        .input-box{margin-bottom:18px;}
        .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;}
        .register-btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;}
        .message{margin-top:15px;text-align:center;color:yellow;}
        .links{margin-top:20px;text-align:center;}
        .links a{color:#00ffff;text-decoration:none;}
        .note{font-size:12px;color:#aaa;margin-top:10px;text-align:center;}
        @media(max-width:420px){.register-container{width:90%;padding:25px;}}
    </style>
</head>
<body>
<div class="register-container">
    <h2>Create Account</h2>
    <form method="POST">
        <div class="input-box"><input type="text" name="name" placeholder="Full Name" required></div>
        <div class="input-box"><input type="email" name="email" placeholder="Email Address" required></div>
        <div class="input-box"><input type="password" name="password" placeholder="Password" required></div>
        <button type="submit" name="register" class="register-btn">Register</button>
        <div class="message"><?php echo $message; ?></div>
        <div class="links"><a href="login.php">Already have an account? Login</a></div>
        <div class="note">OTP will expire in <?php echo OTP_EXPIRY_MINUTES; ?> minutes</div>
    </form>
</div>
</body>
</html>