<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Aksi\UbahKetersediaanProduk;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHabis;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;

/**
 * F-17 BR-17.2 + PRD-11: keadaan "habis" (86) satu produk di tiap outlet aktif yang boleh diakses pelaku, per kanal
 * (`Kanal`: Kasir, TokoOnline, SelfOrder; true = habis), untuk panel di halaman detail produk. `Habis` = habis di semua
 * kanal. Produk induk varian tidak punya keadaan sendiri (ditandai per varian), jadi hasilnya null.
 */
final class KetersediaanProdukPerOutlet
{
    public function __construct(private readonly PetaUuidOutlet $petaOutlet) {}

    /**
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array{UuidOutlet: string, NamaOutlet: string, Habis: bool, Kanal: array<string, bool>}>|null
     */
    public function Ambil(Produk $produk, ?array $idOutletBoleh): ?array
    {
        if ($produk->Jenis === JenisProduk::IndukVarian) {
            return null;
        }

        $kanalPerOutlet = [];

        foreach (ProdukHabis::query()->where('IdProduk', $produk->Id)->get(['IdOutlet', 'Kanal']) as $b) {
            $kanalPerOutlet[$b->IdOutlet][] = $b->Kanal->value;
        }

        return array_map(function (array $outlet) use ($kanalPerOutlet): array {
            $kanal = UbahKetersediaanProduk::AmbilEfektif($kanalPerOutlet[$outlet['Id']] ?? []);

            return [
                'UuidOutlet' => $outlet['Uuid'],
                'NamaOutlet' => $outlet['Nama'],
                'Habis' => ! in_array(false, $kanal, true),
                'Kanal' => $kanal,
            ];
        }, $this->petaOutlet->AmbilRingkas($idOutletBoleh, true));
    }
}
