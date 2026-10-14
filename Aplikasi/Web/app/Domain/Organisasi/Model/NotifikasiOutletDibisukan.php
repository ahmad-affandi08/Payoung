<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Outlet yang kejadiannya tidak dikabarkan kepada satu pengguna (PLT-08): berlaku untuk kabar yang membawa outlet
 * (transaksi besar, pesanan online), bukan ringkasan operasional tenant.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPengguna
 * @property int $IdOutlet
 * @property Carbon|null $DibuatPada
 */
final class NotifikasiOutletDibisukan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'NotifikasiOutletDibisukan';
}
