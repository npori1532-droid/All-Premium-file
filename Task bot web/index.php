<?php
// =============================================
//  index.php - User Panel (Telegram Mini App)
// =============================================
session_start();
require_once __DIR__ . '/db.php';

// ── AJAX / API handlers ─────────────────────
if (isset($_POST['action']) || isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $db     = getDB();

    // ── Auth / Login ──────────────────────────
    if ($action === 'auth') {
        $initData = $_POST['initData'] ?? '';
        $refCode  = $_POST['ref'] ?? '';
        $botToken = getSetting('bot_token');

        // Parse user from initData
        parse_str($initData, $parsed);
        $userJson = $parsed['user'] ?? null;
        if (!$userJson) {
            echo json_encode(['success' => false, 'message' => 'No user data']);
            exit;
        }
        $tgUser = json_decode($userJson, true);
        if (!$tgUser || empty($tgUser['id'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid user']);
            exit;
        }

        // Optional: validate initData signature (skip in dev if no token)
        // if ($botToken && !validateTelegramInitData($initData, $botToken)) {
        //     echo json_encode(['success' => false, 'message' => 'Invalid signature']);
        //     exit;
        // }

        $user = createOrUpdateUser($tgUser, $refCode ?: null);
        $_SESSION['user_id']     = $user['id'];
        $_SESSION['telegram_id'] = $user['telegram_id'];

        echo json_encode(['success' => true, 'user' => sanitizeUser($user)]);
        exit;
    }

    // Require session for all other actions
    $sessionUserId = $_SESSION['user_id'] ?? null;
    if (!$sessionUserId) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated', 'code' => 401]);
        exit;
    }

    // Fetch current user
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$sessionUserId]);
    $me = $stmt->fetch();
    if (!$me || $me['is_banned']) {
        session_destroy();
        echo json_encode(['success' => false, 'message' => 'Account banned or not found', 'code' => 403]);
        exit;
    }

    // ── Get user profile ──────────────────────
    if ($action === 'get_profile') {
        $totalTasks   = $db->prepare("SELECT COUNT(*) FROM user_tasks WHERE user_id=?");
        $totalTasks->execute([$me['id']]);
        $totalRefs    = $db->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_id=?");
        $totalRefs->execute([$me['id']]);
        echo json_encode([
            'success'      => true,
            'user'         => sanitizeUser($me),
            'total_tasks'  => (int) $totalTasks->fetchColumn(),
            'total_refs'   => (int) $totalRefs->fetchColumn(),
            'marquee_text' => getSetting('marquee_text'),
            'ref_bonus'    => getSetting('referral_bonus'),
            'bot_username' => getSetting('bot_username'),
        ]);
        exit;
    }

    // ── Get tasks ────────────────────────────
    if ($action === 'get_tasks') {
        $stmt = $db->prepare("SELECT t.*, 
            CASE WHEN ut.id IS NOT NULL THEN 1 ELSE 0 END AS completed
            FROM tasks t
            LEFT JOIN user_tasks ut ON ut.task_id = t.id AND ut.user_id = ?
            WHERE t.is_active = 1
            ORDER BY t.sort_order ASC, t.id DESC");
        $stmt->execute([$me['id']]);
        echo json_encode(['success' => true, 'tasks' => $stmt->fetchAll()]);
        exit;
    }

    // ── Verify / complete task ────────────────
    if ($action === 'verify_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $stmt   = $db->prepare("SELECT * FROM tasks WHERE id = ? AND is_active = 1");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) { echo json_encode(['success' => false, 'message' => 'টাস্ক পাওয়া যায়নি']); exit; }

        // Check already completed
        $check = $db->prepare("SELECT id FROM user_tasks WHERE user_id=? AND task_id=?");
        $check->execute([$me['id'], $taskId]);
        if ($check->fetch()) { echo json_encode(['success' => false, 'message' => 'আপনি আগেই এই টাস্কটি সম্পন্ন করেছেন']); exit; }

        // Telegram channel verification
        if ($task['type'] === 'telegram' && $task['channel_username']) {
            $channel = ltrim($task['channel_username'], '@');
            $joined  = checkTelegramMembership((int)$me['telegram_id'], $channel);
            if (!$joined) {
                echo json_encode(['success' => false, 'message' => 'আপনি এখনো চ্যানেলে জয়েন করেননি। জয়েন করে আবার চেষ্টা করুন।', 'need_join' => true]);
                exit;
            }
        }

        // Mark completed and add balance
        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO user_tasks (user_id, task_id) VALUES (?,?)")->execute([$me['id'], $taskId]);
            $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$task['points'], $me['id']]);
            addTransaction($me['id'], 'task_reward', $task['points'], 'টাস্ক সম্পন্ন: ' . $task['name']);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'ত্রুটি হয়েছে, পুনরায় চেষ্টা করুন']);
            exit;
        }

        $stmt = $db->prepare("SELECT balance FROM users WHERE id=?");
        $stmt->execute([$me['id']]);
        $newBal = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'message' => '🎉 টাস্ক সম্পন্ন! ৳' . $task['points'] . ' আপনার ব্যালেন্সে যোগ হয়েছে', 'new_balance' => $newBal, 'points' => $task['points']]);
        exit;
    }

    // ── Get transactions ──────────────────────
    if ($action === 'get_transactions') {
        $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT 50");
        $stmt->execute([$me['id']]);
        echo json_encode(['success' => true, 'transactions' => $stmt->fetchAll()]);
        exit;
    }

    // ── Get withdrawals ───────────────────────
    if ($action === 'get_withdrawals') {
        $stmt = $db->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY id DESC LIMIT 50");
        $stmt->execute([$me['id']]);
        echo json_encode(['success' => true, 'withdrawals' => $stmt->fetchAll()]);
        exit;
    }

    // ── Submit withdrawal ─────────────────────
    if ($action === 'withdraw') {
        $method  = trim($_POST['method'] ?? '');
        $account = trim($_POST['account'] ?? '');
        $amount  = (float)($_POST['amount'] ?? 0);
        $minWd   = (float)(getSetting('min_withdrawal') ?? 1100);
        $adsReq  = (int)(getSetting('ads_required') ?? 10);

        if (!$method || !$account) { echo json_encode(['success' => false, 'message' => 'পেমেন্ট তথ্য দিন']); exit; }
        if ($amount < $minWd) { echo json_encode(['success' => false, 'message' => "সর্বনিম্ন উত্তোলন ৳{$minWd}"]); exit; }
        if ($me['balance'] < $amount) { echo json_encode(['success' => false, 'message' => 'অপর্যাপ্ত ব্যালেন্স']); exit; }
        if ($me['ads_watched'] < $adsReq) { echo json_encode(['success' => false, 'message' => "উত্তোলনের জন্য কমপক্ষে {$adsReq}টি বিজ্ঞাপন দেখতে হবে। আপনি দেখেছেন: {$me['ads_watched']}/{$adsReq}"]); exit; }

        // Check pending withdrawal
        $pending = $db->prepare("SELECT id FROM withdrawals WHERE user_id=? AND status='pending'");
        $pending->execute([$me['id']]);
        if ($pending->fetch()) { echo json_encode(['success' => false, 'message' => 'আপনার একটি পেন্ডিং রিকোয়েস্ট আছে। অনুমোদনের পর পুনরায় চেষ্টা করুন।']); exit; }

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE users SET balance = balance - ? WHERE id=?")->execute([$amount, $me['id']]);
            $db->prepare("INSERT INTO withdrawals (user_id, method, account, amount) VALUES (?,?,?,?)")->execute([$me['id'], $method, $account, $amount]);
            addTransaction($me['id'], 'withdrawal', -$amount, 'উত্তোলন রিকোয়েস্ট - ' . $method);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'ত্রুটি হয়েছে']); exit;
        }

        echo json_encode(['success' => true, 'message' => '✅ উত্তোলন রিকোয়েস্ট পাঠানো হয়েছে!']);
        exit;
    }

    // ── Watch ad ─────────────────────────────
    if ($action === 'watch_ad') {
        $db->prepare("UPDATE users SET ads_watched = ads_watched + 1 WHERE id=?")->execute([$me['id']]);
        echo json_encode(['success' => true, 'ads_watched' => $me['ads_watched'] + 1]);
        exit;
    }

    // ── Leaderboard ───────────────────────────
    if ($action === 'leaderboard') {
        $stmt = $db->query("SELECT first_name, last_name, username, balance FROM users WHERE is_banned=0 ORDER BY balance DESC LIMIT 10");
        echo json_encode(['success' => true, 'leaders' => $stmt->fetchAll()]);
        exit;
    }

    // ── Get referrals ─────────────────────────
    if ($action === 'get_referrals') {
        $stmt = $db->prepare("SELECT u.first_name, u.last_name, u.username, r.created_at
            FROM referrals r JOIN users u ON u.id = r.referred_id
            WHERE r.referrer_id = ? ORDER BY r.id DESC LIMIT 50");
        $stmt->execute([$me['id']]);
        echo json_encode([
            'success'   => true,
            'referrals' => $stmt->fetchAll(),
            'ref_bonus' => getSetting('referral_bonus'),
            'ref_code'  => $me['referral_code'],
            'bot_user'  => getSetting('bot_username'),
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

function sanitizeUser(array $u): array {
    return [
        'id'            => $u['id'],
        'telegram_id'   => $u['telegram_id'],
        'username'      => $u['username'],
        'first_name'    => $u['first_name'],
        'last_name'     => $u['last_name'],
        'photo_url'     => $u['photo_url'],
        'balance'       => (float) $u['balance'],
        'bonus_balance' => (float) $u['bonus_balance'],
        'referral_code' => $u['referral_code'],
        'ads_watched'   => (int) $u['ads_watched'],
    ];
}

$adsRequired = (int)(getSetting('ads_required') ?? 10);
$minWd       = (float)(getSetting('min_withdrawal') ?? 1100);
$botUsername = getSetting('bot_username') ?? 'FastCashBD1bot';
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Rozgar Bot 💸 দ্রত টাকা 💰 ✓</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
:root{--primary:#6366f1;--accent:#a855f7;--bg:#f1f5f9;--card:#fff;--text:#1e293b;--sub:#64748b;--border:#e2e8f0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Roboto',sans-serif;background:var(--bg);min-height:100vh;color:var(--text);overflow-x:hidden}

/* ── Loading Screen ── */
#loading-screen{position:fixed;inset:0;background:radial-gradient(circle at center,#fff 0%,#fdfcf0 100%);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:opacity .6s,visibility .6s}
#loading-screen.hidden{opacity:0;visibility:hidden;pointer-events:none}
.loader-wrap{display:flex;flex-direction:column;align-items:center;width:100%;max-width:320px;padding:20px}
.logo-ring{position:relative;width:120px;height:120px;margin-bottom:40px}
.dashed-ring{position:absolute;inset:-10px;border:2.5px dashed #8b5cf6;border-radius:50%;animation:spin 10s linear infinite}
@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}
.logo-inner{width:100%;height:100%;background:linear-gradient(135deg,#6366f1,#a855f7);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 10px 30px rgba(139,92,246,.35);position:relative;z-index:2}
.logo-inner svg{width:56px;height:56px;color:#fff}
.brand{font-size:2.5rem;font-weight:900;color:#1e293b;letter-spacing:-1px;margin-bottom:4px}
.slogan{font-size:.72rem;font-weight:700;color:#94a3b8;letter-spacing:2px;text-transform:uppercase;margin-bottom:56px}
.prog-card{background:#fff;width:100%;border-radius:24px;padding:22px;box-shadow:0 20px 40px rgba(0,0,0,.06);border:1px solid #f1f5f9}
.prog-info{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:14px}
.prog-labels{display:flex;flex-direction:column;gap:3px}
.prog-status{font-size:.68rem;font-weight:800;color:#a855f7;text-transform:uppercase}
.prog-system{font-size:.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase}
.prog-pct{font-size:2.2rem;font-weight:900;color:#1e293b;line-height:1}
.prog-bar-bg{width:100%;height:10px;background:#f1f5f9;border-radius:10px;overflow:hidden}
.prog-bar-fill{height:100%;width:0;background:linear-gradient(90deg,#6366f1,#a855f7);border-radius:10px;transition:width .1s ease-out}
.loader-footer{position:absolute;bottom:32px;font-size:.68rem;font-weight:700;color:#94a3b8;letter-spacing:1px;text-transform:uppercase}

/* ── App ── */
#app{display:none;min-height:100vh;padding-bottom:80px}
#app.ready{display:block}

/* ── Top Header ── */
.top-header{position:sticky;top:0;z-40;background:#fff;border-bottom:1px solid var(--border);padding:0 16px}
.top-header-inner{display:flex;align-items:center;gap:12px;height:64px}
.user-avatar{width:50px;height:50px;border-radius:50%;border:3px solid #a855f7;background:linear-gradient(135deg,#6366f1,#a855f7);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:1.2rem;flex-shrink:0;overflow:hidden;cursor:pointer}
.user-avatar img{width:100%;height:100%;object-fit:cover}
.user-info{flex:1}
.user-name{font-size:1.1rem;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:5px}
.user-verify{color:#a855f7}
.notif-btn{padding:10px;border:none;background:none;cursor:pointer}
.notif-btn svg{width:22px;height:22px;color:#94a3b8}

/* ── Balance Card ── */
.balance-card{margin:16px;background:linear-gradient(135deg,#6366f1,#a855f7);border-radius:24px;padding:28px 24px;color:#fff;box-shadow:0 8px 24px rgba(99,102,241,.3)}
.bal-label{font-size:.85rem;font-weight:500;opacity:.9;letter-spacing:.5px}
.bal-amount{font-size:3rem;font-weight:900;margin-top:10px;letter-spacing:-1px}

/* ── Marquee ── */
.marquee-wrap{margin:0 16px;background:#fff;border-radius:16px;border:1px solid var(--border);height:52px;display:flex;align-items:center;overflow:hidden;position:relative;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.marquee-inner{white-space:nowrap;position:absolute;font-size:.875rem;will-change:transform}

/* ── Stats Grid ── */
.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:16px}
.stat-card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);transition:.2s}
.stat-card:hover{border-color:#a855f7;transform:translateY(-1px)}
.stat-icon{width:34px;height:34px;color:#8b5cf6;margin-bottom:8px}
.stat-label{font-size:.8rem;color:#64748b;margin-bottom:4px}
.stat-val{font-size:1.6rem;font-weight:800;color:#1e293b}

/* ── Section header ── */
.section-title{font-size:1.1rem;font-weight:800;color:#1e293b;padding:8px 16px 4px}

/* ── Page switcher ── */
.tab-page{display:none;padding-bottom:8px}
.tab-page.active{display:block}

/* ── Task Cards ── */
.tasks-list{padding:0 16px;display:flex;flex-direction:column;gap:12px;margin-top:8px}
.task-card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:16px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.task-logo{width:52px;height:52px;border-radius:14px;background:#f5f3ff;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center}
.task-logo img{width:100%;height:100%;object-fit:cover}
.task-info{flex:1;min-width:0}
.task-name{font-size:.95rem;font-weight:700;color:#1e293b;margin-bottom:3px}
.task-points{font-size:.82rem;color:#64748b}
.task-points strong{color:#6366f1}
.task-btn{padding:10px 18px;border:none;border-radius:12px;font-size:.85rem;font-weight:700;cursor:pointer;transition:.2s;white-space:nowrap;flex-shrink:0}
.task-btn.join{background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff}
.task-btn.join:hover{opacity:.9}
.task-btn.verify{background:#f5f3ff;color:#6366f1}
.task-btn.done{background:#f0fdf4;color:#16a34a;cursor:default}
.task-btn:disabled{opacity:.6;cursor:not-allowed}

/* ── Page header ── */
.page-header{padding:16px;font-size:1.3rem;font-weight:900;color:#1e293b;display:flex;align-items:center;gap:8px}

/* ── Wallet Page ── */
.wallet-balance-card{margin:0 16px 16px;background:linear-gradient(135deg,#6366f1,#a855f7);border-radius:20px;padding:24px;color:#fff;box-shadow:0 8px 24px rgba(99,102,241,.3);text-align:center}
.wallet-bal-label{font-size:.85rem;opacity:.9}
.wallet-bal-amt{font-size:2.6rem;font-weight:900;margin:8px 0}
.withdraw-btn{display:flex;align-items:center;justify-content:center;gap:10px;margin:0 16px 16px;padding:16px;background:linear-gradient(135deg,#6366f1,#a855f7);border-radius:16px;color:#fff;font-size:1rem;font-weight:700;cursor:pointer;border:none;width:calc(100% - 32px);box-shadow:0 4px 16px rgba(99,102,241,.3);transition:.2s}
.withdraw-btn:hover{opacity:.9;transform:translateY(-1px)}
.menu-list{margin:0 16px;display:flex;flex-direction:column;gap:10px}
.menu-item{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid var(--border);border-radius:16px;padding:16px;cursor:pointer;transition:.2s}
.menu-item:hover{border-color:#a855f7;background:#faf5ff}
.menu-icon{width:42px;height:42px;border-radius:12px;background:#f5f3ff;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.menu-icon svg{width:20px;height:20px;color:#6366f1}
.menu-text-wrap{flex:1}
.menu-title{font-size:.95rem;font-weight:700;color:#1e293b}
.menu-sub{font-size:.8rem;color:#94a3b8;margin-top:2px}
.menu-arrow{color:#cbd5e1}
.menu-item.danger{border-color:#fee2e2}.menu-item.danger .menu-title{color:#ef4444}.menu-item.danger .menu-icon{background:#fee2e2}.menu-item.danger .menu-icon svg{color:#ef4444}

/* ── Support Page ── */
.support-wrap{padding:16px}
.how-card{background:linear-gradient(135deg,#6366f1,#a855f7);border-radius:20px;padding:28px;color:#fff;text-align:center;margin-bottom:20px}
.how-title{font-size:1.3rem;font-weight:900;margin-bottom:10px}
.how-desc{font-size:.9rem;opacity:.9;line-height:1.6}
.watch-btn{display:inline-flex;align-items:center;gap:8px;background:#fff;color:#6366f1;padding:12px 24px;border-radius:12px;font-weight:700;font-size:.95rem;cursor:pointer;border:none;margin-top:18px;transition:.2s}
.watch-btn:hover{background:#f5f3ff}
.support-card{background:#ef4444;border-radius:20px;padding:28px;color:#fff;text-align:center}
.support-icon{width:60px;height:60px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
.support-icon svg{width:30px;height:30px}
.support-title{font-size:1.3rem;font-weight:900;margin-bottom:8px}
.support-desc{font-size:.85rem;opacity:.9;margin-bottom:16px}
.support-link{display:inline-flex;align-items:center;gap:8px;background:#fff;color:#ef4444;padding:12px 24px;border-radius:12px;font-weight:700;font-size:.9rem;text-decoration:none;transition:.2s}
.support-link:hover{background:#fff5f5}

/* ── Referral Page ── */
.ref-card{background:#1e293b;border-radius:20px;padding:24px;margin:0 16px 16px;color:#fff}
.ref-title{font-size:1.1rem;font-weight:800;margin-bottom:8px;display:flex;align-items:center;gap:8px}
.ref-desc{font-size:.875rem;color:#94a3b8;line-height:1.6;margin-bottom:16px}
.ref-bonus-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.08);border-radius:999px;padding:8px 16px;font-size:.85rem;font-weight:600;color:#a5b4fc;margin-bottom:16px}
.ref-link-box{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.06);border-radius:12px;padding:12px 14px;margin-bottom:16px}
.ref-link-text{flex:1;font-size:.82rem;color:#cbd5e1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.copy-icon-btn{background:linear-gradient(135deg,#6366f1,#a855f7);border:none;border-radius:10px;width:38px;height:38px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0}
.copy-icon-btn svg{width:18px;height:18px;color:#fff}
.ref-btns{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.btn-copy{background:linear-gradient(135deg,#ec4899,#a855f7);color:#fff;border:none;border-radius:12px;padding:12px;font-weight:700;font-size:.875rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:.2s}
.btn-copy:hover{opacity:.9}
.btn-share{background:linear-gradient(135deg,#6366f1,#3b82f6);color:#fff;border:none;border-radius:12px;padding:12px;font-weight:700;font-size:.875rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:.2s}
.btn-share:hover{opacity:.9}
.ref-friends-title{font-size:1rem;font-weight:800;color:#1e293b;padding:0 16px 10px;display:flex;align-items:center;justify-content:space-between}
.ref-friends-count{background:#f5f3ff;color:#6366f1;padding:4px 12px;border-radius:999px;font-size:.78rem;font-weight:700}
.ref-friend-item{display:flex;align-items:center;gap:12px;padding:12px 16px;background:#fff;border-bottom:1px solid #f8fafc}
.ref-friend-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#a855f7);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.9rem}
.ref-empty{text-align:center;padding:48px 0;color:#94a3b8}
.ref-empty svg{width:52px;height:52px;margin:0 auto 12px;display:block;color:#e2e8f0}

/* ── Modal ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:flex;align-items:flex-end;opacity:0;visibility:hidden;transition:.25s}
.modal-overlay.open{opacity:1;visibility:visible}
.modal-sheet{background:#fff;border-radius:24px 24px 0 0;width:100%;padding:24px;max-height:92vh;overflow-y:auto;transform:translateY(100%);transition:.3s cubic-bezier(.32,.72,0,1)}
.modal-overlay.open .modal-sheet{transform:translateY(0)}
.sheet-handle{width:40px;height:4px;background:#e2e8f0;border-radius:2px;margin:0 auto 20px}
.sheet-title{font-size:1.25rem;font-weight:800;margin-bottom:6px;color:#1e293b}
.sheet-close{position:absolute;top:20px;right:20px;background:none;border:none;font-size:1.2rem;cursor:pointer;color:#64748b;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:#f1f5f9}

/* Withdrawal form */
.wd-warning{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px;margin-bottom:16px;font-size:.85rem;color:#1d4ed8;line-height:1.5}
.wd-warning strong{display:block;margin-bottom:4px}
.fld-label{font-size:.85rem;font-weight:700;color:#475569;margin-bottom:6px;display:block}
.fld-input{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font-size:.95rem;outline:none;transition:.2s;background:#fff;color:#1e293b;margin-bottom:14px;font-family:inherit}
.fld-input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12)}
.fld-select{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font-size:.95rem;outline:none;appearance:none;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 12px center;cursor:pointer;color:#1e293b;margin-bottom:14px;font-family:inherit;transition:.2s}
.fld-select:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12)}
.wd-btns{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:4px}
.btn-cancel{background:#f1f5f9;color:#475569;border:none;border-radius:12px;padding:14px;font-weight:700;font-size:.95rem;cursor:pointer;transition:.2s}
.btn-cancel:hover{background:#e2e8f0}
.btn-submit{background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff;border:none;border-radius:12px;padding:14px;font-weight:700;font-size:.95rem;cursor:pointer;transition:.2s}
.btn-submit:hover{opacity:.9}
.btn-submit:disabled{opacity:.5;cursor:not-allowed}

/* ── Transaction list ── */
.tx-list{padding:0 16px;display:flex;flex-direction:column;gap:8px}
.tx-item{background:#fff;border:1px solid var(--border);border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px}
.tx-icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem}
.tx-icon.earn{background:#dcfce7}.tx-icon.spend{background:#fee2e2}
.tx-info{flex:1}
.tx-desc{font-size:.875rem;font-weight:600;color:#1e293b}
.tx-date{font-size:.75rem;color:#94a3b8;margin-top:2px}
.tx-amt{font-size:.95rem;font-weight:800}
.tx-amt.pos{color:#16a34a}.tx-amt.neg{color:#ef4444}

/* ── Leaderboard ── */
.leader-list{padding:0 16px;display:flex;flex-direction:column;gap:8px}
.leader-item{background:#fff;border:1px solid var(--border);border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px}
.leader-rank{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.9rem;flex-shrink:0;background:#f5f3ff;color:#6366f1}
.leader-rank.gold{background:#fef9c3;color:#a16207}
.leader-rank.silver{background:#f1f5f9;color:#64748b}
.leader-rank.bronze{background:#fff7ed;color:#c2410c}

/* ── Toast ── */
#toast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%) translateY(20px);background:#1e293b;color:#fff;padding:12px 22px;border-radius:14px;font-size:.875rem;font-weight:500;z-index:9999;opacity:0;transition:.3s;pointer-events:none;max-width:320px;text-align:center;white-space:pre-line}
#toast.show{transform:translateX(-50%) translateY(0);opacity:1}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}

/* ── Bottom Nav ── */
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid var(--border);padding:8px 0 10px;z-index:100;box-shadow:0 -4px 20px rgba(0,0,0,.06)}
.nav-items{display:flex;align-items:center;justify-content:space-around;max-width:480px;margin:0 auto}
.nav-btn{display:flex;flex-direction:column;align-items:center;gap:3px;color:#94a3b8;font-size:.7rem;font-weight:600;cursor:pointer;padding:4px 8px;border:none;background:none;transition:.2s;min-width:60px}
.nav-btn.active{color:#8b5cf6}
.nav-btn.active svg{color:#8b5cf6}
.nav-btn svg{width:24px;height:24px;transition:.2s}

/* ── Empty state ── */
.empty-state{text-align:center;padding:60px 20px;color:#94a3b8}
.empty-state svg{width:60px;height:60px;margin:0 auto 14px;display:block;color:#e2e8f0}
.empty-state p{font-size:.9rem}
</style>
</head>
<body>

<!-- Loading Screen -->
<div id="loading-screen">
  <div class="loader-wrap">
    <div class="logo-ring">
      <div class="dashed-ring"></div>
      <div class="logo-inner">
        <svg fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.82v-1.91c-.39-.05-.77-.13-1.14-.24l-.19-.06-1.12.83-1.66-1.66.83-1.12-.06-.19c-.15-.46-.24-.94-.27-1.44H5.18v-2.35h1.91c.05-.39.13-.77.24-1.14l.06-.19-.83-1.12 1.66-1.66 1.12.83.19-.06c.46-.15.94-.24 1.44-.27V5.18h2.35v1.91c.39.05.77.13 1.14.24l.19.06 1.12-.83 1.66 1.66-.83 1.12.06.19c.15.46.24.94.27 1.44h1.91v2.35h-1.91c-.05.39-.13.77-.24 1.14l-.06.19.83 1.12-1.66 1.66-1.12-.83-.19.06c-.46.15-.94.24-1.44.27zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z"/></svg>
      </div>
    </div>
    <div class="brand">Rozgar Bot</div>
    <div class="slogan">Secure Earning Interface</div>
    <div class="prog-card">
      <div class="prog-info">
        <div class="prog-labels">
          <span class="prog-status" id="loadStatus">INITIALIZING...</span>
          <span class="prog-system">SYSTEM CHECK</span>
        </div>
        <div class="prog-pct" id="loadPct">0%</div>
      </div>
      <div class="prog-bar-bg"><div class="prog-bar-fill" id="loadBar"></div></div>
    </div>
  </div>
  <div class="loader-footer">© 2026 Rozgar Bot</div>
</div>

<!-- App -->
<div id="app">
  <!-- Header -->
  <div class="top-header">
    <div class="top-header-inner">
      <div class="user-avatar" id="userAvatar">?</div>
      <div class="user-info">
        <div class="user-name" id="userName">লোড হচ্ছে... <span class="user-verify">✓</span></div>
      </div>
      <button class="notif-btn"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg></button>
    </div>
  </div>

  <!-- ── Home Tab ── -->
  <div class="tab-page active" id="tab-home">
    <!-- Balance -->
    <div class="balance-card">
      <div class="bal-label">মোট ব্যালেন্স</div>
      <div class="bal-amount" id="homeBalance">৳0.00</div>
    </div>
    <!-- Marquee -->
    <div class="marquee-wrap"><div class="marquee-inner" id="marqueeText">লোড হচ্ছে...</div></div>
    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card">
        <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        <div class="stat-label">মোট টাস্ক</div>
        <div class="stat-val" id="statTasks">0</div>
      </div>
      <div class="stat-card">
        <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        <div class="stat-label">মোট রেফার</div>
        <div class="stat-val" id="statRefs">0</div>
      </div>
      <div class="stat-card">
        <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
        <div class="stat-label">বিজ্ঞাপন দেখা</div>
        <div class="stat-val" id="statAds">0</div>
      </div>
      <div class="stat-card">
        <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <div class="stat-label">বোনাস ব্যালেন্স</div>
        <div class="stat-val" id="statBonus">৳0.00</div>
      </div>
    </div>
  </div>

  <!-- ── Task Tab ── -->
  <div class="tab-page" id="tab-tasks">
    <div class="page-header">📋 কাজ</div>
    <div class="tasks-list" id="tasksList">
      <div class="empty-state"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg><p>টাস্ক লোড হচ্ছে...</p></div>
    </div>
  </div>

  <!-- ── Support Tab ── -->
  <div class="tab-page" id="tab-support">
    <div class="page-header">📡 সাপোর্ট</div>
    <div class="support-wrap">
      <div style="font-size:1rem;font-weight:800;color:#1e293b;margin-bottom:12px">কাজের ভিডিও / টিউটোরিয়াল</div>
      <div class="how-card">
        <div class="how-title">কিভাবে কাজ করবেন?</div>
        <div class="how-desc">কাজ শুরু করার আগে এই ভিডিওটি দেখে নিন। ভিডিওতে সম্পূর্ণভাবে দেখানো হয়েছে কিভাবে সঠিকভাবে কাজ করবেন।</div>
        <button class="watch-btn" id="watchVideoBtn" onclick="watchTutorial()">📺 ভিডিওটি দেখুন</button>
      </div>
      <div style="font-size:1rem;font-weight:800;color:#1e293b;margin-bottom:12px">সাহায্য প্রয়োজন?</div>
      <div class="support-card">
        <div class="support-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg></div>
        <div class="support-title">কাস্টমার সাপোর্ট</div>
        <div class="support-desc">যেকোনো সমস্যা সমাধানের জন্য আমাদের সাথে যোগাযোগ করুন</div>
        <a id="supportLink" href="#" class="support-link" target="_blank">
          <svg fill="currentColor" viewBox="0 0 24 24" width="18" height="18"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
          টেলিগ্রামে যোগাযোগ করুন
        </a>
      </div>
    </div>
  </div>

  <!-- ── Referral Tab ── -->
  <div class="tab-page" id="tab-refer">
    <div class="page-header">🎁 রেফার</div>
    <div class="ref-card">
      <div class="ref-title">🎁 বন্ধুকে রেফার করুন এবং আয় করুন</div>
      <div class="ref-desc">আপনার রেফার লিংকটি শেয়ার করুন এবং আপনার বন্ধুদের আমন্ত্রণ করুন। আপনার বন্ধু সাইন আপ করলে আপনি <strong id="refBonusAmt">100.00</strong> TAKA বোনাস পাবেন।</div>
      <div class="ref-bonus-badge">😊 প্রতি রেফারে <span id="refBonusBadge">100.00</span> TAKA বোনাস</div>
      <div class="ref-link-box">
        <div class="ref-link-text" id="refLinkText">লোড হচ্ছে...</div>
        <button class="copy-icon-btn" onclick="copyRefLink()"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg></button>
      </div>
      <div class="ref-btns">
        <button class="btn-copy" onclick="copyRefLink()">📋 লিংক কপি করুন</button>
        <button class="btn-share" onclick="shareRefLink()">↗️ শেয়ার করুন</button>
      </div>
    </div>
    <div class="ref-friends-title">
      রেফার করা বন্ধুদের তালিকা
      <span class="ref-friends-count" id="refFriendsCount">মোট: 0</span>
    </div>
    <div id="refFriendsList">
      <div class="ref-empty"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg><p>এখনো কোনো রেফার নেই</p></div>
    </div>
  </div>

  <!-- ── Wallet Tab ── -->
  <div class="tab-page" id="tab-wallet">
    <div class="page-header">💳 ওয়ালেট</div>
    <div class="wallet-balance-card">
      <div class="wallet-bal-label">মোট ব্যালেন্স</div>
      <div class="wallet-bal-amt" id="walletBalance">৳0.00</div>
    </div>
    <button class="withdraw-btn" onclick="openWithdrawModal()">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="22" height="22"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      উত্তোলন করুন
    </button>
    <div class="menu-list">
      <div class="menu-item" onclick="showTxHistory()">
        <div class="menu-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
        <div class="menu-text-wrap"><div class="menu-title">লেনদেনের ইতিহাস</div><div class="menu-sub">আপনার সমস্ত আয়ের রেকর্ড দেখুন</div></div>
        <svg class="menu-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      </div>
      <div class="menu-item" onclick="showWdHistory()">
        <div class="menu-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg></div>
        <div class="menu-text-wrap"><div class="menu-title">উত্তোলনের ইতিহাস</div><div class="menu-sub">টাকা তোলার রেকর্ড দেখুন</div></div>
        <svg class="menu-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      </div>
      <div class="menu-item" onclick="showLeaderboard()">
        <div class="menu-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg></div>
        <div class="menu-text-wrap"><div class="menu-title">লিডারবোর্ড</div><div class="menu-sub">সেরা ১০ জন আয়কারীকে দেখুন</div></div>
        <svg class="menu-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      </div>
      <div class="menu-item danger" onclick="doLogout()">
        <div class="menu-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg></div>
        <div class="menu-text-wrap"><div class="menu-title">লগআউট</div><div class="menu-sub">অ্যাকাউন্ট থেকে বিদায় নিন</div></div>
        <svg class="menu-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      </div>
    </div>
  </div>

</div><!-- /#app -->

<!-- ── Bottom Navigation ── -->
<div class="bottom-nav">
  <div class="nav-items">
    <button class="nav-btn active" id="nav-home" onclick="switchTab('home')">
      <svg fill="currentColor" viewBox="0 0 24 24"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.707.707a1 1 0 001.414-1.414l-7-7z"/></svg>
      হোম
    </button>
    <button class="nav-btn" id="nav-tasks" onclick="switchTab('tasks')">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
      কাজ
    </button>
    <button class="nav-btn" id="nav-support" onclick="switchTab('support')">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m-2.829-2.829a5 5 0 000-7.07M3 18v-6a9 9 0 0118 0v6M21 19a2 2 0 01-2 2h-1a2 2 0 01-2-2v-3a2 2 0 012-2h3zM3 19a2 2 0 002 2h1a2 2 0 002-2v-3a2 2 0 00-2-2H3z"/></svg>
      সাপোর্ট
    </button>
    <button class="nav-btn" id="nav-refer" onclick="switchTab('refer')">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
      রেফার
    </button>
    <button class="nav-btn" id="nav-wallet" onclick="switchTab('wallet')">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H5a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
      ওয়ালেট
    </button>
  </div>
</div>

<!-- ── Withdrawal Modal ── -->
<div class="modal-overlay" id="wdModal">
  <div class="modal-sheet" style="position:relative">
    <div class="sheet-handle"></div>
    <button class="sheet-close" onclick="closeModal('wdModal')">✕</button>
    <div class="sheet-title">উত্তোলন করুন</div>
    <div id="wdWarning" class="wd-warning">
      <strong>⚠️ বিজ্ঞাপন দেখার রিকোয়ারমেন্ট</strong>
      উত্তোলনের জন্য আপনাকে অন্তত <span id="wdAdsReq"><?= $adsRequired ?></span>টি বিজ্ঞাপন দেখতে হবে।
      বর্তমানে আপনি দেখেছেন: <strong id="wdAdsWatched">0</strong>/<span><?= $adsRequired ?></span>। বাকি আছে: <strong id="wdAdsLeft"><?= $adsRequired ?></strong>।
    </div>
    <label class="fld-label">পেমেন্ট মেথড</label>
    <select class="fld-select" id="wdMethod">
      <option value="">-- মেথড বেছে নিন --</option>
      <option value="বিকাশ [ BKASH ]">বিকাশ [ BKASH ]</option>
      <option value="নগদ [ NAGAD ]">নগদ [ NAGAD ]</option>
      <option value="রকেট [ ROCKET ]">রকেট [ ROCKET ]</option>
      <option value="USDT [ TRC20 ]">USDT [ TRC20 ]</option>
      <option value="Binance ID">Binance ID</option>
    </select>
    <label class="fld-label">উত্তোলন নাম্বার / Binance ID</label>
    <input type="text" class="fld-input" id="wdAccount" placeholder="আপনার ফোন নম্বর বা অ্যাড্রেস লিখুন">
    <label class="fld-label">পরিমাণ (৳)</label>
    <input type="number" class="fld-input" id="wdAmount" placeholder="নূন্যতম ৳<?= $minWd ?>" min="<?= $minWd ?>" step="1">
    <div class="wd-btns">
      <button class="btn-cancel" onclick="closeModal('wdModal')">বাতিল</button>
      <button class="btn-submit" id="wdSubmitBtn" onclick="submitWithdrawal()">অনুরোধ পাঠান</button>
    </div>
  </div>
</div>

<!-- ── Transaction History Modal ── -->
<div class="modal-overlay" id="txModal">
  <div class="modal-sheet" style="position:relative;max-height:85vh;overflow-y:auto">
    <div class="sheet-handle"></div>
    <button class="sheet-close" onclick="closeModal('txModal')">✕</button>
    <div class="sheet-title" style="margin-bottom:16px">📜 লেনদেনের ইতিহাস</div>
    <div id="txList" class="tx-list"></div>
  </div>
</div>

<!-- ── Withdrawal History Modal ── -->
<div class="modal-overlay" id="wdHistModal">
  <div class="modal-sheet" style="position:relative;max-height:85vh;overflow-y:auto">
    <div class="sheet-handle"></div>
    <button class="sheet-close" onclick="closeModal('wdHistModal')">✕</button>
    <div class="sheet-title" style="margin-bottom:16px">📊 উত্তোলনের ইতিহাস</div>
    <div id="wdHistList" class="tx-list"></div>
  </div>
</div>

<!-- ── Leaderboard Modal ── -->
<div class="modal-overlay" id="lbModal">
  <div class="modal-sheet" style="position:relative">
    <div class="sheet-handle"></div>
    <button class="sheet-close" onclick="closeModal('lbModal')">✕</button>
    <div class="sheet-title" style="margin-bottom:16px">🏆 লিডারবোর্ড - সেরা ১০</div>
    <div id="lbList" class="leader-list"></div>
  </div>
</div>

<div id="toast"></div>

<script>
// ════════════════════════════════════════
//  CONFIG
// ════════════════════════════════════════
const ADS_REQUIRED = <?= $adsRequired ?>;
const MIN_WD       = <?= $minWd ?>;
const BOT_USERNAME = '<?= $botUsername ?>';

// ════════════════════════════════════════
//  STATE
// ════════════════════════════════════════
let currentUser  = null;
let currentTab   = 'home';
let tasksLoaded  = false;
let referLoaded  = false;
let refLink      = '';

// ════════════════════════════════════════
//  INIT / AUTH
// ════════════════════════════════════════
const tg = window.Telegram?.WebApp;

document.addEventListener('DOMContentLoaded', async () => {
  // Loading animation
  const loadBar  = document.getElementById('loadBar');
  const loadPct  = document.getElementById('loadPct');
  const loadSt   = document.getElementById('loadStatus');
  const loader   = document.getElementById('loading-screen');
  const msgs     = ['CONNECTING...','LOADING USER DATA...','ENCRYPTING SESSION...','ALMOST READY...'];

  let pct = 0;
  const iv = setInterval(() => {
    pct += Math.random() * 6;
    if (pct > 100) pct = 100;
    loadBar.style.width = pct + '%';
    loadPct.textContent = Math.floor(pct) + '%';
    loadSt.textContent = pct < 25 ? msgs[0] : pct < 55 ? msgs[1] : pct < 85 ? msgs[2] : msgs[3];
    if (pct >= 100) clearInterval(iv);
  }, 60);

  // Telegram WebApp setup
  if (tg) { tg.expand(); tg.ready(); }

  // Get ref code from URL
  const params  = new URLSearchParams(window.location.search);
  const refCode = params.get('ref') || '';

  // Authenticate
  const initData = tg?.initData || '';
  if (!initData && !<?= json_encode(!empty($_SESSION['user_id'])) ?>) {
    // Dev/debug mode — allow without initData (optional remove in production)
    setTimeout(() => {
      loader.classList.add('hidden');
      document.getElementById('app').classList.add('ready');
      loadProfile();
    }, 2000);
    return;
  }

  if (<?= json_encode(!empty($_SESSION['user_id'])) ?>) {
    // Already authenticated
    setTimeout(() => { loader.classList.add('hidden'); document.getElementById('app').classList.add('ready'); loadProfile(); }, 1500);
    return;
  }

  // Auth via Telegram initData
  try {
    const fd = new FormData();
    fd.append('action', 'auth');
    fd.append('initData', initData);
    fd.append('ref', refCode);
    const r = await fetch('index.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
      currentUser = d.user;
      setTimeout(() => {
        loader.classList.add('hidden');
        document.getElementById('app').classList.add('ready');
        loadProfile();
      }, 1200);
    } else {
      loadPct.textContent = 'ERROR';
      loadSt.textContent  = 'AUTH FAILED';
    }
  } catch (e) {
    console.error(e);
    setTimeout(() => { loader.classList.add('hidden'); document.getElementById('app').classList.add('ready'); loadProfile(); }, 2000);
  }
});

// ════════════════════════════════════════
//  API
// ════════════════════════════════════════
async function api(data) {
  const fd = new FormData();
  Object.keys(data).forEach(k => fd.append(k, data[k]));
  const r = await fetch('index.php', { method: 'POST', body: fd });
  return r.json();
}

// ════════════════════════════════════════
//  LOAD PROFILE
// ════════════════════════════════════════
async function loadProfile() {
  const d = await api({ action: 'get_profile' });
  if (!d.success) { toast('প্রোফাইল লোড ব্যর্থ হয়েছে', 'error'); return; }
  const u = d.user;
  currentUser = u;

  // Header
  const avEl = document.getElementById('userAvatar');
  if (u.photo_url) {
    avEl.innerHTML = `<img src="${esc(u.photo_url)}" alt="avatar">`;
  } else {
    avEl.textContent = (u.first_name || u.username || '?')[0].toUpperCase();
  }
  document.getElementById('userName').innerHTML = `${esc(u.first_name || u.username || 'User')} <span class="user-verify">✓</span>`;

  // Home stats
  document.getElementById('homeBalance').textContent = '৳' + parseFloat(u.balance).toFixed(2);
  document.getElementById('walletBalance').textContent = '৳' + parseFloat(u.balance).toFixed(2);
  document.getElementById('statTasks').textContent = d.total_tasks;
  document.getElementById('statRefs').textContent  = d.total_refs;
  document.getElementById('statAds').textContent   = u.ads_watched;
  document.getElementById('statBonus').textContent = '৳' + parseFloat(u.bonus_balance).toFixed(2);

  // Marquee
  startMarquee(d.marquee_text || '🎉 Rozgar Bot এ স্বাগতম!');

  // Referral link
  refLink = `https://t.me/${BOT_USERNAME}?start=${u.referral_code}`;
  document.getElementById('refLinkText').textContent = refLink;
  document.getElementById('refBonusAmt').textContent = parseFloat(d.ref_bonus || 100).toFixed(2);
  document.getElementById('refBonusBadge').textContent = parseFloat(d.ref_bonus || 100).toFixed(2);

  // Wd modal ads counter
  document.getElementById('wdAdsWatched').textContent = u.ads_watched;
  const left = Math.max(0, ADS_REQUIRED - u.ads_watched);
  document.getElementById('wdAdsLeft').textContent = left;

  // Support link
  if (d.support_username) {
    document.getElementById('supportLink').href = 'https://t.me/' + d.support_username.replace('@','');
  }
}

// ════════════════════════════════════════
//  MARQUEE
// ════════════════════════════════════════
function startMarquee(text) {
  const el   = document.getElementById('marqueeText');
  const wrap = el.parentElement;
  el.innerHTML = `🎉 <span style="color:#f97316;font-weight:700">${esc(text.split('***')[0]||'')}</span> ${esc(text)}`;

  function run() {
    const sw = wrap.offsetWidth;
    const cw = el.offsetWidth;
    el.style.transition = 'none';
    el.style.transform  = `translateX(${sw}px)`;
    void el.offsetHeight;
    const dur = 7000 + Math.random() * 2000;
    el.style.transition = `transform ${dur}ms linear`;
    el.style.transform  = `translateX(${-cw}px)`;
    setTimeout(run, dur);
  }
  run();
}

// ════════════════════════════════════════
//  TAB SWITCHING
// ════════════════════════════════════════
function switchTab(tab) {
  document.querySelectorAll('.tab-page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  document.getElementById('nav-' + tab).classList.add('active');
  currentTab = tab;

  if (tab === 'tasks' && !tasksLoaded) { loadTasks(); tasksLoaded = true; }
  if (tab === 'refer' && !referLoaded) { loadReferrals(); referLoaded = true; }
  if (tab === 'wallet') { updateWalletDisplay(); }
}

// ════════════════════════════════════════
//  TASKS
// ════════════════════════════════════════
async function loadTasks() {
  const d = await api({ action: 'get_tasks' });
  const list = document.getElementById('tasksList');
  if (!d.success || !d.tasks.length) {
    list.innerHTML = `<div class="empty-state"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg><p>এখনো কোনো টাস্ক নেই</p></div>`;
    return;
  }

  list.innerHTML = d.tasks.map(t => {
    const done = t.completed == 1;
    const logoFallback = `data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'><rect fill='%236366f1' width='40' height='40' rx='10'/><text y='26' x='10' fill='white' font-size='18'>T</text></svg>`;
    let btnHtml;
    if (done) {
      btnHtml = `<button class="task-btn done">✅ সম্পন্ন</button>`;
    } else if (t.type === 'telegram') {
      btnHtml = `<button class="task-btn join" onclick="joinTelegram(${t.id},'${esc(t.link)}','${esc(t.channel_username||'')}')">জেযন করুন</button>`;
    } else {
      // Check if user already opened
      const opened = localStorage.getItem('task_opened_' + t.id);
      if (opened) {
        btnHtml = `<button class="task-btn verify" onclick="verifyTask(${t.id},this)">সংগ্রহ করুন</button>`;
      } else {
        btnHtml = `<button class="task-btn join" onclick="startTask(${t.id},'${esc(t.link)}',this)">শুরু করুন</button>`;
      }
    }
    return `
    <div class="task-card" id="task-card-${t.id}">
      <div class="task-logo"><img src="${esc(t.logo)}" onerror="this.src='${logoFallback}'" alt="logo"></div>
      <div class="task-info">
        <div class="task-name">${esc(t.name)}</div>
        <div class="task-points">পুরস্কার: <strong>৳${parseFloat(t.points).toFixed(2)}</strong></div>
      </div>
      ${btnHtml}
    </div>`;
  }).join('');
}

// General task: open link then show verify
function startTask(taskId, link, btn) {
  window.open(link, '_blank');
  localStorage.setItem('task_opened_' + taskId, '1');
  btn.textContent = 'সংগ্রহ করুন';
  btn.className   = 'task-btn verify';
  btn.setAttribute('onclick', `verifyTask(${taskId},this)`);
}

// Telegram join task
function joinTelegram(taskId, link, channel) {
  window.open(link, '_blank');
  // Show verify after delay
  setTimeout(() => {
    const card = document.getElementById('task-card-' + taskId);
    if (card) {
      const btn = card.querySelector('.task-btn');
      if (btn && !btn.classList.contains('done')) {
        btn.textContent = 'যাচাই করুন';
        btn.className   = 'task-btn verify';
        btn.setAttribute('onclick', `verifyTask(${taskId},this)`);
      }
    }
  }, 3000);
}

async function verifyTask(taskId, btn) {
  btn.disabled    = true;
  btn.textContent = 'যাচাই হচ্ছে...';
  const d = await api({ action: 'verify_task', task_id: taskId });
  if (d.success) {
    btn.textContent = '✅ সম্পন্ন';
    btn.className   = 'task-btn done';
    btn.disabled    = false;
    toast('🎉 ' + d.message, 'success');
    // Update balance display
    if (currentUser) {
      currentUser.balance = parseFloat(d.new_balance);
      document.getElementById('homeBalance').textContent  = '৳' + parseFloat(d.new_balance).toFixed(2);
      document.getElementById('walletBalance').textContent = '৳' + parseFloat(d.new_balance).toFixed(2);
      document.getElementById('statTasks').textContent    = parseInt(document.getElementById('statTasks').textContent) + 1;
    }
    localStorage.removeItem('task_opened_' + taskId);
  } else {
    toast(d.message, 'error');
    btn.disabled    = false;
    btn.textContent = d.need_join ? 'জেযন করুন' : 'আবার চেষ্টা করুন';
    btn.className   = d.need_join ? 'task-btn join' : 'task-btn verify';
  }
}

// ════════════════════════════════════════
//  REFERRALS
// ════════════════════════════════════════
async function loadReferrals() {
  const d = await api({ action: 'get_referrals' });
  if (!d.success) return;
  document.getElementById('refFriendsCount').textContent = 'মোট: ' + d.referrals.length;
  const list = document.getElementById('refFriendsList');
  if (!d.referrals.length) {
    list.innerHTML = `<div class="ref-empty"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg><p>এখনো কোনো রেফার নেই</p></div>`;
    return;
  }
  list.innerHTML = d.referrals.map(r => `
    <div class="ref-friend-item">
      <div class="ref-friend-avatar">${(r.first_name || r.username || '?')[0].toUpperCase()}</div>
      <div style="flex:1"><div style="font-weight:600;font-size:.875rem">${esc(r.first_name||'')} ${esc(r.last_name||'')}</div><div style="font-size:.75rem;color:#94a3b8">@${esc(r.username||'N/A')}</div></div>
      <div style="font-size:.75rem;color:#94a3b8">${r.created_at.split(' ')[0]}</div>
    </div>
  `).join('');
}

function copyRefLink() {
  if (!refLink) return;
  if (navigator.clipboard) {
    navigator.clipboard.writeText(refLink).then(() => toast('✅ লিংক কপি হয়েছে!', 'success'));
  } else {
    const ta = document.createElement('textarea');
    ta.value = refLink; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select(); document.execCommand('copy');
    document.body.removeChild(ta); toast('✅ লিংক কপি হয়েছে!', 'success');
  }
}

function shareRefLink() {
  if (!refLink) return;
  if (tg?.shareUrl) {
    tg.shareUrl(refLink, 'Rozgar Bot - দ্রুত টাকা উপার্জন করুন! আমার রেফার লিংক ব্যবহার করে যোগ দিন। 💰');
  } else if (navigator.share) {
    navigator.share({ title: 'Rozgar Bot', text: 'দ্রুত টাকা উপার্জন করুন!', url: refLink });
  } else {
    copyRefLink();
  }
}

// ════════════════════════════════════════
//  WITHDRAWAL
// ════════════════════════════════════════
function updateWalletDisplay() {
  if (currentUser) {
    document.getElementById('walletBalance').textContent = '৳' + parseFloat(currentUser.balance).toFixed(2);
    document.getElementById('wdAdsWatched').textContent  = currentUser.ads_watched;
    document.getElementById('wdAdsLeft').textContent     = Math.max(0, ADS_REQUIRED - currentUser.ads_watched);
  }
}

function openWithdrawModal() {
  updateWalletDisplay();
  openModal('wdModal');
}

async function submitWithdrawal() {
  const method  = document.getElementById('wdMethod').value;
  const account = document.getElementById('wdAccount').value.trim();
  const amount  = parseFloat(document.getElementById('wdAmount').value);
  const btn     = document.getElementById('wdSubmitBtn');

  if (!method) { toast('পেমেন্ট মেথড বেছে নিন', 'error'); return; }
  if (!account) { toast('অ্যাকাউন্ট নম্বর দিন', 'error'); return; }
  if (!amount || amount < MIN_WD) { toast(`সর্বনিম্ন উত্তোলন ৳${MIN_WD}`, 'error'); return; }

  btn.disabled = true; btn.textContent = 'পাঠানো হচ্ছে...';
  const d = await api({ action: 'withdraw', method, account, amount });
  btn.disabled = false; btn.textContent = 'অনুরোধ পাঠান';

  if (d.success) {
    toast(d.message, 'success');
    closeModal('wdModal');
    if (currentUser) { currentUser.balance -= amount; updateWalletDisplay(); }
    document.getElementById('homeBalance').textContent = '৳' + parseFloat(currentUser?.balance || 0).toFixed(2);
  } else {
    toast(d.message, 'error');
  }
}

// ════════════════════════════════════════
//  TRANSACTION / WD HISTORY
// ════════════════════════════════════════
async function showTxHistory() {
  openModal('txModal');
  const d = await api({ action: 'get_transactions' });
  const list = document.getElementById('txList');
  if (!d.success || !d.transactions.length) { list.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:40px">কোনো লেনদেন নেই</p>'; return; }
  list.innerHTML = d.transactions.map(t => {
    const pos = t.amount >= 0;
    return `<div class="tx-item">
      <div class="tx-icon ${pos ? 'earn' : 'spend'}">${pos ? '💰' : '📤'}</div>
      <div class="tx-info"><div class="tx-desc">${esc(t.description||t.type)}</div><div class="tx-date">${t.created_at}</div></div>
      <div class="tx-amt ${pos ? 'pos' : 'neg'}">${pos ? '+' : ''}৳${parseFloat(t.amount).toFixed(2)}</div>
    </div>`;
  }).join('');
}

async function showWdHistory() {
  openModal('wdHistModal');
  const d = await api({ action: 'get_withdrawals' });
  const list = document.getElementById('wdHistList');
  if (!d.success || !d.withdrawals.length) { list.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:40px">কোনো উত্তোলন নেই</p>'; return; }
  const stMap = { pending: '⏳ পেন্ডিং', approved: '✅ অনুমোদিত', rejected: '❌ প্রত্যাখ্যাত' };
  list.innerHTML = d.withdrawals.map(w => `
    <div class="tx-item">
      <div class="tx-icon spend">📤</div>
      <div class="tx-info"><div class="tx-desc">${esc(w.method)} • ${esc(w.account)}</div><div class="tx-date">${w.created_at}</div></div>
      <div style="text-align:right"><div class="tx-amt neg">৳${parseFloat(w.amount).toFixed(2)}</div><div style="font-size:.72rem;color:#64748b">${stMap[w.status]}</div></div>
    </div>
  `).join('');
}

async function showLeaderboard() {
  openModal('lbModal');
  const d = await api({ action: 'leaderboard' });
  const list = document.getElementById('lbList');
  if (!d.success || !d.leaders.length) { list.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:40px">কোনো ডেটা নেই</p>'; return; }
  const rankClass = (i) => i === 0 ? 'gold' : i === 1 ? 'silver' : i === 2 ? 'bronze' : '';
  const medals   = ['🥇','🥈','🥉'];
  list.innerHTML = d.leaders.map((l, i) => `
    <div class="leader-item">
      <div class="leader-rank ${rankClass(i)}">${medals[i] || (i+1)}</div>
      <div style="flex:1"><div style="font-weight:700;font-size:.9rem">${esc(l.first_name||'')} ${esc(l.last_name||'')}</div><div style="font-size:.75rem;color:#94a3b8">@${esc(l.username||'N/A')}</div></div>
      <div style="font-weight:800;color:#6366f1">৳${parseFloat(l.balance).toFixed(2)}</div>
    </div>
  `).join('');
}

// ════════════════════════════════════════
//  SUPPORT
// ════════════════════════════════════════
function watchTutorial() {
  const btn = document.getElementById('watchVideoBtn');
  const url = btn.dataset.url;
  if (url) window.open(url, '_blank');
  else toast('ভিডিও লিংক এখনো যোগ করা হয়নি', 'error');
}

// Fetch tutorial URL
(async () => {
  const d = await api({ action: 'get_profile' }).catch(() => null);
  if (d?.success) {
    const tutBtn = document.getElementById('watchVideoBtn');
    const sup    = document.getElementById('supportLink');
    if (d.tutorial_video) tutBtn.dataset.url = d.tutorial_video;
    if (d.support_username) sup.href = 'https://t.me/' + (d.support_username||'').replace('@','');
  }
})();

// ════════════════════════════════════════
//  LOGOUT
// ════════════════════════════════════════
function doLogout() {
  if (!confirm('লগআউট করবেন?')) return;
  fetch('index.php?action=logout').then(() => {
    sessionStorage.clear();
    if (tg) tg.close();
    else window.location.reload();
  });
}

// ════════════════════════════════════════
//  MODAL
// ════════════════════════════════════════
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ════════════════════════════════════════
//  TOAST
// ════════════════════════════════════════
function toast(msg, type = '') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = 'show ' + type;
  setTimeout(() => t.className = '', 3500);
}

// ════════════════════════════════════════
//  UTILITY
// ════════════════════════════════════════
function esc(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ════════════════════════════════════════
//  Logout action handler
// ════════════════════════════════════════
<?php
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}
?>
</script>
</body>
</html>
