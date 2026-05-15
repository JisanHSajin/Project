<?php
/**
 * Device Management Helper Functions
 * Handles device tracking and limitation (max 3 devices per user)
 */

class DeviceManager {
    private $conn;
    private $max_devices = 3;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    /**
     * Generate unique device fingerprint
     */
    public function getDeviceFingerprint() {
        $fingerprint = [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            'platform' => php_uname('s') ?? '',
            'ip' => $this->getClientIP()
        ];
        
        // For web, also consider screen resolution if available via JavaScript
        // We'll handle that with a separate cookie/header
        
        return hash('sha256', json_encode($fingerprint));
    }
    
    /**
     * Get client IP address
     */
    private function getClientIP() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }
    
    /**
     * Detect device type from user agent
     */
    public function detectDeviceType() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', $user_agent)) {
            return 'mobile';
        }
        
        if (preg_match('/(ipad|tablet|(android(?!.*mobile))|(windows(?!.*phone)(.*touch))|kindle|playbook|silk|(puffin(?!.*(IP|AP|WP))))/i', $user_agent)) {
            return 'tablet';
        }
        
        return 'desktop';
    }
    
    /**
     * Get device name for display
     */
    public function getDeviceName() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser';
        
        // Try to get OS info
        $os = 'Unknown OS';
        $os_patterns = [
            'Windows NT 10.0' => 'Windows 10',
            'Windows NT 6.3' => 'Windows 8.1',
            'Windows NT 6.2' => 'Windows 8',
            'Windows NT 6.1' => 'Windows 7',
            'Windows NT 6.0' => 'Windows Vista',
            'Windows NT 5.1' => 'Windows XP',
            'Mac OS X' => 'Mac OS X',
            'Mac OS' => 'Mac OS',
            'iPhone' => 'iPhone',
            'iPad' => 'iPad',
            'Android' => 'Android',
            'Linux' => 'Linux'
        ];
        
        foreach ($os_patterns as $pattern => $name) {
            if (stripos($user_agent, $pattern) !== false) {
                $os = $name;
                break;
            }
        }
        
        // Try to get browser info
        $browser = 'Unknown Browser';
        $browser_patterns = [
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'Chrome' => 'Chrome',
            'Firefox' => 'Firefox',
            'Safari' => 'Safari',
            'MSIE' => 'Internet Explorer',
            'Trident' => 'Internet Explorer'
        ];
        
        foreach ($browser_patterns as $pattern => $name) {
            if (stripos($user_agent, $pattern) !== false) {
                $browser = $name;
                break;
            }
        }
        
        $device_type = $this->detectDeviceType();
        return "$os - $browser ($device_type)";
    }
    
    /**
     * Register or validate device for a user
     * Returns: 
     *   'success' => true/false, 
     *   'message' => string, 
     *   'action' => 'new_device'/'existing_device'/'limit_reached',
     *   'device_count' => int
     */
    public function registerDevice($user_id, $force = false) {
        $device_id = $this->getDeviceFingerprint();
        $device_name = $this->getDeviceName();
        $device_type = $this->detectDeviceType();
        
        // Check if this device is already registered
        $check_sql = "SELECT * FROM device_tokens WHERE user_id = ? AND device_id = ?";
        $stmt = $this->conn->prepare($check_sql);
        $stmt->bind_param("is", $user_id, $device_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Device already exists - update last login
            $update_sql = "UPDATE device_tokens SET last_login = NOW(), device_name = ?, device_type = ? WHERE user_id = ? AND device_id = ?";
            $stmt = $this->conn->prepare($update_sql);
            $stmt->bind_param("ssis", $device_name, $device_type, $user_id, $device_id);
            $stmt->execute();
            
            return [
                'success' => true,
                'message' => 'Device already registered',
                'action' => 'existing_device',
                'device_count' => $this->getActiveDeviceCount($user_id)
            ];
        }
        
        // Check current active device count
        $current_count = $this->getActiveDeviceCount($user_id);
        
        if ($current_count >= $this->max_devices && !$force) {
            return [
                'success' => false,
                'message' => "Maximum $this->max_devices devices limit reached. Please remove a device from your profile first.",
                'action' => 'limit_reached',
                'device_count' => $current_count,
                'max_devices' => $this->max_devices
            ];
        }
        
        // Register new device
        $insert_sql = "INSERT INTO device_tokens (user_id, device_id, device_name, device_type, last_login, is_active) 
                       VALUES (?, ?, ?, ?, NOW(), 1)";
        $stmt = $this->conn->prepare($insert_sql);
        $stmt->bind_param("isss", $user_id, $device_id, $device_name, $device_type);
        
        if ($stmt->execute()) {
            return [
                'success' => true,
                'message' => 'New device registered',
                'action' => 'new_device',
                'device_count' => $this->getActiveDeviceCount($user_id),
                'max_devices' => $this->max_devices
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to register device',
            'action' => 'error'
        ];
    }
    
    /**
     * Get active device count for a user
     */
    public function getActiveDeviceCount($user_id) {
        $sql = "SELECT COUNT(*) as count FROM device_tokens WHERE user_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'] ?? 0;
    }
    
    /**
     * Get all devices for a user
     */
    public function getUserDevices($user_id) {
        $sql = "SELECT id, device_name, device_type, last_login, created_at FROM device_tokens 
                WHERE user_id = ? AND is_active = 1 ORDER BY last_login DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result();
    }
    
    /**
     * Remove a specific device
     */
    public function removeDevice($user_id, $device_token_id) {
        // First verify this device belongs to the user
        $sql = "SELECT id FROM device_tokens WHERE id = ? AND user_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $device_token_id, $user_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows === 0) {
            return false;
        }
        
        // Soft delete (set inactive) or hard delete?
        $delete_sql = "DELETE FROM device_tokens WHERE id = ? AND user_id = ?";
        $stmt = $this->conn->prepare($delete_sql);
        $stmt->bind_param("ii", $device_token_id, $user_id);
        
        return $stmt->execute();
    }
    
    /**
     * Remove all devices for a user (used on password reset)
     */
    public function removeAllDevices($user_id) {
        $sql = "DELETE FROM device_tokens WHERE user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        return $stmt->execute();
    }
    
    /**
     * Check if current device is allowed for login
     * Returns array with allowed status and message
     */
    public function checkDeviceAccess($user_id) {
        $device_id = $this->getDeviceFingerprint();
        
        // Check if this device is registered
        $sql = "SELECT id FROM device_tokens WHERE user_id = ? AND device_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("is", $user_id, $device_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            return ['allowed' => true, 'message' => 'Device authorized'];
        }
        
        // Check device limit
        $current_count = $this->getActiveDeviceCount($user_id);
        
        if ($current_count >= $this->max_devices) {
            return [
                'allowed' => false, 
                'message' => "Device limit reached. Maximum $this->max_devices devices allowed.",
                'device_count' => $current_count,
                'max_devices' => $this->max_devices
            ];
        }
        
        // Auto-register new device (first time login on this device)
        $result = $this->registerDevice($user_id);
        return [
            'allowed' => $result['success'],
            'message' => $result['message'],
            'device_count' => $result['device_count'] ?? $current_count,
            'max_devices' => $this->max_devices,
            'action' => $result['action']
        ];
    }
}
?>