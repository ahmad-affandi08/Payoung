<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Harga\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Katalog\Harga\Enum\StatusUsulanHarga;
use App\Domain\Katalog\Model\Produk;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Usulan perubahan harga dasar yang melewati batas aman harga otomatis (D-266). Menunggu keputusan pemilik, lalu
 * Diterapkan (harga berubah lewat `SimpanHargaProduk`), Ditolak, atau Digantikan usulan yang lebih baru untuk produk
 * yang sama. Hanya satu usulan Menunggu per produk.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdProduk
 * @property int $IdProdukSatuan
 * @property int|null $IdPenerimaanBarang
 * @property string $Modal
 * @property string $PersenUntung
 * @property string $HargaLama
 * @property string $HargaBaru
 * @property string $PerubahanPersen
 * @property StatusUsulanHarga $Status
 * @property int|null $DiputuskanOleh
 * @property Carbon|null $DiputuskanPada
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Produk $Produk
 */
final class UsulanHargaOtomatis extends ModelDasar
{
    use MilikTenant;

    protected $table = 'UsulanHargaOtomatis';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Menunggu',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Modal' => 'decimal:6',
            'PersenUntung' => 'decimal:4',
            'HargaLama' => 'decimal:2',
            'HargaBaru' => 'decimal:2',
            'PerubahanPersen' => 'decimal:2',
            'Status' => StatusUsulanHarga::class,
            'DiputuskanPada' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Produk, $this>
     */
    public function Produk(): BelongsTo
    {
        return $this->belongsTo(Produk::class, 'IdProduk', 'Id');
    }
}
