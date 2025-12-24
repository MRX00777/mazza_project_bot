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
    curl_close($ch);
    $result = json_decode($response, true);
    if (!$result || !isset($result["ok"])) {
        file_put_contents(__DIR__."/log.txt", date("Y-m-d H:i:s")." Telegram error: ".$response."\n", FILE_APPEND);
    }
    return $result;
}

// ====== КЕШИРОВАНИЕ ТЕМ ======
function getOrCreateTopic($groupId, $topicName) {
    global $topicsFile;
    $topics = json_decode(file_get_contents($topicsFile), true);
    if (isset($topics[$topicName])) return $topics[$topicName];

    $res = tgRequest("getForumTopicList", ["chat_id" => $groupId]);
    if ($res && isset($res["result"]["topics"])) {
        foreach ($res["result"]["topics"] as $t) {
            if (mb_strtolower($t["name"]) === mb_strtolower($topicName)) {
                $threadId = $t["message_thread_id"];
                $topics[$topicName] = $threadId;
                file_put_contents($topicsFile, json_encode($topics, JSON_PRETTY_PRINT));
                return $threadId;
            }
        }
    }

    $res = tgRequest("createForumTopic", ["chat_id" => $groupId, "name" => $topicName]);
    if (!$res || !isset($res["result"]["message_thread_id"])) {
        file_put_contents(__DIR__."/log.txt", date("Y-m-d H:i:s")." ERROR: cannot create topic $topicName\n", FILE_APPEND);
        return null;
    }

    $threadId = $res["result"]["message_thread_id"];
    $topics[$topicName] = $threadId;
    file_put_contents($topicsFile, json_encode($topics, JSON_PRETTY_PRINT));
    return $threadId;
}

// ====== МАППИНГ ======
function saveMapping($staffMsgId, $clientChatId, $clientMsgId, $threadId = null) {
    global $dbFile;
    $db = json_decode(file_get_contents($dbFile), true);
    $db[$staffMsgId] = [
        "staff_message_id" => $staffMsgId,   // message_id в группе сотрудников (на него должны отвечать сотрудники)
        "client_chat_id"   => $clientChatId, // id чата клиента
        "client_message_id"=> $clientMsgId,  // исходный message_id клиента (на него бот будет отвечать при отправке сотруднику)
        "thread_id"        => $threadId      // thread, чтобы держать всё в одной теме
    ];
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
}

function getMapping($staffMsgId) {
    global $dbFile;
    $db = json_decode(file_get_contents($dbFile), true);
    return $db[$staffMsgId] ?? null;
}

function getMappingByClient($clientMsgId) {
    global $dbFile;
    $db = json_decode(file_get_contents($dbFile), true);
    foreach ($db as $map) {
        if ($map["client_message_id"] == $clientMsgId) {
            return $map;
        }
    }
    return null;
}

// ====== ПОЛУЧЕНИЕ ОБНОВЛЕНИЯ ======
$update = json_decode(file_get_contents("php://input"), true);
file_put_contents(__DIR__."/log.txt", date("Y-m-d H:i:s")." Update: ".print_r($update, true)."\n", FILE_APPEND);

if (!$update || !isset($update["message"])) { echo "OK"; exit; }

$msg = $update["message"];
$chatId = $msg["chat"]["id"];
$msgId  = $msg["message_id"];
$userId = $msg["from"]["id"];
$userName = trim(($msg["from"]["first_name"] ?? "") . " " . ($msg["from"]["last_name"] ?? ""));

// ====================
// ОТВЕТ СОТРУДНИКА (в группе → клиенту)
// ====================
if ($chatId === $staffGroupId && isset($msg["reply_to_message"])) {
    // доступ только для разрешённых сотрудников
    if (!in_array($userId, $allowedStaff)) { echo "OK"; exit; }

    // сотрудники ДОЛЖНЫ отвечать именно на скопированное сообщение клиента (то, что бот сохранил)
    $staffMsgId = $msg["reply_to_message"]["message_id"];
    $mapping = getMapping($staffMsgId);
    if (!$mapping) { echo "OK"; exit; }

    // отправляем текст сотрудника клиенту как reply на исходный message клиента
    if (isset($msg["text"])) {
        $header = "👨‍💼 От сотрудника: $userName\n\n";
        $params = [
            "chat_id" => $mapping["client_chat_id"],
            "text" => $header.$msg["text"],
            "reply_to_message_id" => $mapping["client_message_id"]
        ];
        $sent = tgRequest("sendMessage", $params);
        if (!$sent || (isset($sent["ok"]) && !$sent["ok"])) {
            file_put_contents(__DIR__."/log.txt", date("Y-m-d H:i:s")." sendMessage to client error: ".json_encode($sent)."\n", FILE_APPEND);
        }
    }

    echo "OK";
    exit;
}

// ====================
// КЛИЕНТ ПИШЕТ (клиент → группу)
// ====================
$groupName = $msg["chat"]["title"] ?? "Личное";
$threadId = getOrCreateTopic($staffGroupId, $groupName);

// параметры копирования сообщения клиента в группу сотрудников (любой тип контента)
$copyParams = [
    "chat_id" => $staffGroupId,
    "from_chat_id" => $chatId,
    "message_id" => $msgId,
    "message_thread_id" => $threadId
];

// если клиент ответил на сообщение сотрудника — найдём, к какому staff‑message в группе привязать reply
if (isset($msg["reply_to_message"])) {
    $replyMapping = getMappingByClient($msg["reply_to_message"]["message_id"]);
    if ($replyMapping) {
        // reply должен указывать на ТО сообщение в группе, которое сотрудники видят и на которое будут отвечать
        $copyParams["reply_to_message_id"] = $replyMapping["staff_message_id"];
        // используем тот же thread, чтобы не было "message thread not found"
        if (!empty($replyMapping["thread_id"])) {
            $copyParams["message_thread_id"] = $replyMapping["thread_id"];
        }
    }
}

// копируем сообщение (любой тип) клиента в группу
$sent = tgRequest("copyMessage", $copyParams);
if (!$sent || (isset($sent["ok"]) && !$sent["ok"])) {
    file_put_contents(__DIR__."/log.txt", date("Y-m-d H:i:s")." copyMessage error: ".json_encode($sent)."\n", FILE_APPEND);
}

// сохраняем маппинг: на ЭТО сообщение в группе должны отвечать сотрудники
if (isset($sent["result"]["message_id"])) {
    $savedThreadId = $copyParams["message_thread_id"] ?? null;
    saveMapping($sent["result"]["message_id"], $chatId, $msgId, $savedThreadId);
}

echo "OK";
exit;

?>
