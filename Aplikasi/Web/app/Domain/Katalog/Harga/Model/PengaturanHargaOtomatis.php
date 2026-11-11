<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Pengaturan harga jual otomatis (D-266), satu baris per tenant: saklar utama dan batas aman perubahan harga
 * (`BatasPerubahanPersen`, bawaan 20). Perubahan di atas batas tidak diterapkan sendiri, tetapi jadi usulan.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property bool $Aktif
 * @property string $BatasPerubahanPersen
 * @property int|null $DiubahOleh
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class PengaturanHargaOtomatis extends ModelDasar
{
    use MilikTenant;

    public const BATAS_BAWAAN = '20.00';

    protected $table = 'PengaturanHargaOtomatis';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = [
        'Aktif' => false,
        'BatasPerubahanPersen' => self::BATAS_BAWAAN,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Aktif' => 'boolean',
            'BatasPerubahanPersen' => 'decimal:2',
        ];
    }
}
