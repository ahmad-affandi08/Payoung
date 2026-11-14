<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;

/**
 * Saldo WhatsApp utama tenant (D-300): cache yang dikunci saat mutasi, kebenarannya `MutasiSaldoWhatsapp`.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property string $Saldo
 */
final class SaldoWhatsapp extends ModelDasar
{
    use MilikTenant;

    protected $table = 'SaldoWhatsapp';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = ['Saldo' => '0.00'];
}
