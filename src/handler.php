<?php

ini_set('display_errors', 0); // Disable error display
ini_set('log_errors', 1);

define('BOT_TOKEN', getenv('BOT_TOKEN'));
define('DB_FILE', __DIR__ . '/db/bot.db');


function write_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message");
}

function init_database() {
    if (!is_dir(__DIR__ . '/db')) {
        mkdir(__DIR__ . '/db', 0700);
    }
    
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        chat_id TEXT PRIMARY KEY,
        username TEXT,
        is_admin INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS secret_data (
        id INTEGER PRIMARY KEY,
        data TEXT
    )");
    
    $pdo->exec("INSERT OR IGNORE INTO users (chat_id, username, is_admin) VALUES ('', 'phantom_admin', 1)");
    $pdo->exec("INSERT OR IGNORE INTO secret_data (id, data) VALUES (1, 'CTF{Ph4nt0m_L0g1c_Byp4ss_1s_R3al}')");
    
    return $pdo;
}

function sendMessage($chat_id, $text) {
    $apiUrl = 'https://api.telegram.org/bot' . BOT_TOKEN . '/';
    $url = $apiUrl . "sendMessage?chat_id={$chat_id}&text=" . urlencode($text);
    
    // Use @ to suppress errors, but log them properly
    $result = @file_get_contents($url);
    if ($result === false) {
        write_log("[HANDLER_ERROR] Failed to send message to chat_id: $chat_id");
    }
}


function sendSourceFile($chat_id) {
    $apiUrl = 'https://api.telegram.org/bot' . BOT_TOKEN . '/sendDocument';
    $file_path = __FILE__; // __FILE__ is the path to this file (handler.php)
    $file_name = 'handler.php'; // The name the user will see

    // Create a boundary for the multipart/form-data
    $boundary = '----' . uniqid();
    
    // Build the request body
    $body = '';
    // chat_id field
    $body .= "--" . $boundary . "\r\n";
    $body .= 'Content-Disposition: form-data; name="chat_id"' . "\r\n\r\n";
    $body .= $chat_id . "\r\n";
    
    // document field
    $body .= "--" . $boundary . "\r\n";
    $body .= 'Content-Disposition: form-data; name="document"; filename="' . $file_name . '"' . "\r\n";
    $body .= 'Content-Type: text/php' . "\r\n\r\n";
    $body .= file_get_contents($file_path) . "\r\n"; // Read the source code
    
    // End boundary
    $body .= "--" . $boundary . "--\r\n";

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: multipart/form-data; boundary=' . $boundary,
            'content' => $body,
        ],
    ];
    
    $context = stream_context_create($options);
    $result = @file_get_contents($apiUrl, false, $context);
    
    if ($result === false) {
        write_log("[HANDLER_ERROR] Failed to send source file to chat_id: $chat_id");
    } else {
        write_log("[HANDLER_INFO] Source file sent to $chat_id");
    }
}


function get_text_from_update($update) {
    if (isset($update['message']['text'])) {
        return $update['message']['text'];
    }
    if (isset($update['edited_message']['text'])) {
        return $update['edited_message']['text'];
    }
    if (isset($update['callback_query']['data'])) {
        return $update['callback_query']['data'];
    }
    return null;
}

function handle_update($pdo, $update) {
    try {
        
        $chat_id = $update['message']['chat']['id'];
        
        $stmt = $pdo->query("SELECT * FROM users WHERE chat_id = '$chat_id'");
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        write_log("[AUTH_DEBUG] Brittle auth check for chat_id '$chat_id' matched user: " . ($user ? $user['username'] : 'NONE'));

        
        $text = get_text_from_update($update);
        
        $real_chat_id = null;
        if (isset($update['message']['chat']['id'])) {
            $real_chat_id = $update['message']['chat']['id'];
        } elseif (isset($update['edited_message']['chat']['id'])) {
            $real_chat_id = $update['edited_message']['chat']['id'];
        } elseif (isset($update['callback_query']['from']['id'])) {
            $real_chat_id = $update['callback_query']['from']['id'];
        }

        if (!$real_chat_id) {
            write_log("[HANDLER_WARN] Received update without a replyable chat_id. Skipping.");
            return; // Not an update we can reply to
        }

        if ($text === '/start') {
            $real_username = $update['message']['from']['username'] ?? 'player';
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO users (chat_id, username, is_admin) VALUES (?, ?, 0)");
            $stmt->execute([$real_chat_id, $real_username]);
            write_log("[HANDLER_INFO] Registered new user: $real_username ($real_chat_id)");
            
            if ($user && $user['is_admin'] == 1) {
                write_log("[HANDLER_INFO] Admin '{$user['username']}' used /start. (Real_id: $real_chat_id)");
                sendMessage($real_chat_id, "Welcome, ADMIN {$user['username']}! Your real ID is $real_chat_id.");
            } else {
                sendMessage($real_chat_id, "Welcome, player! You are registered. Your ID is $real_chat_id. Try /help for commands.");
            }

        } elseif ($text === '/help') {
            $help_text = "Available commands:\n"
                       . "/start - Register in the system\n"
                       . "/help - Show this message\n"
                       . "/getSource - Get the bot's source code\n"
                       . "/read_flag - (Admins Only) Read the flag";
            sendMessage($real_chat_id, $help_text);

        } elseif ($text === '/getSource') {
            write_log("[HANDLER_INFO] User $real_chat_id requested source code.");
            sendMessage($real_chat_id, "Sending the bot's source code...");
            sendSourceFile($real_chat_id);

        } elseif ($text === '/read_flag') {
            if ($user && $user['is_admin'] == 1) {
                $flag = $pdo->query("SELECT data FROM secret_data WHERE id = 1")->fetchColumn();
                write_log("[CTF_SUCCESS] User '{$user['username']}' (real_id: $real_chat_id) read the flag!");
                sendMessage($real_chat_id, "Access Granted, {$user['username']}! The flag is: {$flag}");
            } else {
                write_log("[CTF_FAIL] User (real_id: $real_chat_id) failed /read_flag. Auth check was for user: " . ($user ? $user['username'] : 'NONE'));
                sendMessage($real_chat_id, "Access Denied. You are not an admin. (Auth check for user: " . ($user ? $user['username'] : 'NONE') . ")");
            }
        
        } else if ($text) {
             sendMessage($real_chat_id, "Unknown command. Try /help.");
        }

    } catch (Exception $e) {
        write_log("[HANDLER_FATAL] Exception: " . $e->getMessage());
    }
}

