<?php
session_start();
define('SECURE_ACCESS', true);

if (!file_exists('config.php')) {
    die("❌ ไม่พบไฟล์ config.php กรุณาสร้างไฟล์ตั้งค่าก่อนใช้งาน");
}
require_once 'config.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// จัดการการเข้าสู่ระบบด้วย Google
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['credential'])) {
    $token = $_POST['credential'];
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . $token;
    
    // ตรวจสอบ Token กับ Google
    if (function_exists('curl_version')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
    } else {
        $response = @file_get_contents($url);
    }
    
    $payload = json_decode($response, true);
    
    if ($payload && isset($payload['email'])) {
        if ($payload['aud'] === GOOGLE_CLIENT_ID) {
            if (in_array($payload['email'], ALLOWED_EMAILS)) {
                $_SESSION['is_logged_in'] = true;
                $_SESSION['user_email'] = $payload['email'];
                header("Location: " . $_SERVER['PHP_SELF']); 
                exit;
            } else {
                $login_error = "<div class='error'>❌ อีเมล {$payload['email']} ไม่ได้รับอนุญาตให้เข้าใช้งานระบบ</div>";
            }
        } else {
            $login_error = "<div class='error'>❌ Client ID ไม่ถูกต้อง กรุณาตรวจสอบการตั้งค่า</div>";
        }
    } else {
        $login_error = "<div class='error'>❌ การยืนยันตัวตนกับ Google ล้มเหลว หรือ Token หมดอายุ</div>";
    }
}

// ==========================================
// 1. หน้า Login (Google Sign-In)
// ==========================================
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - Update System</title>
        <style>
            body { font-family: sans-serif; background-color: #f4f4f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login-box { background: white; padding: 40px 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); text-align: center; width: 100%; max-width: 350px; }
            h3 { color: #333; margin-top: 0; margin-bottom: 20px; }
            .error { color: #721c24; background-color: #f8d7da; padding: 10px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #f5c6cb; font-size: 14px; word-break: break-all; }
            .google-btn-container { display: flex; justify-content: center; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h3>🔒 เข้าสู่ระบบด้วย Google</h3>
            <?php echo $login_error; ?>
            
            <script src="https://accounts.google.com/gsi/client" async defer></script>
            <div id="g_id_onload"
                 data-client_id="<?php echo GOOGLE_CLIENT_ID; ?>"
                 data-context="signin"
                 data-ux_mode="popup"
                 data-login_uri="<?php echo 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']; ?>"
                 data-auto_prompt="false">
            </div>
            
            <div class="google-btn-container">
                <div class="g_id_signin"
                     data-type="standard"
                     data-shape="rectangular"
                     data-theme="outline"
                     data-text="signin_with"
                     data-size="large"
                     data-logo_alignment="left">
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================
// 2. ฟังก์ชันช่วยเหลือและเตรียมไฟล์
// ==========================================
$dev_file = DEV_FILE;
$prod_file = PROD_FILE;
$backup_dir_dev = BACKUP_DIR_DEV; 
$backup_dir_prod = BACKUP_DIR_PROD; 
$message = '';

// ตรวจสอบและสร้างโฟลเดอร์ Backup
foreach ([$backup_dir_dev, $backup_dir_prod] as $dir) {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            $message .= "<div class='error'>❌ ไม่สามารถสร้างโฟลเดอร์ `$dir` ได้ (ตรวจสอบ Permission ของ Server)</div>";
        } else {
            file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\nOptions -Indexes\n");
        }
    } elseif (!file_exists($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\nOptions -Indexes\n");
    }
}

if (!file_exists($dev_file) && file_exists($prod_file)) {
    copy($prod_file, $dev_file);
}

function getBackups($dir) {
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*.txt');
    if ($files === false) return [];
    
    $clean_backups = array_filter($files, function($f) { 
        return strpos($f, '_failed.txt') === false; 
    });
    
    if (!empty($clean_backups)) {
        rsort($clean_backups); 
        return $clean_backups;
    }
    return [];
}

// ==========================================
// 3. จัดการ Action ต่างๆ (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("<div class='error'>❌ เกิดข้อผิดพลาดด้านความปลอดภัย (Invalid CSRF Token) กรุณารีเฟรชหน้าเว็บแล้วลองใหม่</div>");
    }

    clearstatcache();
    $action = $_POST['action'];
    date_default_timezone_set('Asia/Bangkok');
    $timestamp = date('Ymd_His');

    if ($action === 'save_dev') {
        $new_code = $_POST['new_code'];
        
        // เช็คและสร้างโฟลเดอร์สำหรับไฟล์ Dev อัตโนมัติ (เผื่อระบุ path ที่ยังไม่มี)
        $dev_dir = dirname($dev_file);
        if (!is_dir($dev_dir) && $dev_dir !== '.' && $dev_dir !== '') {
            @mkdir($dev_dir, 0755, true);
        }

        $backup_success = true;
        if (file_exists($dev_file)) {
            $backup_success = @copy($dev_file, $backup_dir_dev . '/' . $timestamp . '.txt');
        }
        
        // บันทึกไฟล์และตรวจสอบผลลัพธ์
        if (file_put_contents($dev_file, $new_code) !== false) {
            if ($backup_success) {
                $message = "<div class='success'>✅ บันทึกโค้ดลง <b>Dev</b> สำเร็จ!</div>";
            } else {
                $message = "<div class='warning'>⚠️ บันทึก Dev สำเร็จ แต่ <b>ไม่สามารถสร้างไฟล์แบคอัพได้</b> (ตรวจสอบสิทธิ์ Permission โฟลเดอร์ Backup)</div>";
            }
        } else {
            $message = "<div class='error'>❌ ไม่สามารถสร้าง/บันทึกไฟล์ Dev ได้ (ตรวจสอบ Permission)</div>";
        }
    }
    
    elseif ($action === 'restore_dev' && !empty($_POST['backup_file'])) {
        $selected_file = basename($_POST['backup_file']); 
        $backup_path = $backup_dir_dev . '/' . $selected_file;
        
        if (file_exists($backup_path) && pathinfo($backup_path, PATHINFO_EXTENSION) === 'txt') {
            if (file_exists($dev_file)) {
                copy($dev_file, $backup_dir_dev . '/' . $timestamp . '_failed.txt');
            }
            copy($backup_path, $dev_file);
            $message = "<div class='success'>⏪ กู้คืน <b>Dev</b> เป็นเวอร์ชั่น: " . $selected_file . " สำเร็จ!</div>";
        } else {
            $message = "<div class='error'>❌ ไม่พบไฟล์สำรองที่ระบุ หรือรูปแบบไฟล์ไม่ถูกต้อง</div>";
        }
    }
    
    elseif ($action === 'deploy_prod') {
        if (file_exists($dev_file)) {
            // เช็คและสร้างโฟลเดอร์สำหรับไฟล์ Prod อัตโนมัติ
            $prod_dir = dirname($prod_file);
            if (!is_dir($prod_dir) && $prod_dir !== '.' && $prod_dir !== '') {
                @mkdir($prod_dir, 0755, true);
            }

            $backup_success = true;
            if (file_exists($prod_file)) {
                $backup_success = @copy($prod_file, $backup_dir_prod . '/' . $timestamp . '.txt');
            }
            
            if (copy($dev_file, $prod_file)) {
                if ($backup_success) {
                    $message = "<div class='success' style='background-color:#d4edda; border-color:#c3e6cb; color:#155724;'>
                                🚀 <b>Deploy สำเร็จ!</b> คัดลอกโค้ดจาก Dev ขึ้นหน้าเว็บจริงเรียบร้อยแล้ว
                                </div>";
                } else {
                    $message = "<div class='warning'>⚠️ <b>Deploy สำเร็จ!</b> แต่ไม่สามารถสร้างไฟล์แบคอัพเว็บจริงได้ (ตรวจสอบสิทธิ์ Permission โฟลเดอร์ Backup)</div>";
                }
            } else {
                $message = "<div class='error'>❌ ไม่สามารถเขียนไฟล์เว็บจริงได้ (ตรวจสอบ Permission ของ $prod_file)</div>";
            }
        } else {
            $message = "<div class='warning'>⚠️ ไม่พบไฟล์ Dev กรุณาบันทึก Dev ก่อนทำการ Deploy</div>";
        }
    }

    elseif ($action === 'restore_prod' && !empty($_POST['backup_file'])) {
        $selected_file = basename($_POST['backup_file']); 
        $backup_path = $backup_dir_prod . '/' . $selected_file;
        
        if (file_exists($backup_path) && pathinfo($backup_path, PATHINFO_EXTENSION) === 'txt') {
            if (file_exists($prod_file)) {
                copy($prod_file, $backup_dir_prod . '/' . $timestamp . '_failed.txt');
            }
            copy($backup_path, $prod_file);
            $message = "<div class='success'>⏪ กู้คืน <b>เว็บจริง (Production)</b> กลับเป็นเวอร์ชั่น: " . $selected_file . " สำเร็จ!</div>";
        } else {
            $message = "<div class='error'>❌ ไม่พบไฟล์สำรองที่ระบุ หรือรูปแบบไฟล์ไม่ถูกต้อง</div>";
        }
    }
}

$current_dev_code = file_exists($dev_file) ? htmlspecialchars(file_get_contents($dev_file)) : '';
$dev_backups = getBackups($backup_dir_dev);
$prod_backups = getBackups($backup_dir_prod);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัพเดทเว็บลงเวลาปฎิบัติราชการ (Dev -> Production)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/theme/monokai.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/display/fullscreen.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/dialog/dialog.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/search/matchesonscrollbar.min.css">
    
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f4f4f9; color: #333; }
        .container { max-width: 1000px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        h2 { margin: 0; }
        
        .user-profile { display: flex; align-items: center; gap: 15px; }
        .user-email { font-size: 14px; color: #555; background: #e2e8f0; padding: 6px 12px; border-radius: 20px; }
        
        .panel { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 25px; border-left: 5px solid; }
        .panel.dev { border-left-color: #007bff; }
        .panel.prod { border-left-color: #28a745; background-color: #fcfdfc; }
        
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px; flex-wrap: wrap; gap: 10px; }
        .panel-header h3 { margin: 0; font-size: 18px; }
        
        .btn-link { background: #e9ecef; color: #333; padding: 6px 12px; text-decoration: none; border-radius: 4px; font-size: 14px; display: inline-flex; align-items: center; border: none; cursor: pointer; font-family: inherit; }
        .btn-link:hover { background: #dde2e6; }
        
        .CodeMirror {
            height: 450px;
            font-family: 'Courier New', monospace;
            font-size: 15px;
            border: 1px solid #ccc;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        .CodeMirror-fullscreen {
            border-radius: 0;
            border: none;
            z-index: 9999;
        }
        
        button { border: none; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: bold; transition: 0.2s; }
        .btn-save { padding: 10px 20px; background-color: #007bff; color: white; height: 100%; }
        .btn-save:hover { background-color: #0056b3; }
        
        .btn-restore { padding: 8px 15px; background-color: #ff9800; color: white; font-size: 14px; }
        .btn-restore:hover { background-color: #e68a00; }
        
        .btn-deploy { padding: 10px 20px; background-color: #28a745; color: white; font-size: 16px; box-shadow: 0 4px 6px rgba(40,167,69,0.3); height: 100%; }
        .btn-deploy:hover { background-color: #218838; transform: translateY(-1px); }
        
        .logout-btn { background-color: #dc3545; color: white; padding: 8px 15px; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: bold; }
        .logout-btn:hover { background-color: #c82333; }
        
        .restore-box { background: #f8f9fa; border: 1px solid #e9ecef; padding: 10px 15px; border-radius: 6px; display: inline-flex; align-items: center; gap: 10px; }
        select { padding: 8px; border-radius: 4px; border: 1px solid #ccc; font-size: 14px; outline: none; }
        
        .action-bar { display: flex; justify-content: space-between; align-items: stretch; flex-wrap: wrap; gap: 15px; margin-top: 15px; }
        
        .msg { padding: 15px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border: 1px solid; }
        .success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
        .error { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
        .warning { color: #856404; background-color: #fff3cd; border-color: #ffeeba; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h2>ระบบจัดการและอัพเดทเว็บไซต์ลงเวลาปฎิบัติราชการ</h2>
        <div class="user-profile">
            <?php if (isset($_SESSION['user_email'])): ?>
                <span class="user-email">👤 <?php echo htmlspecialchars($_SESSION['user_email']); ?></span>
            <?php endif; ?>
            <a href="?action=logout" class="logout-btn">ออกจากระบบ</a>
        </div>
    </div>
    
    <?php if ($message) echo "<div class='msg'> $message </div>"; ?>

    <div class="panel dev">
        <div class="panel-header">
            <h3>🛠️ โหมด Development (<?php echo $dev_file; ?>)</h3>
            <div style="display: flex; gap: 10px;">
                <button type="button" id="btnFullscreen" class="btn-link" style="background: #fff; border: 1px solid #ccc;">🔲 ขยายเต็มจอ (F11)</button>
                <a href="<?php echo $dev_file; ?>" target="_blank" class="btn-link">🔍 เปิดดูหน้า Dev</a>
            </div>
        </div>
        
        <form id="devForm" method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <textarea id="codeEditor" name="new_code"><?php echo $current_dev_code; ?></textarea>
        </form>

        <div class="action-bar">
            <button type="submit" form="devForm" name="action" value="save_dev" class="btn-save" onclick="editor.save();">💾 บันทึก Dev</button>

            <?php if (!empty($dev_backups)): ?>
            <form method="post" action="" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="restore-box">
                    <label style="font-size: 14px; font-weight: bold; color: #555;">กู้คืน Dev จาก:</label>
                    <select name="backup_file" required>
                        <option value="" disabled selected>-- เลือกไฟล์แบคอัพ --</option>
                        <?php foreach ($dev_backups as $bk): ?>
                            <option value="<?php echo basename($bk); ?>"><?php echo basename($bk); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="action" value="restore_dev" class="btn-restore" onclick="return confirm('⚠️ ยืนยันกู้คืน Dev จากไฟล์ที่เลือก?\n(โค้ดปัจจุบันจะถูกบันทึกเป็นไฟล์ _failed.txt ก่อนทับ)');">
                        ⏪ กู้คืน Dev
                    </button>
                </div>
            </form>
            <?php else: ?>
                <div class="restore-box" style="border: 1px dashed #ccc; background: transparent;">
                    <span style="font-size: 13px; color: #999;">🗂️ ยังไม่มีไฟล์แบคอัพ (ระบบจะสร้างให้อัตโนมัติเมื่อกดบันทึก)</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel prod">
        <div class="panel-header">
            <h3>🌍 โหมด Production เว็บจริง (<?php echo $prod_file; ?>)</h3>
            <a href="<?php echo $prod_file; ?>" target="_blank" class="btn-link">🌐 เปิดดูหน้าเว็บจริง</a>
        </div>
        
        <p style="color: #555; font-size: 14px; margin-top: 0;">หากทดสอบหน้า Dev แล้วใช้งานได้ปกติ ให้กดปุ่มด้านล่างเพื่อส่งขึ้นหน้าเว็บจริง (ไฟล์เว็บจริงจะถูกแบคอัพก่อนทับเสมอ)</p>
        
        <div class="action-bar" style="border-top: 1px dashed #ccc; padding-top: 15px;">
            <form method="post" action="" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="submit" name="action" value="deploy_prod" class="btn-deploy" onclick="return confirm('🚀 ยืนยันการ Deploy?\n\nระบบจะก๊อปปี้ไฟล์ Dev (<?php echo $dev_file; ?>)\nไปทับไฟล์ Production (<?php echo $prod_file; ?>) ทันที');">
                    🚀 ส่งอัพเดทขึ้นเว็บจริง (Deploy)
                </button>
            </form>

            <?php if (!empty($prod_backups)): ?>
            <form method="post" action="" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="restore-box" style="background: #fff3cd; border-color: #ffeeba;">
                    <label style="font-size: 14px; font-weight: bold; color: #856404;">🚨 ฉุกเฉิน - กู้คืนเว็บจริงจาก:</label>
                    <select name="backup_file" required>
                        <option value="" disabled selected>-- เลือกไฟล์แบคอัพ --</option>
                        <?php foreach ($prod_backups as $bk): ?>
                            <option value="<?php echo basename($bk); ?>"><?php echo basename($bk); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="action" value="restore_prod" class="btn-restore" style="background-color: #dc3545;" onclick="return confirm('🚨 คำเตือน: ยืนยันกู้คืน Production?\n\nระบบจะนำไฟล์แบคอัพที่เลือกมาทับหน้าเว็บจริงทันที');">
                        ⏪ กู้คืนเว็บจริง
                    </button>
                </div>
            </form>
            <?php else: ?>
                <div style="display: flex; align-items: center;">
                    <span style="font-size: 13px; color: #999;">ยังไม่มีไฟล์แบคอัพสำหรับเว็บจริง</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/clike/clike.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/display/fullscreen.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/dialog/dialog.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/search/searchcursor.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/search/search.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/search/jump-to-line.min.js"></script>

<script>
    var editor = CodeMirror.fromTextArea(document.getElementById("codeEditor"), {
        lineNumbers: true,               
        mode: "application/x-httpd-php", 
        theme: "monokai",                
        matchBrackets: true,             
        indentUnit: 4,                   
        indentWithTabs: true,
        extraKeys: {
            "F11": function(cm) {
                cm.setOption("fullScreen", !cm.getOption("fullScreen"));
            },
            "Esc": function(cm) {
                if (cm.getOption("fullScreen")) cm.setOption("fullScreen", false);
            },
            "Ctrl-F": "findPersistent",
            "Cmd-F": "findPersistent"
        }
    });

    document.getElementById("btnFullscreen").addEventListener("click", function() {
        var isFullScreen = editor.getOption("fullScreen");
        editor.setOption("fullScreen", !isFullScreen);
    });

    document.getElementById("devForm").addEventListener("submit", function() {
        editor.save();
    });
</script>

</body>
</html>