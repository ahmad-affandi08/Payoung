<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pelanggan\Enum\StatusPembayaranPiutang;
use Illuminate\Support\Carbon;

/**
 * Pelunasan piutang (F-12) dari satu akun kas/bank untuk satu atau banyak piutang satu pelanggan.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdPelanggan
 * @property int $IdAkun
 * @property Carbon $Tanggal
 * @property string $Jumlah
 * @property string $PotonganPph bagian Jumlah yang dipotong pelanggan sebagai PPh (F-21), bukan uang masuk
 * @property string|null $NomorBuktiPotong
 * @property StatusPembayaranPiutang $Status
 * @property string|null $Catatan
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property int|null $DibuatOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 */
final class PembayaranPiutang extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'PembayaranPiutang';

    protected $table = 'PembayaranPiutang';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Tanggal' => 'date', 'Jumlah' => 'decimal:2', 'PotonganPph' => 'decimal:2', 'Status' => StatusPembayaranPiutang::class, 'DibatalkanPada' => 'datetime'];
    }
}
