<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Penempatan produk di rak (F-05c INV-07): satu produk satu rak per lokasi stok (`IdGudang` disalin dari rak supaya
 * keunikan dijaga indeks). Memindahkan produk ke rak lain mengganti `IdRak`.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdRak
 * @property int $IdGudang
 * @property int $IdProduk
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class ProdukRak extends ModelDasar
{
    use MilikTenant;

    protected $table = 'ProdukRak';

    protected bool $pakaiUuid = false;
}
