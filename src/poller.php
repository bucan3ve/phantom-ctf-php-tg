<?php
// This is the bot's "main" file. It runs in a loop.
require_once 'handler.php';

// Define the API URL
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// This is the offset, so we only get new messages
$offset = 0;
echo "Bot is running... Polling for updates.\n";

// Initialize the database just once
$pdo = init_database();

// Clear any pending webhooks, just in case
@file_get_contents(API_URL . "setWebhook?url=");

while (true) {
    // Use long-polling (timeout=60)
    $url = API_URL . "getUpdates?offset={$offset}&timeout=60";
    $response_json = @file_get_contents($url); // Use @ to suppress warnings on timeout

    if ($response_json === false) {
        echo "Failed to get updates (timeout or network issue), retrying...\n";
        sleep(2);
        continue;
    }

    $response = json_decode($response_json, true);

    if (!$response || !$response['ok']) {
        echo "Error from Telegram, retrying...\n";
        sleep(10);
        continue;
    }

    foreach ($response['result'] as $update) {
        echo "Processing update ID " . $update['update_id'] . "\n";
        
        // Pass the $pdo and $update to the handler
        handle_update($pdo, $update);

        // Update offset to the next ID
        $offset = $update['update_id'] + 1;
    }
}
