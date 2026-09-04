<?php

namespace App\Services\Integrations\Google;

use App\Services\Integrations\Exceptions\IntegrationException;

final class GmailAddressValidator
{
    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    public function emails(mixed $raw, int $max, bool $required = false, string $field = 'recipients'): array
    {
        if ($raw === null || $raw === '') {
            if ($required) {
                throw new IntegrationException('invalid_arguments', $field.' is required.');
            }

            return [];
        }

        $items = is_array($raw) ? $raw : (preg_split('/\s*,\s*/', (string) $raw) ?: []);
        if (count($items) > $max) {
            throw new IntegrationException('invalid_arguments', 'Too many '.$field.'.');
        }

        $emails = [];
        foreach ($items as $item) {
            $email = strtolower(trim((string) $item));
            if ($email === '') {
                continue;
            }

            if (preg_match('/[\r\n]/', $email) === 1) {
                throw new IntegrationException('invalid_arguments', 'Recipient contains a forbidden newline.');
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new IntegrationException('gmail_invalid_recipient', 'Recipient email is invalid.');
            }

            $emails[] = $email;
        }

        $emails = array_values(array_unique($emails));
        if ($required && $emails === []) {
            throw new IntegrationException('invalid_arguments', $field.' is required.');
        }

        return $emails;
    }

    public function headerText(mixed $value, int $max, bool $required = false): string
    {
        $text = trim((string) $value);
        if (preg_match('/[\r\n]/', (string) $value) === 1) {
            throw new IntegrationException('invalid_arguments', 'Text contains a forbidden newline.');
        }

        if ($text === '') {
            if ($required) {
                throw new IntegrationException('invalid_arguments', 'A required text field is empty.');
            }

            return '';
        }

        if (mb_strlen($text) > $max) {
            throw new IntegrationException('invalid_arguments', 'A text field exceeds the configured maximum.');
        }

        return $text;
    }

    public function bodyText(mixed $value, int $max, bool $required = false): string
    {
        $text = trim((string) $value);
        if (str_contains((string) $value, "\0")) {
            throw new IntegrationException('invalid_arguments', 'Body contains a forbidden character.');
        }

        if ($text === '') {
            if ($required) {
                throw new IntegrationException('invalid_arguments', 'A required text field is empty.');
            }

            return '';
        }

        if (mb_strlen($text) > $max) {
            throw new IntegrationException('invalid_arguments', 'A text field exceeds the configured maximum.');
        }

        return $text;
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    public function labelIds(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $items = is_array($raw) ? $raw : [(string) $raw];
        $max = (int) config('google_gmail.max_labels', 50);
        if (count($items) > $max) {
            throw new IntegrationException('invalid_arguments', 'Too many labels.');
        }

        $ids = [];
        foreach ($items as $item) {
            $id = trim((string) $item);
            if ($id === '') {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9_\-\/]+$/', $id) !== 1) {
                throw new IntegrationException('invalid_arguments', 'Label id is invalid.');
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }
}
