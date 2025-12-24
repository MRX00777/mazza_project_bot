import logging
import json
import os
from aiogram import Bot, Dispatcher, executor, types

# --- Конфиг ---
BOT_TOKEN = "8597712382:AAE4-gQXxx22AE71-yAFJ4wLQ3cG5PiNmXA"
STAFF_GROUP_ID = -1003280448019
ALLOWED_STAFF = [1062756366]

# --- Логирование в файл и консоль ---
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    handlers=[
        logging.FileHandler("log.txt", encoding="utf-8"),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger("bridge-bot")

bot = Bot(token=BOT_TOKEN)
dp = Dispatcher(bot)

# --- JSON база ---
DB_FILE = "messages.json"
TOPICS_FILE = "topics.json"  # соответствие клиентской группы -> message_thread_id

def load_json(path, default):
    if not os.path.exists(path):
        with open(path, "w", encoding="utf-8") as f:
            json.dump(default, f, ensure_ascii=False, indent=2)
        return default
    with open(path, "r", encoding="utf-8") as f:
        try:
            return json.load(f)
        except Exception:
            # если файл повреждён — создаём заново
            logging.error(f"Файл {path} повреждён, пересоздаю")
            with open(path, "w", encoding="utf-8") as w:
                json.dump(default, w, ensure_ascii=False, indent=2)
            return default

def save_json(path, data):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)

def get_client_key(chat: types.Chat) -> str:
    # Ключ для идентификации клиента: используем title если есть, иначе ID
    return chat.title or str(chat.id)

async def get_or_create_topic(client_key: str) -> int:
    topics = load_json(TOPICS_FILE, {})  # {client_key: topic_id}
    topic_id = topics.get(client_key)
    if topic_id:
        return topic_id

    # Создаём новую тему и сохраняем её ID
    try:
        new_topic = await bot.create_forum_topic(STAFF_GROUP_ID, name=client_key)
        topic_id = new_topic.message_thread_id
        topics[client_key] = topic_id
        save_json(TOPICS_FILE, topics)
        logger.info(f"Создана новая тема '{client_key}' с ID {topic_id}")
        return topic_id
    except Exception as e:
        logger.exception(f"Не удалось создать тему для '{client_key}': {e}")
        raise

@dp.message_handler(content_types=types.ContentTypes.ANY)
async def from_client(message: types.Message):
    # Игнорируем сообщения из группы сотрудников
    if message.chat.id == STAFF_GROUP_ID:
        return

    # Только групповые чаты клиентов (бот должен быть админом там)
    try:
        client_key = get_client_key(message.chat)
        topic_id = await get_or_create_topic(client_key)

        # Текст предварительного сообщения с метаданными клиента
        header = (
            f"📥 [Группа: {client_key}]\n"
            f"Клиент: {message.from_user.full_name}\n"
        )

        # Отправляем в тему сотрудников заголовок и само содержание (тип-сейф)
        await bot.send_message(
            STAFF_GROUP_ID,
            header,
            message_thread_id=topic_id
        )

        # Далее отправляем реальный контент клиента
        if message.text:
            sent = await bot.send_message(
                STAFF_GROUP_ID,
                message.text,
                message_thread_id=topic_id,
                reply_to_message_id=None
            )
        elif message.photo:
            sent = await bot.send_photo(
                STAFF_GROUP_ID,
                message.photo[-1].file_id,
                caption=message.caption or "",
                message_thread_id=topic_id
            )
        elif message.document:
            sent = await bot.send_document(
                STAFF_GROUP_ID,
                message.document.file_id,
                caption=message.caption or "",
                message_thread_id=topic_id
            )
        elif message.voice:
            sent = await bot.send_voice(
                STAFF_GROUP_ID,
                message.voice.file_id,
                caption=message.caption or "",
                message_thread_id=topic_id
            )
        elif message.video:
            sent = await bot.send_video(
                STAFF_GROUP_ID,
                message.video.file_id,
                caption=message.caption or "",
                message_thread_id=topic_id
            )
        elif message.sticker:
            sent = await bot.send_sticker(
                STAFF_GROUP_ID,
                message.sticker.file_id,
                message_thread_id=topic_id
            )
        else:
            sent = await bot.send_message(
                STAFF_GROUP_ID,
                "⚠️ Тип сообщения клиента не поддержан ботом.",
                message_thread_id=topic_id
            )

        # Сохраняем соответствие: staff_msg_id -> client_chat_id / client_msg_id / topic_id
        db = load_json(DB_FILE, {})
        db[str(sent.message_id)] = {
            "client_chat_id": message.chat.id,
            "client_msg_id": message.message_id,
            "topic_id": topic_id
        }
        save_json(DB_FILE, db)
        logger.info(f"Клиентское сообщение {message.message_id} → staff_msg {sent.message_id} в теме {topic_id}")
    except Exception:
        logger.exception("Ошибка при обработке сообщения клиента")

@dp.message_handler(lambda m: m.chat.id == STAFF_GROUP_ID, content_types=types.ContentTypes.ANY)
async def from_staff(message: types.Message):
    # Только разрешённые сотрудники
    if message.from_user.id not in ALLOWED_STAFF:
        logger.warning(f"Пользователь {message.from_user.id} пытался ответить без прав")
        return

    # Ответ должен быть реплаем на клиентский контент в теме
    if not message.reply_to_message:
        return

    try:
        db = load_json(DB_FILE, {})
        mapping = db.get(str(message.reply_to_message.message_id))
        if not mapping:
            # Нечему сопоставить — возможно, ответили на заголовок или старое сообщение
            logger.info("Не найдено соответствие для reply_to_message_id, пропускаю")
            return

        client_chat_id = mapping["client_chat_id"]

        # Отправляем ответ сотрудника в клиентскую группу
        if message.text:
            await bot.send_message(
                client_chat_id,
                f"👨‍💼 От сотрудника {message.from_user.full_name}: {message.text}"
            )
        elif message.photo:
            await bot.send_photo(
                client_chat_id,
                message.photo[-1].file_id,
                caption=f"👨‍💼 От сотрудника {message.from_user.full_name}" + (f"\n{message.caption}" if message.caption else "")
            )
        elif message.document:
            await bot.send_document(
                client_chat_id,
                message.document.file_id,
                caption=f"👨‍💼 От сотрудника {message.from_user.full_name}" + (f"\n{message.caption}" if message.caption else "")
            )
        elif message.voice:
            await bot.send_voice(
                client_chat_id,
                message.voice.file_id,
                caption=f"👨‍💼 От сотрудника {message.from_user.full_name}"
            )
        elif message.video:
            await bot.send_video(
                client_chat_id,
                message.video.file_id,
                caption=f"👨‍💼 От сотрудника {message.from_user.full_name}" + (f"\n{message.caption}" if message.caption else "")
            )
        elif message.sticker:
            await bot.send_sticker(
                client_chat_id,
                message.sticker.file_id
            )
        else:
            await bot.send_message(
                client_chat_id,
                f"👨‍💼 От сотрудника {message.from_user.full_name}: [тип сообщения не поддержан]"
            )

        logger.info(f"Ответ сотрудника {message.from_user.id} доставлен в {client_chat_id}")
    except Exception:
        logger.exception("Ошибка при обработке ответа сотрудника")

if __name__ == "__main__":
    logger.info("Бот запускается")
    executor.start_polling(dp, skip_updates=True)
