# Голосовая архитектура

Целевые фазы: **Phase 3** (подключение голоса к тому же ядру), **Phase 4** (естественный realtime). В Phase 1–2 голос не реализуется, но текстовой pipeline не должен мешать появлению STT/TTS.

Voice — experimental / future. Другой интерфейс к **тому же** Jarvis, не отдельный ассистент и не отдельный User Space. После распознавания реплика идёт в [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md). UI Orb отделён от runtime: [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md).

### Invariants (фиксированы)

- тот же User Space;
- тот же selected conversation (`active_conversation_id` / открытый chat);
- тот же Conversation Engine;
- тот же AI configuration соответствующего space (Owner Conversation AI или Default User Conversation AI);
- одна memory;
- никаких отдельных voice memories.

Transport / STT / TTS / realtime / interruption — `TBD` практическими тестами. Не закрывать эти решения в docs как будто уже выбраны.

---

## Не целевая схема

```
записал файл → долго обработали целиком → получили mp3
```

Допустима как временный технический костыль на самом старте Phase 3 (`TBD`), но не как архитектура продукта.

## Целевая схема

Максимально realtime разговор:

- понять начало и конец реплики;
- быстро ответить;
- позволить перебить;
- остановить собственную речь при перебивании;
- сохранить тот же conversation/memory context, что у текста.

---

## Voice Runtime vs Voice UI

**Voice Runtime** (этот документ): audio transport, STT, TTS, turn detection, realtime provider, `voice_sessions`.

**Voice UI** ([CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md)): Orb, transcript, controls. Consumes `VoiceVisualizationState` (`state`, input/output amplitude, frequency bands, connection state). Not bound to ElevenLabs or any vendor.

Provider can change without rewriting the Orb. UI can change without rewriting Core.

## Слои (Runtime)

```
Audio I/O (mic / speaker)
        ↓
Speech-to-Text          ← provider abstraction
        ↓
Turn detection          ← конец реплики / пауза / barge-in
        ↓
Conversation orchestration  ← тот же Core engine
        ↓
LLM                     ← AI Layer
        ↓
Text-to-Speech          ← provider abstraction
        ↓
Streaming playback
        ↓
VoiceVisualizationState → Voice UI (Orb)
```

### Speech-to-Text

Поток или чанки аудио → текст (частичный и финальный). Частичные гипотезы можно показывать в UI; в raw messages писать **финальный** текст реплики (политика промежуточных — `TBD`).

### Turn detection

Когда пользователь закончил говорить; когда начал перебивать Jarvis. Не путать с LLM. Может быть VAD, time-based, серверный endpointing. Конкретный алгоритм — `TBD`.

### Conversation orchestration

Тот же engine: persist, retrieve, generate, persist. Voice session ссылается на `conversation_id`.

### LLM

Без специального «голосового мозга». Допустимы другие decoding-параметры (короче ответы) через настройки, не через отдельную память.

### Text-to-Speech

Текст ответа → аудио. Предпочтительный provider на старте Phase 3: **ElevenLabs**. Обязателен abstraction layer: смена vendor не меняет Core. ADR-008.

### Streaming

Частичный LLM-текст может уходить в TTS до конца полного ответа (`TBD` protocol). Клиент играет поток.

### Interruption / barge-in

Пользователь заговорил — клиент/сервер:

1. останавливает playback;
2. помечает незавершённый assistant turn (`TBD`: сохранить обрезанный текст или discarded flag);
3. запускает новый inbound.

Не создавать второго пользователя и второй memory store.

---

## Provider abstraction

Логические порты:

- `SpeechToTextProvider`
- `TextToSpeechProvider`
- опционально `RealtimeDuplexProvider`, если vendor даёт один сокет на оба направления (`TBD`)

ElevenLabs — дефолтная реализация TTS (и возможно больше, `TBD`), не часть доменной модели.

Другие кандидаты не фиксируются.

---

## Сессии

Концепт `voice_sessions`:

- принадлежит user + conversation;
- канал-origin: web workspace / desktop / mobile;
- состояние: idle / connecting / listening / thinking / speaking / interrupted / error / muted;
- ссылки на messages, порождённые за сессию.

Точный realtime транспорт (WebRTC vs WebSocket audio) — `TBD`.

---

## Что не делать

- Не хранить «голосовую память» отдельно от текстовой.
- Не вызывать ElevenLabs из Telegram adapter или из Orb shaders.
- Не требовать Vector DB ради голоса.
- Не блокировать Phase 1 выбором speech vendor.
- Не создавать отдельную voice conversation, если пользователь не выбрал New Chat.
- Не делать Voice Mode отдельным ассистентом.
