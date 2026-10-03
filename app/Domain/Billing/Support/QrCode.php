<?php

namespace App\Domain\Billing\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** A QR code as an SVG data URI, generated on the server (the UPI payment link with the amount filled in). */
final class QrCode
{
    public static function dataUri(string $text, int $size = 240): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString($text);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
