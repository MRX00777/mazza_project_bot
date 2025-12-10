<?php

// ====== НАСТРОЙКИ ======
$botToken = "8597712382:AAE4-gQXxx22AE71-yAFJ4wLQ3cG5PiNmXA";
$staffGroupId = -1003280448019;
$allowedStaff = [1062756366];

$apiUrl = "https://api.telegram.org/bot$botToken/";

// ====== ФАЙЛЫ ======
$dbFile = __DIR__ . "/messages.json";
$topicsFile = __DIR__ . "/topics.json";

if (!file_exists($dbFile)) file_put_contents($dbFile, "{}");
if (!file_exists($topicsFile)) file_put_contents($topicsFile, "{}");

// ====== ФУНКЦИЯ API ======
function tgRequest($method, $params = []) {
    global $apiUrl;
    $ch = curl_init($apiUrl . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        file_put_contents(__DIR__."/log.txt", date("Y-m-d H:i:s")." Curl error: ".curl_error($ch)."\n", FILE_APPEND);
        curl_close($ch);
        return null;
    }

    curl_close($ch);
    $result = json_decode($response, true);

    if (!$result || empty($result["ok"])) {
        file_put_contents(__DIR__."/log.txt", date("Y-m-d H:i:s")." Telegram error: ".$response."\n", FILE_APPEND);
    }

    return $result;
}

// ====== КЕШИРОВАНИЕ ТЕМ ======
function getOrCreateTopic($groupId, $topicName) {
    global $topicsFile;

    $topics = json_decode(file_get_contents($topicsFile), true);

    // Если тема уже есть — возвращаем
    if (isset($topics[$topicName])) {
        return $topics[$topicName];
    }

    // Иначе создаём
    $res = tgRequest("createForumTopic", [
        "chat_id" => $groupId,
        "name"    => $topicName
    ]);

    if (!$res || !isset($res["result"]["message_thread_id"])) {
        file_put_contents(__DIR__."/log.txt", date("Y-m-d H:i:s")." ERROR: cannot create topic $topicName\n", FILE_APPEND);
        return null;
    }

    $threadId = $res["result"]["message_thread_id"];

    // Сохраняем в topics.json
    $topics[$topicName] = $threadId;
    file_put_contents($topicsFile, json_encode($topics, JSON_PRETTY_PRINT));

    return $threadId;
}

// ====== МАППИНГ ======
function saveMapping($staffMsgId, $clientChatId, $clientMsgId, $threadId) {
    global $dbFile;
    $db = json_decode(file_get_contents($dbFile), true);
    $db[$staffMsgId] = [
        "client_chat_id" => $clientChatId,
        "client_message_id" => $clientMsgId,
        "thread_id" => $threadId
    ];
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
}

function getMapping($staffMsgId) {
    global $dbFile;
    $db = json_decode(file_get_contents($dbFile), true);
    return $db[$staffMsgId] ?? null;
}

// ====== ПОЛУЧЕНИЕ ОБНОВЛЕНИЯ ======
$update = json_decode(file_get_contents("php://input"), true);
file_put_contents(__DIR__."/log.txt", date("Y-m-d H:i:s")." Update: ".print_r($update, true)."\n", FILE_APPEND);

if (!$update) { echo "OK"; exit; }

// ====== ОБРАБОТКА СООБЩЕНИЙ ======
if (!isset($update["message"])) { echo "OK"; exit; }

$msg = $update["message"];
$chatId = $msg["chat"]["id"];
$msgId  = $msg["message_id"];
$userId = $msg["from"]["id"];
$userName = trim(($msg["from"]["first_name"] ?? "") . " " . ($msg["from"]["last_name"] ?? ""));

// ====================
// ОТВЕТ СОТРУДНИКА
// ====================
if ($chatId === $staffGroupId && isset($msg["reply_to_message"])) {

    if (!in_array($userId, $allowedStaff)) { echo "OK"; exit; }

    $staffMsgId = $msg["reply_to_message"]["message_id"];
    $mapping = getMapping($staffMsgId);

    if (!$mapping) { echo "OK"; exit; }

    $params = [
        "chat_id" => $mapping["client_chat_id"],
        "reply_to_message_id" => $mapping["client_message_id"]
    ];

    $header = "👨‍💼 От сотрудника: $userName\n\n";

    if (isset($msg["text"])) {
        $params["text"] = $header.$msg["text"];
        tgRequest("sendMessage", $params);
    }

    echo "OK"; 
    exit;
}

// ====================
// КЛИЕНТ ПИШЕТ
// ====================
$groupName = $msg["chat"]["title"] ?? "Личное";
$threadId = getOrCreateTopic($staffGroupId, $groupName);

$params = [
    "chat_id" => $staffGroupId,
    "message_thread_id" => $threadId
];

$header = "📥 Группа: $groupName\n👤 Клиент: $userName\n----------------------------------\n";

if (isset($msg["text"])) {
    $params["text"] = $header.$msg["text"];
    $sent = tgRequest("sendMessage", $params);
}

if (isset($sent["result"]["message_id"])) {
    saveMapping($sent["result"]["message_id"], $chatId, $msgId, $threadId);
}

echo "OK";
exit;

?>
