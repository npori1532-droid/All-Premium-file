<?php
/**
 * নেপা-দা বট v2.0 - জটিল সংস্করণ
 * 
 * নতুন ক্ষমতা:
 * - গ্রুপে শুধু মেনশন/রিপ্লাই পেলেই উত্তর
 * - ইউজারকে নাম ধরে মেনশন করে উত্তর
 * - কথোপকথনের প্রসঙ্গ মনে রাখে (শেষ ৫ মেসেজ)
 * - রেট লিমিটিং (স্প্যাম প্রতিরোধ)
 * - বিস্তারিত ডিবাগিং
 */

// ======================== কনফিগারেশন ========================
define('BOT_TOKEN', '7917109526:AAEBVrhPXIw1aoMU2rOBZAAA_AR5z7E');
define('ADMIN_ID', '6743390968');
define('BOT_USERNAME', '@nepa_da_bot'); // ← আপনার বটের ইউজারনেম এখানে বসান! যেমন: @NepaDaBot
define('DB_FILE', __DIR__ . '/nepa_v2.sqlite');
define('AI_API_URL', 'https://api-rebix.vercel.app/api/gpt-5');
define('CONTEXT_LIMIT', 5);                   // কতগুলো আগের মেসেজ প্রসঙ্গ হিসেবে পাঠাবে
define('RATE_LIMIT_SECONDS', 10);             // এক ইউজার কত সেকেন্ড পর পর প্রশ্ন করতে পারবে
define('ENABLE_DEBUG', true);                 // ডিবাগ লগিং চালু/বন্ধ

// ======================== অটোলোডার ========================
spl_autoload_register(function ($class) {
    if (file_exists(__DIR__ . "/classes/$class.php")) {
        require_once __DIR__ . "/classes/$class.php";
    }
});

// ======================== সহায়ক ফাংশন ========================
function debugLog($msg) {
    if (!ENABLE_DEBUG) return;
    $log = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/debug.log', $log, FILE_APPEND | LOCK_EX);
}

// ======================== SQLite ডাটাবেজ ম্যানেজার ========================
class Database {
    private SQLite3 $db;
    
    public function __construct() {
        $this->db = new SQLite3(DB_FILE);
        $this->db->enableExceptions(true);
        $this->initTables();
    }
    
    private function initTables(): void {
        // মেসেজ হিস্টোরি
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS message_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id TEXT NOT NULL,
                user_id TEXT NOT NULL,
                user_name TEXT,
                message_text TEXT,
                bot_reply TEXT,
                chat_type TEXT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        // প্রসঙ্গ ক্যাশে (শেষ N মেসেজ)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS context_cache (
                chat_id TEXT PRIMARY KEY,
                context_json TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        // ইউজার প্রোফাইল
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                user_id TEXT PRIMARY KEY,
                first_name TEXT,
                username TEXT,
                interaction_count INTEGER DEFAULT 0,
                last_interaction DATETIME,
                rate_limit_until INTEGER DEFAULT 0
            )
        ");
        // ইন্ডেক্স
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_history_chat ON message_history(chat_id)");
    }
    
    // প্রসঙ্গ সংরক্ষণ
    public function updateContext(string $chat_id, array $messages): void {
        $json = json_encode($messages, JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO context_cache (chat_id, context_json, updated_at)
            VALUES (:chat_id, :json, CURRENT_TIMESTAMP)
        ");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $stmt->bindValue(':json', $json, SQLITE3_TEXT);
        $stmt->execute();
    }
    
    // প্রসঙ্গ পাওয়া
    public function getContext(string $chat_id): array {
        $stmt = $this->db->prepare("SELECT context_json FROM context_cache WHERE chat_id = :chat_id");
        $stmt->bindValue(':chat_id', $chat_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row && $row['context_json']) {
            return json_decode($row['context_json'], true) ?? [];
        }
        return [];
    }
    
    // মেসেজ লগ ও প্রসঙ্গ আপডেট
    public function logAndUpdateContext(string $chat_id, string $user_id, string $user_name, 
                                        string $user_msg, string $bot_reply, string $chat_type): void {
        // ইতিহাসে সংরক্ষণ
        $stmt = $this->db->prepare("
            INSERT INTO message_history (chat_id, user_id, user_name, message_text, bot_reply, chat_type)
            VALUES (:cid, :uid, :uname, :umsg, :breply, :ctype)
        ");
        $stmt->bindValue(':cid', $chat_id, SQLITE3_TEXT);
        $stmt->bindValue(':uid', $user_id, SQLITE3_TEXT);
        $stmt->bindValue(':uname', $user_name, SQLITE3_TEXT);
        $stmt->bindValue(':umsg', $user_msg, SQLITE3_TEXT);
        $stmt->bindValue(':breply', $bot_reply, SQLITE3_TEXT);
        $stmt->bindValue(':ctype', $chat_type, SQLITE3_TEXT);
        $stmt->execute();
        
        // প্রসঙ্গ আপডেট
        $context = $this->getContext($chat_id);
        $context[] = ["role" => "user", "content" => $user_msg];
        $context[] = ["role" => "assistant", "content" => $bot_reply];
        if (count($context) > CONTEXT_LIMIT * 2) {
            $context = array_slice($context, -CONTEXT_LIMIT * 2);
        }
        $this->updateContext($chat_id, $context);
    }
    
    // ইউজার রেট লিমিট চেক ও আপডেট
    public function checkRateLimit(string $user_id): bool {
        $now = time();
        $stmt = $this->db->prepare("
            SELECT rate_limit_until, interaction_count FROM users WHERE user_id = :uid
        ");
        $stmt->bindValue(':uid', $user_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($row) {
            if ($row['rate_limit_until'] > $now) {
                return false; // এখনো লিমিটেড
            }
        }
        
        // আপডেট
        $new_limit = $now + RATE_LIMIT_SECONDS;
        $this->db->exec("
            INSERT INTO users (user_id, rate_limit_until, last_interaction, interaction_count)
            VALUES ('$user_id', $new_limit, $now, COALESCE((SELECT interaction_count FROM users WHERE user_id='$user_id'),0)+1)
            ON CONFLICT(user_id) DO UPDATE SET
                rate_limit_until = $new_limit,
                last_interaction = $now,
                interaction_count = interaction_count + 1
        ");
        return true;
    }
    
    public function saveUser(array $user): void {
        $stmt = $this->db->prepare("
            INSERT OR IGNORE INTO users (user_id, first_name, username, last_interaction)
            VALUES (:uid, :fname, :uname, CURRENT_TIMESTAMP)
        ");
        $stmt->bindValue(':uid', $user['id'], SQLITE3_TEXT);
        $stmt->bindValue(':fname', $user['first_name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':uname', $user['username'] ?? '', SQLITE3_TEXT);
        $stmt->execute();
    }
}

// ======================== টেলিগ্রাম API ========================
class TelegramAPI {
    private string $token;
    
    public function __construct(string $token) {
        $this->token = $token;
    }
    
    public function sendMessage(string $chat_id, string $text, ?int $reply_to = null): ?array {
        $chunks = $this->splitLongMessage($text);
        $lastResponse = null;
        
        foreach ($chunks as $i => $chunk) {
            $response = $this->sendChunk($chat_id, $chunk, ($i === 0) ? $reply_to : null);
            if ($response && isset($response['ok']) && $response['ok']) {
                $lastResponse = $response;
            }
            if (count($chunks) > 1) usleep(200000);
        }
        return $lastResponse;
    }
    
    private function sendChunk(string $chat_id, string $text, ?int $reply_to = null): ?array {
        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";
        $post = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ];
        if ($reply_to) $post['reply_to_message_id'] = $reply_to;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            debugLog("Telegram API error $httpCode: $response");
            return null;
        }
        return json_decode($response, true);
    }
    
    private function splitLongMessage(string $text): array {
        if (mb_strlen($text) <= 4000) return [$text];
        $chunks = [];
        $lines = explode("\n", $text);
        $current = '';
        foreach ($lines as $line) {
            if (mb_strlen($current . "\n" . $line) > 4000) {
                $chunks[] = $current;
                $current = $line;
            } else {
                $current = ($current === '') ? $line : $current . "\n" . $line;
            }
        }
        if ($current) $chunks[] = $current;
        return $chunks;
    }
}

// ======================== বট কোর ========================
class NepaDaBot {
    private Database $db;
    private TelegramAPI $telegram;
    private ?array $update;
    
    public function __construct() {
        $this->db = new Database();
        $this->telegram = new TelegramAPI(BOT_TOKEN);
        $this->update = json_decode(file_get_contents("php://input"), true);
    }
    
    public function handle(): void {
        if (!$this->update) return;
        
        $message = $this->update['message'] ?? null;
        if (!$message || empty($message['text'])) return;
        
        $text = trim($message['text']);
        $chat = $message['chat'];
        $chat_id = $chat['id'];
        $chat_type = $chat['type'];
        $user = $message['from'];
        $user_id = $user['id'];
        $user_name = $user['first_name'] ?? 'ভাই';
        $username = $user['username'] ?? null;
        
        // ইউজার সংরক্ষণ
        $this->db->saveUser($user);
        
        // ডিবাগ লগ
        debugLog("Message from $user_name ($user_id) in $chat_type: $text");
        
        // রেট লিমিট চেক (শুধু গ্রুপে)
        if ($chat_type !== 'private') {
            if (!$this->db->checkRateLimit($user_id)) {
                debugLog("Rate limited for user $user_id");
                return;
            }
        }
        
        // রিপ্লাই দেওয়া হবে কিনা?
        if (!$this->shouldReply($message, $chat_type)) {
            debugLog("shouldReply returned false");
            return;
        }
        
        // কমান্ড হ্যান্ডলিং (শুধু অ্যাডমিনের জন্য)
        if (strpos($text, '/debug') === 0 && $user_id == ADMIN_ID) {
            $this->handleDebugCommand($chat_id);
            return;
        }
        
        // লিঙ্ক/কমান্ড ইগনোর
        if (preg_match('/(https?:\/\/|t\.me\/|^\s*\/(start|help))/iu', $text)) {
            return;
        }
        
        // ইউজার মেনশন স্ট্রিং তৈরি
        $mention = $this->formatMention($user);
        
        // প্রসঙ্গ ও প্রম্পট তৈরি
        $isCodeRequest = $this->isCodeRequest($text);
        $context = $this->db->getContext($chat_id);
        $prompt = $this->buildPrompt($text, $isCodeRequest, $context, $mention);
        
        // AI কল
        $reply = $this->getAIResponse($prompt);
        
        // রিপ্লাইতে মেনশন জুড়ে দেওয়া (গ্রুপে)
        if ($chat_type !== 'private') {
            $reply = "$mention " . $reply;
        }
        
        // রিপ্লাই টু মেসেজ আইডি
        $reply_to_msg_id = null;
        if ($chat_type !== 'private' && isset($message['reply_to_message'])) {
            $reply_to_msg_id = $message['reply_to_message']['message_id'];
        }
        
        // পাঠানো
        $this->telegram->sendMessage($chat_id, $reply, $reply_to_msg_id);
        
        // লগ ও প্রসঙ্গ আপডেট
        $this->db->logAndUpdateContext($chat_id, $user_id, $user_name, $text, $reply, $chat_type);
        
        debugLog("Reply sent to $chat_id: $reply");
    }
    
    private function formatMention(array $user): string {
        $username = $user['username'] ?? null;
        $first_name = $user['first_name'] ?? 'ভাই';
        if ($username) {
            return "@$username";
        } else {
            // টেলিগ্রামে নাম মেনশনের জন্য HTML ফরম্যাট
            return '<a href="tg://user?id=' . $user['id'] . '">' . htmlspecialchars($first_name) . '</a>';
        }
    }
    
    private function shouldReply(array $message, string $chat_type): bool {
        // প্রাইভেট চ্যাট → সবসময়
        if ($chat_type === 'private') {
            return true;
        }
        
        // গ্রুপ/সুপারগ্রুপ
        if (in_array($chat_type, ['group', 'supergroup'])) {
            $text = $message['text'] ?? '';
            $entities = $message['entities'] ?? [];
            
            // ১. বটকে রিপ্লাই?
            if (isset($message['reply_to_message']['from']['id'])) {
                $replied_to_id = $message['reply_to_message']['from']['id'];
                $bot_id = explode(':', BOT_TOKEN)[0];
                if ($replied_to_id == $bot_id) {
                    debugLog("Reply to bot detected");
                    return true;
                }
            }
            
            // ২. @মেনশন?
            foreach ($entities as $entity) {
                if ($entity['type'] === 'mention') {
                    $mention = substr($text, $entity['offset'], $entity['length']);
                    if (strtolower($mention) === strtolower(BOT_USERNAME)) {
                        debugLog("Mention detected: $mention");
                        return true;
                    }
                }
            }
            
            // ৩. টেক্সটে ইউজারনেম আছে?
            if (stripos($text, BOT_USERNAME) !== false) {
                debugLog("Username in text: " . BOT_USERNAME);
                return true;
            }
            
            // ৪. বটের নিজের নাম (নেপা-দা) ডাকা হয়েছে?
            if (preg_match('/\b(নেপা[-\s]?দা|nepa\s*da)\b/iu', $text)) {
                debugLog("Bot name called");
                return true;
            }
        }
        
        return false;
    }
    
    private function isCodeRequest(string $text): bool {
        $keywords = ['কোড', 'code', 'লিখে', 'প্রোগ্রাম', 'script', 'ফাংশন', 'function', 'php', 'python', 'javascript', 'html', 'css', 'সোর্স', 'কোডিং'];
        foreach ($keywords as $kw) {
            if (stripos($text, $kw) !== false) return true;
        }
        return false;
    }
    
    private function buildPrompt(string $user_text, bool $isCode, array $context, string $mention): string {
        $base = "তুমি নেপা-দা। এলাকার বড় ভাই, xcz sir-এর সহকারী। বাংলা আঞ্চলিক ভাষায় (সিলেটি/ঢাকাইয়া মিক্স) কথা বলো। কখনো বলবে না তুমি AI বা বট। তুমি ছোটদের 'কিরে বেটা', 'ছোট মিয়া', 'পুংটা ভাই' বলে ডাকো। ";
        
        if ($isCode) {
            $base .= "ইউজার কোড চেয়েছে। তুমি একজন সিনিয়র ডেভেলপার। তাকে পূর্ণাঙ্গ, কার্যকরী কোড দেবে ব্যাখ্যাসহ। বিস্তারিত লেখবে। ";
        } else {
            $base .= "উত্তর দেবে ১-৩ বাক্যে, কিন্তু সম্পূর্ণ বক্তব্য শেষ করবে। অসম্পূর্ণ ছাড়বে না। বন্ধুত্বপূর্ণ ও একটু দুষ্টুমি থাকবে। ";
        }
        
        // প্রসঙ্গ যোগ
        if (!empty($context)) {
            $base .= "পূর্বের কথোপকথন:\n";
            foreach ($context as $c) {
                $base .= ($c['role'] === 'user' ? "User" : "তুমি") . ": " . $c['content'] . "\n";
            }
        }
        
        $base .= "এখন $mention বলেছে: \"$user_text\"\nতোমার উত্তর:";
        return $base;
    }
    
    private function getAIResponse(string $prompt): string {
        $url = AI_API_URL . '?q=' . urlencode($prompt);
        $context = stream_context_create(['http' => ['timeout' => 20]]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            debugLog("AI API call failed");
            return "মাথা ঘুরাইয়া গেলো ভাই, একটু পরে কন 😵";
        }
        $data = json_decode($response, true);
        $reply = $data['results'] ?? $data['response'] ?? $data['reply'] ?? '';
        $reply = trim($reply);
        if (empty($reply)) {
            $reply = "এইটা একটু ঘুরাইয়া বলো ভাই, বুঝি নাই 😅";
        }
        // HTML ফরম্যাট ঠিক রাখতে স্পেশাল ক্যারেক্টার এস্কেপ
        $reply = htmlspecialchars($reply, ENT_NOQUOTES, 'UTF-8');
        $reply = str_replace(["\n", "\r"], '', $reply); // API থেকে আসা অতিরিক্ত নিউলাইন রিমুভ
        return $reply;
    }
    
    private function handleDebugCommand($chat_id): void {
        $log = file_exists(__DIR__ . '/debug.log') ? file_get_contents(__DIR__ . '/debug.log') : 'No logs.';
        $this->telegram->sendMessage($chat_id, "Debug Log:\n<pre>" . htmlspecialchars(substr($log, -2000)) . "</pre>");
    }
}

// ======================== এক্সিকিউশন ========================
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    try {
        $bot = new NepaDaBot();
        $bot->handle();
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) {
        debugLog("Fatal error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal Server Error']);
    }
}