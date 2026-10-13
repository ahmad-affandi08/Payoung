<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Kueri;

use App\Domain\Katalog\Kueri\SatuanProdukJual;
use App\Domain\Penjualan\Enum\StatusPesananPenjualan;
use App\Domain\Penjualan\Model\PesananPenjualan;
use App\Domain\Penjualan\Model\PesananPenjualanDetail;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Permintaan dari pre-order (pesanan penjualan F-12 bagian 2) yang harus diambil pada satu tanggal, dijumlahkan per
 * produk dalam satuan dasar, untuk rencana produksi harian (§9.11). Hanya pesanan yang masih menunggu pengambilan
 * (`Dipesan`, `Siap`); yang sudah diambil sudah tercatat sebagai penjualan, yang dibatalkan tidak dihitung.
 */
final class PermintaanPreOrder
{
    public function __construct(private readonly SatuanProdukJual $satuan) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return array<int, string> id produk → jumlah (satuan dasar, desimal 4 angka)
     */
    public function AmbilPerProduk(CarbonImmutable $tanggalAmbil, ?array $idOutletBoleh): array
    {
        $pesanan = PesananPenjualan::query()
            ->whereDate('TanggalAmbil', $tanggalAmbil->toDateString())
            ->whereIn('Status', [StatusPesananPenjualan::Dipesan->value, StatusPesananPenjualan::Siap->value])
            ->when($idOutletBoleh !== null, fn ($k) => $k->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->pluck('Id');

        if ($pesanan->isEmpty()) {
            return [];
        }

        $detail = PesananPenjualanDetail::query()->whereIn('IdPesananPenjualan', $pesanan->all())->get(['IdProduk', 'UuidProdukSatuan', 'Jumlah']);
        $konversi = [];

        foreach ($this->satuan->AmbilUntukProduk(array_values(array_unique($detail->pluck('IdProduk')->all()))) as $daftar) {
            foreach ($daftar as $s) {
                $konversi[$s['Uuid']] = $s['Konversi'];
            }
        }

        /** @var array<int, BigDecimal> $total */
        $total = [];

        foreach ($detail as $d) {
            $faktor = $d->UuidProdukSatuan === null ? '1' : ($konversi[$d->UuidProdukSatuan] ?? '1');
            $total[$d->IdProduk] = ($total[$d->IdProduk] ?? BigDecimal::zero())->plus(BigDecimal::of($d->Jumlah)->multipliedBy($faktor));
        }

        return array_map(fn (BigDecimal $b): string => (string) $b->toScale(4), $total);
    }
}
