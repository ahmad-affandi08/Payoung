<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

use App\Domain\Tenant\Model\PengaturanPlatform;

/**
 * Pengaturan penagihan platform yang diubah Super Admin dari konsol (P-08, D-114): status PKP dan rekening tujuan
 * transfer. Nilai dari konsol (`PengaturanPlatform`) menang; selama belum pernah disimpan dari konsol dipakai nilai
 * awal dari server (`config/tagihan.php`, diisi `.env` pada deployment lama) supaya deployment yang sudah berjalan
 * tidak tiba-tiba berubah, misalnya mulai menagih PPN sebelum SPPKP terbit.
 */
final class PengaturanPenagihanPlatform
{
    public const KUNCI_PKP = 'tagihan.platform-pkp';

    public const KUNCI_REKENING = 'tagihan.rekening-tujuan';

    public function PlatformPkp(): bool
    {
        $nilai = $this->Baca(self::KUNCI_PKP);

        return $nilai === null ? (bool) config('tagihan.PlatformPkp') : $nilai === '1';
    }

    public function PkpDariKonsol(): bool
    {
        return $this->Baca(self::KUNCI_PKP) !== null;
    }

    /**
     * @return list<array{Kode: string, NamaBank: string, NomorRekening: string, AtasNama: string}>
     */
    public function Rekening(): array
    {
        $disimpan = $this->Baca(self::KUNCI_REKENING);
        $daftar = $disimpan === null ? config('tagihan.RekeningTujuan') : json_decode($disimpan, true);
        $hasil = [];

        foreach (is_array($daftar) ? $daftar : [] as $baris) {
            if (! is_array($baris)) {
                continue;
            }

            $rekening = [
                'Kode' => self::Teks($baris, 'Kode'),
                'NamaBank' => self::Teks($baris, 'NamaBank'),
                'NomorRekening' => self::Teks($baris, 'NomorRekening'),
                'AtasNama' => self::Teks($baris, 'AtasNama'),
            ];

            // Baris tanpa kode, bank, atau nomor diabaikan; tanpa rekening, tagihan manual belum bisa dibayar.
            if ($rekening['Kode'] !== '' && $rekening['NamaBank'] !== '' && $rekening['NomorRekening'] !== '') {
                $hasil[] = $rekening;
            }
        }

        return $hasil;
    }

    public function RekeningDariKonsol(): bool
    {
        return $this->Baca(self::KUNCI_REKENING) !== null;
    }

    private function Baca(string $kunci): ?string
    {
        $nilai = PengaturanPlatform::query()->where('Kunci', $kunci)->value('Nilai');

        return is_string($nilai) ? $nilai : null;
    }

    /**
     * @param  array<mixed>  $baris
     */
    private static function Teks(array $baris, string $kunci): string
    {
        $nilai = $baris[$kunci] ?? '';

        return is_string($nilai) ? trim($nilai) : '';
    }
}
