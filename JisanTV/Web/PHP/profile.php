<?php
session_start();
include "db.php";
include "device_helper.php";
include "config.php";

// ========== SESSION & DEVICE VALIDATION ==========
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Check if password was changed
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    if (!isset($_SESSION['password_hash']) || $_SESSION['password_hash'] !== $user['password']) {
        session_destroy();
        header("Location: login.php?msg=password_changed");
        exit;
    }
}

// Check if current device is still authorized
$device_manager = new DeviceManager($conn);
$device_id = $device_manager->getDeviceFingerprint();

$device_check = $conn->prepare("SELECT id FROM device_tokens WHERE user_id = ? AND device_id = ? AND is_active = 1");
$device_check->bind_param("is", $user_id, $device_id);
$device_check->execute();
$device_result = $device_check->get_result();

if ($device_result->num_rows == 0) {
    // Device was removed - logout immediately
    session_destroy();
    header("Location: login.php?msg=device_removed");
    exit;
}
// ========== END OF VALIDATION ==========

// Remove device
$message = "";
if (isset($_GET['remove_device'])) {
    $device_token_id = (int)$_GET['remove_device'];
    
    // Check if removing current device
    $current_device_check = $conn->prepare("SELECT device_id FROM device_tokens WHERE id = ? AND user_id = ?");
    $current_device_check->bind_param("ii", $device_token_id, $user_id);
    $current_device_check->execute();
    $current_device_result = $current_device_check->get_result();
    
    if ($current_device_row = $current_device_result->fetch_assoc()) {
        $is_current_device = ($current_device_row['device_id'] == $device_id);
        
        if ($device_manager->removeDevice($user_id, $device_token_id)) {
            if ($is_current_device) {
                // Removing current device - logout immediately
                session_destroy();
                header("Location: login.php?msg=self_device_removed");
                exit;
            }
            $message = "Device removed successfully!";
        } else {
            $message = "Failed to remove device!";
        }
    }
}

// Remove all devices
if (isset($_GET['remove_all_devices'])) {
    $is_current_removed = $device_manager->removeAllDevices($user_id);
    if ($is_current_removed) {
        session_destroy();
        header("Location: login.php?msg=all_devices_removed");
        exit;
    }
}

// Get user info
$user = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id='$user_id'"));

// Get subscription
$sub = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM subscriptions WHERE user_id='$user_id' ORDER BY id DESC LIMIT 1"));
$sub_active = false;
$sub_message = "No Active Subscription";
if ($sub && $sub['status'] == 'active' && $sub['expires_at'] >= date("Y-m-d")) {
    $sub_active = true;
    $sub_message = "✅ Active - Expires on " . $sub['expires_at'];
} elseif ($sub) {
    $sub_message = "❌ Expired on " . $sub['expires_at'];
}

$devices = $device_manager->getUserDevices($user_id);
$device_count = $device_manager->getActiveDeviceCount($user_id);
?>

<!DOCTYPE html>
<html>
<head>
    <title>My Profile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px;}
        .profile-container{background:#1a1a1a;padding:40px;width:550px;border-radius:12px;box-shadow:0 0 20px rgba(0,255,255,0.2);border:1px solid #00ffff33;}
        .profile-container h2{text-align:center;margin-bottom:25px;color:#00ffff;}
        .info-box{background:#111;padding:15px;margin-bottom:20px;border-radius:10px;border:1px solid #00ffff33;}
        .info-box strong{color:lime;}
        .device-box{background:#111;padding:15px;margin-bottom:20px;border-radius:10px;border:1px solid #00ffff33;}
        .device-box h3{color:lime;margin-bottom:15px;}
        .device-item{display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid #333;}
        .device-name{font-weight:bold;}
        .current-device{color:#00ffff;font-size:11px;margin-left:8px;}
        .device-detail{font-size:12px;color:#aaa;margin-top:5px;}
        .remove-device{color:red;text-decoration:none;padding:5px 10px;border-radius:5px;background:#330000;}
        .remove-device:hover{background:#660000;}
        .btn{display:block;width:100%;text-align:center;padding:12px;border-radius:8px;font-weight:bold;text-decoration:none;margin-top:10px;}
        .btn-dashboard{background:#00ffff;color:black;}
        .btn-buy{background:orange;color:black;}
        .btn-logout{background:red;color:white;}
        .btn-remove-all{background:#ff6600;color:white;}
        .device-count{color:lime;font-weight:bold;}
        .message{margin-bottom:15px;padding:10px;border-radius:8px;text-align:center;background:green;color:white;}
        .warning-note{background:#331100;padding:10px;border-radius:8px;margin-top:15px;color:#ffaa00;font-size:12px;text-align:center;}
        @media(max-width:600px){.profile-container{width:95%;padding:20px;}
        .device-item{flex-direction:column;text-align:center;}
        .remove-device{margin-top:10px;}}
    </style>
</head>
<body>
<div class="profile-container">
    <h2>👤 My Profile</h2>
    <?php if(isset($message) && $message): ?><div class="message"><?php echo $message; ?></div><?php endif; ?>
    
    <div class="info-box">
        <p><strong>Name:</strong> <?php echo htmlspecialchars($user['name']); ?></p>
        <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
        <p><strong>Member Since:</strong> <?php echo date("d M Y", strtotime($user['created_at'])); ?></p>
    </div>
    
    <div class="info-box">
        <p><strong>Subscription:</strong> <?php echo $sub_message; ?></p>
        <?php if(!$sub_active): ?>
            <a href="buy.php" class="btn btn-buy">Buy Subscription</a>
        <?php endif; ?>
    </div>
    
    <div class="device-box">
        <h3>📱 Connected Devices (<span class="device-count"><?php echo $device_count; ?>/<?php echo MAX_DEVICES; ?></span>)</h3>
        <?php if($device_count == 0): ?>
            <p style="color:#aaa; text-align:center;">No devices connected.</p>
        <?php else: ?>
            <?php while($device = mysqli_fetch_assoc($devices)): ?>
            <?php 
                $is_current = ($device['device_id'] ?? '') == $device_id;
            ?>
            <div class="device-item">
                <div class="device-info">
                    <div class="device-name">
                        <?php echo htmlspecialchars($device['device_name']); ?>
                        <?php if($is_current): ?>
                            <span class="current-device">(Current Device)</span>
                        <?php endif; ?>
                    </div>
                    <div class="device-detail">Last active: <?php echo date("d M Y H:i", strtotime($device['last_login'])); ?></div>
                </div>
                <a href="?remove_device=<?php echo $device['id']; ?>" class="remove-device" onclick="return confirm('Remove this device? You will be logged out from it immediately.')">❌ Remove</a>
            </div>
            <?php endwhile; ?>
            <?php if($device_count > 0): ?>
                <a href="?remove_all_devices=1" class="btn btn-remove-all" onclick="return confirm('⚠️ WARNING: This will log you out from ALL devices including this one! Continue?')">🔒 Remove All Devices</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <div class="warning-note">
        ⚠️ Note: Removing a device will immediately log out that device.
    </div>
    
    <a href="home.php" class="btn btn-dashboard">Go to Home</a>
    <a href="logout.php" class="btn btn-logout">Logout</a>
</div>
</body>
</html>