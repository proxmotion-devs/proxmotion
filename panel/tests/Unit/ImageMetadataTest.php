<?php

namespace Tests\Unit;

use App\Support\Images\ImageMetadata;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Metadata removed from uploaded logos (docs/adr/0053), the image data kept.
 */
#[RequiresPhpExtension('gd')]
class ImageMetadataTest extends TestCase
{
    private const string SECRET = 'GPS 52.5200 13.4050';

    private function image(string $type): string
    {
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        match ($type) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image),
            'webp' => imagewebp($image),
        };

        return (string) ob_get_clean();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);
        $this->assertNotFalse($info);

        return [$info[0], $info[1]];
    }

    public function test_png_text_chunks_are_removed()
    {
        $png = $this->image('png');
        $data = "Comment\0".self::SECRET;
        $chunk = pack('N', strlen($data)).'tEXt'.$data.pack('N', crc32('tEXt'.$data));
        // Right after the signature and IHDR (8 + 25 bytes).
        $png = substr($png, 0, 33).$chunk.substr($png, 33);

        $stripped = ImageMetadata::strip($png, 'image/png');

        $this->assertNotNull($stripped);
        $this->assertStringNotContainsString(self::SECRET, $stripped);
        $this->assertSame($this->image('png'), $stripped);
        $this->assertSame([20, 10], $this->dimensions($stripped));
    }

    public function test_jpeg_exif_and_comments_are_removed_and_fill_bytes_are_read()
    {
        $jpeg = $this->image('jpeg');
        $exif = "Exif\0\0".self::SECRET;
        $comment = 'by '.self::SECRET;
        $jpeg = "\xFF\xD8\xFF\xFF\xE1".pack('n', strlen($exif) + 2).$exif."\xFF\xFE".pack('n', strlen($comment) + 2).$comment.substr($jpeg, 2);

        $stripped = ImageMetadata::strip($jpeg, 'image/jpeg');

        $this->assertNotNull($stripped);
        $this->assertStringNotContainsString(self::SECRET, $stripped);
        // GD writes a comment of its own, which goes too.
        $this->assertStringNotContainsString('CREATOR', $stripped);
        $this->assertSame(ImageMetadata::strip($this->image('jpeg'), 'image/jpeg'), $stripped);
        $this->assertSame([20, 10], $this->dimensions($stripped));
    }

    public function test_webp_exif_is_removed_and_the_sizes_fixed()
    {
        $webp = $this->image('webp');
        $exif = 'EXIF'.pack('V', strlen(self::SECRET)).self::SECRET.(strlen(self::SECRET) % 2 === 1 ? "\0" : '');
        $chunks = substr($webp, 12).$exif;
        $webp = 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;

        $stripped = ImageMetadata::strip($webp, 'image/webp');

        $this->assertNotNull($stripped);
        $this->assertStringNotContainsString(self::SECRET, $stripped);
        $this->assertSame($this->image('webp'), $stripped);
        $this->assertSame([20, 10], $this->dimensions($stripped));
    }

    public function test_malformed_files_are_refused()
    {
        $png = $this->image('png');

        $this->assertNull(ImageMetadata::strip(substr($png, 0, 40), 'image/png'));
        $this->assertNull(ImageMetadata::strip('<svg/>', 'image/png'));
        $this->assertNull(ImageMetadata::strip("\xFF\xD8\xFF\xE1\xFF\xFF", 'image/jpeg'));
        $this->assertNull(ImageMetadata::strip('RIFF....WEBPVP8 '.pack('V', 9999), 'image/webp'));
        $this->assertNull(ImageMetadata::strip($png, 'image/svg+xml'));
    }
}
