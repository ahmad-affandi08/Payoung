<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Support\Carbon;

/**
 * Baris bahan order produksi (F-05e): `JumlahStandar` = kebutuhan resep untuk jumlah hasil (0 untuk bahan tambahan),
 * `Jumlah` = pemakaian aktual dalam satuan dasar; `Nilai` = HPP bahan yang keluar saat dicatat.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdOrderProduksi
 * @property int $Urutan
 * @property int $IdProduk
 * @property string $NamaProduk
 * @property string|null $Sku
 * @property string $JumlahStandar
 * @property string $Jumlah
 * @property string|null $Nilai
 * @property Carbon|null $DibuatPada
 */
final class OrderProduksiBahan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'OrderProduksiBahan';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Sku' => null,
        'JumlahStandar' => '0.0000',
        'Nilai' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Urutan' => 'integer',
            'JumlahStandar' => 'string',
            'Jumlah' => 'string',
            'Nilai' => 'string',
        ];
    }
}
