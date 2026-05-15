<?php
session_start();
include "db.php";
include "config.php";

// ========== ADMIN LOGIN ==========
if (!isset($_SESSION['admin_logged'])) {
    if (isset($_POST['admin_login'])) {
        if ($_POST['password'] == ADMIN_PASSWORD) {
            $_SESSION['admin_logged'] = true;
        } else {
            $login_message = "Wrong Admin Password!";
        }
    }
    
    // Admin Login Form
    echo '<!DOCTYPE html>
    <html>
    <head><title>Admin Login</title><meta name="viewport" content="width=device-width, initial-scale=1">
    <style>*{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
    body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;height:100vh;}
    .admin-login{background:#1a1a1a;padding:40px;border-radius:12px;width:350px;box-shadow:0 0 20px rgba(0,255,255,0.2);border:1px solid #00ffff33;text-align:center;}
    .admin-login h2{color:#00ffff;margin-bottom:25px;}
    .input-box{margin-bottom:18px;}
    .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;}
    .login-btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;}
    .message{margin-top:15px;color:yellow;}
    .links{margin-top:20px;}
    .links a{color:#00ffff;text-decoration:none;}
    @media(max-width:420px){.admin-login{width:90%;padding:25px;}}
    </style></head>
    <body><div class="admin-login"><h2>Admin Login</h2>
    <form method="POST"><div class="input-box"><input type="password" name="password" placeholder="Admin Password" required></div>
    <button type="submit" name="admin_login" class="login-btn">Login</button>
    <div class="message">'.($login_message ?? '').'</div></form>
    <div class="links"><a href="home.php">Go to Home</a></div></div></body></html>';
    exit;
}

// ========== ADMIN LOGOUT ==========
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// ========== APPROVE PAYMENT ==========
if (isset($_GET['approve'])) {
    $payment_id = (int)$_GET['approve'];
    
    // Get payment details
    $result = mysqli_query($conn, "SELECT * FROM payments WHERE id='$payment_id'");
    $pay = mysqli_fetch_assoc($result);
    
    if ($pay) {
        $user_id = $pay['user_id'];
        $amount = $pay['amount'];
        
        // Calculate months based on amount - Using if-else for compatibility
        $months = 0;
        if ($amount == PRICE_1_MONTH) {
            $months = 1;
        } elseif ($amount == PRICE_3_MONTH) {
            $months = 3;
        } elseif ($amount == PRICE_6_MONTH) {
            $months = 6;
        }
        
        if ($months > 0) {
            $expires = date("Y-m-d", strtotime("+$months month"));
            $plan_name = $months . " Month" . ($months > 1 ? "s" : "");
            
            // Insert subscription
            $insert = mysqli_query($conn, "INSERT INTO subscriptions (user_id, plan, expires_at, status) VALUES ('$user_id', '$plan_name', '$expires', 'active')");
            
            // Update payment status
            $update = mysqli_query($conn, "UPDATE payments SET status='approved' WHERE id='$payment_id'");
            
            // Debug - you can remove these after testing
            if (!$insert) {
                die("Insert failed: " . mysqli_error($conn));
            }
            if (!$update) {
                die("Update failed: " . mysqli_error($conn));
            }
        }
    }
    header("Location: admin.php");
    exit;
}

// ========== REJECT PAYMENT ==========
if (isset($_GET['reject'])) {
    $payment_id = (int)$_GET['reject'];
    mysqli_query($conn, "UPDATE payments SET status='rejected' WHERE id='$payment_id'");
    header("Location: admin.php");
    exit;
}

// Fetch pending payments
$pending = mysqli_query($conn, "SELECT payments.*, users.email FROM payments JOIN users ON payments.user_id = users.id WHERE status='pending' ORDER BY id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;padding:20px;}
        h2{color:#00ffff;text-align:center;margin-bottom:25px;}
        table{width:100%;border-collapse:collapse;background:#1a1a1a;border-radius:10px;overflow:hidden;}
        th,td{padding:12px;text-align:center;border-bottom:1px solid #333;}
        th{background:#222;color:#00ffff;}
        tr:hover{background:#111;}
        a{color:#00ffff;text-decoration:none;}
        a.reject{color:red;}
        .btn-logout{position:fixed;top:20px;right:20px;padding:10px 15px;border-radius:8px;background:red;color:white;}
        .btn-home{position:fixed;top:20px;left:20px;padding:10px 15px;border-radius:8px;background:cyan;color:black;}
        @media(max-width:600px){table,th,td{font-size:12px;padding:8px;}}
    </style>
</head>
<body>
<h2>Pending Payments</h2>
<a href="?logout=1" class="btn-logout">Logout</a>
<a href="home.php" class="btn-home">Home</a>

<?php if(mysqli_num_rows($pending) == 0): ?>
    <p style="text-align:center; margin-top:50px;">No pending payments.</p>
<?php else: ?>
    <table>
        <tr>
            <th>ID</th>
            <th>User Email</th>
            <th>Transaction ID</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        <?php while($row = mysqli_fetch_assoc($pending)): ?>
        <tr>
            <td><?php echo $row['id']; ?></td>
            <td><?php echo htmlspecialchars($row['email']); ?></td>
            <td><?php echo htmlspecialchars($row['trxid']); ?></td>
            <td><?php echo $row['amount']; ?>৳</td>
            <td><?php echo $row['status']; ?></td>
            <td>
                <a href="?approve=<?php echo $row['id']; ?>" onclick="return confirm('Approve this payment?')">✅ Approve</a> | 
                <a href="?reject=<?php echo $row['id']; ?>" class="reject" onclick="return confirm('Reject this payment?')">❌ Reject</a>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>
<?php endif; ?>

</body>
</html>