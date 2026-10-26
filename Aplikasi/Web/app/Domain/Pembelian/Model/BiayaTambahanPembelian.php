<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Model;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\JagaDokumenTerposting;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pembelian\Enum\DasarAlokasiBiaya;
use App\Domain\Pembelian\Enum\JenisBiayaTambahan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Biaya tambahan pembelian `BY/{YYMM}/{SEQ4}` (v3.41, INV-14): biaya pihak ketiga atas satu penerimaan barang,
 * dibayar dari kas/bank. `KePersediaan` menaikkan nilai stok yang masih ada, `KeHpp` untuk barang yang sudah terjual.
 * Langsung dicatat; pembatalan = mutasi & jurnal pembalik, hanya selama stoknya belum bergerak lagi.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdPenerimaanBarang
 * @property int|null $IdOutlet
 * @property int|null $IdPemasok
 * @property JenisBiayaTambahan $Jenis
 * @property DasarAlokasiBiaya $DasarAlokasi
 * @property Carbon $Tanggal
 * @property string $Jumlah
 * @property string $KePersediaan
 * @property string $KeHpp
 * @property int $IdAkunKasBank
 * @property StatusDokumenTerposting $Status
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property string|null $Catatan
 * @property int|null $DibuatOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Collection<int, BiayaTambahanPembelianDetail> $Detail
 */
final class BiayaTambahanPembelian extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    public const JENIS_DOKUMEN = 'BiayaTambahanPembelian';

    protected $table = 'BiayaTambahanPembelian';

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Diposting', 'Catatan' => null, 'IdJurnal' => null, 'IdJurnalPembatalan' => null];

    /**
     * @return list<string>
     */
    public function AmbilKolomBolehBerubah(): array
    {
        return ['Status', 'KePersediaan', 'KeHpp', 'IdJurnal', 'IdJurnalPembatalan', 'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal'];
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusDokumenTerposting $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status biaya tambahan {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return HasMany<BiayaTambahanPembelianDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(BiayaTambahanPembelianDetail::class, 'IdBiayaTambahanPembelian', 'Id')->orderBy('Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'Status' => StatusDokumenTerposting::class,
            'Jenis' => JenisBiayaTambahan::class,
            'DasarAlokasi' => DasarAlokasiBiaya::class,
            'DibatalkanPada' => 'datetime',
        ];
    }
}
