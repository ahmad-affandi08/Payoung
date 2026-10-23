<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Kueri;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Data\DataPengaturanStruk;
use App\Domain\Tenant\Model\Tenant;

/**
 * Pengaturan struk tenant dari `Tenant.Pengaturan.Struk` (PRD v1.79). Nilai rusak kembali ke bawaan: saklar bawaan
 * aktif, teks terlalu panjang/kosong = null, baris kepala dipotong ke 3 baris sah.
 */
final class PengaturanStrukTenant
{
    public function __construct(private readonly KonteksTenant $konteks) {}

    /** Kunci saklar tampil yang boleh berbeda per outlet (D-76); teks isian tetap satu untuk tenant. */
    public const SAKLAR_OUTLET = [
        'TampilkanLogo', 'TampilkanAlamat', 'TampilkanTelepon', 'TampilkanNpwp',
        'TampilkanKasir', 'TampilkanPelanggan', 'TampilkanHemat', 'TampilkanStrukDigital', 'TampilkanUmpanBalik',
    ];

    /**
     * Pengaturan struk tenant; dengan `$idOutlet`, saklar tampil outlet itu menimpa saklar tenant (D-76, menggantikan D-70 per merek), teks tidak.
     */
    public function Ambil(?int $idTenant = null, ?int $idOutlet = null): DataPengaturanStruk
    {
        $pengaturan = Tenant::query()->whereKey($idTenant ?? $this->konteks->Wajib())->firstOrFail()->Pengaturan ?? [];
        $struk = is_array($pengaturan['Struk'] ?? null) ? $pengaturan['Struk'] : [];

        foreach (self::AmbilTimpaanOutlet($pengaturan, $idOutlet) as $kunci => $nilai) {
            if (in_array($kunci, self::SAKLAR_OUTLET, true) && is_bool($nilai)) {
                $struk[$kunci] = $nilai;
            }
        }
        $saklar = static fn (string $kunci): bool => ($struk[$kunci] ?? true) !== false;

        return new DataPengaturanStruk(
            tampilkanLogo: $saklar('TampilkanLogo'),
            namaDicetak: self::AmbilTeks($struk['NamaDicetak'] ?? null, DataPengaturanStruk::PANJANG_BARIS_MAKSIMAL),
            teksKepala: self::AmbilKepala($struk['TeksKepala'] ?? null),
            tampilkanAlamat: $saklar('TampilkanAlamat'),
            tampilkanTelepon: $saklar('TampilkanTelepon'),
            tampilkanNpwp: $saklar('TampilkanNpwp'),
            tampilkanKasir: $saklar('TampilkanKasir'),
            tampilkanPelanggan: $saklar('TampilkanPelanggan'),
            tampilkanHemat: $saklar('TampilkanHemat'),
            catatanKaki: self::AmbilTeks($struk['CatatanKaki'] ?? null, DataPengaturanStruk::PANJANG_CATATAN_KAKI_MAKSIMAL),
            teksPenutup: self::AmbilTeks($struk['TeksPenutup'] ?? null, DataPengaturanStruk::PANJANG_BARIS_MAKSIMAL),
            tampilkanStrukDigital: $saklar('TampilkanStrukDigital'),
            tampilkanUmpanBalik: $saklar('TampilkanUmpanBalik'),
        );
    }

    /**
     * Path logo usaha yang dicetak di struk: null bila tenant tanpa logo atau logo dimatikan di pengaturan struk.
     * Dipakai `data-awal` (`AdaLogo`) dan `GET /api/pos/v1/logo-struk` agar keduanya selalu sepakat.
     */
    public function AmbilPathLogo(int $idTenant, ?int $idOutlet = null): ?string
    {
        if (! $this->Ambil($idTenant, $idOutlet)->tampilkanLogo) {
            return null;
        }

        return $this->AmbilPathLogoUsaha($idTenant, $idOutlet);
    }

    /**
     * Path identitas merek usaha (logo outlet bila ada, selain itu logo usaha) **tanpa** melihat saklar tampil struk.
     * Dipakai bilah atas aplikasi Kasir: logo outlet harus tetap tampil walau logonya dimatikan di struk.
     */
    public function AmbilPathLogoUsaha(int $idTenant, ?int $idOutlet = null): ?string
    {
        $pengaturan = Tenant::query()->whereKey($idTenant)->firstOrFail()->Pengaturan ?? [];
        $logoOutlet = self::AmbilTimpaanOutlet($pengaturan, $idOutlet)['PathLogo'] ?? null;

        if (is_string($logoOutlet) && $logoOutlet !== '') {
            return $logoOutlet;
        }

        return is_string($pengaturan['PathLogo'] ?? null) ? $pengaturan['PathLogo'] : null;
    }

    /**
     * Path logo struk khusus outlet (null = outlet memakai logo usaha). Untuk halaman pengaturan.
     */
    public function AmbilPathLogoOutlet(int $idTenant, int $idOutlet): ?string
    {
        $pengaturan = Tenant::query()->whereKey($idTenant)->firstOrFail()->Pengaturan ?? [];
        $path = self::AmbilTimpaanOutlet($pengaturan, $idOutlet)['PathLogo'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @param  array<string, mixed>  $pengaturan
     * @return array<string, mixed>
     */
    private static function AmbilTimpaanOutlet(array $pengaturan, ?int $idOutlet): array
    {
        $semua = $pengaturan['StrukOutlet'] ?? null;
        $milikOutlet = $idOutlet === null || ! is_array($semua) ? null : ($semua[(string) $idOutlet] ?? null);

        return is_array($milikOutlet) ? $milikOutlet : [];
    }

    private static function AmbilTeks(mixed $nilai, int $panjangMaksimal): ?string
    {
        if (! is_string($nilai)) {
            return null;
        }

        $teks = trim($nilai);

        return $teks === '' || mb_strlen($teks) > $panjangMaksimal ? null : $teks;
    }

    /**
     * @return list<string>
     */
    private static function AmbilKepala(mixed $nilai): array
    {
        if (! is_array($nilai)) {
            return [];
        }

        $baris = [];

        foreach ($nilai as $teks) {
            $teks = self::AmbilTeks($teks, DataPengaturanStruk::PANJANG_BARIS_MAKSIMAL);

            if ($teks !== null && count($baris) < DataPengaturanStruk::JUMLAH_TEKS_KEPALA_MAKSIMAL) {
                $baris[] = $teks;
            }
        }

        return $baris;
    }
}
