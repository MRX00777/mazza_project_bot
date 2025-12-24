<?php

// ====== НАСТРОЙКИ ======
$botToken = "8597712382:AAE4-gQXxx22AE71-yAFJ4wLQ3cG5PiNmXA";
$staffGroupId = -1003280448019;
$allowedStaff = [1062756366];

$apiUrl = "https://api.telegram.org/bot$botToken/";

// ====== ФАЙЛЫ БД ======
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
    return json_decode($response, true);
}

// ====== РАБОТА С ТОПИКАМИ ======
function getThreadForClient($chatId, $groupTitle) {
    global $staffGroupId, $topicsFile;
    $topics = json_decode(file_get_contents($topicsFile), true);

    // Если для этого чата (группы) уже есть топик
    if (isset($topics["c_$chatId"])) return $topics["c_$chatId"];

    // Создаем топик с названием группы клиента
    $topicName = "📂 " . $groupTitle;
    $res = tgRequest("createForumTopic", ["chat_id" => $staffGroupId, "name" => $topicName]);
    
    if (isset($res["result"]["message_thread_id"])) {
        $threadId = $res["result"]["message_thread_id"];
        $topics["c_$chatId"] = $threadId;      // Чат клиента -> Топик
        $topics["t_$threadId"] = $chatId;      // Топик -> Чат клиента
        file_put_contents($topicsFile, json_encode($topics, JSON_PRETTY_PRINT));
        return $threadId;
    }
    return null;
}

function getClientIdByThread($threadId) {
    global $topicsFile;
    $topics = json_decode(file_get_contents($topicsFile), true);
    return $topics["t_$threadId"] ?? null;
}

// ====== ПОЛУЧЕНИЕ ОБНОВЛЕНИЯ ======
$update = json_decode(file_get_contents("php://input"), true);
if (!$update || !isset($update["message"])) exit;

$msg = $update["message"];
$chatId = $msg["chat"]["id"];
$msgId  = $msg["message_id"];
$userId = $msg["from"]["id"];

// Определяем имя группы (или "Личное", если это ЛС)
$groupTitle = $msg["chat"]["title"] ?? "Личное (".$msg["from"]["first_name"].")";

// Определяем имя конкретного человека
$firstName = $msg["from"]["first_name"] ?? "";
$lastName = $msg["from"]["last_name"] ?? "";
$senderName = trim($firstName . " " . $lastName);
if (empty($senderName)) $senderName = "User_$userId";

// ====================
// ВХОДЯЩЕЕ ОТ СОТРУДНИКА (Группа сотрудников -> Клиентская группа)
// ====================
if ($chatId == $staffGroupId) {
    if (!in_array($userId, $allowedStaff)) exit;

    $targetClientId = null;
    $replyToMsgId = null;
    $currentThreadId = $msg["message_thread_id"] ?? null;

    if (isset($msg["reply_to_message"])) {
        $db = json_decode(file_get_contents($dbFile), true);
        $map = $db[$msg["reply_to_message"]["message_id"]] ?? null;
        if ($map) {
            $targetClientId = $map["client_chat_id"];
            $replyToMsgId = $map["client_message_id"];
        }
    }
    
    if (!$targetClientId && $currentThreadId) {
        $targetClientId = getClientIdByThread($currentThreadId);
    }

    if ($targetClientId) {
        $method = "sendMessage";
        $params = ["chat_id" => $targetClientId];
        if ($replyToMsgId) $params["reply_to_message_id"] = $replyToMsgId;

        if (isset($msg["text"])) {
            $params["text"] = "👨‍💼 *Поддержка:*\n\n" . $msg["text"];
            $params["parse_mode"] = "Markdown";
        } elseif (isset($msg["photo"])) {
            $method = "sendPhoto";
            $params["photo"] = end($msg["photo"])["file_id"];
            $params["caption"] = "👨‍💼 Ответ поддержки";
        } elseif (isset($msg["video"])) {
            $method = "sendVideo";
            $params["video"] = $msg["video"]["file_id"];
        } elseif (isset($msg["document"])) {
            $method = "sendDocument";
            $params["document"] = $msg["document"]["file_id"];
        } elseif (isset($msg["voice"])) {
            $method = "sendVoice";
            $params["voice"] = $msg["voice"]["file_id"];
        }

        $result = tgRequest($method, $params);

        if (!$result || (isset($result["ok"]) && !$result["ok"])) {
            $error = $result["description"] ?? "Ошибка";
            tgRequest("sendMessage", [
                "chat_id" => $staffGroupId,
                "message_thread_id" => $currentThreadId,
                "text" => "⚠️ *Не доставлено:* $error",
                "parse_mode" => "Markdown"
            ]);
        }
    }
    exit;
}

// ====================
// ВХОДЯЩЕЕ ОТ КЛИЕНТА (Группа клиента -> Группа сотрудников)
// ====================
$threadId = getThreadForClient($chatId, $groupTitle);

if ($threadId) {
    $method = "sendMessage";
    $params = [
        "chat_id" => $staffGroupId,
        "message_thread_id" => $threadId,
        "parse_mode" => "Markdown"
    ];

    // Формируем заголовок: [Имя пользователя]
    $prefix = "👤 *{$senderName}:*\n";

    if (isset($msg["text"])) {
        $params["text"] = $prefix . $msg["text"];
    } elseif (isset($msg["photo"])) {
        $method = "sendPhoto";
        $params["photo"] = end($msg["photo"])["file_id"];
        $params["caption"] = $prefix;
    } elseif (isset($msg["video"])) {
        $method = "sendVideo";
        $params["video"] = $msg["video"]["file_id"];
        $params["caption"] = $prefix;
    } elseif (isset($msg["document"])) {
        $method = "sendDocument";
        $params["document"] = $msg["document"]["file_id"];
        $params["caption"] = $prefix;
    } else {
        // Если тип сообщения сложный, просто копируем
        $sent = tgRequest("copyMessage", [
            "chat_id" => $staffGroupId,
            "from_chat_id" => $chatId,
            "message_id" => $msgId,
            "message_thread_id" => $threadId
        ]);
        $resId = $sent["result"]["message_id"] ?? null;
    }

    if ($method !== "copyMessage" && !isset($resId)) {
        $sent = tgRequest($method, $params);
        $resId = $sent["result"]["message_id"] ?? null;
    }

    if ($resId) {
        $db = json_decode(file_get_contents($dbFile), true);
        $db[$resId] = [
            "client_chat_id" => $chatId,
            "client_message_id" => $msgId
        ];
        if (count($db) > 2000) $db = array_slice($db, -2000, null, true);
        file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
    }
}