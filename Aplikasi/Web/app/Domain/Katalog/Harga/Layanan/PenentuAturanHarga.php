<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Layanan;

use App\Domain\Katalog\Harga\Enum\CakupanAturanHarga;
use App\Domain\Katalog\Harga\Model\AturanHargaOtomatis;
use App\Domain\Katalog\Layanan\PohonKategoriTenant;
use App\Domain\Katalog\Model\Produk;

/**
 * Memilih aturan harga otomatis untuk satu produk (D-266). Yang paling khusus menang: aturan Produk, lalu aturan
 * kategori produk itu, lalu aturan kategori induk (turun-temurun sampai akar), lalu aturan Semua. Hanya aturan aktif.
 * Dimuat sekali per proses supaya satu penerimaan dengan ratusan produk tidak mengulang kueri.
 */
final class PenentuAturanHarga
{
    /** @var array<int, AturanHargaOtomatis> kunci = IdProduk */
    private array $perProduk = [];

    /** @var array<int, AturanHargaOtomatis> kunci = IdKategori */
    private array $perKategori = [];

    private ?AturanHargaOtomatis $semua = null;

    private function __construct(private readonly PohonKategoriTenant $pohon) {}

    public static function Muat(): self
    {
        $penentu = new self(PohonKategoriTenant::Muat());

        foreach (AturanHargaOtomatis::query()->where('Aktif', true)->orderBy('Id')->get() as $aturan) {
            match ($aturan->Cakupan) {
                CakupanAturanHarga::Produk => $aturan->IdProduk === null ?: $penentu->perProduk[$aturan->IdProduk] = $aturan,
                CakupanAturanHarga::Kategori => $aturan->IdKategori === null ?: $penentu->perKategori[$aturan->IdKategori] = $aturan,
                CakupanAturanHarga::Semua => $penentu->semua = $aturan,
            };
        }

        return $penentu;
    }

    public function Tentukan(Produk $produk): ?AturanHargaOtomatis
    {
        if (isset($this->perProduk[$produk->Id])) {
            return $this->perProduk[$produk->Id];
        }

        // Varian mewarisi aturan dari produk induknya sebelum kategori.
        if ($produk->IdInduk !== null && isset($this->perProduk[$produk->IdInduk])) {
            return $this->perProduk[$produk->IdInduk];
        }

        if ($produk->IdKategori !== null) {
            foreach ($this->pohon->AmbilIdDenganLeluhur($produk->IdKategori) as $idKategori) {
                if (isset($this->perKategori[$idKategori])) {
                    return $this->perKategori[$idKategori];
                }
            }
        }

        return $this->semua;
    }

    public function CekAdaAturan(): bool
    {
        return $this->perProduk !== [] || $this->perKategori !== [] || $this->semua !== null;
    }
}
