<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Kueri;

use App\Domain\Katalog\Enum\KanalKetersediaan;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\Model\ProdukHabis;

/**
 * F-17 BR-17.2 + PRD-11: Uuid produk yang habis di satu outlet untuk satu kanal (bawaan `Kasir`: aplikasi POS/KDS), yaitu
 * yang ditandai `Semua` atau kanal itu. Produk yang sudah diarsipkan atau dihapus tidak ikut.
 */
final class ProdukHabisOutlet
{
    /**
     * @return list<string>
     */
    public function AmbilUuid(int $idOutlet, KanalKetersediaan $kanal = KanalKetersediaan::Kasir): array
    {
        return array_values(Produk::query()
            ->whereIn('Id', ProdukHabis::query()->where('IdOutlet', $idOutlet)->whereIn('Kanal', [KanalKetersediaan::Semua->value, $kanal->value])->select('IdProduk'))
            ->orderBy('Uuid')
            ->pluck('Uuid')
            ->all());
    }
}
