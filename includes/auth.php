<?php
// includes/auth.php - Session management, authentication, CSRF, and helper utilities

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// Base URL helper
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? null) == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = dirname($scriptName);
    
    // Normalize path separators for Windows
    $dir = str_replace('\\', '/', $dir);
    // Remove /admin or /ajax if nested
    $dir = preg_replace('#/(admin|ajax)$#', '', $dir);
    $dir = rtrim($dir, '/');
    
    return $protocol . $host . $dir;
}

// Project URL helper (uses random access_key instead of predictable numeric ID)
function getProjectUrl($project) {
    if (is_array($project)) {
        $key = !empty($project['access_key']) ? $project['access_key'] : ($project['id'] ?? 0);
    } else {
        $key = $project;
    }
    return getBaseUrl() . '/project?id=' . urlencode($key);
}


// CSRF Helpers
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Admin Auth Helpers
function isAdminLoggedIn() {
    return !empty($_SESSION['admin_id']);
}

function refreshAdminSession() {
    if (empty($_SESSION['admin_id'])) return;
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT id, name, email, role, is_active FROM admins WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => (int)$_SESSION['admin_id']]);
        $currentAdmin = $stmt->fetch();
        if ($currentAdmin && (int)($currentAdmin['is_active'] ?? 1) === 1) {
            $_SESSION['admin_name'] = $currentAdmin['name'];
            $_SESSION['admin_email'] = $currentAdmin['email'];
            $_SESSION['admin_role'] = $currentAdmin['role'] ?? 'editor';
        } else {
            unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_email'], $_SESSION['admin_role']);
            header("Location: " . getBaseUrl() . "/admin/login.php?error=" . urlencode("Your account has been deactivated. Please contact an administrator."));
            exit;
        }
    } catch (Exception $e) {
        // Fallback to existing session variables if DB query fails
    }
}

function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        header("Location: " . getBaseUrl() . "/admin/login.php");
        exit;
    }
    refreshAdminSession();
}

function isMasterAdmin() {
    return isAdminLoggedIn() && isset($_SESSION['admin_role']) && in_array($_SESSION['admin_role'], ['master', 'admin']);
}

function isEditorAdmin() {
    return isAdminLoggedIn() && isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'editor';
}

function isSalesAdmin() {
    return isAdminLoggedIn() && isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'sales';
}

function getAdminRoleLabel($role = null) {
    $r = strtolower($role ?? ($_SESSION['admin_role'] ?? ''));
    if (in_array($r, ['master', 'admin'])) return 'Admin (Manage All)';
    if ($r === 'editor') return 'Editor (Manage Video)';
    if ($r === 'sales') return 'Sales (Share Only)';
    return ucfirst($r);
}

function requireMasterAdmin() {
    if (!isMasterAdmin()) {
        header("Location: " . getBaseUrl() . "/admin/index");
        exit;
    }
}

function getEditorProjectIds($adminId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT project_id FROM admin_projects WHERE admin_id = :aid");
    $stmt->execute(['aid' => $adminId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Can admin edit/delete projects or upload/delete videos? (Admin & Editor only, Sales cannot)
function canAdminManageProject($projectId) {
    if (!isAdminLoggedIn()) return false;
    if (isSalesAdmin()) return false; // Sales can ONLY share, not edit/delete or upload
    if (isMasterAdmin()) return true;
    
    $assigned = getEditorProjectIds($_SESSION['admin_id']);
    return in_array($projectId, $assigned);
}

// Can admin share access to a project or video? (Admin, Editor, AND Sales can share!)
function canAdminShareResource($projectId = 0) {
    if (!isAdminLoggedIn()) return false;
    if (isMasterAdmin() || isSalesAdmin()) return true;
    
    // For editors, check assigned projects if a specific project is passed
    if ($projectId > 0) {
        $assigned = getEditorProjectIds($_SESSION['admin_id']);
        return in_array($projectId, $assigned);
    }
    return true;
}

// Viewer Auth Helpers
function getViewerEmailForVideo($videoId) {
    if (isset($_SESSION['viewer_access'][$videoId])) {
        return $_SESSION['viewer_access'][$videoId];
    }
    if (isset($_COOKIE['pv_viewer_email']) && filter_var($_COOKIE['pv_viewer_email'], FILTER_VALIDATE_EMAIL)) {
        return strtolower(trim($_COOKIE['pv_viewer_email']));
    }
    return $_SESSION['global_viewer_email'] ?? null;
}

function setViewerEmailForVideo($videoId, $email) {
    if (!isset($_SESSION['viewer_access'])) {
        $_SESSION['viewer_access'] = [];
    }
    $cleanEmail = strtolower(trim($email));
    $_SESSION['viewer_access'][$videoId] = $cleanEmail;
    $_SESSION['global_viewer_email'] = $cleanEmail;
    if (!headers_sent()) {
        @setcookie('pv_viewer_email', $cleanEmail, time() + (86400 * 30), "/");
    }
}

function getViewerEmailForProject($projectId) {
    if (isset($_SESSION['project_viewer_access'][$projectId])) {
        return $_SESSION['project_viewer_access'][$projectId];
    }
    if (isset($_COOKIE['pv_viewer_email']) && filter_var($_COOKIE['pv_viewer_email'], FILTER_VALIDATE_EMAIL)) {
        return strtolower(trim($_COOKIE['pv_viewer_email']));
    }
    return $_SESSION['global_viewer_email'] ?? null;
}

function setViewerEmailForProject($projectId, $email) {
    if (!isset($_SESSION['project_viewer_access'])) {
        $_SESSION['project_viewer_access'] = [];
    }
    $cleanEmail = strtolower(trim($email));
    $_SESSION['project_viewer_access'][$projectId] = $cleanEmail;
    $_SESSION['global_viewer_email'] = $cleanEmail;
    if (!headers_sent()) {
        @setcookie('pv_viewer_email', $cleanEmail, time() + (86400 * 30), "/");
    }
}

function isEmailVerifiedGlobal($email) {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $db = getDBConnection();
    $cleanEmail = strtolower(trim($email));

    // Check if email has verified an OTP
    $stmt = $db->prepare("SELECT id FROM otp_verifications WHERE LOWER(email) = LOWER(:email) AND verified = 1 LIMIT 1");
    $stmt->execute(['email' => $cleanEmail]);
    if ($stmt->fetch() !== false) {
        return true;
    }

    // Check if email has direct video access
    $stmtV = $db->prepare("SELECT id FROM video_access WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $stmtV->execute(['email' => $cleanEmail]);
    if ($stmtV->fetch() !== false) {
        return true;
    }

    // Check if email has direct project access
    $stmtP = $db->prepare("SELECT id FROM project_access WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $stmtP->execute(['email' => $cleanEmail]);
    return $stmtP->fetch() !== false;
}

function hasProjectAccess($projectId, $email) {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $db = getDBConnection();
    
    $stmt = $db->prepare("SELECT id FROM project_access WHERE project_id = :project_id AND LOWER(email) = LOWER(:email) LIMIT 1");
    $stmt->execute(['project_id' => $projectId, 'email' => trim($email)]);
    return $stmt->fetch() !== false;
}

function hasVideoAccess($videoId, $email) {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $db = getDBConnection();

    // Check parent project permission (explicit project_access)
    $stmtP = $db->prepare("
        SELECT pa.id as pa_id
        FROM projects p 
        JOIN videos v ON p.id = v.project_id 
        LEFT JOIN project_access pa ON pa.project_id = p.id AND LOWER(pa.email) = LOWER(:email)
        WHERE v.id = :video_id 
        LIMIT 1
    ");
    $stmtP->execute(['video_id' => $videoId, 'email' => trim($email)]);
    $pRow = $stmtP->fetch();
    if ($pRow && !empty($pRow['pa_id'])) {
        return true;
    }

    // Direct video permission
    $stmt = $db->prepare("SELECT id FROM video_access WHERE video_id = :video_id AND LOWER(email) = LOWER(:email)");
    $stmt->execute(['video_id' => $videoId, 'email' => trim($email)]);
    return $stmt->fetch() !== false;
}

// Data Sanitization Helper
function sanitize($data) {
    return trim((string)$data);
}

// Time Formatting Helper (seconds -> MM:SS or HH:MM:SS)
function formatSeconds($seconds) {
    $seconds = max(0, (int)$seconds);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
    }
    return sprintf("%02d:%02d", $minutes, $secs);
}

// Access Request Helpers
function getAccessRequestStatus($projectId, $videoId, $email) {
    if (empty($email)) return false;
    $db = getDBConnection();
    $cleanEmail = strtolower(trim($email));
    if ($videoId > 0) {
        $stmt = $db->prepare("SELECT * FROM access_requests WHERE (video_id = :vid OR project_id = :pid) AND LOWER(email) = LOWER(:email) ORDER BY requested_at DESC LIMIT 1");
        $stmt->execute(['vid' => $videoId, 'pid' => $projectId, 'email' => $cleanEmail]);
    } else {
        $stmt = $db->prepare("SELECT * FROM access_requests WHERE project_id = :pid AND LOWER(email) = LOWER(:email) ORDER BY requested_at DESC LIMIT 1");
        $stmt->execute(['pid' => $projectId, 'email' => $cleanEmail]);
    }
    return $stmt->fetch();
}

function submitAccessRequest($projectId, $videoId, $email) {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $db = getDBConnection();
    $cleanEmail = strtolower(trim($email));
    
    $vIdVal = $videoId > 0 ? $videoId : null;
    
    $stmt = $db->prepare("SELECT id, status FROM access_requests WHERE project_id = :pid AND (video_id = :vid OR (video_id IS NULL AND :vid2 IS NULL)) AND LOWER(email) = LOWER(:email) LIMIT 1");
    $stmt->execute(['pid' => $projectId, 'vid' => $vIdVal, 'vid2' => $vIdVal, 'email' => $cleanEmail]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        $stmtUpdate = $db->prepare("UPDATE access_requests SET status = 'pending', requested_at = NOW() WHERE id = :id");
        $stmtUpdate->execute(['id' => $existing['id']]);
    } else {
        $stmtIns = $db->prepare("INSERT INTO access_requests (project_id, video_id, email, status, requested_at) VALUES (:pid, :vid, :email, 'pending', NOW())");
        $stmtIns->execute([
            'pid' => $projectId,
            'vid' => $vIdVal,
            'email' => $cleanEmail
        ]);
    }
    return true;
}

function approveAccessRequest($requestId, $adminId) {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT * FROM access_requests WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $requestId]);
    $req = $stmt->fetch();
    if (!$req) return false;

    if (!empty($req['video_id'])) {
        $stmtGrant = $db->prepare("INSERT INTO video_access (video_id, email, granted_by_admin_id, granted_at) VALUES (:vid, :email, :aid, NOW()) ON DUPLICATE KEY UPDATE granted_by_admin_id = VALUES(granted_by_admin_id)");
        $stmtGrant->execute(['vid' => $req['video_id'], 'email' => $req['email'], 'aid' => $adminId]);
    } else {
        $stmtGrant = $db->prepare("INSERT INTO project_access (project_id, email, granted_by_admin_id, granted_at) VALUES (:pid, :email, :aid, NOW()) ON DUPLICATE KEY UPDATE granted_by_admin_id = VALUES(granted_by_admin_id)");
        $stmtGrant->execute(['pid' => $req['project_id'], 'email' => $req['email'], 'aid' => $adminId]);
    }

    $stmtUpd = $db->prepare("UPDATE access_requests SET status = 'approved', processed_at = NOW(), processed_by_admin_id = :aid WHERE id = :id");
    $stmtUpd->execute(['aid' => $adminId, 'id' => $requestId]);

    return $req;
}

function autoGrantVideoAccess($videoId, $email) {
    $db = getDBConnection();
    $cleanEmail = strtolower(trim($email));
    $stmtGrant = $db->prepare("INSERT INTO video_access (video_id, email, granted_at) VALUES (:vid, :email, NOW()) ON DUPLICATE KEY UPDATE granted_at = NOW()");
    $stmtGrant->execute(['vid' => $videoId, 'email' => $cleanEmail]);
}

function autoGrantProjectAccess($projectId, $email) {
    $db = getDBConnection();
    $cleanEmail = strtolower(trim($email));
    $stmtGrant = $db->prepare("INSERT INTO project_access (project_id, email, granted_at) VALUES (:pid, :email, NOW()) ON DUPLICATE KEY UPDATE granted_at = NOW()");
    $stmtGrant->execute(['pid' => $projectId, 'email' => $cleanEmail]);
}

function rejectAccessRequest($requestId, $adminId) {
    $db = getDBConnection();
    $stmtUpd = $db->prepare("UPDATE access_requests SET status = 'rejected', processed_at = NOW(), processed_by_admin_id = :aid WHERE id = :id");
    $stmtUpd->execute(['aid' => $adminId, 'id' => $requestId]);
    return true;
}

if (!function_exists('getRelativeTimeStr')) {
    function getRelativeTimeStr($datetimeStr) {
        if (empty($datetimeStr) || $datetimeStr === '0000-00-00 00:00:00') return 'Unknown';
        
        $time = strtotime($datetimeStr);
        if (!$time) return 'Unknown';

        $now = time();
        $diff = $now - $time;

        if ($diff < 0) {
            $absDiff = abs($diff);
            if ($absDiff < 60) return 'Just now';
            if ($absDiff < 3600) return (int)floor($absDiff / 60) . 'm ago';
            if ($absDiff < 86400) return (int)floor($absDiff / 3600) . 'h ago';
            return date('M j, Y', $time);
        }

        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return max(1, (int)floor($diff / 60)) . 'm ago';
        if ($diff < 86400) return (int)floor($diff / 3600) . 'h ago';
        if ($diff < 2592000) return (int)floor($diff / 86400) . 'd ago';
        return date('M j, Y', $time);
    }
}

// System Settings Functions
function getSystemSetting($key, $default = '') {
    $db = getDBConnection();
    try {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = :k LIMIT 1");
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function setSystemSetting($key, $value) {
    $db = getDBConnection();
    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2");
    $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
}

function getSMTPSettings() {
    return [
        'smtp_host'       => getSystemSetting('smtp_host', ''),
        'smtp_port'       => getSystemSetting('smtp_port', '587'),
        'smtp_encryption' => getSystemSetting('smtp_encryption', 'tls'),
        'smtp_user'       => getSystemSetting('smtp_user', ''),
        'smtp_pass'       => getSystemSetting('smtp_pass', ''),
        'smtp_from_email' => getSystemSetting('smtp_from_email', ''),
        'smtp_from_name'  => getSystemSetting('smtp_from_name', 'PitchVault'),
    ];
}

function saveSMTPSettings($settings) {
    foreach ($settings as $key => $val) {
        setSystemSetting($key, trim($val));
    }
}

// System Email Sender with Socket SMTP & mail() Fallback
function sendSystemEmail($toEmail, $subject, $htmlContent, $plainText = '') {
    $settings = getSMTPSettings();
    $host = trim($settings['smtp_host']);
    $port = (int)($settings['smtp_port'] ?: 587);
    $encryption = strtolower(trim($settings['smtp_encryption'] ?: 'tls'));
    $username = trim($settings['smtp_user']);
    $password = $settings['smtp_pass'];
    $fromEmail = trim($settings['smtp_from_email']) ?: ($username ?: 'noreply@pitchvault.com');
    $fromName = trim($settings['smtp_from_name']) ?: 'PitchVault';

    if (empty($plainText)) {
        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlContent));
    }

    if (empty($host)) {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . '=?UTF-8?B?' . base64_encode($fromName) . '?=' . " <{$fromEmail}>\r\n";
        $success = @mail($toEmail, $subject, $htmlContent, $headers);
        if ($success) {
            return ['success' => true, 'mode' => 'mail'];
        } else {
            return ['success' => false, 'error' => 'Native PHP mail() function returned false and no SMTP host is configured in Account Settings.'];
        }
    }

    try {
        $connectionHost = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
        $socket = @fsockopen($connectionHost, $port, $errno, $errstr, 12);
        if (!$socket) {
            return ['success' => false, 'error' => "Failed to connect to SMTP server {$host}:{$port} ($errstr)"];
        }

        $getResponse = function($sock) {
            $data = '';
            while ($str = fgets($sock, 515)) {
                $data .= $str;
                if (substr($str, 3, 1) === ' ') break;
            }
            return $data;
        };

        $sendCommand = function($sock, $cmd) use ($getResponse) {
            fputs($sock, $cmd . "\r\n");
            return $getResponse($sock);
        };

        $greeting = $getResponse($socket);

        $ehloResp = $sendCommand($socket, "EHLO " . gethostname());

        if ($encryption === 'tls') {
            $tlsResp = $sendCommand($socket, "STARTTLS");
            if (substr($tlsResp, 0, 3) !== '220') {
                fclose($socket);
                return ['success' => false, 'error' => "STARTTLS failed: {$tlsResp}"];
            }
            $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$crypto) {
                fclose($socket);
                return ['success' => false, 'error' => 'Failed to establish TLS encryption with SMTP server'];
            }
            $sendCommand($socket, "EHLO " . gethostname());
        }

        if (!empty($username) && !empty($password)) {
            $authResp = $sendCommand($socket, "AUTH LOGIN");
            if (substr($authResp, 0, 3) !== '334') {
                fclose($socket);
                return ['success' => false, 'error' => "AUTH LOGIN command failed: {$authResp}"];
            }

            $userResp = $sendCommand($socket, base64_encode($username));
            if (substr($userResp, 0, 3) !== '334') {
                fclose($socket);
                return ['success' => false, 'error' => "Username authentication failed: {$userResp}"];
            }

            $passResp = $sendCommand($socket, base64_encode($password));
            if (substr($passResp, 0, 3) !== '235') {
                fclose($socket);
                return ['success' => false, 'error' => "Password authentication failed: {$passResp}"];
            }
        }

        $mailFromResp = $sendCommand($socket, "MAIL FROM:<{$fromEmail}>");
        if (substr($mailFromResp, 0, 3) !== '250') {
            fclose($socket);
            return ['success' => false, 'error' => "MAIL FROM failed: {$mailFromResp}"];
        }

        $rcptToResp = $sendCommand($socket, "RCPT TO:<{$toEmail}>");
        if (substr($rcptToResp, 0, 3) !== '250' && substr($rcptToResp, 0, 3) !== '251') {
            fclose($socket);
            return ['success' => false, 'error' => "Recipient rejected: {$rcptToResp}"];
        }

        $dataResp = $sendCommand($socket, "DATA");
        if (substr($dataResp, 0, 3) !== '354') {
            fclose($socket);
            return ['success' => false, 'error' => "DATA command failed: {$dataResp}"];
        }

        $boundary = "----=_NextPart_" . md5(uniqid((string)time(), true));
        $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n";
        $headers .= "To: <{$toEmail}>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

        $body = $headers . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $plainText . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlContent . "\r\n\r\n";
        $body .= "--{$boundary}--\r\n.";

        $sendBodyResp = $sendCommand($socket, $body);
        $sendCommand($socket, "QUIT");
        fclose($socket);

        if (substr($sendBodyResp, 0, 3) === '250') {
            return ['success' => true, 'mode' => 'smtp'];
        } else {
            return ['success' => false, 'error' => "SMTP Delivery Error: {$sendBodyResp}"];
        }

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// 6-Digit OTP Generator & Verifier for Video Access
function generateVideoOTP($videoId, $email, $videoTitle = 'Presentation') {
    $db = getDBConnection();
    $email = strtolower(trim($email));
    
    $otpCode = sprintf('%06d', mt_rand(0, 999999));
    $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 mins

    $del = $db->prepare("DELETE FROM otp_verifications WHERE video_id = :vid AND LOWER(email) = LOWER(:email) AND verified = 0");
    $del->execute(['vid' => $videoId, 'email' => $email]);

    $ins = $db->prepare("INSERT INTO otp_verifications (video_id, email, otp_code, expires_at) VALUES (:vid, :email, :code, :exp)");
    $ins->execute([
        'vid' => $videoId,
        'email' => $email,
        'code' => $otpCode,
        'exp' => $expiresAt
    ]);

    $subject = "Your 6-Digit PitchVault Access Code - " . $otpCode;
    $htmlContent = '
    <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 520px; margin: 0 auto; padding: 24px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px;">
        <div style="text-align: center; padding-bottom: 20px; border-bottom: 1px solid #f1f5f9;">
            <div style="display: inline-block; width: 48px; height: 48px; background: linear-gradient(135deg, #4f46e5, #6366f1); border-radius: 12px; line-height: 48px; color: #ffffff; font-weight: bold; font-size: 20px; margin-bottom: 10px;">PV</div>
            <h2 style="margin: 0; color: #0f172a; font-size: 20px; font-weight: 700;">PitchVault Verification Code</h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">Security verification for private presentation</p>
        </div>
        <div style="padding: 24px 0; text-align: center;">
            <p style="margin: 0 0 16px; color: #334155; font-size: 14px;">Use the 6-digit code below to verify your access to <strong>' . htmlspecialchars($videoTitle) . '</strong>:</p>
            <div style="display: inline-block; letter-spacing: 8px; font-size: 32px; font-weight: 800; color: #4f46e5; background-color: #f8fafc; border: 2px dashed #cbd5e1; padding: 14px 28px; border-radius: 12px; font-family: monospace;">
                ' . $otpCode . '
            </div>
            <p style="margin: 16px 0 0; color: #94a3b8; font-size: 12px;">This code will expire in <strong>15 minutes</strong>. Do not share this code with anyone.</p>
        </div>
        <div style="padding-top: 16px; border-top: 1px solid #f1f5f9; text-align: center; color: #94a3b8; font-size: 11px;">
            If you did not request access to this presentation, please ignore this email.
        </div>
    </div>';

    $sendResult = sendSystemEmail($email, $subject, $htmlContent);
    return array_merge(['otp_code' => $otpCode], $sendResult);
}

function verifyVideoOTP($videoId, $email, $inputOtp) {
    $db = getDBConnection();
    $email = strtolower(trim($email));
    $inputOtp = trim($inputOtp);

    if (empty($inputOtp) || strlen($inputOtp) !== 6) return false;

    $stmt = $db->prepare("SELECT id FROM otp_verifications WHERE video_id = :vid AND LOWER(email) = LOWER(:email) AND otp_code = :code AND verified = 0 AND expires_at >= NOW() LIMIT 1");
    $stmt->execute([
        'vid' => $videoId,
        'email' => $email,
        'code' => $inputOtp
    ]);
    $row = $stmt->fetch();

    if ($row) {
        $upd = $db->prepare("UPDATE otp_verifications SET verified = 1 WHERE id = :id");
        $upd->execute(['id' => $row['id']]);
        return true;
    }

    return false;
}

// 6-Digit OTP Generator & Verifier for Project Access
function generateProjectOTP($projectId, $email, $projectTitle = 'Project Presentation') {
    $db = getDBConnection();
    $email = strtolower(trim($email));
    
    $otpCode = sprintf('%06d', mt_rand(0, 999999));
    $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 mins

    $del = $db->prepare("DELETE FROM otp_verifications WHERE project_id = :pid AND LOWER(email) = LOWER(:email) AND verified = 0");
    $del->execute(['pid' => $projectId, 'email' => $email]);

    $ins = $db->prepare("INSERT INTO otp_verifications (project_id, email, otp_code, expires_at) VALUES (:pid, :email, :code, :exp)");
    $ins->execute([
        'pid' => $projectId,
        'email' => $email,
        'code' => $otpCode,
        'exp' => $expiresAt
    ]);

    $subject = "Your 6-Digit PitchVault Access Code - " . $otpCode;
    $htmlContent = '
    <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 520px; margin: 0 auto; padding: 24px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px;">
        <div style="text-align: center; padding-bottom: 20px; border-bottom: 1px solid #f1f5f9;">
            <div style="display: inline-block; width: 48px; height: 48px; background: linear-gradient(135deg, #4f46e5, #6366f1); border-radius: 12px; line-height: 48px; color: #ffffff; font-weight: bold; font-size: 20px; margin-bottom: 10px;">PV</div>
            <h2 style="margin: 0; color: #0f172a; font-size: 20px; font-weight: 700;">PitchVault Verification Code</h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">Security verification for project presentation</p>
        </div>
        <div style="padding: 24px 0; text-align: center;">
            <p style="margin: 0 0 16px; color: #334155; font-size: 14px;">Use the 6-digit code below to verify your access to <strong>' . htmlspecialchars($projectTitle) . '</strong>:</p>
            <div style="display: inline-block; letter-spacing: 8px; font-size: 32px; font-weight: 800; color: #4f46e5; background-color: #f8fafc; border: 2px dashed #cbd5e1; padding: 14px 28px; border-radius: 12px; font-family: monospace;">
                ' . $otpCode . '
            </div>
            <p style="margin: 16px 0 0; color: #94a3b8; font-size: 12px;">This code will expire in <strong>15 minutes</strong>. Do not share this code with anyone.</p>
        </div>
        <div style="padding-top: 16px; border-top: 1px solid #f1f5f9; text-align: center; color: #94a3b8; font-size: 11px;">
            If you did not request access to this presentation, please ignore this email.
        </div>
    </div>';

    $sendResult = sendSystemEmail($email, $subject, $htmlContent);
    return array_merge(['otp_code' => $otpCode], $sendResult);
}

function verifyProjectOTP($projectId, $email, $inputOtp) {
    $db = getDBConnection();
    $email = strtolower(trim($email));
    $inputOtp = trim($inputOtp);

    if (empty($inputOtp) || strlen($inputOtp) !== 6) return false;

    $stmt = $db->prepare("SELECT id FROM otp_verifications WHERE project_id = :pid AND LOWER(email) = LOWER(:email) AND otp_code = :code AND verified = 0 AND expires_at >= NOW() LIMIT 1");
    $stmt->execute([
        'pid' => $projectId,
        'email' => $email,
        'code' => $inputOtp
    ]);
    $row = $stmt->fetch();

    if ($row) {
        $upd = $db->prepare("UPDATE otp_verifications SET verified = 1 WHERE id = :id");
        $upd->execute(['id' => $row['id']]);
        return true;
    }

    return false;
}

// Welcome Email with Login Credentials & Login URL for New Admin Users
function sendNewUserWelcomeEmail($name, $email, $rawPassword, $roleLabel = 'User') {
    $loginUrl = getBaseUrl() . '/admin/login.php';
    $subject = "Welcome to PitchVault - Your Account Credentials";

    $htmlContent = '
    <div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; max-width: 560px; margin: 0 auto; padding: 24px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px;">
        <div style="text-align: center; padding-bottom: 20px; border-bottom: 1px solid #f1f5f9;">
            <div style="display: inline-block; width: 52px; height: 52px; background: linear-gradient(135deg, #4f46e5, #6366f1); border-radius: 14px; line-height: 52px; color: #ffffff; font-weight: bold; font-size: 22px; margin-bottom: 12px; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);">PV</div>
            <h2 style="margin: 0; color: #0f172a; font-size: 22px; font-weight: 700;">Welcome to PitchVault</h2>
            <p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">Your admin account credentials & access details</p>
        </div>

        <div style="padding: 24px 0;">
            <p style="margin: 0 0 16px; color: #334155; font-size: 15px; line-height: 1.6;">Hello <strong>' . htmlspecialchars($name) . '</strong>,</p>
            <p style="margin: 0 0 20px; color: #475569; font-size: 14px; line-height: 1.6;">An account has been created for you on the PitchVault platform with the role <strong>' . htmlspecialchars($roleLabel) . '</strong>. You can log in using the credentials below:</p>

            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; margin-bottom: 24px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <tr>
                        <td style="padding: 6px 0; color: #64748b; font-weight: 600; width: 120px;">Login URL:</td>
                        <td style="padding: 6px 0; color: #0f172a;"><a href="' . $loginUrl . '" style="color: #4f46e5; text-decoration: none; font-weight: 600;">' . $loginUrl . '</a></td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 0; color: #64748b; font-weight: 600;">Email Address:</td>
                        <td style="padding: 6px 0; color: #0f172a; font-family: monospace; font-size: 14px; font-weight: 600;">' . htmlspecialchars($email) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 0; color: #64748b; font-weight: 600;">Password:</td>
                        <td style="padding: 6px 0; font-family: monospace; font-size: 14px; font-weight: 700; color: #4f46e5;">' . htmlspecialchars($rawPassword) . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 0; color: #64748b; font-weight: 600;">Assigned Role:</td>
                        <td style="padding: 6px 0; color: #0f172a; font-weight: 600;">' . htmlspecialchars($roleLabel) . '</td>
                    </tr>
                </table>
            </div>

            <div style="text-align: center; margin: 28px 0 16px;">
                <a href="' . $loginUrl . '" style="display: inline-block; padding: 12px 32px; background: linear-gradient(135deg, #4f46e5, #4338ca); color: #ffffff; text-decoration: none; font-weight: 600; font-size: 14px; border-radius: 12px; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);">Log In to Your Account &rarr;</a>
            </div>

            <p style="margin: 20px 0 0; color: #94a3b8; font-size: 12px; text-align: center;">For security, we recommend changing your password after your initial login.</p>
        </div>

        <div style="padding-top: 20px; border-top: 1px solid #f1f5f9; text-align: center; color: #94a3b8; font-size: 11px;">
            This is an automated notification from PitchVault System Administration.
        </div>
    </div>';

    return sendSystemEmail($email, $subject, $htmlContent);
}

