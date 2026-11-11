<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Katalog\Harga\Enum\ArahHargaOtomatis;
use App\Domain\Katalog\Harga\Enum\CakupanAturanHarga;
use Illuminate\Support\Carbon;

/**
 * Satu aturan harga otomatis (D-266): harga jual = harga beli terakhir × (1 + `PersenUntung`/100), dibulatkan ke
 * kelipatan `Pembulatan` (0 = tanpa pembulatan). `Cakupan` Semua tanpa kaitan, Kategori memakai `IdKategori`, Produk
 * memakai `IdProduk`. Yang paling khusus menang (`PenentuAturanHarga`).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property CakupanAturanHarga $Cakupan
 * @property int|null $IdKategori
 * @property int|null $IdProduk
 * @property string $PersenUntung
 * @property int $Pembulatan
 * @property ArahHargaOtomatis $Arah
 * @property bool $Aktif
 * @property int|null $DiubahOleh
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class AturanHargaOtomatis extends ModelDasar
{
    use MilikTenant;

    protected $table = 'AturanHargaOtomatis';

    /** @var array<string, mixed> */
    protected $attributes = [
        'IdKategori' => null,
        'IdProduk' => null,
        'Pembulatan' => 0,
        'Arah' => 'NaikTurun',
        'Aktif' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Cakupan' => CakupanAturanHarga::class,
            'Arah' => ArahHargaOtomatis::class,
            'PersenUntung' => 'decimal:4',
            'Pembulatan' => 'integer',
            'Aktif' => 'boolean',
        ];
    }
}
