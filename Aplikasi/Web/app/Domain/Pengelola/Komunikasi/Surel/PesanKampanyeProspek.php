<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Surel;

use App\Domain\Bersama\Surel\SurelDasar;
use Symfony\Component\Mime\Email;

/**
 * Email kampanye Payoung ke calon pelanggan (D-216). Isi teks biasa (baris kosong memisahkan paragraf). Setiap email
 * memuat sumber alamat, tautan berhenti, dan header `List-Unsubscribe` satu klik. Dikirim lewat pengirim "kampanye"
 * (integrasi `EmailKampanye`), bukan pengirim email transaksi, supaya reputasi domain transaksi terjaga.
 */
final class PesanKampanyeProspek extends SurelDasar
{
    /** @var list<string> */
    public readonly array $paragraf;

    public function __construct(
        public readonly string $subjek,
        string $isi,
        public readonly string $tautanBerhenti,
        public readonly string $sumber,
        string $alamatPengirim,
        string $namaPengirim,
    ) {
        $this->paragraf = array_values(array_filter(
            array_map('trim', preg_split('/\R{2,}/', trim($isi)) ?: []),
            fn (string $p): bool => $p !== '',
        ));

        $this->from($alamatPengirim, $namaPengirim)->subject($subjek)->IsiSurel('Pengelola.KampanyeProspek', [
            'Judul' => $subjek,
            'Paragraf' => $this->paragraf,
            'TautanBerhenti' => $tautanBerhenti,
            'Sumber' => $sumber,
            'EmailDukungan' => $alamatPengirim,
        ]);

        $this->withSymfonyMessage(function (Email $pesan) use ($tautanBerhenti): void {
            $pesan->getHeaders()->addTextHeader('List-Unsubscribe', "<{$tautanBerhenti}>");
            $pesan->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }
}
