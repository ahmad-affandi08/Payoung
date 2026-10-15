<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Integrasi\Kueri;

use App\Domain\Pengelola\Integrasi\Aksi\SimpanKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\JenisIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\LingkunganIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;

/**
 * Halaman integrasi (P-05): satu slot per jenis × lingkungan. Kredensial tidak pernah ikut, hanya petunjuknya
 * (BR-P05.1). Gerbang pembayaran sejak v2.06 diatur tenant; halaman ini hanya menampilkan katalognya
 * (`RingkasanGerbangTenant`).
 */
final class DaftarIntegrasi
{
    /**
     * @return list<array<string, mixed>>
     */
    public function Ambil(): array
    {
        $tersimpan = KonfigurasiIntegrasi::query()->get()->keyBy(
            fn (KonfigurasiIntegrasi $konfigurasi): string => $konfigurasi->Jenis->value.'|'.$konfigurasi->Lingkungan->value,
        );
        $hasil = [];

        foreach (JenisIntegrasi::AmbilJenisPlatform() as $jenis) {
            foreach (LingkunganIntegrasi::cases() as $lingkungan) {
                $konfigurasi = $tersimpan->get($jenis->value.'|'.$lingkungan->value);
                $penyedia = $konfigurasi instanceof KonfigurasiIntegrasi ? $konfigurasi->Penyedia : $jenis->AmbilPenyedia();
                $hasil[] = [
                    'Jenis' => $jenis->value,
                    'LabelJenis' => $jenis->AmbilLabel(),
                    'Lingkungan' => $lingkungan->value,
                    'LingkunganServer' => $lingkungan === LingkunganIntegrasi::AmbilSaatIni(),
                    'Penyedia' => ['Nilai' => $penyedia->value, 'Label' => $penyedia->AmbilLabel()],
                    'BidangPengaturan' => $penyedia->AmbilBidangPengaturan(),
                    'BidangKredensial' => $penyedia->AmbilBidangKredensial(),
                    // v2.04: katalog penyedia yang bisa dipilih untuk jenis ini.
                    'DaftarPenyedia' => array_map(fn (PenyediaIntegrasi $p): array => [
                        'Nilai' => $p->value,
                        'Label' => $p->AmbilLabel(),
                        'Keterangan' => $p->AmbilKeterangan(),
                        'Resmi' => $p->CekResmi(),
                        'BidangPengaturan' => $p->AmbilBidangPengaturan(),
                        'BidangKredensial' => $p->AmbilBidangKredensial(),
                    ], $jenis->AmbilDaftarPenyedia()),
                    'Konfigurasi' => $konfigurasi instanceof KonfigurasiIntegrasi ? [
                        'Uuid' => $konfigurasi->Uuid,
                        'Pengaturan' => $konfigurasi->Pengaturan,
                        // T2: kata sandi yang tersimpan sebelum aturan ini juga disembunyikan penuh saat ditampilkan.
                        'PetunjukKredensial' => array_merge(
                            $konfigurasi->PetunjukKredensial,
                            array_fill_keys(array_intersect(array_keys($konfigurasi->PetunjukKredensial), SimpanKonfigurasiIntegrasi::KUNCI_TERSEMBUNYI_PENUH), '••••'),
                        ),
                        'Aktif' => $konfigurasi->Aktif,
                        'Status' => $konfigurasi->Status->value,
                        'LabelStatus' => $konfigurasi->Status->AmbilLabel(),
                        'TerakhirDiujiPada' => $konfigurasi->TerakhirDiujiPada?->toIso8601String(),
                        'HasilUji' => $konfigurasi->HasilUji,
                        'KredensialDiubahPada' => $konfigurasi->KredensialDiubahPada->toIso8601String(),
                        'RotasiSetiapHari' => $konfigurasi->RotasiSetiapHari,
                        'PerluRotasi' => $konfigurasi->CekPerluRotasi(),
                        // D-159: kunci publik RSA SNAP (bukan rahasia) untuk didaftarkan di dasbor DOKU; kunci pribadinya tidak pernah keluar.
                        'KunciPublikSnap' => $konfigurasi->Kredensial['KunciPublikSnap'] ?? null,
                    ] : null,
                ];
            }
        }

        return $hasil;
    }
}
