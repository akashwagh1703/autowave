<?php

namespace App\Domain\Billing\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use RuntimeException;

/** QR codes generated on the server: SVG data URIs for the UI, PNG bytes for WhatsApp. */
final class QrCode
{
    public static function dataUri(string $text, int $size = 240): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString($text);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** PNG bytes (WhatsApp / Meta media upload). Uses GD so Imagick is not required. */
    public static function png(string $text, int $size = 400, int $margin = 2): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('The GD extension is required to generate QR images.');
        }

        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M())->getMatrix();
        $modules = $matrix->getWidth();
        $scale = max(1, intdiv(max($size, $modules), $modules + (2 * $margin)));
        $pixels = ($modules + (2 * $margin)) * $scale;

        $image = imagecreatetruecolor($pixels, $pixels);
        if ($image === false) {
            throw new RuntimeException('Could not create the QR image.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        if ($white === false || $black === false) {
            imagedestroy($image);
            throw new RuntimeException('Could not allocate QR colours.');
        }

        imagefilledrectangle($image, 0, 0, $pixels - 1, $pixels - 1, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) !== 1) {
                    continue;
                }

                $left = ($x + $margin) * $scale;
                $top = ($y + $margin) * $scale;
                imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
            }
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);
        $png = ob_get_clean();

        if ($png === false || $png === '') {
            throw new RuntimeException('Could not encode the QR image as PNG.');
        }

        return $png;
    }
}
