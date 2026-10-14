<?php

declare(strict_types=1);

namespace App\Domain\Bersama\Sinkron\Layanan;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Menghitung penolakan item sinkron per (jenis item, kode galat) untuk dasbor operasional (PGL-20 lanjutan). Hanya satu
 * penghitung di store cache `metrik` per menit; tidak menyentuh basis data di jalur sinkron dan kegagalan cache tidak
 * pernah mengubah hasil sinkron. `Panen()` dipanggil detak scheduler (domain Pengelola) yang menyimpannya per jam. Yang
 * dicatat hanya jenis dan kode: tanpa pesan, isi, pengguna, perangkat, maupun tenant.
 */
final class PencatatGalatSinkron
{
    private const AWALAN = 'metrik-galat-sinkron';

    private const TTL_DETIK = 1800;

    /** Pasangan (jenis, kode) berbeda per menit yang dihitung; sisanya digabung ke `Lainnya`. */
    private const MAKS_PASANGAN = 60;

    /** Menit yang masih dicari penghitungnya saat dipanen (menutup detak yang terlewat). */
    private const MENIT_TELUSUR = 10;

    public function Catat(string $jenisItem, string $kodeGalat): void
    {
        try {
            $kunci = self::Kunci((int) now()->startOfMinute()->getTimestamp());
            $nilai = $this->Penyimpan()->get($kunci);
            $nilai = is_array($nilai) ? $nilai : [];
            $pasangan = mb_substr($jenisItem, 0, 60).'|'.mb_substr($kodeGalat, 0, 80);

            if (! isset($nilai[$pasangan]) && count($nilai) >= self::MAKS_PASANGAN) {
                $pasangan = 'Lainnya|Lainnya';
            }

            $nilai[$pasangan] = (int) ($nilai[$pasangan] ?? 0) + 1;
            $this->Penyimpan()->put($kunci, $nilai, self::TTL_DETIK);
        } catch (Throwable) {
            // Pelengkap: cache bermasalah tidak boleh mengganggu sinkron.
        }
    }

    /**
     * Mengambil dan mengosongkan penghitung menit-menit yang sudah lewat.
     *
     * @return list<array{Menit: int, JenisItem: string, KodeGalat: string, Jumlah: int}>
     */
    public function Panen(): array
    {
        $menitIni = (int) now()->startOfMinute()->getTimestamp();
        $hasil = [];

        for ($mundur = 1; $mundur <= self::MENIT_TELUSUR; $mundur++) {
            $menit = $menitIni - ($mundur * 60);
            $nilai = $this->Penyimpan()->pull(self::Kunci($menit));

            if (! is_array($nilai)) {
                continue;
            }

            foreach ($nilai as $pasangan => $jumlah) {
                [$jenis, $kode] = array_pad(explode('|', (string) $pasangan, 2), 2, 'Lainnya');
                $hasil[] = ['Menit' => $menit, 'JenisItem' => $jenis, 'KodeGalat' => $kode, 'Jumlah' => (int) $jumlah];
            }
        }

        return $hasil;
    }

    private static function Kunci(int $menit): string
    {
        return self::AWALAN.":{$menit}";
    }

    private function Penyimpan(): Repository
    {
        return Cache::store('metrik');
    }
}
