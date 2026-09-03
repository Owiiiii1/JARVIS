<?php

namespace App\Services\Telegram\Pairing;

use App\Enums\UserStatus;
use App\Models\ChannelIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class TelegramPairingService
{
    public function findTelegramIdentity(string $externalUserId): ?ChannelIdentity
    {
        return ChannelIdentity::findTelegramByExternalUserId($externalUserId);
    }

    public function touchIdentity(ChannelIdentity $identity, TelegramInboundContext $context): void
    {
        $identity->fill([
            'username' => $context->username,
            'first_name' => $context->firstName,
            'last_name' => $context->lastName,
            'last_seen_at' => now(),
        ]);

        if ($identity->isDirty()) {
            $identity->save();
        } else {
            $identity->forceFill(['last_seen_at' => now()])->save();
        }
    }

    public function attemptPairing(TelegramInboundContext $context, string $accessCode): TelegramPairingResult
    {
        $existingIdentity = $this->findTelegramIdentity($context->externalUserId);

        if ($existingIdentity !== null) {
            $this->touchIdentity($existingIdentity, $context);

            return new TelegramPairingResult(
                outcome: TelegramPairingOutcome::AlreadyLinked,
                messages: [TelegramPairingMessages::ALREADY_AUTHORIZED],
                identity: $existingIdentity,
            );
        }

        $user = User::query()
            ->where('access_code', $accessCode)
            ->first();

        if ($user === null) {
            return new TelegramPairingResult(
                outcome: TelegramPairingOutcome::InvalidCode,
                messages: [TelegramPairingMessages::INVALID_CODE],
            );
        }

        if (! $user->isActive() || $user->status !== UserStatus::Active) {
            return new TelegramPairingResult(
                outcome: TelegramPairingOutcome::DisabledUser,
                messages: [TelegramPairingMessages::DISABLED_USER],
            );
        }

        if (ChannelIdentity::findTelegramForUser($user->id) !== null) {
            return new TelegramPairingResult(
                outcome: TelegramPairingOutcome::UserAlreadyHasTelegram,
                messages: [TelegramPairingMessages::USER_ALREADY_HAS_TELEGRAM],
            );
        }

        try {
            $identity = DB::transaction(function () use ($user, $context): ChannelIdentity {
                if (ChannelIdentity::findTelegramForUser($user->id) !== null) {
                    throw new \RuntimeException('user_already_has_telegram');
                }

                if (ChannelIdentity::findTelegramByExternalUserId($context->externalUserId) !== null) {
                    throw new \RuntimeException('telegram_already_linked');
                }

                return ChannelIdentity::query()->create([
                    'user_id' => $user->id,
                    'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
                    'external_user_id' => $context->externalUserId,
                    'external_chat_id' => $context->externalChatId,
                    'username' => $context->username,
                    'first_name' => $context->firstName,
                    'last_name' => $context->lastName,
                    'linked_at' => now(),
                    'last_seen_at' => now(),
                ]);
            });
        } catch (QueryException|\RuntimeException) {
            $linked = $this->findTelegramIdentity($context->externalUserId);

            if ($linked !== null) {
                return new TelegramPairingResult(
                    outcome: TelegramPairingOutcome::AlreadyLinked,
                    messages: [TelegramPairingMessages::ALREADY_AUTHORIZED],
                    identity: $linked,
                );
            }

            return new TelegramPairingResult(
                outcome: TelegramPairingOutcome::UserAlreadyHasTelegram,
                messages: [TelegramPairingMessages::USER_ALREADY_HAS_TELEGRAM],
            );
        }

        return new TelegramPairingResult(
            outcome: TelegramPairingOutcome::Paired,
            messages: TelegramPairingResult::successMessages(),
            identity: $identity,
        );
    }

    public function unlinkTelegram(User $user): bool
    {
        $deleted = ChannelIdentity::query()
            ->where('user_id', $user->id)
            ->where('channel', ChannelIdentity::CHANNEL_TELEGRAM)
            ->delete();

        return $deleted > 0;
    }
}
