<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * Removes metadata from PNG, JPEG and WebP files without decoding them
 * (docs/adr/0053): EXIF, XMP, IPTC and comments can carry the camera, the
 * place a photo was taken or its author. Colour profiles stay, so the image
 * looks the same. Only whole metadata blocks are dropped; the image data is
 * copied byte for byte.
 */
final class ImageMetadata
{
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** PNG chunks holding text or EXIF. */
    private const array PNG_DROPPED = ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'];

    /** JPEG markers after SOI holding EXIF/XMP (APP1), IPTC (APP13) and comments (COM). */
    private const array JPEG_DROPPED = [0xE1, 0xED, 0xFE];

    /** WebP chunks holding EXIF and XMP, and the VP8X flags announcing them. */
    private const array WEBP_DROPPED = ['EXIF', 'XMP '];

    private const int WEBP_FLAG_EXIF = 0x08;

    private const int WEBP_FLAG_XMP = 0x04;

    /**
     * The file without its metadata, or null when it is not a well-formed
     * file of the type.
     */
    public static function strip(string $bytes, string $type): ?string
    {
        return match ($type) {
            'image/png' => self::png($bytes),
            'image/jpeg' => self::jpeg($bytes),
            'image/webp' => self::webp($bytes),
            default => null,
        };
    }

    private static function png(string $bytes): ?string
    {
        if (! str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return null;
        }

        $out = self::PNG_SIGNATURE;
        $offset = strlen(self::PNG_SIGNATURE);

        while ($offset + 12 <= strlen($bytes)) {
            $length = self::uint32be($bytes, $offset);
            $type = substr($bytes, $offset + 4, 4);
            // Length, type, data and CRC.
            $size = 12 + $length;

            if ($offset + $size > strlen($bytes)) {
                return null;
            }

            if (! in_array($type, self::PNG_DROPPED, true)) {
                $out .= substr($bytes, $offset, $size);
            }

            $offset += $size;

            if ($type === 'IEND') {
                return $out;
            }
        }

        return null;
    }

    private static function jpeg(string $bytes): ?string
    {
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            return null;
        }

        $out = "\xFF\xD8";
        $offset = 2;

        while ($offset + 4 <= strlen($bytes)) {
            if ($bytes[$offset] !== "\xFF") {
                return null;
            }

            $marker = ord($bytes[$offset + 1]);

            // Fill bytes before a marker.
            if ($marker === 0xFF) {
                $offset++;

                continue;
            }

            // Start of scan: the compressed image follows to the end of the file.
            if ($marker === 0xDA) {
                return $out.substr($bytes, $offset);
            }

            // Segment length, itself included.
            $length = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);

            if ($length < 2 || $offset + 2 + $length > strlen($bytes)) {
                return null;
            }

            if (! in_array($marker, self::JPEG_DROPPED, true)) {
                $out .= substr($bytes, $offset, 2 + $length);
            }

            $offset += 2 + $length;
        }

        return null;
    }

    private static function webp(string $bytes): ?string
    {
        if (strlen($bytes) < 12 || ! str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            return null;
        }

        $chunks = '';
        $offset = 12;

        while ($offset + 8 <= strlen($bytes)) {
            $type = substr($bytes, $offset, 4);
            $length = self::uint32le($bytes, $offset + 4);
            // Chunks are padded to an even length.
            $size = 8 + $length + ($length % 2);

            if ($offset + 8 + $length > strlen($bytes)) {
                return null;
            }

            $chunk = substr($bytes, $offset, $size);

            if ($type === 'VP8X' && $length >= 1) {
                $flags = ord($chunk[8]) & ~(self::WEBP_FLAG_EXIF | self::WEBP_FLAG_XMP);
                $chunk[8] = chr($flags);
            }

            if (! in_array($type, self::WEBP_DROPPED, true)) {
                $chunks .= $chunk;
            }

            $offset += $size;
        }

        if ($chunks === '') {
            return null;
        }

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    private static function uint32be(string $bytes, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('N', $bytes, $offset);

        return $value[1];
    }

    private static function uint32le(string $bytes, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('V', $bytes, $offset);

        return $value[1];
    }
}
