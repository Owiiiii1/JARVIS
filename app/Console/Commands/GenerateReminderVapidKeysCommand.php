<?php

namespace App\Console\Commands;

use App\Services\Reminders\VapidConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

#[Signature('jarvis:reminders:vapid {--print : Show the public key only}')]
#[Description('Create instance VAPID keys once and persist them in .env. Does not rotate existing keys.')]
class GenerateReminderVapidKeysCommand extends Command
{
    public function handle(): int
    {
        if (VapidConfig::isConfigured()) {
            $this->info('VAPID keys already configured.');
            $this->line('Public key: '.VapidConfig::publicKey());

            if ($this->option('print')) {
                return self::SUCCESS;
            }

            $this->comment('Private key is not printed. Remove VAPID_* from .env only if you intend to rotate.');

            return self::SUCCESS;
        }

        $keys = VAPID::createVapidKeys();
        $subject = VapidConfig::subject();
        $this->writeEnv($keys['publicKey'], $keys['privateKey'], $subject);

        $this->info('VAPID keys written to .env.');
        $this->line('Public key: '.$keys['publicKey']);

        return self::SUCCESS;
    }

    private function writeEnv(string $public, string $private, string $subject): void
    {
        $path = base_path('.env');

        if (! is_file($path) || ! is_writable($path)) {
            $this->error('.env is missing or not writable. Add these keys manually:');
            $this->line('VAPID_PUBLIC_KEY='.$public);
            $this->line('VAPID_PRIVATE_KEY='.$private);
            $this->line('VAPID_SUBJECT='.$subject);

            return;
        }

        $contents = file_get_contents($path) ?: '';
        $block = PHP_EOL.'# Web Push VAPID (Phase B.1). Do not regenerate on deploy.'.PHP_EOL
            .'VAPID_PUBLIC_KEY='.$public.PHP_EOL
            .'VAPID_PRIVATE_KEY='.$private.PHP_EOL
            .'VAPID_SUBJECT='.$subject.PHP_EOL;

        if (str_contains($contents, 'VAPID_PUBLIC_KEY=')) {
            $contents = preg_replace('/^VAPID_PUBLIC_KEY=.*$/m', 'VAPID_PUBLIC_KEY='.$public, $contents) ?? $contents;
            $contents = preg_replace('/^VAPID_PRIVATE_KEY=.*$/m', 'VAPID_PRIVATE_KEY='.$private, $contents) ?? $contents;
            $contents = preg_replace('/^VAPID_SUBJECT=.*$/m', 'VAPID_SUBJECT='.$subject, $contents) ?? $contents;
            file_put_contents($path, $contents);

            return;
        }

        file_put_contents($path, rtrim($contents).$block);
    }
}
