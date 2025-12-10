<?php

// ====== НАСТРОЙКИ ======
$botToken = "8472452119:AAFvR89YfbBCsejhyMmKNe1ZWyR0ijn8P0I";
$apiUrl = "https://api.telegram.org/bot$botToken/";
$staffGroupId = -5013164010; // Группа сотрудников
$allowedStaff = [1062756366]; // Кто может отвечать

// Файл базы (JSON)
$dbFile = __DIR__ . "/messages.json";
if (!file_exists($dbFile)) {
    file_put_contents($dbFile, json_encode([]));
}

// ====== ФУНКЦИИ ======
function tgRequest($method, $params = []) {
    global $apiUrl;
    $url = $apiUrl . $method;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function saveMapping($staffMsgId, $clientChatId, $clientMsgId) {
    global $dbFile;
    $db = json_decode(file_get_contents($dbFile), true);

    $db[$staffMsgId] = [
        "client_chat_id" => $clientChatId,
        "client_message_id" => $clientMsgId
    ];

    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT));
}

function getMapping($staffMsgId) {
    global $dbFile;
    $db = json_decode(file_get_contents($dbFile), true);
    return $db[$staffMsgId] ?? null;
}

// ====== Работа с темами (topics) ======
function findTopicId($chatId, $topicName) {
    $res = tgRequest("getForumTopicList", ["chat_id" => $chatId]);
    if (!isset($res["result"]["topics"])) return null;

    foreach ($res["result"]["topics"] as $topic) {
        if (trim(mb_strtolower($topic["name"])) == trim(mb_strtolower($topicName))) {
            return $topic["message_thread_id"];
        }
    }

    return null;
}

function createTopic($chatId, $topicName) {
    $res = tgRequest("createForumTopic", [
        "chat_id" => $chatId,
        "name" => $topicName
    ]);

    if (isset($res["result"]["message_thread_id"])) {
        return $res["result"]["message_thread_id"];
    }

    return null;
}

// ====== ПОЛУЧЕНИЕ ОБНОВЛЕНИЯ ======
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit;

// =====================
// ОБРАБОТКА СООБЩЕНИЙ
// =====================
if (isset($update["message"])) {

    $msg = $update["message"];
    $chatId = $msg["chat"]["id"];
    $msgId = $msg["message_id"];
    $userId = $msg["from"]["id"];
    $userName = ($msg["from"]["first_name"] ?? "") . " " . ($msg["from"]["last_name"] ?? "");

    // ===== Ответ сотрудника =====
    if ($chatId == $staffGroupId && isset($msg["reply_to_message"])) {

        if (!in_array($userId, $GLOBALS["allowedStaff"])) exit;

        $staffMsgId = $msg["reply_to_message"]["message_id"];
        $mapping = getMapping($staffMsgId);

        if (!$mapping) {
            tgRequest("sendMessage", [
                "chat_id" => $staffGroupId,
                "text" => "❗ Не найдено связанное клиентское сообщение"
            ]);
            exit;
        }

        $clientChatId = $mapping["client_chat_id"];
        $clientMsgId  = $mapping["client_message_id"];
        $header = "👨‍💼 *От сотрудника:* $userName\n\n";

        if (isset($msg["text"])) {
            tgRequest("sendMessage", [
                "chat_id" => $clientChatId,
                "text" => $header . $msg["text"],
                "parse_mode" => "Markdown",
                "reply_to_message_id" => $clientMsgId
            ]);
        } elseif (isset($msg["photo"])) {
            $photo = end($msg["photo"]);
            tgRequest("sendPhoto", [
                "chat_id" => $clientChatId,
                "photo" => $photo["file_id"],
                "caption" => $header . ($msg["caption"] ?? ""),
                "reply_to_message_id" => $clientMsgId
            ]);
        } elseif (isset($msg["document"])) {
            tgRequest("sendDocument", [
                "chat_id" => $clientChatId,
                "document" => $msg["document"]["file_id"],
                "caption" => $header . ($msg["caption"] ?? ""),
                "reply_to_message_id" => $clientMsgId
            ]);
        } elseif (isset($msg["voice"])) {
            tgRequest("sendVoice", [
                "chat_id" => $clientChatId,
                "voice" => $msg["voice"]["file_id"],
                "caption" => $header,
                "reply_to_message_id" => $clientMsgId
            ]);
        } elseif (isset($msg["video"])) {
            tgRequest("sendVideo", [
                "chat_id" => $clientChatId,
                "video" => $msg["video"]["file_id"],
                "caption" => $header . ($msg["caption"] ?? ""),
                "reply_to_message_id" => $clientMsgId
            ]);
        }

        exit;
    }

    // ===== Клиент пишет (пересылаем сотрудникам) =====
    $groupName = $msg["chat"]["title"] ?? "Личное";

    // Ищем тему
    $threadId = findTopicId($staffGroupId, $groupName);
    if (!$threadId) {
        // Создаём тему если не существует
        $threadId = createTopic($staffGroupId, $groupName);
    }

    $header  = "📥 *Группа:* $groupName\n";
    $header .= "👤 *Клиент:* $userName\n";
    $header .= "🆔 Chat: $chatId | Msg: $msgId\n";
    $header .= "----------------------------------\n";

    $sent = null;

    if (isset($msg["text"])) {
        $sent = tgRequest("sendMessage", [
            "chat_id" => $staffGroupId,
            "message_thread_id" => $threadId,
            "text" => $header . $msg["text"],
            "parse_mode" => "Markdown"
        ]);
    } elseif (isset($msg["photo"])) {
        $photo = end($msg["photo"]);
        $sent = tgRequest("sendPhoto", [
            "chat_id" => $staffGroupId,
            "message_thread_id" => $threadId,
            "photo" => $photo["file_id"],
            "caption" => $header . ($msg["caption"] ?? "")
        ]);
    } elseif (isset($msg["document"])) {
        $sent = tgRequest("sendDocument", [
            "chat_id" => $staffGroupId,
            "message_thread_id" => $threadId,
            "document" => $msg["document"]["file_id"],
            "caption" => $header . ($msg["caption"] ?? "")
        ]);
    } elseif (isset($msg["voice"])) {
        $sent = tgRequest("sendVoice", [
            "chat_id" => $staffGroupId,
            "message_thread_id" => $threadId,
            "voice" => $msg["voice"]["file_id"],
            "caption" => $header
        ]);
    } elseif (isset($msg["video"])) {
        $sent = tgRequest("sendVideo", [
            "chat_id" => $staffGroupId,
            "message_thread_id" => $threadId,
            "video" => $msg["video"]["file_id"],
            "caption" => $header . ($msg["caption"] ?? "")
        ]);
    }

    // Сохраняем связь сообщений
    if ($sent && isset($sent["result"]["message_id"])) {
        saveMapping($sent["result"]["message_id"], $chatId, $msgId);
    }
}

?>
