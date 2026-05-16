<?php
session_start();
include "db.php";
include "config.php";

$email = $_GET['email'] ?? '';
if (empty($email)) {
    header("Location: login.php");
    exit;
}

$message = "";
$success = false;
$otp_expired = false;

if (isset($_POST['verify'])) {
    $entered_otp = $_POST['otp'];
    
    // Check with expiration
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE email = ? AND otp = ? AND is_verified = 0");
    mysqli_stmt_bind_param($stmt, "ss", $email, $entered_otp);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($check = mysqli_fetch_assoc($result)) {
        // Check if OTP has expired
        $otp_expires_at = strtotime($check['otp_expires_at']);
        $current_time = time();
        
        if ($otp_expires_at > $current_time) {
            // OTP is valid
            $update_stmt = mysqli_prepare($conn, "UPDATE users SET is_verified = 1, otp = NULL, otp_expires_at = NULL WHERE email = ?");
            mysqli_stmt_bind_param($update_stmt, "s", $email);
            mysqli_stmt_execute($update_stmt);
            $message = "Account Verified Successfully!";
            $success = true;
        } else {
            // OTP has expired
            $otp_expired = true;
            $message = "OTP has expired! Please request a new OTP.";
        }
    } else {
        $message = "Wrong OTP! Please try again.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verify Email</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;height:100vh;}
        .verify-container{background:#1a1a1a;padding:40px;width:380px;border-radius:12px;box-shadow:0 0 20px rgba(0,255,255,0.2);border:1px solid #00ffff33;text-align:center;}
        .verify-container h2{margin-bottom:25px;color:#00ffff;}
        .info-text{background:#111;padding:10px;border-radius:8px;margin-bottom:20px;color:#aaa;font-size:14px;}
        .expiry-warning{background:#331100;padding:8px;border-radius:6px;margin-bottom:15px;color:#ffaa00;font-size:12px;}
        .input-box{margin-bottom:18px;}
        .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;text-align:center;letter-spacing:3px;font-size:18px;}
        .verify-btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;}
        .message{margin-top:15px;color:yellow;}
        .success{color:lime;margin-top:15px;}
        .error{color:#ff6666;}
        .links{margin-top:20px;}
        .links a{color:#00ffff;text-decoration:none;}
        .timer{font-size:12px;color:#00ffff;margin-top:10px;}
        @media(max-width:420px){.verify-container{width:90%;padding:25px;}}
    </style>
</head>
<body>
<div class="verify-container">
    <h2>Email Verification</h2>
    <div class="info-text">
        Verifying: <strong><?php echo htmlspecialchars($email); ?></strong>
    </div>
    <div class="expiry-warning">
        ⏰ OTP expires in <strong><?php echo OTP_EXPIRY_MINUTES; ?> minutes</strong>
    </div>
    
    <?php if(isset($_SESSION['verify_notice'])): ?>
        <div class="message"><?php echo $_SESSION['verify_notice']; unset($_SESSION['verify_notice']); ?></div>
    <?php endif; ?>
    
    <?php if(!$success): ?>
        <form method="POST">
            <div class="input-box">
                <input type="text" name="otp" placeholder="Enter 6-digit OTP" maxlength="6" required autofocus>
            </div>
            <button name="verify" class="verify-btn">Verify Account</button>
            <div class="message <?php echo $otp_expired ? 'error' : ''; ?>"><?php echo $message; ?></div>
        </form>
        <div class="links">
            <a href="resend_otp.php?email=<?php echo urlencode($email); ?>">Resend OTP</a>
            <a href="login.php">Back to Login</a>
        </div>
        <div id="timer" class="timer"></div>
    <?php else: ?>
        <div class="success"><?php echo $message; ?></div>
        <div class="links"><a href="login.php">Login Now →</a></div>
    <?php endif; ?>
</div>

<?php if(!$success): ?>
<script>
    // Optional: Show countdown timer for OTP expiry (based on server time)
    // You can implement a countdown if you store the expiry time in a data attribute
</script>
<?php endif; ?>
</body>
</html>