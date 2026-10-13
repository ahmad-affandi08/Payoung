<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Layanan;

use App\Domain\Persediaan\Enum\StatusStokOpname;
use App\Domain\Persediaan\Model\StokOpname;

/**
 * F-05c INV-07: apakah ada stok opname per rak yang masih aktif (Berlangsung/Ditinjau) pada rak tertentu. Penempatan produk
 * di rak dibekukan selama itu supaya cakupan hitung tidak bergeser di tengah opname.
 */
final class PemeriksaOpnameRak
{
    /**
     * @param  list<int>  $idRak
     * @return string|null nomor opname aktif pertama, null bila tidak ada
     */
    public function CariOpnameAktif(array $idRak): ?string
    {
        if ($idRak === []) {
            return null;
        }

        $nomor = StokOpname::query()
            ->whereIn('IdRak', $idRak)
            ->whereIn('Status', [StatusStokOpname::Berlangsung->value, StatusStokOpname::Ditinjau->value])
            ->orderBy('Id')
            ->value('Nomor');

        return $nomor === null ? null : (string) $nomor;
    }
}
