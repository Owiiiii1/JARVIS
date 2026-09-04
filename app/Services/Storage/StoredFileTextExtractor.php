<?php

namespace App\Services\Storage;

use App\Services\Storage\Exceptions\StoredFileException;
use Illuminate\Http\UploadedFile;

final class StoredFileTextExtractor
{
    /**
     * @return array{text: string, mime: string, extension: string, original_name: ?string, size: int, sha256: string}
     */
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new StoredFileException('invalid_upload', 'Не удалось загрузить файл.');
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw new StoredFileException('empty_file', 'Файл пустой.');
        }

        if ($size > StoredFileConfig::maxFileSizeBytes()) {
            throw new StoredFileException('file_too_large', 'Файл больше '.StoredFileConfig::maxFileSizeMb().' МБ.');
        }

        $bytes = file_get_contents($file->getRealPath() ?: $file->getPathname());

        if (! is_string($bytes) || $bytes === '') {
            throw new StoredFileException('unreadable_file', 'Не удалось прочитать файл.');
        }

        if (str_contains($bytes, "\0")) {
            throw new StoredFileException('binary_rejected', 'Бинарные файлы в Storage пока не принимаются.');
        }

        $name = $this->sanitizeName($file->getClientOriginalName());
        $extension = $this->extensionFromName($name);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = strtolower(trim(explode(';', (string) $finfo->buffer($bytes))[0]));

        if ($detected === 'image/jpg') {
            $detected = 'image/jpeg';
        }

        if (str_starts_with($detected, 'image/') || in_array($detected, ['application/pdf', 'application/zip', 'application/x-executable'], true)) {
            throw new StoredFileException('type_rejected', 'Этот тип файла нельзя сохранить в Storage. Нужен текстовый или исходный файл.');
        }

        if ($extension === '' || ! in_array($extension, StoredFileConfig::allowedExtensions(), true)) {
            throw new StoredFileException('extension_rejected', 'Это расширение не принимается в Storage.');
        }

        $allowedMimes = StoredFileConfig::allowedMimeTypes();
        $mimeOk = $detected === ''
            || in_array($detected, $allowedMimes, true)
            || str_starts_with($detected, 'text/')
            || in_array($detected, [
                'application/json',
                'application/xml',
                'application/javascript',
                'application/octet-stream',
                'inode/x-empty',
            ], true);

        if (! $mimeOk) {
            throw new StoredFileException('mime_rejected', 'Этот тип файла нельзя сохранить в Storage. Нужен текстовый или исходный файл.');
        }

        $text = $this->normalizeText($bytes);

        if ($text === '') {
            throw new StoredFileException('empty_file', 'Файл пустой.');
        }

        $max = StoredFileConfig::maxExtractedChars();

        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
        }

        return [
            'text' => $text,
            'mime' => $detected !== '' && $detected !== 'application/octet-stream' ? $detected : 'text/plain',
            'extension' => $extension,
            'original_name' => $name,
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'bytes' => $bytes,
        ];
    }

    public function extractFromBytes(string $bytes): string
    {
        return $this->normalizeText($bytes);
    }

    private function normalizeText(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        $bytes = str_replace(["\r\n", "\r"], "\n", $bytes);

        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1251,Windows-1252');

            if (! is_string($converted) || ! mb_check_encoding($converted, 'UTF-8')) {
                throw new StoredFileException('encoding_unsupported', 'Не удалось прочитать файл как текст.');
            }

            $bytes = $converted;
        }

        return trim($bytes);
    }

    private function sanitizeName(?string $name): ?string
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $base) ?? '';
        $base = trim($base, '._ ');

        return $base === '' ? null : mb_substr($base, 0, 160);
    }

    private function extensionFromName(?string $name): string
    {
        if (! is_string($name) || ! str_contains($name, '.')) {
            return '';
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext === 'htm') {
            return 'html';
        }

        return $ext;
    }
}
