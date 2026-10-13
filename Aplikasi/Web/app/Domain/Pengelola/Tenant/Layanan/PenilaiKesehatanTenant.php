<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Layanan;

use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;

/**
 * Menilai kesehatan satu tenant dari ukuran pemakaiannya (P-07 PGL-09). Fungsi murni, tanpa akses basis data. Skor 0–100:
 *
 * | Faktor | Maks | Aturan |
 * |---|---|---|
 * | Hari aktif | 40 | hari bertransaksi dalam 14 hari terakhir ÷ target (10 hari; tenant baru diukur dari umurnya) |
 * | Tren transaksi | 15 | 7 hari terakhir ÷ 7 hari sebelumnya: ≥ 90% = 15, ≥ 60% = 8, kurang = 0; tanpa pembanding = 15 bila ada transaksi |
 * | Fitur utama | 20 | 4 poin per fitur yang dipakai 30 hari terakhir (maks. 5 fitur) |
 * | Perangkat | 10 | 10 bila tak ada perangkat lama offline, 4 bila ada |
 * | Tiket | 5 | 5 bila tak ada tiket terbuka lewat 3 hari, 0 bila ada |
 * | Tagihan | 10 | 10 bila langganan tidak tertunggak, 0 bila tertunggak |
 *
 * Kategori: ≥ 70 `Sehat`, 40–69 `PerluPerhatian`, selain itu `Berisiko`. Tenant yang baru mendaftar kurang dari
 * `HARI_MASA_PENGENALAN` hari tidak pernah dicap `Berisiko` (turun satu tingkat ke `PerluPerhatian`): belum bertransaksi di
 * minggu pertama itu wajar, dan yang perlu dikejar ditangani tugas onboarding. Semua hitungan bilangan bulat.
 */
final class PenilaiKesehatanTenant
{
    public const HARI_PENGUKURAN = 14;

    public const TARGET_HARI_AKTIF = 10;

    public const MAKS_FITUR = 5;

    public const POIN_PER_FITUR = 4;

    public const BATAS_SEHAT = 70;

    public const BATAS_PERLU_PERHATIAN = 40;

    public const HARI_MASA_PENGENALAN = 7;

    /**
     * @param  array{HariAktif: int, TransaksiMingguIni: int, TransaksiMingguLalu: int, FiturDipakai: int, PerangkatLamaOffline: int, TiketTerbukaLama: int, Tertunggak: bool, UmurHari: int}  $ukuran
     * @return array{Skor: int, Kategori: KategoriKesehatan, Faktor: array<string, array{Nilai: int|bool, Poin: int, Maks: int}>}
     */
    public function Nilai(array $ukuran): array
    {
        $tersedia = max(1, min(self::HARI_PENGUKURAN, $ukuran['UmurHari'] + 1));
        // Tenant baru tidak dihukum karena umurnya: target hari aktif mengecil sebanding hari yang sudah tersedia.
        $target = max(1, min(self::TARGET_HARI_AKTIF, intdiv($tersedia * self::TARGET_HARI_AKTIF + self::HARI_PENGUKURAN - 1, self::HARI_PENGUKURAN)));
        $poinAktif = intdiv(min($ukuran['HariAktif'], $target) * 40, $target);

        $ini = $ukuran['TransaksiMingguIni'];
        $lalu = $ukuran['TransaksiMingguLalu'];
        $poinTren = match (true) {
            $lalu === 0 => $ini > 0 ? 15 : 0,
            $ini * 10 >= $lalu * 9 => 15,
            $ini * 10 >= $lalu * 6 => 8,
            default => 0,
        };

        $poinFitur = min($ukuran['FiturDipakai'], self::MAKS_FITUR) * self::POIN_PER_FITUR;
        $poinPerangkat = $ukuran['PerangkatLamaOffline'] > 0 ? 4 : 10;
        $poinTiket = $ukuran['TiketTerbukaLama'] > 0 ? 0 : 5;
        $poinTagihan = $ukuran['Tertunggak'] ? 0 : 10;
        $skor = $poinAktif + $poinTren + $poinFitur + $poinPerangkat + $poinTiket + $poinTagihan;
        $kategori = $skor >= self::BATAS_SEHAT ? KategoriKesehatan::Sehat : ($skor >= self::BATAS_PERLU_PERHATIAN ? KategoriKesehatan::PerluPerhatian : KategoriKesehatan::Berisiko);

        if ($kategori === KategoriKesehatan::Berisiko && $ukuran['UmurHari'] < self::HARI_MASA_PENGENALAN) {
            $kategori = KategoriKesehatan::PerluPerhatian;
        }

        return [
            'Skor' => $skor,
            'Kategori' => $kategori,
            'Faktor' => [
                'HariAktif' => ['Nilai' => $ukuran['HariAktif'], 'Poin' => $poinAktif, 'Maks' => 40],
                'TrenTransaksi' => ['Nilai' => $ini, 'Poin' => $poinTren, 'Maks' => 15],
                'FiturDipakai' => ['Nilai' => $ukuran['FiturDipakai'], 'Poin' => $poinFitur, 'Maks' => self::MAKS_FITUR * self::POIN_PER_FITUR],
                'PerangkatLamaOffline' => ['Nilai' => $ukuran['PerangkatLamaOffline'], 'Poin' => $poinPerangkat, 'Maks' => 10],
                'TiketTerbukaLama' => ['Nilai' => $ukuran['TiketTerbukaLama'], 'Poin' => $poinTiket, 'Maks' => 5],
                'Tertunggak' => ['Nilai' => $ukuran['Tertunggak'], 'Poin' => $poinTagihan, 'Maks' => 10],
            ],
        ];
    }
}
