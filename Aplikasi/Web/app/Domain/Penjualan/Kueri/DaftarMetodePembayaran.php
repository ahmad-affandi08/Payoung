<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Referensi\Kueri\ReferensiBankAktif;

/**
 * Metode pembayaran tenant aktif (F-01 langkah 5, checklist "Langkah Berikutnya"). Path gambar QRIS tidak pernah
 * dikirim; pemanggil membuat tautan unduh lewat rute yang memeriksa izin.
 */
final class DaftarMetodePembayaran
{
    public function __construct(private readonly ReferensiBankAktif $referensiBank) {}

    /**
     * @return list<array{Uuid: string, Jenis: string, LabelJenis: string, Nama: string, NamaBank: string|null, NomorRekening: string|null, NamaPemilikRekening: string|null, Kanal: string|null, LabelKanal: string|null, PersenBiaya: string, BatasHariMenunggu: int|null, BatasHariKustom: int|null, AdaGambarQris: bool, NominalOtomatis: bool, Aktif: bool, Wajib: bool}>
     */
    public function Ambil(): array
    {
        $daftar = MetodePembayaran::query()->orderBy('Urutan')->orderBy('Id')->get();
        $namaBank = $this->referensiBank->AmbilNama(array_values(array_filter($daftar->pluck('IdReferensiBank')->all(), 'is_int')));

        return array_values($daftar->map(fn (MetodePembayaran $metode): array => [
            'Uuid' => $metode->Uuid,
            'Jenis' => $metode->Jenis->value,
            'LabelJenis' => $metode->Jenis->AmbilLabel(),
            'Nama' => $metode->Nama,
            'NamaBank' => $metode->IdReferensiBank === null ? null : ($namaBank[$metode->IdReferensiBank] ?? null),
            'NomorRekening' => $metode->NomorRekening,
            'NamaPemilikRekening' => $metode->NamaPemilikRekening,
            'Kanal' => $metode->Kanal?->value,
            'LabelKanal' => $metode->Kanal?->AmbilLabel(),
            'PersenBiaya' => $metode->PersenBiaya,
            // F-08: batas hari menunggu pencairan (null = metode tanpa pencairan); `Kustom` null = memakai bawaan jenis.
            'BatasHariMenunggu' => PembayaranBelumDicairkan::AmbilBatasHari($metode),
            'BatasHariKustom' => $metode->BatasHariMenunggu,
            'AdaGambarQris' => $metode->PathGambarQris !== null,
            // D-189: QRIS statis yang teksnya tersimpan = kasir menyusun QRIS bernominal.
            'NominalOtomatis' => $metode->TeksQris !== null,
            'Aktif' => $metode->Aktif,
            'Wajib' => $metode->Jenis === JenisMetodePembayaran::Tunai,
        ])->all());
    }

    /**
     * Metode aktif berjenis fase 1 untuk `data-awal` POS (F-07b). Path gambar QRIS tidak dikirim; perangkat mengunduh
     * lewat `GET /api/pos/v1/metode-pembayaran/{uuid}/gambar-qris`.
     *
     * @return list<array{Uuid: string, Jenis: string, Nama: string, NomorRekening: string|null, NamaPemilikRekening: string|null, AdaGambarQris: bool, TeksQris: string|null, Urutan: int, Kanal: string|null}>
     */
    public function AmbilUntukPos(): array
    {
        return array_values(MetodePembayaran::query()
            ->where('Aktif', true)
            ->orderBy('Urutan')
            ->orderBy('Id')
            ->get()
            ->filter(fn (MetodePembayaran $metode): bool => $metode->Jenis->CekDidukungPos())
            ->map(fn (MetodePembayaran $metode): array => [
                'Uuid' => $metode->Uuid,
                'Jenis' => $metode->Jenis->value,
                'Nama' => $metode->Nama,
                'NomorRekening' => $metode->NomorRekening,
                'NamaPemilikRekening' => $metode->NamaPemilikRekening,
                'AdaGambarQris' => $metode->PathGambarQris !== null,
                // D-189: payload QRIS statis (publik di gambar QR-nya); kasir menyusun QRIS bernominal secara lokal.
                'TeksQris' => $metode->Jenis === JenisMetodePembayaran::QrisStatis ? $metode->TeksQris : null,
                'Urutan' => $metode->Urutan,
                // X8: kanal platform metode Marketplace (kasir memakainya hanya untuk penjualan kanal itu).
                'Kanal' => $metode->Kanal?->value,
            ])
            ->all());
    }

    /**
     * Path gambar QRIS statis untuk unduhan POS (F-07b): hanya metode QRIS statis yang **aktif** di tenant aktif, sama
     * dengan daftar `MetodePembayaran` di `data-awal` (metode nonaktif tidak bisa dipilih di kasir, jadi gambarnya
     * tidak disajikan). Null bila tidak ada.
     */
    public function CariPathGambarQrisPos(string $uuid): ?string
    {
        $metode = MetodePembayaran::query()
            ->where('Uuid', $uuid)
            ->where('Jenis', JenisMetodePembayaran::QrisStatis->value)
            ->where('Aktif', true)
            ->first();

        return $metode?->PathGambarQris;
    }

    public function Cari(string $uuid): ?MetodePembayaran
    {
        return MetodePembayaran::query()->where('Uuid', $uuid)->first();
    }

    /** Checklist: metode pembayaran aktif selain Tunai yang sudah diatur. */
    public function HitungAktifSelainTunai(): int
    {
        return MetodePembayaran::query()
            ->where('Aktif', true)
            ->where('Jenis', '!=', JenisMetodePembayaran::Tunai->value)
            ->count();
    }
}
