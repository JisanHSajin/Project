<?php
session_start();
include "db.php";
include "config.php";
include "device_helper.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$device_manager = new DeviceManager($conn);

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
} else {
    session_destroy();
    header("Location: login.php");
    exit;
}

$device_id = $device_manager->getDeviceFingerprint();
$device_check = $conn->prepare("SELECT id FROM device_tokens WHERE user_id = ? AND device_id = ? AND is_active = 1");
$device_check->bind_param("is", $user_id, $device_id);
$device_check->execute();

if ($device_check->get_result()->num_rows == 0) {
    session_destroy();
    header("Location: login.php?msg=device_removed");
    exit;
}

function fetchM3U($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

function parseM3U($data) {
    $channels = [];
    if (!$data) return $channels;
    
    $lines = preg_split("/\r\n|\n|\r/", $data);
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], "#EXTINF") !== false) {
            preg_match('/tvg-logo="([^"]*)"/', $lines[$i], $logo);
            preg_match('/group-title="([^"]*)"/', $lines[$i], $group);
            preg_match('/,(.*)$/', $lines[$i], $name);
            $url = trim($lines[$i + 1] ?? "");
            $category = $group[1] ?? "Others";
            
            if (!empty($url)) {
                $channels[$category][] = [
                    "name" => trim($name[1] ?? "Channel"),
                    "logo" => $logo[1] ?? "",
                    "url" => $url
                ];
            }
        }
    }
    
    foreach ($channels as $cat => $ch_list) {
        usort($ch_list, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        $channels[$cat] = $ch_list;
    }
    
    return $channels;
}

$premium_active = false;
$stmt = $conn->prepare("SELECT * FROM subscriptions WHERE user_id = ? AND status = 'active' AND expires_at >= CURDATE()");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$premium_active = ($stmt->get_result()->num_rows > 0);
$stmt->close();

$free_channels = parseM3U(fetchM3U(FREE_M3U_URL));
$premium_channels = $premium_active ? parseM3U(fetchM3U(PREMIUM_M3U_URL)) : [];

$is_mobile_app = false;
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (stripos($user_agent, 'JisanTV') !== false || 
    stripos($user_agent, 'JisanRealTV') !== false ||
    stripos($user_agent, 'wv') !== false ||
    (stripos($user_agent, 'Android') !== false && stripos($user_agent, 'wv') !== false)) {
    $is_mobile_app = true;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>LiveNetTV - Watch Live Channels</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;}
        
        .site-header{display:flex;justify-content:space-between;align-items:center;padding:15px 30px;background:#1a1a1a;border-bottom:1px solid #00ffff33;flex-wrap:wrap;gap:10px;}
        .site-branding a{display:flex;align-items:center;text-decoration:none;color:white;}
        .site-branding img{width:45px;height:45px;margin-right:10px;border-radius:10px;}
        .site-branding span{font-size:18px;font-weight:bold;color:#00ffff;}
        .site-nav{display:flex;gap:20px;flex-wrap:wrap;}
        .site-nav a,.header-right a{color:#00ffff;text-decoration:none;font-weight:bold;}
        .site-nav a:hover,.header-right a:hover{color:#00cccc;}
        .header-right{display:flex;gap:15px;align-items:center;flex-wrap:wrap;}
        
        .main-layout{display:flex;gap:20px;padding:20px 30px;}
        .left-side{flex:1;display:flex;justify-content:center;align-items:center;}
        .left-side .player-container{width:90%;max-width:800px;background:#000;border-radius:15px;overflow:hidden;border:1px solid #00ffff33;position:relative;}
        .player-container video{width:100%;height:auto;min-height:400px;max-height:500px;background:#000;}
        .right-side{flex:1;max-height:80vh;overflow-y:auto;}
        
        .player-info{background:#1a1a1a;padding:10px;text-align:center;border-top:1px solid #00ffff33;}
        .player-info .channel-name{color:#00ffff;font-weight:bold;font-size:16px;}
        .loading-overlay{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,0.8);padding:10px 20px;border-radius:8px;color:#00ffff;display:none;z-index:100;}
        
        .search-box{padding:20px 30px;position:sticky;top:0;background:#0f0f0f;z-index:500;}
        #searchInput{width:100%;padding:14px;border-radius:10px;border:1px solid #333;background:#1a1a1a;color:white;font-size:15px;}
        #searchInput:focus{border-color:#00ffff;outline:none;}
        
        .category-filter{padding:10px 30px;display:flex;flex-wrap:wrap;gap:10px;border-bottom:1px solid #00ffff33;}
        .cat-btn{padding:8px 12px;background:#1a1a1a;border:1px solid #00ffff33;border-radius:8px;cursor:pointer;color:white;}
        .cat-btn:hover,.cat-btn.active{background:#00ffff;color:black;}
        
        h2{margin:30px;color:#00ffff;}
        .category-block{margin-bottom:20px;}
        .category-title{margin:20px 30px 15px;font-size:18px;color:#00ffff;text-align:center;padding:10px;background:#1a1a1a;border:1px solid #00ffff33;border-radius:10px;}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:15px;padding:0 30px 30px;}
        .card{background:#1a1a1a;padding:15px;border-radius:12px;text-align:center;cursor:pointer;border:1px solid #00ffff22;transition:0.3s;}
        .card:hover{transform:translateY(-5px);box-shadow:0 0 15px rgba(0,255,255,0.4);}
        .card img{max-height:50px;max-width:100%;margin-bottom:10px;}
        
        .lockbox{padding:30px;text-align:center;background:#1a1a1a;margin:0 30px 30px;border-radius:15px;border:1px solid red;}
        .lockbox a{color:lime;font-size:18px;}
        
        .app-ad-banner {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border: 1px solid #00ffff44;
            border-radius: 16px;
            margin: 20px 30px;
            padding: 20px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,255,255,0.1);
        }
        .app-ad-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(0,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }
        @keyframes pulse {
            0% { opacity: 0.3; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.05); }
            100% { opacity: 0.3; transform: scale(1); }
        }
        .app-ad-content {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            z-index: 2;
            flex: 2;
        }
        .app-ad-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #00ffff, #0066ff);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            box-shadow: 0 0 15px rgba(0,255,255,0.5);
        }
        .app-ad-text h3 {
            color: #00ffff;
            font-size: 20px;
            margin-bottom: 5px;
        }
        .app-ad-text p {
            color: #ccc;
            font-size: 14px;
        }
        .app-ad-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            z-index: 2;
        }
        .app-download-btn {
            background: linear-gradient(135deg, #00ffff, #0066ff);
            color: #000;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 40px;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: 0.3s;
            box-shadow: 0 3px 10px rgba(0,255,255,0.3);
        }
        .app-download-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,255,255,0.5);
            background: linear-gradient(135deg, #00ddff, #0055dd);
        }
        .app-download-btn.secondary {
            background: linear-gradient(135deg, #ff6600, #ff0066);
            box-shadow: 0 3px 10px rgba(255,102,0,0.3);
        }
        .app-download-btn.secondary:hover {
            box-shadow: 0 8px 25px rgba(255,102,0,0.5);
        }
        .close-ad {
            position: absolute;
            top: 10px;
            right: 15px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            cursor: pointer;
            font-size: 18px;
            padding: 0 8px;
            border-radius: 50%;
            z-index: 3;
            transition: 0.3s;
        }
        .close-ad:hover {
            background: rgba(255,255,255,0.4);
            color: #ff4444;
        }
        
        .site-footer{background:#1a1a1a;padding:30px;text-align:center;border-top:1px solid #00ffff22;margin-top:40px;}
        .footer-social{display:flex;justify-content:center;gap:20px;margin-bottom:15px;}
        .footer-social svg{width:24px;height:24px;fill:white;}
        .footer-social a:hover svg{fill:#00ffff;}
        
        body {
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            -khtml-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        
        @media(max-width:768px){
            .site-header{flex-direction:column;text-align:center;}
            .main-layout{flex-direction:column;}
            .left-side .player-container{height:280px;}
            .player-container video{min-height:250px;max-height:280px;}
            .right-side{max-height:none;}
            .grid{grid-template-columns:repeat(2,1fr);gap:12px;padding:0 12px 18px;}
            h2,.category-title{margin:15px 12px;}
            .search-box,.category-filter{padding:10px 12px;}
            .app-ad-banner { margin: 15px 12px; flex-direction: column; text-align: center; }
            .app-ad-content { justify-content: center; }
        }
        
        .mobile-app-hidden {
            display: none !important;
        }
    </style>
</head>
<body>

<header class="site-header">
    <div class="site-branding">
        <a href="home.php">
            <img src="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png" alt="Logo">
            <span>LiveNetTV</span>
        </a>
    </div>
    <nav class="site-nav">
        <a href="#channels">Channels</a>
        <a href="#footer">Connect</a>
        <a href="https://apkpure.com/developer?id=41147142" target="_blank">App</a>
    </nav>
    <div class="header-right">
        <?php if(isset($_SESSION['user_id'])): ?>
            <span><b><?php echo htmlspecialchars($_SESSION['user_name']); ?></b></span>
            <a href="profile.php">Profile</a>
            <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php">Register</a>
        <?php endif; ?>
    </div>
</header>

<div class="app-ad-banner <?php echo $is_mobile_app ? 'mobile-app-hidden' : ''; ?>" id="mainAdBanner">
    <button class="close-ad" onclick="document.getElementById('mainAdBanner').style.display='none';">×</button>
    <div class="app-ad-content">
        <div class="app-ad-icon">📺</div>
        <div class="app-ad-text">
            <h3>🔥 Get JisanTV Mobile App!</h3>
            <p>Watch 1000+ live channels on your phone - Better experience, less buffering!</p>
        </div>
    </div>
    <div class="app-ad-buttons">
        <a href="https://apkpure.com/p/com.jisanhsajin.jisantv" target="_blank" class="app-download-btn">
            📱 JisanTV
        </a>
        <a href="https://apkpure.com/p/com.jisanhsajin.jisanrealtv" target="_blank" class="app-download-btn secondary">
            🎬 JisanRealTV
        </a>
    </div>
</div>

<div class="main-layout">
    <div class="left-side">
        <div class="player-container">
            <video id="video" controls crossorigin="anonymous"></video>
            <div class="loading-overlay" id="loadingOverlay">Loading stream...</div>
            <div class="player-info">
                <div class="channel-name" id="channelName">Select a channel to play</div>
            </div>
        </div>
    </div>
    
    <div class="right-side">
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="Search channels..." onkeyup="searchChannels()">
        </div>
        
        <div class="category-filter" id="categoryFilter"></div>
        
        <h2 id="channels">📺 Live Channels</h2>
        
        <?php foreach($free_channels as $cat => $channels): ?>
        <div class="category-block" data-category="<?php echo strtolower($cat); ?>">
            <div class="category-title"><?php echo htmlspecialchars($cat); ?></div>
            <div class="grid">
                <?php foreach($channels as $ch): ?>
                <div class="card" data-name="<?php echo strtolower($ch['name']); ?>" onclick="playStream('<?php echo addslashes($ch['url']); ?>', '<?php echo addslashes($ch['name']); ?>')">
                    <img src="<?php echo htmlspecialchars($ch['logo']); ?>" alt="<?php echo htmlspecialchars($ch['name']); ?>" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22%2300ffff%22%3E%3Cpath d=%22M4 6h16v12H4z%22/%3E%3C/svg%3E'">
                    <div><?php echo htmlspecialchars($ch['name']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <h2>⭐ Premium Channels</h2>
        <?php if(!$premium_active): ?>
        <div class="lockbox">
            <h3>🔒 Premium Locked</h3>
            <p>You need an active subscription to unlock premium channels.</p>
            <a href="buy.php">Buy Premium Now →</a>
        </div>
        <?php else: ?>
            <?php foreach($premium_channels as $cat => $channels): ?>
            <div class="category-block" data-category="<?php echo strtolower($cat); ?>">
                <div class="category-title"><?php echo htmlspecialchars($cat); ?></div>
                <div class="grid">
                    <?php foreach($channels as $ch): ?>
                    <div class="card" data-name="<?php echo strtolower($ch['name']); ?>" onclick="playStream('<?php echo addslashes($ch['url']); ?>', '<?php echo addslashes($ch['name']); ?>')">
                        <img src="<?php echo htmlspecialchars($ch['logo']); ?>" alt="<?php echo htmlspecialchars($ch['name']); ?>" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22%23ffd700%22%3E%3Cpath d=%22M12 2l2.4 7.2h7.6l-6 4.8 2.4 7.2-6-4.8-6 4.8 2.4-7.2-6-4.8h7.6z%22/%3E%3C/svg%3E'">
                        <div><?php echo htmlspecialchars($ch['name']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<footer class="site-footer" id="footer">
    <div class="footer-social">
        <a href="https://www.facebook.com/jisanhsajin/" target="_blank"><svg viewBox="0 0 24 24"><path d="M22.675 0H1.325C.593 0 0 .593 0 1.325v21.351C0 23.407.593 24 1.325 24H12.82v-9.294H9.692v-3.622h3.128V8.413c0-3.1 1.894-4.788 4.659-4.788 1.325 0 2.464.099 2.796.143v3.24l-1.918.001c-1.504 0-1.795.715-1.795 1.763v2.312h3.587l-.467 3.622h-3.12V24h6.116c.73 0 1.324-.593 1.324-1.324V1.325C24 .593 23.407 0 22.675 0z"/></svg></a>
        <a href="https://www.instagram.com/jisanhsajin/" target="_blank"><svg viewBox="0 0 24 24"><path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zm5 5a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm6.5-.9a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2z"/></svg></a>
        <a href="https://www.linkedin.com/in/jisanhsajin/" target="_blank"><svg viewBox="0 0 24 24"><path d="M20.447 20.452H17.24v-5.569c0-1.327-.026-3.037-1.85-3.037-1.852 0-2.136 1.447-2.136 2.942v5.664H9.06V9h3.033v1.561h.043c.423-.8 1.455-1.644 2.996-1.644 3.202 0 3.794 2.107 3.794 4.847v6.688zM5.337 7.433a1.755 1.755 0 1 1 0-3.509 1.755 1.755 0 0 1 0 3.509zM6.814 20.452H3.861V9h2.953v11.452z"/></svg></a>
        <a href="https://www.youtube.com/@JisanHSajin" target="_blank"><svg viewBox="0 0 24 24"><path d="M23.498 6.186a2.946 2.946 0 0 0-2.074-2.075C19.708 3.5 12 3.5 12 3.5s-7.708 0-9.424.611A2.946 2.946 0 0 0 .502 6.186C0 7.895 0 12 0 12s0 4.105.502 5.814a2.946 2.946 0 0 0 2.074 2.075C4.292 20.5 12 20.5 12 20.5s7.708 0 9.424-.611a2.946 2.946 0 0 0 2.074-2.075C24 16.105 24 12 24 12s0-4.105-.502-5.814zM9.545 15.568V8.432L15.818 12z"/></svg></a>
        <a href="https://github.com/jisanhsajin" target="_blank"><svg viewBox="0 0 24 24"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.387.6.113.82-.258.82-.577v-2.234c-3.338.724-4.042-1.61-4.042-1.61-.546-1.387-1.333-1.756-1.333-1.756-1.089-.744.084-.729.084-.729 1.205.084 1.838 1.237 1.838 1.237 1.07 1.835 2.809 1.304 3.495.997.108-.776.418-1.304.762-1.604-2.665-.3-5.467-1.332-5.467-5.93 0-1.31.468-2.38 1.236-3.22-.124-.303-.536-1.523.116-3.176 0 0 1.008-.322 3.3 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.29-1.552 3.296-1.23 3.296-1.23.653 1.653.242 2.873.118 3.176.77.84 1.236 1.91 1.236 3.22 0 4.61-2.807 5.625-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .321.216.694.825.576C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12z"/></svg></a>
    </div>
    <p>© 2026 LiveNetTV | All Rights Reserved</p>
</footer>

<script>
(function() {
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        return false;
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'F12') {
            e.preventDefault();
            return false;
        }
        if (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'J' || e.key === 'C' || e.key === 'K')) {
            e.preventDefault();
            return false;
        }
        if (e.ctrlKey && (e.key === 'u' || e.key === 'U' || e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            return false;
        }
        if (e.ctrlKey && (e.key === 'p' || e.key === 'P')) {
            e.preventDefault();
            return false;
        }
    });
    
    document.addEventListener('dragstart', function(e) {
        e.preventDefault();
        return false;
    });
    
    var devtools = function() {
        var start = performance.now();
        debugger;
        var end = performance.now();
        if (end - start > 100) {
            document.body.innerHTML = '<div style="text-align:center;padding:50px;background:#111;color:red;"><h1>Access Denied</h1><p>Developer tools are not allowed on this site.</p><p>Please close devtools and refresh the page.</p></div>';
        }
    };
    
    setInterval(devtools, 1000);
    
    if (window.console) {
        var noop = function() {};
        var methods = ['log', 'debug', 'info', 'warn', 'error', 'table', 'trace', 'dir', 'dirxml', 'group', 'groupCollapsed', 'groupEnd', 'time', 'timeEnd', 'profile', 'profileEnd'];
        for (var i = 0; i < methods.length; i++) {
            if (console[methods[i]]) console[methods[i]] = noop;
        }
    }
    
    if (window.self !== window.top) {
        window.top.location = window.self.location;
    }
    
    document.addEventListener('copy', function(e) { e.preventDefault(); return false; });
    document.addEventListener('cut', function(e) { e.preventDefault(); return false; });
    document.addEventListener('paste', function(e) { e.preventDefault(); return false; });
    
    document.body.style.userSelect = 'none';
    document.body.style.webkitUserSelect = 'none';
    document.body.style.msUserSelect = 'none';
    
    var checkDevToolsInterval = setInterval(function() {
        var widthThreshold = window.outerWidth - window.innerWidth > 200;
        var heightThreshold = window.outerHeight - window.innerHeight > 200;
        if (widthThreshold || heightThreshold) {
            clearInterval(checkDevToolsInterval);
            document.body.innerHTML = '<div style="text-align:center;padding:50px;background:#111;color:red;"><h1>Access Denied</h1><p>Developer tools detected. Please close them to continue.</p></div>';
            window.location.href = 'logout.php?msg=devtools_dimension';
        }
    }, 1500);
})();

let hls = null;

function getStreamType(url) {
    const ext = url.split('?')[0].split('#')[0].split('.').pop().toLowerCase();
    if (ext === 'm3u8' || ext === 'm3u' || url.includes('.m3u8')) return 'hls';
    return 'native';
}

function destroyPlayer() {
    const video = document.getElementById('video');
    if (hls) { hls.destroy(); hls = null; }
    video.pause();
    video.removeAttribute('src');
    video.load();
}

function showLoading(show) {
    document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
}

function updateChannelName(name) {
    document.getElementById('channelName').innerHTML = name;
}

function playStream(url, channelName) {
    const video = document.getElementById('video');
    const streamType = getStreamType(url);
    
    updateChannelName(channelName);
    showLoading(true);
    destroyPlayer();
    video.controls = true;
    
    if (streamType === 'hls' && Hls.isSupported()) {
        hls = new Hls({ debug: false, enableWorker: true, lowLatencyMode: true });
        hls.loadSource(url);
        hls.attachMedia(video);
        hls.on(Hls.Events.MANIFEST_PARSED, () => {
            showLoading(false);
            video.play().catch(e => console.log('Autoplay prevented'));
        });
        hls.on(Hls.Events.ERROR, (event, data) => {
            showLoading(false);
            if (data.fatal) {
                if (data.type === Hls.ErrorTypes.NETWORK_ERROR) hls.startLoad();
                else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) hls.recoverMediaError();
            }
        });
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = url;
        video.addEventListener('loadedmetadata', () => {
            showLoading(false);
            video.play().catch(e => console.log('Autoplay prevented'));
        });
    } else {
        video.src = url;
        video.addEventListener('loadedmetadata', () => {
            showLoading(false);
            video.play().catch(e => console.log('Autoplay prevented'));
        });
        video.addEventListener('error', () => {
            showLoading(false);
            alert('Cannot play this stream.');
        });
    }
    
    document.querySelector(".player-container").scrollIntoView({ behavior: "smooth" });
}

function searchChannels() {
    let input = document.getElementById("searchInput").value.toLowerCase();
    document.querySelectorAll(".category-block").forEach(block => {
        let catName = block.querySelector(".category-title").innerText.toLowerCase();
        let cards = block.querySelectorAll(".card");
        let hasMatch = false;
        cards.forEach(card => {
            let chName = card.getAttribute("data-name");
            if(chName.includes(input) || catName.includes(input)) {
                card.style.display = "block";
                hasMatch = true;
            } else {
                card.style.display = "none";
            }
        });
        block.style.display = hasMatch ? "block" : "none";
    });
}

window.onload = function() {
    let categories = new Set();
    document.querySelectorAll(".category-block").forEach(block => {
        categories.add(block.querySelector(".category-title").innerText);
    });
    let filterDiv = document.getElementById("categoryFilter");
    filterDiv.innerHTML = '<div class="cat-btn active" onclick="filterCategory(\'all\', event)">All</div>';
    categories.forEach(cat => {
        filterDiv.innerHTML += '<div class="cat-btn" onclick="filterCategory(\'' + cat.toLowerCase() + '\', event)">' + cat + '</div>';
    });
};

function filterCategory(category, event) {
    document.querySelectorAll(".cat-btn").forEach(btn => btn.classList.remove("active"));
    event.target.classList.add("active");
    document.querySelectorAll(".category-block").forEach(block => {
        let cat = block.querySelector(".category-title").innerText.toLowerCase();
        block.style.display = (category === "all" || cat === category) ? "block" : "none";
    });
}

document.addEventListener("keydown", e => {
    let video = document.getElementById("video");
    if(!video) return;
    if(e.key === "ArrowUp") { e.preventDefault(); video.volume = Math.min(1, video.volume + 0.1); }
    if(e.key === "ArrowDown") { e.preventDefault(); video.volume = Math.max(0, video.volume - 0.1); }
});

document.addEventListener('DOMContentLoaded', function() {
    <?php if(!$is_mobile_app): ?>
    if(localStorage.getItem('mainAdClosed') === 'true') {
        const banner = document.getElementById('mainAdBanner');
        if(banner) banner.style.display = 'none';
    }
    
    const closeBtn = document.querySelector('#mainAdBanner .close-ad');
    if(closeBtn) {
        closeBtn.addEventListener('click', function() {
            localStorage.setItem('mainAdClosed', 'true');
        });
    }
    <?php endif; ?>
});
</script>
</body>
</html>
