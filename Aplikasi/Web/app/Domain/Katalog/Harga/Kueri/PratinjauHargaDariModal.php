<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Kueri;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Harga\Layanan\PenghitungHargaDariModal;
use App\Domain\Katalog\Harga\Layanan\PerencanaHargaDariModal;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Pembelian\Kueri\HargaBeliTerakhirProduk;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Pratinjau "Harga dari harga beli" massal (D-266): untuk produk terpilih, tampilkan harga sekarang, modal (harga beli
 * terakhir per satuan jual), dan harga baru dari untung persen + pembulatan, tanpa menyimpan apa pun. Produk yang tidak
 * bisa dihitung ditandai alasannya (belum pernah dibeli, belum punya harga, bukan produk berstok biasa). `Susun`
 * dipakai juga oleh aksi penerapan supaya yang diterapkan persis yang dipratinjau.
 */
final class PratinjauHargaDariModal
{
    public const MAKS = 200;

    public function __construct(
        private readonly HargaBeliTerakhirProduk $hargaBeli,
        private readonly PerencanaHargaDariModal $perencana,
    ) {}

    /**
     * @param  list<string>  $uuid
     * @return list<array{Produk: Produk, Rencana: array{Status: string, Satuan: ProdukSatuan|null, Lama: Uang|null, ModalJual: BigDecimal|null, Baru: Uang|null}}>
     *
     * @throws PelanggaranAturanBisnis PilihanKosong, TerlaluBanyak, ProdukTidakDikenal, UntungTidakValid, PembulatanTidakDikenal
     */
    public function Susun(array $uuid, string $persenUntung, int $pembulatan): array
    {
        $persen = PenghitungHargaDariModal::AmbilPersen($persenUntung);
        PenghitungHargaDariModal::PastikanPembulatanSah($pembulatan);
        $uuid = array_values(array_unique($uuid));

        if ($uuid === []) {
            throw new PelanggaranAturanBisnis('PilihanKosong', 'Pilih minimal satu produk.', 'Uuid');
        }

        if (count($uuid) > self::MAKS) {
            throw new PelanggaranAturanBisnis('TerlaluBanyak', 'Maksimal '.self::MAKS.' produk sekali proses.', 'Uuid');
        }

        $produk = Produk::query()->whereIn('Uuid', $uuid)->orderBy('Nama')->orderBy('Id')->get();

        if ($produk->count() !== count($uuid)) {
            throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Sebagian produk tidak ditemukan. Muat ulang halaman.', 'Uuid');
        }

        $modal = $this->hargaBeli->Ambil(array_values($produk->map(fn (Produk $p): int => $p->Id)->all()));

        return array_values($produk->map(fn (Produk $p): array => [
            'Produk' => $p,
            'Rencana' => $this->perencana->Rencanakan($p, $modal[$p->Id] ?? null, $persen, $pembulatan),
        ])->all());
    }

    /**
     * @param  list<string>  $uuid
     * @return array{Baris: list<array<string, mixed>>, Ringkasan: array<string, int>}
     */
    public function Ambil(array $uuid, string $persenUntung, int $pembulatan): array
    {
        $ringkasan = [PerencanaHargaDariModal::BERUBAH => 0, PerencanaHargaDariModal::SAMA => 0, PerencanaHargaDariModal::TANPA_HARGA_BELI => 0, PerencanaHargaDariModal::TANPA_HARGA => 0, PerencanaHargaDariModal::DILEWATI_JENIS => 0];
        $baris = [];

        foreach ($this->Susun($uuid, $persenUntung, $pembulatan) as $item) {
            $p = $item['Produk'];
            $r = $item['Rencana'];
            $ringkasan[$r['Status']]++;
            $baris[] = [
                'Uuid' => $p->Uuid,
                'Nama' => $p->Nama,
                'Sku' => $p->Sku,
                'Status' => $r['Status'],
                'HargaLama' => $r['Lama']?->KeString(),
                'Modal' => $r['ModalJual'] === null ? null : (string) $r['ModalJual']->toScale(2, RoundingMode::HalfUp),
                'HargaBaru' => $r['Baru']?->KeString(),
                'PerubahanPersen' => $r['Lama'] !== null && $r['Baru'] !== null ? (string) PenghitungHargaDariModal::HitungPerubahanPersen($r['Lama'], $r['Baru']) : null,
            ];
        }

        return ['Baris' => $baris, 'Ringkasan' => $ringkasan];
    }
}
