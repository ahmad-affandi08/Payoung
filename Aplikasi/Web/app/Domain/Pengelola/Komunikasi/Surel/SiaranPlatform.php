<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Surel;

use App\Domain\Bersama\Surel\SurelDasar;

/**
 * Siaran Payoung kepada pemilik tenant (D-210). Isi ditulis petugas sebagai teks biasa; baris kosong memisahkan paragraf.
 */
final class SiaranPlatform extends SurelDasar
{
    /** @var list<string> */
    public readonly array $paragraf;

    public function __construct(public readonly string $nama, public readonly string $judul, string $isi)
    {
        $this->paragraf = array_values(array_filter(
            array_map('trim', preg_split('/\R{2,}/', trim($isi)) ?: []),
            fn (string $p): bool => $p !== '',
        ));

        $this->subject($judul)->IsiSurel('Pengelola.SiaranPlatform', [
            'Nama' => $nama,
            'Judul' => $judul,
            'Paragraf' => $this->paragraf,
            'EmailDukungan' => config('mail.from.address'),
        ]);
    }
}
