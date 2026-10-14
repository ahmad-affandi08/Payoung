<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Organisasi\Enum\StatusTurProduk;
use Illuminate\Support\Carbon;

/**
 * Hasil tur produk satu pengguna (PLT-12). Baris ada = tur itu tidak ditawarkan otomatis lagi.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPengguna
 * @property string $Kode
 * @property StatusTurProduk $Status
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class TurProduk extends ModelDasar
{
    use MilikTenant;

    protected $table = 'TurProduk';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['Status' => StatusTurProduk::class];
    }
}
