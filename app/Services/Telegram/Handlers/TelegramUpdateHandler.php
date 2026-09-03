<?php

namespace App\Services\Telegram\Handlers;

use App\Enums\MessageChannel;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Services\Conversations\ChannelContext;
use App\Services\Conversations\ConversationAiService;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\ConversationTurnService;
use App\Services\Telegram\Pairing\TelegramInboundContext;
use App\Services\Telegram\Pairing\TelegramPairingMessages;
use App\Services\Telegram\Pairing\TelegramPairingOutcome;
use App\Services\Telegram\Pairing\TelegramPairingService;
use App\Services\Telegram\TelegramChatKeyboard;
use App\Services\Telegram\TelegramConversationMessages;
use App\Services\Telegram\TelegramIdentityState;
use DateTimeImmutable;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\MessageType as TelegramMessageType;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Throwable;

final class TelegramUpdateHandler
{
    public function __construct(
        private readonly TelegramPairingService $pairingService,
        private readonly ConversationService $conversationService,
        private readonly TelegramIdentityState $identityState,
        private readonly TelegramChatKeyboard $keyboard,
        private readonly ConversationAiService $conversationAi,
        private readonly ConversationTurnService $conversationTurns,
    ) {}

    public function handleMessage(Nutgram $bot): void
    {
        $message = $bot->message();

        if ($message === null) {
            return;
        }

        if (! $this->isPrivateChat($message)) {
            if ($this->isGroupLikeChat($message)) {
                $this->send($bot, TelegramPairingMessages::GROUP_PAIRING_HINT, chatId: $message->chat->id);
            }

            return;
        }

        $from = $message->from;

        if ($from === null) {
            return;
        }

        $context = new TelegramInboundContext(
            externalUserId: (string) $from->id,
            externalChatId: (string) $message->chat->id,
            username: $from->username,
            firstName: $from->first_name,
            lastName: $from->last_name,
        );

        $identity = $this->pairingService->findTelegramIdentity($context->externalUserId);

        if ($identity !== null) {
            $this->pairingService->touchIdentity($identity, $context);
            $this->handlePairedMessage($bot, $message, $identity);

            return;
        }

        if ($this->isStartCommand($message)) {
            $this->send($bot, TelegramPairingMessages::REQUEST_CODE);

            return;
        }

        if ($message->getType() === TelegramMessageType::TEXT && filled($message->text)) {
            $result = $this->pairingService->attemptPairing($context, trim($message->text));

            foreach ($result->messages as $response) {
                $this->send($bot, $response);
            }

            if ($result->outcome === TelegramPairingOutcome::Paired && $result->identity !== null) {
                $result->identity->loadMissing('user');
                $conversation = $this->conversationService->ensureActiveConversation($result->identity);
                $this->reply(
                    $bot,
                    TelegramConversationMessages::connectedWithChat($conversation->title),
                    $result->identity,
                );

                $greeting = $this->conversationAi->greetAfterPairing($result->identity->user, $conversation);
                $greetingText = $greeting->replyText();

                if (filled($greetingText) && ! $greeting->skipped) {
                    $this->reply($bot, $greetingText, $result->identity);
                }
            }

            return;
        }

        $this->send($bot, TelegramPairingMessages::SEND_CODE_AS_TEXT);
    }

    public function handleCallbackQuery(Nutgram $bot): void
    {
        $query = $bot->callbackQuery();

        if ($query === null || $query->from === null) {
            return;
        }

        $identity = $this->pairingService->findTelegramIdentity((string) $query->from->id);

        if ($identity === null) {
            $this->answerCallback($bot);

            return;
        }

        $conversationId = $this->keyboard->parseSelectCallback($query->data);

        if ($conversationId === null) {
            $this->answerCallback($bot);

            return;
        }

        $this->identityState->clear($identity);
        $conversation = $this->conversationService->findOwned($identity->user, $conversationId);

        if ($conversation === null || ! $this->conversationService->setActiveConversation($identity, $conversation)) {
            $this->answerCallback($bot, TelegramConversationMessages::CHAT_NOT_FOUND);
            $this->reply($bot, TelegramConversationMessages::CHAT_NOT_FOUND, $identity);

            return;
        }

        $this->answerCallback($bot);
        $this->reply($bot, TelegramConversationMessages::chatSelected($conversation->title), $identity);
    }

    private function handlePairedMessage(Nutgram $bot, Message $message, ChannelIdentity $identity): void
    {
        $identity->loadMissing('user');
        $conversation = $this->conversationService->ensureActiveConversation($identity);

        if ($this->isStartCommand($message)) {
            $this->identityState->clear($identity);
            $this->reply($bot, TelegramConversationMessages::connectedWithChat($conversation->title), $identity);

            return;
        }

        if ($message->getType() !== TelegramMessageType::TEXT || ! filled($message->text)) {
            $this->reply($bot, TelegramPairingMessages::UNSUPPORTED_MESSAGE_TYPE, $identity);

            return;
        }

        $text = trim($message->text);

        if ($this->identityState->isAwaitingNewChatTitle($identity)) {
            $this->handleNewChatTitle($bot, $identity, $text);

            return;
        }

        if ($this->isMenuAction($text, TelegramChatKeyboard::BUTTON_CHATS, '/chats')) {
            $this->showChatList($bot, $identity);

            return;
        }

        if ($this->isMenuAction($text, TelegramChatKeyboard::BUTTON_NEW_CHAT, '/newchat')) {
            $this->identityState->setAwaitingNewChatTitle($identity);
            $this->reply(
                $bot,
                TelegramConversationMessages::ENTER_NEW_CHAT_TITLE,
                $identity,
                awaitingTitle: true,
            );

            return;
        }

        if ($this->isMenuAction($text, TelegramChatKeyboard::BUTTON_CURRENT_CHAT, '/current')) {
            $this->reply($bot, TelegramConversationMessages::currentChat($conversation->title), $identity);

            return;
        }

        $this->persistPairedText($bot, $message, $identity, $conversation, $text);
    }

    private function handleNewChatTitle(Nutgram $bot, ChannelIdentity $identity, string $text): void
    {
        if ($this->isCancel($text)) {
            $this->identityState->clear($identity);
            $conversation = $this->conversationService->ensureActiveConversation($identity);
            $this->reply(
                $bot,
                TelegramConversationMessages::CANCELLED.' '.TelegramConversationMessages::currentChat($conversation->title),
                $identity,
            );

            return;
        }

        if (! $this->conversationService->isValidTitle($text)) {
            $this->reply(
                $bot,
                TelegramConversationMessages::INVALID_TITLE,
                $identity,
                awaitingTitle: true,
            );

            return;
        }

        $conversation = $this->conversationService->createPersonal($identity->user, $text);
        $this->conversationService->setActiveConversation($identity, $conversation);
        $this->identityState->clear($identity);
        $this->reply($bot, TelegramConversationMessages::chatCreated($conversation->title), $identity);
    }

    private function showChatList(Nutgram $bot, ChannelIdentity $identity): void
    {
        $conversations = $this->conversationService->listForUser($identity->user);

        if ($conversations->isEmpty()) {
            $this->reply($bot, TelegramConversationMessages::NO_CHATS, $identity);

            return;
        }

        $text = TelegramConversationMessages::SELECT_CHAT;

        if ($conversations->count() === ConversationService::LIST_LIMIT) {
            $text .= "\n".TelegramConversationMessages::LIST_TRUNCATED;
        }

        $this->send($bot, $text, replyMarkup: $this->keyboard->chatList($conversations));
    }

    private function persistPairedText(
        Nutgram $bot,
        Message $message,
        ChannelIdentity $identity,
        Conversation $conversation,
        string $text,
    ): void {
        $turn = $this->conversationTurns->handleUserMessage(
            $identity->user,
            $conversation,
            $text,
            new ChannelContext(
                channel: MessageChannel::Telegram,
                channelMessageId: (string) $message->message_id,
                occurredAt: (new DateTimeImmutable)->setTimestamp((int) $message->date),
            ),
        );

        if (! $turn->created) {
            return;
        }

        $reply = $turn->replyText() ?? ConversationAiService::AI_FAILURE;

        $this->reply($bot, $reply, $identity);
    }

    private function reply(Nutgram $bot, string $text, ChannelIdentity $identity, bool $awaitingTitle = false): void
    {
        $this->send($bot, $text, replyMarkup: $this->keyboard->menu($awaitingTitle));
    }

    private function send(
        Nutgram $bot,
        string $text,
        InlineKeyboardMarkup|ReplyKeyboardMarkup|null $replyMarkup = null,
        int|string|null $chatId = null,
    ): void {
        try {
            $bot->sendMessage(
                text: $text,
                chat_id: $chatId,
                reply_markup: $replyMarkup,
            );
        } catch (Throwable) {
            // Outbound Telegram failures must not fail webhook processing.
        }
    }

    private function answerCallback(Nutgram $bot, ?string $text = null): void
    {
        try {
            $bot->answerCallbackQuery(text: $text);
        } catch (Throwable) {
            // Ignore missing/invalid callback queries.
        }
    }

    private function isMenuAction(string $text, string $button, string $command): bool
    {
        if ($text === $button) {
            return true;
        }

        return preg_match('/^'.preg_quote($command, '/').'(?:@\w+)?(?:\s|$)/u', $text) === 1;
    }

    private function isCancel(string $text): bool
    {
        return $text === TelegramChatKeyboard::BUTTON_CANCEL
            || preg_match('/^\/cancel(?:@\w+)?(?:\s|$)/u', $text) === 1;
    }

    private function isPrivateChat(Message $message): bool
    {
        return $message->chat->type === ChatType::PRIVATE;
    }

    private function isGroupLikeChat(Message $message): bool
    {
        return in_array($message->chat->type, [ChatType::GROUP, ChatType::SUPERGROUP], true);
    }

    private function isStartCommand(Message $message): bool
    {
        if ($message->getType() !== TelegramMessageType::TEXT || ! filled($message->text)) {
            return false;
        }

        return preg_match('/^\/start(?:@\w+)?(?:\s|$)/u', trim($message->text)) === 1;
    }
}
