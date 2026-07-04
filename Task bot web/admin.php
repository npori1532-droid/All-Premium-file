<?php
// =============================================
//  admin.php - Admin Panel
// =============================================
session_start();
require_once __DIR__ . '/db.php';

// ── Auth ────────────────────────────────────
if (isset($_POST['admin_login'])) {
    $pass = $_POST['password'] ?? '';
    $hash = getSetting('admin_password');
    if (password_verify($pass, $hash)) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $loginError = 'পাসওয়ার্ড ভুল হয়েছে!';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

$isAdmin = !empty($_SESSION['admin_logged_in']);

// ── AJAX handlers ───────────────────────────
if ($isAdmin && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $db = getDB();
    $action = $_POST['action'];

    // Users
    if ($action === 'get_users') {
        $search = '%' . ($_POST['search'] ?? '') . '%';
        $stmt = $db->prepare("SELECT id, telegram_id, username, first_name, last_name, balance, bonus_balance, ads_watched, is_banned, created_at,
            (SELECT COUNT(*) FROM referrals WHERE referrer_id = users.id) AS total_referrals,
            (SELECT COUNT(*) FROM user_tasks WHERE user_id = users.id) AS total_tasks
            FROM users WHERE username LIKE ? OR first_name LIKE ? OR CAST(telegram_id AS CHAR) LIKE ?
            ORDER BY id DESC LIMIT 100");
        $stmt->execute([$search, $search, $search]);
        echo json_encode(['success' => true, 'users' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'update_user_balance') {
        $userId  = (int)($_POST['user_id'] ?? 0);
        $balance = (float)($_POST['balance'] ?? 0);
        $bonus   = (float)($_POST['bonus'] ?? 0);
        $db->prepare("UPDATE users SET balance=?, bonus_balance=? WHERE id=?")->execute([$balance, $bonus, $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'toggle_ban') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $db->prepare("UPDATE users SET is_banned = NOT is_banned WHERE id=?")->execute([$userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Tasks
    if ($action === 'add_task') {
        $name    = trim($_POST['name'] ?? '');
        $link    = trim($_POST['link'] ?? '');
        $logo    = trim($_POST['logo'] ?? '');
        $points  = (float)($_POST['points'] ?? 0);
        $type    = in_array($_POST['type'] ?? '', ['telegram', 'general']) ? $_POST['type'] : 'general';
        $channel = trim($_POST['channel_username'] ?? '');
        if (!$name || !$link || !$logo || $points <= 0) {
            echo json_encode(['success' => false, 'message' => 'সব ফিল্ড পূরণ করুন']);
            exit;
        }
        $stmt = $db->prepare("INSERT INTO tasks (name, link, logo, points, type, channel_username) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$name, $link, $logo, $points, $type, $channel ?: null]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        exit;
    }

    if ($action === 'edit_task') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $link    = trim($_POST['link'] ?? '');
        $logo    = trim($_POST['logo'] ?? '');
        $points  = (float)($_POST['points'] ?? 0);
        $type    = in_array($_POST['type'] ?? '', ['telegram', 'general']) ? $_POST['type'] : 'general';
        $channel = trim($_POST['channel_username'] ?? '');
        $active  = (int)($_POST['is_active'] ?? 1);
        $stmt = $db->prepare("UPDATE tasks SET name=?, link=?, logo=?, points=?, type=?, channel_username=?, is_active=? WHERE id=?");
        $stmt->execute([$name, $link, $logo, $points, $type, $channel ?: null, $active, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_task') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
        $db->prepare("DELETE FROM user_tasks WHERE task_id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'get_tasks') {
        $stmt = $db->query("SELECT * FROM tasks ORDER BY sort_order ASC, id DESC");
        echo json_encode(['success' => true, 'tasks' => $stmt->fetchAll()]);
        exit;
    }

    // Withdrawals
    if ($action === 'get_withdrawals') {
        $status = $_POST['status'] ?? 'pending';
        $stmt = $db->prepare("SELECT w.*, u.first_name, u.last_name, u.username, u.telegram_id
            FROM withdrawals w LEFT JOIN users u ON u.id = w.user_id
            WHERE w.status=? ORDER BY w.id DESC LIMIT 200");
        $stmt->execute([$status]);
        echo json_encode(['success' => true, 'withdrawals' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'update_withdrawal') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        $note   = trim($_POST['note'] ?? '');
        if (!in_array($status, ['approved', 'rejected', 'pending'])) {
            echo json_encode(['success' => false]);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM withdrawals WHERE id=?");
        $stmt->execute([$id]);
        $wd = $stmt->fetch();
        if (!$wd) { echo json_encode(['success' => false, 'message' => 'Not found']); exit; }

        $db->prepare("UPDATE withdrawals SET status=?, admin_note=? WHERE id=?")->execute([$status, $note, $id]);

        // If rejecting, refund balance
        if ($status === 'rejected' && $wd['status'] === 'pending') {
            $db->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$wd['amount'], $wd['user_id']]);
            addTransaction($wd['user_id'], 'refund', $wd['amount'], 'উত্তোলন প্রত্যাখ্যান - ব্যালেন্স ফেরত');
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // Settings
    if ($action === 'update_settings') {
        $keys = ['bot_token','bot_username','marquee_text','referral_bonus','min_withdrawal','ads_required','support_username','tutorial_video','vote_username'];
        foreach ($keys as $k) {
            if (isset($_POST[$k])) setSetting($k, trim($_POST[$k]));
        }
        if (!empty($_POST['new_password'])) {
            setSetting('admin_password', password_hash($_POST['new_password'], PASSWORD_DEFAULT));
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // Dashboard stats
    if ($action === 'get_stats') {
        $totalUsers   = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $totalBalance = $db->query("SELECT SUM(balance) FROM users")->fetchColumn();
        $totalTasks   = $db->query("SELECT COUNT(*) FROM tasks WHERE is_active=1")->fetchColumn();
        $pendingWd    = $db->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();
        $todayUsers   = $db->query("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        $approvedWd   = $db->query("SELECT SUM(amount) FROM withdrawals WHERE status='approved'")->fetchColumn();
        echo json_encode([
            'success'       => true,
            'total_users'   => $totalUsers,
            'total_balance' => round($totalBalance, 2),
            'total_tasks'   => $totalTasks,
            'pending_wd'    => $pendingWd,
            'today_users'   => $todayUsers,
            'approved_wd'   => round($approvedWd, 2),
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ── HTML Output ─────────────────────────────
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel – Fast Cash</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f1f5f9;color:#1e293b;min-height:100vh}
:root{--primary:#6366f1;--primary-dark:#4f46e5;--accent:#a855f7;--sidebar-w:260px}

/* Login */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;background:linear-gradient(135deg,#6366f1,#a855f7)}
.login-card{background:#fff;border-radius:20px;padding:40px 36px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.15)}
.login-card h2{text-align:center;margin-bottom:28px;font-size:1.6rem;color:#1e293b}
.login-logo{width:70px;height:70px;background:linear-gradient(135deg,#6366f1,#a855f7);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:2rem}
.form-group{margin-bottom:18px}
.form-group label{display:block;font-size:.85rem;font-weight:600;color:#475569;margin-bottom:6px}
.form-group input{width:100%;padding:11px 15px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:1rem;outline:none;transition:.2s}
.form-group input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.btn-primary{width:100%;padding:12px;background:linear-gradient(135deg,#6366f1,#a855f7);border:none;border-radius:10px;color:#fff;font-size:1rem;font-weight:700;cursor:pointer;transition:.2s}
.btn-primary:hover{opacity:.9;transform:translateY(-1px)}
.error-msg{background:#fee2e2;color:#dc2626;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.9rem}

/* Layout */
.layout{display:flex;min-height:100vh}
.sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,#1e1b4b 0%,#312e81 100%);position:fixed;left:-var(--sidebar-w);top:0;height:100vh;z-index:100;transition:transform .3s ease;transform:translateX(0);box-shadow:4px 0 20px rgba(0,0,0,.2)}
.sidebar.open{transform:translateX(0)}
.sidebar-logo{padding:28px 24px 20px;border-bottom:1px solid rgba(255,255,255,.1)}
.sidebar-logo h2{color:#fff;font-size:1.25rem;font-weight:800}
.sidebar-logo p{color:#a5b4fc;font-size:.75rem;margin-top:4px}
.sidebar-nav{padding:16px 12px}
.nav-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;color:#c7d2fe;font-size:.9rem;font-weight:500;cursor:pointer;transition:.2s;margin-bottom:4px;text-decoration:none}
.nav-item:hover,.nav-item.active{background:rgba(255,255,255,.12);color:#fff}
.nav-item svg{width:20px;height:20px;flex-shrink:0}
.sidebar-section{padding:8px 16px;font-size:.7rem;font-weight:700;color:#818cf8;text-transform:uppercase;letter-spacing:1.5px;margin-top:12px}

/* Top bar */
.topbar{position:fixed;top:0;left:0;right:0;height:60px;background:#fff;border-bottom:1.5px solid #e2e8f0;z-index:99;display:flex;align-items:center;padding:0 20px;gap:16px;box-shadow:0 1px 8px rgba(0,0,0,.06)}
.topbar-menu-btn{background:none;border:none;cursor:pointer;padding:8px;border-radius:8px;transition:.2s}
.topbar-menu-btn:hover{background:#f1f5f9}
.topbar-menu-btn span{display:block;width:22px;height:2.5px;background:#475569;border-radius:2px;margin:4px 0;transition:.3s}
.topbar-title{font-size:1.1rem;font-weight:700;color:#1e293b;flex:1}
.topbar-user{display:flex;align-items:center;gap:10px;font-size:.875rem;color:#475569}
.logout-btn{padding:7px 16px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;color:#ef4444;font-weight:600;cursor:pointer;font-size:.8rem;transition:.2s;text-decoration:none}
.logout-btn:hover{background:#fee2e2;border-color:#ef4444}

/* Main */
.main{margin-left:0;padding-top:60px;min-height:100vh;transition:.3s;width:100%}
.main.shifted{margin-left:var(--sidebar-w)}
.page-content{padding:24px;display:none}
.page-content.active{display:block}
.page-title{font-size:1.5rem;font-weight:800;color:#1e293b;margin-bottom:20px;display:flex;align-items:center;gap:10px}

/* Stat cards */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-bottom:28px}
.stat-card{background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1.5px solid #f1f5f9}
.stat-card .s-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:12px}
.stat-card .s-label{font-size:.8rem;color:#64748b;font-weight:500;margin-bottom:4px}
.stat-card .s-value{font-size:1.7rem;font-weight:800;color:#1e293b}
.bg-indigo{background:#eef2ff} .text-indigo{color:#6366f1}
.bg-purple{background:#f5f3ff} .text-purple{color:#a855f7}
.bg-green{background:#f0fdf4}  .text-green{color:#22c55e}
.bg-orange{background:#fff7ed} .text-orange{color:#f97316}
.bg-red{background:#fef2f2}    .text-red{color:#ef4444}
.bg-blue{background:#eff6ff}   .text-blue{color:#3b82f6}

/* Tables */
.table-wrap{background:#fff;border-radius:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;border:1.5px solid #f1f5f9}
.table-header{padding:18px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1.5px solid #f1f5f9;gap:12px;flex-wrap:wrap}
.table-header h3{font-size:1.05rem;font-weight:700;color:#1e293b}
table{width:100%;border-collapse:collapse}
th{background:#f8fafc;padding:12px 16px;text-align:left;font-size:.8rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.5px}
td{padding:12px 16px;font-size:.875rem;border-top:1px solid #f1f5f9;color:#334155;vertical-align:middle}
tr:hover td{background:#fafafa}
.badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.75rem;font-weight:600}
.badge-pending{background:#fef9c3;color:#a16207}
.badge-approved{background:#dcfce7;color:#16a34a}
.badge-rejected{background:#fee2e2;color:#dc2626}
.badge-active{background:#dcfce7;color:#16a34a}
.badge-inactive{background:#f1f5f9;color:#64748b}
.badge-banned{background:#fee2e2;color:#dc2626}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-size:.83rem;font-weight:600;cursor:pointer;border:none;transition:.2s;text-decoration:none}
.btn-sm{padding:5px 12px;font-size:.78rem}
.btn-indigo{background:#6366f1;color:#fff}.btn-indigo:hover{background:#4f46e5}
.btn-green{background:#22c55e;color:#fff}.btn-green:hover{background:#16a34a}
.btn-red{background:#ef4444;color:#fff}.btn-red:hover{background:#dc2626}
.btn-gray{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0}.btn-gray:hover{background:#e2e8f0}
.btn-orange{background:#f97316;color:#fff}.btn-orange:hover{background:#ea580c}

/* Forms */
.form-card{background:#fff;border-radius:16px;padding:28px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1.5px solid #f1f5f9;margin-bottom:24px}
.form-card h3{font-size:1.05rem;font-weight:700;margin-bottom:20px;color:#1e293b;padding-bottom:12px;border-bottom:1.5px solid #f1f5f9}
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px}
.fld{display:flex;flex-direction:column;gap:6px}
.fld label{font-size:.82rem;font-weight:600;color:#475569}
.fld input,.fld select,.fld textarea{padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:.9rem;outline:none;transition:.2s;background:#fff;color:#1e293b}
.fld input:focus,.fld select:focus,.fld textarea:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12)}
.fld textarea{resize:vertical;min-height:80px}

/* Search input */
.search-box{display:flex;align-items:center;gap:8px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 13px}
.search-box input{border:none;background:transparent;outline:none;font-size:.9rem;color:#1e293b;width:200px}
.search-box svg{width:16px;height:16px;color:#94a3b8;flex-shrink:0}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:.2s}
.modal-overlay.open{opacity:1;visibility:visible}
.modal{background:#fff;border-radius:20px;padding:28px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;transform:scale(.95);transition:.2s}
.modal-overlay.open .modal{transform:scale(1)}
.modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:14px;border-bottom:1.5px solid #f1f5f9}
.modal-head h3{font-size:1.1rem;font-weight:700;color:#1e293b}
.modal-close{background:none;border:none;cursor:pointer;padding:6px;border-radius:8px;color:#64748b}
.modal-close:hover{background:#f1f5f9}

/* Toast */
#toast{position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:12px;font-size:.875rem;font-weight:500;z-index:9999;transform:translateY(20px);opacity:0;transition:.3s;pointer-events:none}
#toast.show{transform:translateY(0);opacity:1}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}

/* Overlay for sidebar on mobile */
.sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99;display:none}
.sidebar-overlay.show{display:block}

/* Responsive */
@media(min-width:768px){
    .sidebar{transform:translateX(0)}
    .main{margin-left:var(--sidebar-w)}
    .topbar-menu-btn{display:none}
    .sidebar-overlay{display:none !important}
}
@media(max-width:767px){
    .sidebar{transform:translateX(-100%)}
    .sidebar.open{transform:translateX(0)}
}
</style>
</head>
<body>

<?php if (!$isAdmin): ?>
<!-- ════════════════════════════════════
     LOGIN PAGE
════════════════════════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">🔐</div>
    <h2>Admin Login</h2>
    <?php if (!empty($loginError)): ?>
    <div class="error-msg"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>পাসওয়ার্ড</label>
        <input type="password" name="password" placeholder="পাসওয়ার্ড দিন" autofocus required>
      </div>
      <input type="hidden" name="admin_login" value="1">
      <button type="submit" class="btn-primary">লগইন করুন</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ════════════════════════════════════
     ADMIN PANEL
════════════════════════════════════ -->

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <h2>⚡ Fast Cash</h2>
    <p>Admin Panel</p>
  </div>
  <nav class="sidebar-nav">
    <div class="sidebar-section">মেইন</div>
    <a class="nav-item active" onclick="showPage('dashboard')" href="#">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h7v7H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 18h7v3H3z"/></svg>
      ড্যাশবোর্ড
    </a>
    <div class="sidebar-section">ব্যবস্থাপনা</div>
    <a class="nav-item" onclick="showPage('users')" href="#">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      সকল ইউজার
    </a>
    <a class="nav-item" onclick="showPage('tasks')" href="#">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      টাস্ক ম্যানেজার
    </a>
    <a class="nav-item" onclick="showPage('withdrawals')" href="#">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H5a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
      উত্তোলন রিকোয়েস্ট
    </a>
    <div class="sidebar-section">কনফিগ</div>
    <a class="nav-item" onclick="showPage('settings')" href="#">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
      সেটিংস
    </a>
  </nav>
</aside>

<!-- Top Bar -->
<div class="topbar">
  <button class="topbar-menu-btn" id="menuBtn" onclick="toggleSidebar()">
    <span></span><span></span><span></span>
  </button>
  <div class="topbar-title" id="topbarTitle">ড্যাশবোর্ড</div>
  <div class="topbar-user">
    <span>👤 Admin</span>
    <a href="?logout=1" class="logout-btn">লগআউট</a>
  </div>
</div>

<!-- Main Content -->
<div class="main" id="main">

  <!-- ── Dashboard ── -->
  <div class="page-content active" id="page-dashboard">
    <div class="page-title">📊 ড্যাশবোর্ড</div>
    <div class="stats-grid" id="statsGrid">
      <div class="stat-card"><div class="s-icon bg-indigo"><svg class="text-indigo" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><div class="s-label">মোট ইউজার</div><div class="s-value" id="st-total-users">-</div></div>
      <div class="stat-card"><div class="s-icon bg-green"><svg class="text-green" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="s-label">মোট ব্যালেন্স</div><div class="s-value" id="st-total-balance">-</div></div>
      <div class="stat-card"><div class="s-icon bg-purple"><svg class="text-purple" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div><div class="s-label">সক্রিয় টাস্ক</div><div class="s-value" id="st-total-tasks">-</div></div>
      <div class="stat-card"><div class="s-icon bg-orange"><svg class="text-orange" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="s-label">পেন্ডিং উত্তোলন</div><div class="s-value" id="st-pending-wd">-</div></div>
      <div class="stat-card"><div class="s-icon bg-blue"><svg class="text-blue" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div><div class="s-label">আজকের নতুন ইউজার</div><div class="s-value" id="st-today-users">-</div></div>
      <div class="stat-card"><div class="s-icon bg-green"><svg class="text-green" width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div><div class="s-label">অনুমোদিত উত্তোলন</div><div class="s-value" id="st-approved-wd">-</div></div>
    </div>
  </div>

  <!-- ── Users ── -->
  <div class="page-content" id="page-users">
    <div class="page-title">👥 সকল ইউজার</div>
    <div class="table-wrap">
      <div class="table-header">
        <h3>ইউজার তালিকা</h3>
        <div class="search-box">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input type="text" id="userSearch" placeholder="নাম / টেলিগ্রাম ID..." oninput="loadUsers()">
        </div>
      </div>
      <div style="overflow-x:auto">
        <table>
          <thead><tr><th>#</th><th>ইউজার</th><th>Telegram ID</th><th>ব্যালেন্স</th><th>বোনাস</th><th>টাস্ক</th><th>রেফার</th><th>বিজ্ঞাপন</th><th>স্ট্যাটাস</th><th>অ্যাকশন</th></tr></thead>
          <tbody id="usersTableBody"><tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8">লোড হচ্ছে...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── Tasks ── -->
  <div class="page-content" id="page-tasks">
    <div class="page-title">📋 টাস্ক ম্যানেজার</div>
    <!-- Add task form -->
    <div class="form-card">
      <h3>➕ নতুন টাস্ক যোগ করুন</h3>
      <div class="form-grid">
        <div class="fld"><label>টাস্ক নাম *</label><input type="text" id="t_name" placeholder="যেমন: Telegram Channel"></div>
        <div class="fld"><label>টাস্ক লিংক *</label><input type="text" id="t_link" placeholder="https://t.me/..."></div>
        <div class="fld"><label>লোগো URL *</label><input type="text" id="t_logo" placeholder="https://i.ibb.co/..."></div>
        <div class="fld"><label>পয়েন্ট (৳) *</label><input type="number" id="t_points" placeholder="1000" min="1"></div>
        <div class="fld"><label>টাইপ</label>
          <select id="t_type">
            <option value="telegram">Telegram (অটো চেক)</option>
            <option value="general">General (ম্যানুয়াল)</option>
          </select>
        </div>
        <div class="fld"><label>চ্যানেল ইউজারনেম (Telegram হলে)</label><input type="text" id="t_channel" placeholder="@channelname বা channelname"></div>
      </div>
      <div style="margin-top:16px"><button class="btn btn-indigo" onclick="addTask()">✅ টাস্ক যোগ করুন</button></div>
    </div>
    <!-- Task list -->
    <div class="table-wrap">
      <div class="table-header"><h3>টাস্ক তালিকা</h3><button class="btn btn-gray btn-sm" onclick="loadTasks()">🔄 রিফ্রেশ</button></div>
      <div style="overflow-x:auto">
        <table>
          <thead><tr><th>#</th><th>লোগো</th><th>নাম</th><th>পয়েন্ট</th><th>টাইপ</th><th>স্ট্যাটাস</th><th>অ্যাকশন</th></tr></thead>
          <tbody id="tasksTableBody"><tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8">লোড হচ্ছে...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── Withdrawals ── -->
  <div class="page-content" id="page-withdrawals">
    <div class="page-title">💳 উত্তোলন রিকোয়েস্ট</div>
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
      <button class="btn btn-orange btn-sm" onclick="loadWithdrawals('pending')" id="wd-btn-pending">⏳ পেন্ডিং</button>
      <button class="btn btn-green btn-sm" onclick="loadWithdrawals('approved')" id="wd-btn-approved">✅ অনুমোদিত</button>
      <button class="btn btn-red btn-sm" onclick="loadWithdrawals('rejected')" id="wd-btn-rejected">❌ প্রত্যাখ্যাত</button>
    </div>
    <div class="table-wrap">
      <div class="table-header"><h3 id="wdTableTitle">পেন্ডিং রিকোয়েস্ট</h3></div>
      <div style="overflow-x:auto">
        <table>
          <thead><tr><th>#</th><th>ইউজার</th><th>পেমেন্ট মেথড</th><th>অ্যাকাউন্ট</th><th>পরিমাণ</th><th>তারিখ</th><th>স্ট্যাটাস</th><th>অ্যাকশন</th></tr></thead>
          <tbody id="wdTableBody"><tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8">লোড হচ্ছে...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── Settings ── -->
  <div class="page-content" id="page-settings">
    <div class="page-title">⚙️ সেটিংস</div>
    <div class="form-card">
      <h3>🤖 টেলিগ্রাম বট</h3>
      <div class="form-grid">
        <div class="fld"><label>Bot Token</label><input type="text" id="s_bot_token" placeholder="123456:ABC..."></div>
        <div class="fld"><label>Bot Username</label><input type="text" id="s_bot_username" placeholder="FastCashBD1bot"></div>
        <div class="fld"><label>ভোটের ইউজারনেম</label><input type="text" id="s_vote_username" placeholder="@username"></div>
        <div class="fld"><label>সাপোর্ট ইউজারনেম</label><input type="text" id="s_support_username" placeholder="@support_username"></div>
      </div>
    </div>
    <div class="form-card">
      <h3>💰 ইকোনমি</h3>
      <div class="form-grid">
        <div class="fld"><label>রেফার বোনাস (৳)</label><input type="number" id="s_referral_bonus" placeholder="100" min="0"></div>
        <div class="fld"><label>সর্বনিম্ন উত্তোলন (৳)</label><input type="number" id="s_min_withdrawal" placeholder="1100" min="0"></div>
        <div class="fld"><label>প্রয়োজনীয় বিজ্ঞাপন (সংখ্যা)</label><input type="number" id="s_ads_required" placeholder="10" min="0"></div>
      </div>
    </div>
    <div class="form-card">
      <h3>📢 কন্টেন্ট</h3>
      <div class="form-grid" style="grid-template-columns:1fr">
        <div class="fld"><label>Marquee টেক্সট (স্ক্রলিং নোটিশ)</label><input type="text" id="s_marquee_text" placeholder="স্বাগত বার্তা..."></div>
        <div class="fld"><label>টিউটোরিয়াল ভিডিও URL</label><input type="text" id="s_tutorial_video" placeholder="https://youtube.com/..."></div>
      </div>
    </div>
    <div class="form-card">
      <h3>🔐 অ্যাডমিন পাসওয়ার্ড পরিবর্তন</h3>
      <div class="form-grid">
        <div class="fld"><label>নতুন পাসওয়ার্ড</label><input type="password" id="s_new_password" placeholder="খালি রাখলে পরিবর্তন হবে না"></div>
      </div>
    </div>
    <button class="btn btn-indigo" onclick="saveSettings()">💾 সেটিংস সংরক্ষণ করুন</button>
  </div>

</div><!-- end .main -->

<!-- ── Edit User Modal ── -->
<div class="modal-overlay" id="editUserModal">
  <div class="modal">
    <div class="modal-head">
      <h3>✏️ ইউজার ব্যালেন্স আপডেট</h3>
      <button class="modal-close" onclick="closeModal('editUserModal')">✕</button>
    </div>
    <div class="form-grid">
      <div class="fld"><label>ইউজার</label><input type="text" id="eu_name" readonly style="background:#f8fafc"></div>
      <div class="fld"><label>ব্যালেন্স (৳)</label><input type="number" id="eu_balance" placeholder="0.00" step="0.01"></div>
      <div class="fld"><label>বোনাস ব্যালেন্স (৳)</label><input type="number" id="eu_bonus" placeholder="0.00" step="0.01"></div>
    </div>
    <input type="hidden" id="eu_id">
    <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
      <button class="btn btn-gray" onclick="closeModal('editUserModal')">বাতিল</button>
      <button class="btn btn-indigo" onclick="saveUserBalance()">💾 সংরক্ষণ করুন</button>
    </div>
  </div>
</div>

<!-- ── Edit Task Modal ── -->
<div class="modal-overlay" id="editTaskModal">
  <div class="modal">
    <div class="modal-head">
      <h3>✏️ টাস্ক সম্পাদনা</h3>
      <button class="modal-close" onclick="closeModal('editTaskModal')">✕</button>
    </div>
    <div class="form-grid">
      <div class="fld"><label>নাম *</label><input type="text" id="et_name"></div>
      <div class="fld"><label>লিংক *</label><input type="text" id="et_link"></div>
      <div class="fld"><label>লোগো URL *</label><input type="text" id="et_logo"></div>
      <div class="fld"><label>পয়েন্ট (৳) *</label><input type="number" id="et_points" step="0.01" min="0"></div>
      <div class="fld"><label>টাইপ</label><select id="et_type"><option value="telegram">Telegram</option><option value="general">General</option></select></div>
      <div class="fld"><label>চ্যানেল ইউজারনেম</label><input type="text" id="et_channel"></div>
      <div class="fld"><label>স্ট্যাটাস</label><select id="et_active"><option value="1">সক্রিয়</option><option value="0">নিষ্ক্রিয়</option></select></div>
    </div>
    <input type="hidden" id="et_id">
    <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
      <button class="btn btn-gray" onclick="closeModal('editTaskModal')">বাতিল</button>
      <button class="btn btn-indigo" onclick="saveTask()">💾 আপডেট করুন</button>
    </div>
  </div>
</div>

<!-- ── Withdrawal Action Modal ── -->
<div class="modal-overlay" id="wdModal">
  <div class="modal">
    <div class="modal-head">
      <h3>💳 উত্তোলন প্রসেস</h3>
      <button class="modal-close" onclick="closeModal('wdModal')">✕</button>
    </div>
    <div id="wdModalContent"></div>
    <input type="hidden" id="wd_id">
    <div style="margin-top:16px" class="fld"><label>নোট (ঐচ্ছিক)</label><textarea id="wd_note" rows="3" placeholder="কারণ বা নোট লিখুন..."></textarea></div>
    <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end;flex-wrap:wrap">
      <button class="btn btn-gray" onclick="closeModal('wdModal')">বাতিল</button>
      <button class="btn btn-red" onclick="processWd('rejected')">❌ প্রত্যাখ্যান</button>
      <button class="btn btn-green" onclick="processWd('approved')">✅ অনুমোদন</button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
// ═══════════════════════════════════
//  Sidebar
// ═══════════════════════════════════
let sidebarOpen = false;
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
const main    = document.getElementById('main');

function toggleSidebar() {
  sidebarOpen = !sidebarOpen;
  sidebar.classList.toggle('open', sidebarOpen);
  overlay.classList.toggle('show', sidebarOpen);
}
function closeSidebar() {
  sidebarOpen = false;
  sidebar.classList.remove('open');
  overlay.classList.remove('show');
}

// ═══════════════════════════════════
//  Page navigation
// ═══════════════════════════════════
const pageTitles = { dashboard:'ড্যাশবোর্ড', users:'সকল ইউজার', tasks:'টাস্ক ম্যানেজার', withdrawals:'উত্তোলন রিকোয়েস্ট', settings:'সেটিংস' };

function showPage(page) {
  document.querySelectorAll('.page-content').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('page-' + page).classList.add('active');
  document.getElementById('topbarTitle').textContent = pageTitles[page] || page;
  event?.currentTarget?.classList.add('active');

  if (page === 'dashboard') loadStats();
  if (page === 'users')     loadUsers();
  if (page === 'tasks')     loadTasks();
  if (page === 'withdrawals') loadWithdrawals('pending');
  if (page === 'settings')  loadSettings();

  if (window.innerWidth < 768) closeSidebar();
}

// ═══════════════════════════════════
//  Toast
// ═══════════════════════════════════
function toast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'show ' + type;
  setTimeout(() => t.className = '', 3000);
}

// ═══════════════════════════════════
//  Modal
// ═══════════════════════════════════
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ═══════════════════════════════════
//  API helper
// ═══════════════════════════════════
async function api(data) {
  const fd = new FormData();
  Object.keys(data).forEach(k => fd.append(k, data[k]));
  const res = await fetch('admin.php', { method: 'POST', body: fd });
  return res.json();
}

// ═══════════════════════════════════
//  Dashboard
// ═══════════════════════════════════
async function loadStats() {
  const d = await api({ action: 'get_stats' });
  if (d.success) {
    document.getElementById('st-total-users').textContent  = d.total_users;
    document.getElementById('st-total-balance').textContent = '৳' + d.total_balance;
    document.getElementById('st-total-tasks').textContent  = d.total_tasks;
    document.getElementById('st-pending-wd').textContent   = d.pending_wd;
    document.getElementById('st-today-users').textContent  = d.today_users;
    document.getElementById('st-approved-wd').textContent  = '৳' + d.approved_wd;
  }
}

// ═══════════════════════════════════
//  Users
// ═══════════════════════════════════
async function loadUsers() {
  const search = document.getElementById('userSearch').value;
  const d = await api({ action: 'get_users', search });
  const tbody = document.getElementById('usersTableBody');
  if (!d.success || !d.users.length) {
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8">কোনো ইউজার পাওয়া যায়নি</td></tr>';
    return;
  }
  tbody.innerHTML = d.users.map((u, i) => `
    <tr>
      <td>${i+1}</td>
      <td><div style="font-weight:600">${escHtml(u.first_name || '')} ${escHtml(u.last_name || '')}</div><div style="font-size:.75rem;color:#94a3b8">@${escHtml(u.username || 'N/A')}</div></td>
      <td><code style="font-size:.8rem">${u.telegram_id}</code></td>
      <td><strong style="color:#16a34a">৳${u.balance}</strong></td>
      <td>৳${u.bonus_balance}</td>
      <td>${u.total_tasks}</td>
      <td>${u.total_referrals}</td>
      <td>${u.ads_watched}</td>
      <td><span class="badge ${u.is_banned ? 'badge-banned' : 'badge-active'}">${u.is_banned ? '🚫 ব্যান' : '✅ সক্রিয়'}</span></td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn btn-indigo btn-sm" onclick="openEditUser(${u.id},'${escHtml(u.first_name||'')} ${escHtml(u.last_name||'')}',${u.balance},${u.bonus_balance})">✏️ এডিট</button>
          <button class="btn ${u.is_banned ? 'btn-green' : 'btn-red'} btn-sm" onclick="toggleBan(${u.id},this)">${u.is_banned ? '✅ আনব্যান' : '🚫 ব্যান'}</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function openEditUser(id, name, balance, bonus) {
  document.getElementById('eu_id').value      = id;
  document.getElementById('eu_name').value    = name;
  document.getElementById('eu_balance').value = balance;
  document.getElementById('eu_bonus').value   = bonus;
  openModal('editUserModal');
}

async function saveUserBalance() {
  const d = await api({
    action: 'update_user_balance',
    user_id: document.getElementById('eu_id').value,
    balance: document.getElementById('eu_balance').value,
    bonus:   document.getElementById('eu_bonus').value,
  });
  if (d.success) { toast('ব্যালেন্স আপডেট হয়েছে ✅'); closeModal('editUserModal'); loadUsers(); }
  else toast('ত্রুটি হয়েছে', 'error');
}

async function toggleBan(id, btn) {
  const d = await api({ action: 'toggle_ban', user_id: id });
  if (d.success) { toast('স্ট্যাটাস পরিবর্তন হয়েছে'); loadUsers(); }
  else toast('ত্রুটি', 'error');
}

// ═══════════════════════════════════
//  Tasks
// ═══════════════════════════════════
async function loadTasks() {
  const d = await api({ action: 'get_tasks' });
  const tbody = document.getElementById('tasksTableBody');
  if (!d.success || !d.tasks.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8">কোনো টাস্ক নেই</td></tr>';
    return;
  }
  tbody.innerHTML = d.tasks.map((t, i) => `
    <tr>
      <td>${i+1}</td>
      <td><img src="${escHtml(t.logo)}" style="width:36px;height:36px;border-radius:8px;object-fit:cover" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22><rect fill=%22%236366f1%22 width=%2224%22 height=%2224%22 rx=%226%22/><text y=%2217%22 x=%227%22 fill=%22white%22 font-size=%2214%22>T</text></svg>'"></td>
      <td><div style="font-weight:600">${escHtml(t.name)}</div><div style="font-size:.75rem;color:#94a3b8">${t.type === 'telegram' ? '📱 Telegram' : '🌐 General'}</div></td>
      <td><strong style="color:#6366f1">৳${t.points}</strong></td>
      <td><span class="badge" style="background:#eff6ff;color:#3b82f6">${escHtml(t.type)}</span></td>
      <td><span class="badge ${t.is_active ? 'badge-active' : 'badge-inactive'}">${t.is_active ? 'সক্রিয়' : 'নিষ্ক্রিয়'}</span></td>
      <td>
        <div style="display:flex;gap:6px">
          <button class="btn btn-indigo btn-sm" onclick="openEditTask(${JSON.stringify(t).replace(/"/g,'&quot;')})">✏️</button>
          <button class="btn btn-red btn-sm" onclick="deleteTask(${t.id})">🗑️</button>
        </div>
      </td>
    </tr>
  `).join('');
}

async function addTask() {
  const d = await api({
    action: 'add_task',
    name:             document.getElementById('t_name').value,
    link:             document.getElementById('t_link').value,
    logo:             document.getElementById('t_logo').value,
    points:           document.getElementById('t_points').value,
    type:             document.getElementById('t_type').value,
    channel_username: document.getElementById('t_channel').value,
  });
  if (d.success) {
    toast('টাস্ক যোগ হয়েছে ✅');
    ['t_name','t_link','t_logo','t_points','t_channel'].forEach(id => document.getElementById(id).value = '');
    loadTasks();
  } else toast(d.message || 'ত্রুটি', 'error');
}

function openEditTask(task) {
  document.getElementById('et_id').value      = task.id;
  document.getElementById('et_name').value    = task.name;
  document.getElementById('et_link').value    = task.link;
  document.getElementById('et_logo').value    = task.logo;
  document.getElementById('et_points').value  = task.points;
  document.getElementById('et_type').value    = task.type;
  document.getElementById('et_channel').value = task.channel_username || '';
  document.getElementById('et_active').value  = task.is_active;
  openModal('editTaskModal');
}

async function saveTask() {
  const d = await api({
    action:           'edit_task',
    id:               document.getElementById('et_id').value,
    name:             document.getElementById('et_name').value,
    link:             document.getElementById('et_link').value,
    logo:             document.getElementById('et_logo').value,
    points:           document.getElementById('et_points').value,
    type:             document.getElementById('et_type').value,
    channel_username: document.getElementById('et_channel').value,
    is_active:        document.getElementById('et_active').value,
  });
  if (d.success) { toast('আপডেট হয়েছে ✅'); closeModal('editTaskModal'); loadTasks(); }
  else toast('ত্রুটি', 'error');
}

async function deleteTask(id) {
  if (!confirm('এই টাস্কটি মুছে ফেলবেন?')) return;
  const d = await api({ action: 'delete_task', id });
  if (d.success) { toast('মুছে ফেলা হয়েছে'); loadTasks(); }
  else toast('ত্রুটি', 'error');
}

// ═══════════════════════════════════
//  Withdrawals
// ═══════════════════════════════════
let currentWdStatus = 'pending';
async function loadWithdrawals(status) {
  currentWdStatus = status;
  const titles = { pending:'পেন্ডিং রিকোয়েস্ট', approved:'অনুমোদিত', rejected:'প্রত্যাখ্যাত' };
  document.getElementById('wdTableTitle').textContent = titles[status];
  const d = await api({ action: 'get_withdrawals', status });
  const tbody = document.getElementById('wdTableBody');
  if (!d.success || !d.withdrawals.length) {
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8">কোনো রিকোয়েস্ট নেই</td></tr>`;
    return;
  }
  tbody.innerHTML = d.withdrawals.map((w, i) => `
    <tr>
      <td>${i+1}</td>
      <td><div style="font-weight:600">${escHtml(w.first_name||'')} ${escHtml(w.last_name||'')}</div><div style="font-size:.75rem;color:#94a3b8">ID: ${w.telegram_id}</div></td>
      <td>${escHtml(w.method)}</td>
      <td><code style="font-size:.82rem">${escHtml(w.account)}</code></td>
      <td><strong style="color:#ef4444">৳${w.amount}</strong></td>
      <td style="font-size:.8rem;color:#64748b">${w.created_at}</td>
      <td><span class="badge badge-${w.status}">${w.status === 'pending' ? '⏳ পেন্ডিং' : w.status === 'approved' ? '✅ অনুমোদিত' : '❌ প্রত্যাখ্যাত'}</span></td>
      <td>${w.status === 'pending' ? `<button class="btn btn-indigo btn-sm" onclick="openWdModal(${w.id},'${escHtml(w.first_name||'')}','${w.amount}','${escHtml(w.method)}','${escHtml(w.account)}')">প্রসেস করুন</button>` : `<span style="font-size:.8rem;color:#94a3b8">${escHtml(w.admin_note||'-')}</span>`}</td>
    </tr>
  `).join('');
}

function openWdModal(id, name, amount, method, account) {
  document.getElementById('wd_id').value = id;
  document.getElementById('wd_note').value = '';
  document.getElementById('wdModalContent').innerHTML = `
    <div style="background:#f8fafc;border-radius:12px;padding:16px;margin-bottom:8px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div><div style="font-size:.75rem;color:#64748b">ইউজার</div><div style="font-weight:700">${name}</div></div>
        <div><div style="font-size:.75rem;color:#64748b">পরিমাণ</div><div style="font-weight:700;color:#ef4444;font-size:1.2rem">৳${amount}</div></div>
        <div><div style="font-size:.75rem;color:#64748b">মেথড</div><div style="font-weight:600">${method}</div></div>
        <div><div style="font-size:.75rem;color:#64748b">অ্যাকাউন্ট</div><div style="font-weight:600;font-size:.85rem">${account}</div></div>
      </div>
    </div>
  `;
  openModal('wdModal');
}

async function processWd(status) {
  const d = await api({
    action: 'update_withdrawal',
    id:     document.getElementById('wd_id').value,
    status,
    note:   document.getElementById('wd_note').value,
  });
  if (d.success) {
    toast(status === 'approved' ? '✅ অনুমোদন হয়েছে' : '❌ প্রত্যাখ্যাত হয়েছে', status === 'approved' ? 'success' : 'error');
    closeModal('wdModal');
    loadWithdrawals(currentWdStatus);
  } else toast('ত্রুটি', 'error');
}

// ═══════════════════════════════════
//  Settings
// ═══════════════════════════════════
const settingFields = ['bot_token','bot_username','vote_username','support_username','referral_bonus','min_withdrawal','ads_required','marquee_text','tutorial_video'];

async function loadSettings() {
  // We'll just pass all via PHP echo into JS on page load — here we use fetch
  const d = await api({ action: 'get_stats' }); // reuse connection
  // Fetch settings via a trick: read from meta tags injected by PHP
  const metas = document.querySelectorAll('meta[data-setting]');
  metas.forEach(m => {
    const key = m.dataset.setting;
    const el  = document.getElementById('s_' + key);
    if (el) el.value = m.content;
  });
}

async function saveSettings() {
  const data = { action: 'update_settings' };
  settingFields.forEach(k => {
    const el = document.getElementById('s_' + k);
    if (el) data[k] = el.value;
  });
  const np = document.getElementById('s_new_password');
  if (np && np.value) data['new_password'] = np.value;

  const d = await api(data);
  if (d.success) { toast('সেটিংস সংরক্ষিত হয়েছে ✅'); if (np) np.value = ''; }
  else toast('ত্রুটি', 'error');
}

// ═══════════════════════════════════
//  Utility
// ═══════════════════════════════════
function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ──
document.addEventListener('DOMContentLoaded', () => {
  loadStats();
  // Pre-fill settings fields from PHP
  <?php
  $skeys = ['bot_token','bot_username','vote_username','support_username','referral_bonus','min_withdrawal','ads_required','marquee_text','tutorial_video'];
  foreach ($skeys as $k) {
      $v = getSetting($k) ?? '';
      echo "var el=document.getElementById('s_{$k}');if(el)el.value=" . json_encode($v) . ";\n";
  }
  ?>
});
</script>

<?php endif; ?>
</body>
</html>
