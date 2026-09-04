<?php

namespace App\Services\Integrations\Google;

use App\Services\Integrations\Exceptions\IntegrationException;

final class GmailMimeBuilder
{
    /**
     * @param  array{
     *     to: list<string>,
     *     cc?: list<string>,
     *     bcc?: list<string>,
     *     subject: string,
     *     body: string,
     *     in_reply_to?: string|null,
     *     references?: string|null
     * }  $message
     */
    public function encode(array $message): string
    {
        $lines = [
            'MIME-Version: 1.0',
            'Date: '.now()->toRfc2822String(),
            'To: '.$this->addressLine($message['to']),
        ];

        if (! empty($message['cc'])) {
            $lines[] = 'Cc: '.$this->addressLine($message['cc']);
        }
        if (! empty($message['bcc'])) {
            $lines[] = 'Bcc: '.$this->addressLine($message['bcc']);
        }

        $lines[] = 'Subject: '.$this->encodeSubject((string) $message['subject']);
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: 8bit';

        $inReplyTo = trim((string) ($message['in_reply_to'] ?? ''));
        if ($inReplyTo !== '') {
            $lines[] = 'In-Reply-To: '.$this->safeHeader($inReplyTo);
            $references = trim((string) ($message['references'] ?? ''));
            $lines[] = 'References: '.$this->safeHeader($references !== '' ? $references.' '.$inReplyTo : $inReplyTo);
        }

        $raw = implode("\r\n", $lines)."\r\n\r\n".(string) $message['body'];

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param  list<string>  $addresses
     */
    private function addressLine(array $addresses): string
    {
        return $this->safeHeader(implode(', ', $addresses));
    }

    private function encodeSubject(string $subject): string
    {
        $subject = $this->safeHeader($subject);
        if ($subject === '' || preg_match('/^[\x20-\x7E]+$/', $subject) === 1) {
            return $subject;
        }

        return '=?UTF-8?B?'.base64_encode($subject).'?=';
    }

    private function safeHeader(string $value): string
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new IntegrationException('invalid_arguments', 'Header contains a forbidden newline.');
        }

        return trim($value);
    }
}
