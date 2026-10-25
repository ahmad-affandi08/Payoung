<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;

/**
 * F-08 (D-189): memeriksa teks payload QRIS **statis** milik toko sebelum disimpan. Aplikasi Kasir menyusun QRIS
 * bernominal dari teks ini di perangkat (tag 01 = 12, tag 54 = nominal, CRC dihitung ulang), jadi teks yang cacat harus
 * ditolak sejak diunggah. Aturan dan kode galat sama dengan padanannya `Qris` (Dart, `Paket/Inti`) dan `Pustaka/Qris.ts`
 * (web), dijaga vektor bersama `Spesifikasi/VektorUjiQris/QrisDinamis.json`.
 */
final class PemeriksaQris
{
    public const BIDANG = 'TeksQris';

    /** CRC16/CCITT-FALSE (polinomial 0x1021, awal 0xFFFF), empat digit heksa huruf besar. */
    public function HitungCrc16(string $teks): string
    {
        $crc = 0xFFFF;

        foreach (str_split($teks) as $karakter) {
            $crc ^= ord($karakter) << 8;

            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return str_pad(strtoupper(dechex($crc)), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<array{0: string, 1: string}> pasangan [tag, nilai] tingkat atas
     */
    public function Urai(string $teks): array
    {
        if (strlen($teks) < 12 || ! str_starts_with($teks, '000201')) {
            throw new PelanggaranAturanBisnis('BukanQris', 'Ini bukan kode QRIS. Pastikan gambar berisi QRIS dari bank atau penyedia Anda.', self::BIDANG);
        }

        $hasil = [];
        $i = 0;
        $panjangTeks = strlen($teks);

        while ($i < $panjangTeks) {
            $tag = substr($teks, $i, 2);
            $panjangTeksTag = substr($teks, $i + 2, 2);

            if ($i + 4 > $panjangTeks || preg_match('/^\d{2}$/', $tag) !== 1 || preg_match('/^\d{2}$/', $panjangTeksTag) !== 1 || $i + 4 + (int) $panjangTeksTag > $panjangTeks) {
                throw new PelanggaranAturanBisnis('BukanQris', 'Kode QRIS terpotong. Gunakan gambar QRIS yang utuh.', self::BIDANG);
            }

            $hasil[] = [$tag, substr($teks, $i + 4, (int) $panjangTeksTag)];
            $i += 4 + (int) $panjangTeksTag;
        }

        return $hasil;
    }

    /**
     * Memastikan [teks] QRIS statis yang utuh (CRC sah, rupiah Indonesia, ada blok merchant).
     *
     * @return array{NamaMerchant: string, Kota: string}
     */
    public function Periksa(string $teks): array
    {
        $isi = trim($teks);

        if ($isi === '') {
            throw new PelanggaranAturanBisnis('Kosong', 'Kode QRIS kosong.', self::BIDANG);
        }

        $bagian = $this->Urai($isi);
        $kunciTerakhir = array_key_last($bagian);

        if ($kunciTerakhir === null) {
            throw new PelanggaranAturanBisnis('BukanQris', 'Ini bukan kode QRIS. Pastikan gambar berisi QRIS dari bank atau penyedia Anda.', self::BIDANG);
        }

        $terakhir = $bagian[$kunciTerakhir];

        if ($terakhir[0] !== '63' || strlen($terakhir[1]) !== 4) {
            throw new PelanggaranAturanBisnis('BukanQris', 'Kode QRIS tidak lengkap. Gunakan gambar QRIS yang utuh.', self::BIDANG);
        }

        if ($this->HitungCrc16(substr($isi, 0, -4)) !== strtoupper($terakhir[1])) {
            throw new PelanggaranAturanBisnis('CrcTidakValid', 'Kode QRIS rusak atau sudah diubah (checksum tidak cocok). Unggah ulang gambar QRIS asli.', self::BIDANG);
        }

        $nilai = [];

        foreach ($bagian as [$tag, $isiTag]) {
            // Kunci diawali 'T': PHP mengubah kunci string numerik ("53") menjadi int, sehingga analisis statis salah paham.
            $nilai['T'.$tag] ??= $isiTag;
        }

        $jenisInisiasi = $nilai['T01'] ?? null;
        $mataUang = $nilai['T53'] ?? null;
        $negara = $nilai['T58'] ?? null;

        if ($jenisInisiasi === '12') {
            throw new PelanggaranAturanBisnis('BukanStatis', 'QRIS ini sudah berisi nominal (dinamis). Unggah QRIS statis toko Anda.', self::BIDANG);
        }

        if ($jenisInisiasi !== '11') {
            throw new PelanggaranAturanBisnis('BukanQris', 'Jenis QRIS tidak dikenali. Unggah QRIS statis toko Anda.', self::BIDANG);
        }

        if ($mataUang !== '360' || $negara !== 'ID') {
            throw new PelanggaranAturanBisnis('BukanRupiahIndonesia', 'QRIS ini bukan QRIS rupiah Indonesia.', self::BIDANG);
        }

        $adaMerchant = false;

        foreach ($bagian as [$tag]) {
            $adaMerchant = $adaMerchant || ((int) $tag >= 26 && (int) $tag <= 51);
        }

        if (! $adaMerchant) {
            throw new PelanggaranAturanBisnis('TanpaMerchant', 'QRIS ini tidak memuat data merchant. Unggah QRIS dari bank atau penyedia Anda.', self::BIDANG);
        }

        return ['NamaMerchant' => $nilai['T59'] ?? '', 'Kota' => $nilai['T60'] ?? ''];
    }
}
