<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Penjualan\Enum\KursusPesanan;
use App\Domain\Penjualan\Enum\StatusBarisPesanan;
use Illuminate\Support\Carbon;

/**
 * Baris pesanan terbuka (append-only, `Uuid` dari perangkat). Harga adalah tampilan saat dipesan; harga final,
 * pajak, dan HPP disnapshot di `PenjualanDetail` saat dibayar (BR-07.2). Batal = void item beralasan (BR-07.5).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdPesananTerbuka
 * @property int $IdProduk
 * @property string|null $UuidProduk
 * @property string|null $UuidProdukSatuan
 * @property string $NamaProduk
 * @property string $Jumlah
 * @property string $HargaSatuan
 * @property string $HargaPilihan
 * @property list<array{UuidPilihan: string, Nama: string, Harga: string}>|null $Pilihan
 * @property string|null $Catatan
 * @property int $Ronde
 * @property KursusPesanan|null $Kursus
 * @property StatusBarisPesanan $Status
 * @property Carbon|null $DikirimKeDapurPada
 * @property int $IdPengguna
 * @property int $IdPerangkat
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property int|null $IdPembatal
 * @property int|null $IdPenyetujuBatal
 * @property Carbon|null $TerbuangDicatatPada void item yang dicatat sebagai bahan terbuang (BR-07.5)
 * @property Carbon|null $DibuatPada
 */
final class PesananTerbukaDetail extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PesananTerbukaDetail';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jumlah' => 'decimal:4',
            'HargaSatuan' => 'decimal:2',
            'HargaPilihan' => 'decimal:2',
            'Pilihan' => 'array',
            'Ronde' => 'integer',
            'Kursus' => KursusPesanan::class,
            'Status' => StatusBarisPesanan::class,
            'DikirimKeDapurPada' => 'datetime',
            'DibatalkanPada' => 'datetime',
            'TerbuangDicatatPada' => 'datetime',
        ];
    }
}
