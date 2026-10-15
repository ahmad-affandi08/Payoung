<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Snap;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** Gambar QR (SVG) dari isi QRIS DOKU untuk halaman bayar Payoung. Dibuat di server: isi QR tidak melewati pustaka pihak ketiga di peramban. */
final class PembuatQrSnap
{
    public function BuatSvg(string $isiQr): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(280, 2), new SvgImageBackEnd)))->writeString($isiQr);
    }
}
