<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use Illuminate\Support\Carbon;

/**
 * Pengaturan operasional platform (D-113): satu baris per kunci, diubah Super Admin dari konsol dengan jejak audit.
 *
 * @property int $Id
 * @property string $Kunci
 * @property string $Nilai
 * @property int|null $IdPenggunaPengelolaPengubah
 * @property Carbon|null $DiubahPada
 */
final class PengaturanPlatform extends ModelDasar
{
    protected $table = 'PengaturanPlatform';

    protected bool $pakaiUuid = false;
}
