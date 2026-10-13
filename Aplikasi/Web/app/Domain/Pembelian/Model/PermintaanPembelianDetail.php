<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pembelian\Enum\StatusPermintaanPembelian;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Baris permintaan pembelian (F-04 PR): jumlah dalam satuan pembelian (`Konversi` ke satuan dasar di-snapshot) dan
 * `HargaPerkiraan` per satuan pembelian (hanya perkiraan; harga sebenarnya ditentukan saat PO). Baris hanya bisa diubah
 * atau dihapus selama permintaan masih Draf.
 *
 * @property int $Id
 * @property int $IdTenant
 * @property int $IdPermintaanPembelian
 * @property int $Urutan
 * @property int $IdProduk
 * @property string $NamaProduk
 * @property string|null $Sku
 * @property int|null $IdProdukSatuan
 * @property string $SimbolSatuan
 * @property string $Konversi
 * @property string $Jumlah
 * @property string $HargaPerkiraan
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 */
final class PermintaanPembelianDetail extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PermintaanPembelianDetail';

    protected bool $pakaiUuid = false;

    /** @var array<string, mixed> */
    protected $attributes = ['Sku' => null, 'IdProdukSatuan' => null, 'HargaPerkiraan' => '0.00'];

    protected static function booted(): void
    {
        $cekDraf = fn (self $baris): bool => PermintaanPembelian::query()->whereKey($baris->IdPermintaanPembelian)->value('Status') === StatusPermintaanPembelian::Draf;

        self::updating(function (self $baris) use ($cekDraf): void {
            if (! $cekDraf($baris)) {
                throw new LogicException('Baris permintaan pembelian di luar Draf tidak boleh diubah.');
            }
        });
        self::deleting(function (self $baris) use ($cekDraf): void {
            if (! $cekDraf($baris)) {
                throw new LogicException('Baris permintaan pembelian di luar Draf tidak boleh dihapus.');
            }
        });
    }
}
