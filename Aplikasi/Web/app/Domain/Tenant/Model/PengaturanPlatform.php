<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use Illuminate\Support\Carbon;

/**
 * Pengaturan operasional platform (D-113, D-114): satu baris per kunci, diubah Super Admin dari konsol dengan jejak
 * audit. Milik domain Tenant karena dibaca juga oleh penagihan (rekening tujuan, status PKP), bukan hanya konsol.
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
