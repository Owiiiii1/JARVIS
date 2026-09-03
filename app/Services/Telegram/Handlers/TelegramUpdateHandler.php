<?php

namespace App\Services\Telegram\Handlers;

use App\Models\ChannelIdentity;
use App\Services\Telegram\Pairing\TelegramInboundContext;
use App\Services\Telegram\Pairing\TelegramPairingMessages;
use App\Services\Telegram\Pairing\TelegramPairingService;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\MessageType;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

final class TelegramUpdateHandler
{
    public function __construct(
        private readonly TelegramPairingService $pairingService,
    ) {}

    public function handleMessage(Nutgram $bot): void
    {
        $message = $bot->message();

        if ($message === null) {
            return;
        }

        if (! $this->isPrivateChat($message)) {
            if ($this->isGroupLikeChat($message)) {
                $bot->sendMessage(
                    text: TelegramPairingMessages::GROUP_PAIRING_HINT,
                    chat_id: $message->chat->id,
                );
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
            $bot->sendMessage(text: TelegramPairingMessages::REQUEST_CODE);

            return;
        }

        if ($message->getType() === MessageType::TEXT && filled($message->text)) {
            $result = $this->pairingService->attemptPairing($context, trim($message->text));

            foreach ($result->messages as $response) {
                $bot->sendMessage(text: $response);
            }

            return;
        }

        $bot->sendMessage(text: TelegramPairingMessages::SEND_CODE_AS_TEXT);
    }

    private function handlePairedMessage(Nutgram $bot, Message $message, ChannelIdentity $identity): void
    {
        if ($this->isStartCommand($message)) {
            $bot->sendMessage(text: TelegramPairingMessages::ALREADY_AUTHORIZED);
            $bot->sendMessage(text: TelegramPairingMessages::AI_COMING_SOON);

            return;
        }

        if ($message->getType() === MessageType::TEXT && filled($message->text)) {
            $bot->sendMessage(text: TelegramPairingMessages::AI_COMING_SOON);

            return;
        }

        $bot->sendMessage(text: TelegramPairingMessages::UNSUPPORTED_MESSAGE_TYPE);
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
        if ($message->getType() !== MessageType::TEXT || ! filled($message->text)) {
            return false;
        }

        return preg_match('/^\/start(?:@\w+)?(?:\s|$)/u', trim($message->text)) === 1;
    }
}
