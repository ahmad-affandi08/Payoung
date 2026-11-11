<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Kueri;

use App\Domain\Katalog\Harga\Enum\ArahHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\CakupanAturanHarga;
use App\Domain\Katalog\Harga\Enum\StatusUsulanHarga;
use App\Domain\Katalog\Harga\Layanan\PenghitungPerubahanHarga;
use App\Domain\Katalog\Harga\Model\AturanHargaOtomatis;
use App\Domain\Katalog\Harga\Model\PengaturanHargaOtomatis;
use App\Domain\Katalog\Harga\Model\UsulanHargaOtomatis;
use App\Domain\Katalog\Layanan\PohonKategoriTenant;
use App\Domain\Katalog\Model\Kategori;
use App\Domain\Katalog\Model\Produk;

/**
 * Data halaman Harga otomatis (D-266): pengaturan, daftar aturan beserta nama sasarannya, usulan yang menunggu, dan
 * pilihan untuk formulir. Aturan dan usulan jumlahnya kecil (satu per kategori atau produk pilihan pemilik).
 */
final class HalamanHargaOtomatis
{
    /**
     * @return array<string, mixed>
     */
    public function Ambil(): array
    {
        $pengaturan = PengaturanHargaOtomatis::query()->first();
        $pohon = PohonKategoriTenant::Muat();
        $aturan = AturanHargaOtomatis::query()->orderBy('Cakupan')->orderBy('Id')->get();
        $uuidKategori = Kategori::query()->whereIn('Id', $aturan->pluck('IdKategori')->filter()->all())->pluck('Uuid', 'Id')->all();
        $produk = Produk::query()->whereIn('Id', $aturan->pluck('IdProduk')->filter()->all())->get(['Id', 'Uuid', 'Nama', 'Sku'])->keyBy('Id');

        return [
            'Pengaturan' => [
                'Aktif' => $pengaturan->Aktif ?? false,
                'BatasPerubahanPersen' => (string) ($pengaturan->BatasPerubahanPersen ?? PengaturanHargaOtomatis::BATAS_BAWAAN),
            ],
            'Aturan' => array_values($aturan->map(function (AturanHargaOtomatis $a) use ($pohon, $uuidKategori, $produk): array {
                $p = $a->IdProduk === null ? null : $produk->get($a->IdProduk);

                return [
                    'Uuid' => $a->Uuid,
                    'Cakupan' => $a->Cakupan->value,
                    'Sasaran' => match ($a->Cakupan) {
                        CakupanAturanHarga::Semua => 'Semua produk berstok',
                        CakupanAturanHarga::Kategori => $a->IdKategori === null ? '-' : $pohon->AmbilJalur($a->IdKategori),
                        CakupanAturanHarga::Produk => $p->Nama ?? '-',
                    },
                    'UuidKategori' => $a->IdKategori === null ? null : ($uuidKategori[$a->IdKategori] ?? null),
                    'Produk' => $p === null ? null : ['Uuid' => $p->Uuid, 'Nama' => $p->Nama, 'Sku' => $p->Sku],
                    'PersenUntung' => (string) $a->PersenUntung,
                    'Pembulatan' => $a->Pembulatan,
                    'Arah' => $a->Arah->value,
                    'Aktif' => $a->Aktif,
                ];
            })->all()),
            'Usulan' => array_values(UsulanHargaOtomatis::query()
                ->where('Status', StatusUsulanHarga::Menunggu->value)
                ->with('Produk:Id,Uuid,Nama,Sku')
                ->orderByDesc('Id')
                ->limit(200)
                ->get()
                ->map(fn (UsulanHargaOtomatis $u): array => [
                    'Uuid' => $u->Uuid,
                    'Produk' => ['Uuid' => $u->Produk->Uuid, 'Nama' => $u->Produk->Nama, 'Sku' => $u->Produk->Sku],
                    'Modal' => (string) $u->Modal,
                    'PersenUntung' => (string) $u->PersenUntung,
                    'HargaLama' => (string) $u->HargaLama,
                    'HargaBaru' => (string) $u->HargaBaru,
                    'PerubahanPersen' => (string) $u->PerubahanPersen,
                    'DibuatPada' => $u->DibuatPada?->toIso8601String(),
                ])->all()),
            'OpsiKategori' => array_values(Kategori::query()->orderBy('Nama')->get(['Id', 'Uuid'])->map(fn (Kategori $k): array => ['Uuid' => $k->Uuid, 'Nama' => $pohon->AmbilJalur($k->Id)])->all()),
            'OpsiCakupan' => array_map(fn (CakupanAturanHarga $c): array => ['Nilai' => $c->value, 'Label' => $c->AmbilLabel()], CakupanAturanHarga::cases()),
            'OpsiArah' => array_map(fn (ArahHargaOtomatis $a): array => ['Nilai' => $a->value, 'Label' => $a->AmbilLabel()], ArahHargaOtomatis::cases()),
            'OpsiPembulatan' => PenghitungPerubahanHarga::PEMBULATAN,
        ];
    }
}
