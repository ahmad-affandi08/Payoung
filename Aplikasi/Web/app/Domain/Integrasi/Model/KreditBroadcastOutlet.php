<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;

/**
 * Kredit broadcast satu outlet hasil alokasi dari saldo utama (D-300). Outlet tanpa baris memakai saldo utama.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdOutlet
 * @property string $Saldo
 */
final class KreditBroadcastOutlet extends ModelDasar
{
    use MilikTenant;

    protected $table = 'KreditBroadcastOutlet';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = ['Saldo' => '0.00'];
}
