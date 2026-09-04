<?php

namespace App\Services\ChatAttachments;

use App\Services\ChatAttachments\Exceptions\ChatAttachmentException;
use Illuminate\Http\UploadedFile;

final class ChatAttachmentInspector
{
    /**
     * @return array{mime: string, width: int, height: int, bytes: string}
     */
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new ChatAttachmentException('invalid_upload', 'Не удалось загрузить файл.');
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw new ChatAttachmentException('empty_file', 'Файл пустой.');
        }

        if ($size > ChatAttachmentConfig::maxFileSizeBytes()) {
            throw new ChatAttachmentException(
                'file_too_large',
                'Изображение больше '.$this->mb(ChatAttachmentConfig::maxFileSizeMb()).'.',
            );
        }

        $bytes = file_get_contents($file->getRealPath() ?: $file->getPathname());

        if (! is_string($bytes) || $bytes === '') {
            throw new ChatAttachmentException('unreadable_file', 'Не удалось прочитать изображение.');
        }

        $detected = $this->detectMime($file, $bytes);

        if (! in_array($detected, ChatAttachmentConfig::allowedMimeTypes(), true)) {
            throw new ChatAttachmentException('mime_not_allowed', 'Этот тип файла не принимается. Нужны PNG, JPEG или WebP.');
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! isset($info[0], $info[1], $info[2])) {
            throw new ChatAttachmentException('malformed_image', 'Файл не является корректным изображением.');
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $imageType = (int) $info[2];

        if ($width < 1 || $height < 1) {
            throw new ChatAttachmentException('malformed_image', 'Файл не является корректным изображением.');
        }

        $fromType = $this->mimeFromImageType($imageType);

        if ($fromType === null || $fromType !== $detected) {
            throw new ChatAttachmentException('mime_mismatch', 'Тип изображения не совпадает с содержимым файла.');
        }

        $pixels = $width * $height;

        if ($pixels > ChatAttachmentConfig::maxPixels()) {
            throw new ChatAttachmentException('image_too_large', 'Изображение слишком большое по разрешению.');
        }

        $this->assertDecodable($bytes, $imageType);

        return [
            'mime' => $detected,
            'width' => $width,
            'height' => $height,
            'bytes' => $bytes,
        ];
    }

    private function detectMime(UploadedFile $file, string $bytes): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = strtolower((string) $finfo->buffer($bytes));

        if ($detected === 'image/jpg') {
            $detected = 'image/jpeg';
        }

        $forbidden = [
            'image/svg+xml',
            'text/html',
            'text/xml',
            'application/xml',
            'application/pdf',
            'application/javascript',
            'application/x-php',
            'application/x-executable',
        ];

        $client = strtolower((string) $file->getMimeType());

        if (in_array($detected, $forbidden, true) || in_array($client, $forbidden, true)) {
            throw new ChatAttachmentException('mime_not_allowed', 'Этот тип файла не принимается. Нужны PNG, JPEG или WebP.');
        }

        return $detected;
    }

    private function mimeFromImageType(int $imageType): ?string
    {
        return match ($imageType) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
            default => null,
        };
    }

    private function assertDecodable(string $bytes, int $imageType): void
    {
        if (! function_exists('imagecreatefromstring')) {
            return;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            if ($imageType === IMAGETYPE_WEBP && ! function_exists('imagecreatefromwebp')) {
                return;
            }

            throw new ChatAttachmentException('malformed_image', 'Файл не является корректным изображением.');
        }

        imagedestroy($image);
    }

    private function mb(int $mb): string
    {
        return $mb.' МБ';
    }
}
