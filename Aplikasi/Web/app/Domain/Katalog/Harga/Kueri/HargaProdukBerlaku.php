<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Kueri;

use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Harga\Data\DataBarisProdukHarga;
use App\Domain\Katalog\Harga\Data\DataDaftarHargaResolusi;
use App\Domain\Katalog\Harga\Data\DataKatalogHarga;
use App\Domain\Katalog\Harga\Data\DataPermintaanHarga;
use App\Domain\Katalog\Harga\Data\HasilHarga;
use App\Domain\Katalog\Harga\Layanan\PenentuHarga;
use App\Domain\Katalog\Harga\Model\DaftarHarga;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHarga;
use App\Domain\Katalog\Model\ProdukSatuan;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Harga satuan yang berlaku di server (lapis 3–5 price engine F-03) untuk F-07, self-order, dan toko online: memuat
 * daftar harga tenant & baris harga satuan itu, lalu memanggil `PenentuHarga` (algoritma sama dengan POS & test
 * vector). Null = satuan tanpa harga dasar (hanya untuk pembelian).
 */
final class HargaProdukBerlaku
{
    public function __construct(
        private readonly PenentuHarga $penentu,
        private readonly PetaUuidOutlet $petaOutlet,
    ) {}

    public function Tentukan(
        Produk $produk,
        ProdukSatuan $satuan,
        Kuantitas $jumlah,
        ?int $idOutlet,
        ?KanalPenjualan $kanal,
        ?string $tier,
        CarbonImmutable $waktu,
    ): ?HasilHarga {
        return $this->TentukanBanyak([[$produk, $satuan]], $jumlah, $idOutlet, $kanal, $tier, $waktu)[$satuan->Id] ?? null;
    }

    /**
     * Harga banyak satuan sekaligus (menu publik: ratusan produk per muat). Baris harga, daftar harga, dan peta outlet
     * dimuat sekali untuk semua pasangan lalu tiap pasangan diselesaikan `PenentuHarga` dengan katalog miliknya sendiri,
     * jadi hasilnya sama persis dengan `Tentukan()` satu per satu (dulu 3 kueri per pasangan).
     *
     * @param  list<array{0: Produk, 1: ProdukSatuan}>  $pasangan
     * @return array<int, HasilHarga|null> kunci = `ProdukSatuan.Id`
     */
    public function TentukanBanyak(
        array $pasangan,
        Kuantitas $jumlah,
        ?int $idOutlet,
        ?KanalPenjualan $kanal,
        ?string $tier,
        CarbonImmutable $waktu,
    ): array {
        if ($pasangan === []) {
            return [];
        }

        $barisPerSatuan = ProdukHarga::query()
            ->whereIn('IdProdukSatuan', array_map(fn (array $p): int => $p[1]->Id, $pasangan))
            ->get()
            ->groupBy('IdProdukSatuan');
        $daftar = DaftarHarga::query()
            ->whereIn('Id', $barisPerSatuan->flatten(1)->pluck('IdDaftarHarga')->filter()->unique()->values()->all())
            ->get()
            ->keyBy('Id');
        $idOutletDaftar = $idOutlet === null ? [] : [$idOutlet];

        foreach ($daftar as $item) {
            $idOutletDaftar = [...$idOutletDaftar, ...($item->IdOutlet ?? [])];
        }

        $uuidOutlet = $this->petaOutlet->Ambil(array_values(array_unique($idOutletDaftar)));
        $uuidOutletPermintaan = $idOutlet === null ? null : ($uuidOutlet[$idOutlet] ?? null);
        $hasil = [];

        foreach ($pasangan as [$produk, $satuan]) {
            $baris = $barisPerSatuan->get($satuan->Id, new Collection)->filter(fn (ProdukHarga $item): bool => $item->IdProduk === $produk->Id);
            $daftarSatuan = $daftar->only($baris->pluck('IdDaftarHarga')->filter()->unique()->values()->all());

            $katalog = new DataKatalogHarga(
                array_values($daftarSatuan->map(fn (DaftarHarga $item): DataDaftarHargaResolusi => self::KeResolusi($item, $uuidOutlet))->all()),
                array_values($baris->map(fn (ProdukHarga $item): DataBarisProdukHarga => new DataBarisProdukHarga(
                    $produk->Uuid,
                    $satuan->Uuid,
                    $item->IdDaftarHarga === null ? null : (string) $daftar->get($item->IdDaftarHarga)?->Uuid,
                    Kuantitas::Dari($item->JumlahMinimum),
                    Uang::Dari($item->Harga),
                ))->all()),
            );

            $hasil[$satuan->Id] = $this->penentu->Tentukan($katalog, new DataPermintaanHarga(
                $produk->Uuid,
                $satuan->Uuid,
                $jumlah,
                $uuidOutletPermintaan,
                $kanal,
                $tier,
                $waktu->utc(),
            ));
        }

        return $hasil;
    }

    /**
     * @param  array<int, string>  $uuidOutlet
     */
    public static function KeResolusi(DaftarHarga $daftar, array $uuidOutlet): DataDaftarHargaResolusi
    {
        return new DataDaftarHargaResolusi(
            $daftar->Uuid,
            $daftar->Aktif,
            $daftar->IdOutlet === null ? null : array_values(array_filter(array_map(fn (int $id): ?string => $uuidOutlet[$id] ?? null, $daftar->IdOutlet))),
            $daftar->Kanal,
            $daftar->TierPelanggan,
            $daftar->MulaiPada === null ? null : CarbonImmutable::instance($daftar->MulaiPada)->utc(),
            $daftar->SelesaiPada === null ? null : CarbonImmutable::instance($daftar->SelesaiPada)->utc(),
            $daftar->Prioritas,
        );
    }
}
