<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Model;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\JagaDokumenTerposting;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Retur pembelian `RB/{OUTLET}/{YYMM}/{SEQ4}` (F-04 fase 1) dari satu GRN: stok keluar `ReturPembelian` bernilai HPP
 * penerimaan (`NilaiBarang`), mengurangi hutang faktur atau hutang belum difakturkan sebesar `NilaiHutang` (+ `Pajak`
 * bila faktur ber-PPN); jurnal J-04.5. Tidak pernah diubah; pembatalan = mutasi & jurnal pembalik.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdPenerimaanBarang
 * @property int|null $IdPemasok
 * @property int|null $IdFakturPembelian
 * @property int $IdGudang
 * @property int|null $IdOutlet
 * @property Carbon $Tanggal
 * @property string $Alasan
 * @property StatusDokumenTerposting $Status
 * @property string $NilaiBarang
 * @property string $NilaiHutang
 * @property string $Pajak
 * @property string $NilaiRefund
 * @property int|null $IdAkunRefund
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property int|null $DibuatOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Collection<int, ReturPembelianDetail> $Detail
 */
final class ReturPembelian extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    public const JENIS_DOKUMEN = 'ReturPembelian';

    protected $table = 'ReturPembelian';

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Diposting', 'IdPemasok' => null, 'IdFakturPembelian' => null, 'IdJurnal' => null, 'IdJurnalPembatalan' => null, 'Pajak' => '0.00', 'NilaiRefund' => '0.00', 'IdAkunRefund' => null];

    /**
     * @return list<string>
     */
    public function AmbilKolomBolehBerubah(): array
    {
        return ['Status', 'IdJurnal', 'NilaiBarang', 'NilaiHutang', 'Pajak', 'IdJurnalPembatalan', 'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal'];
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusDokumenTerposting $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status retur pembelian {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return HasMany<ReturPembelianDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(ReturPembelianDetail::class, 'IdReturPembelian', 'Id')->orderBy('Urutan');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['Tanggal' => 'date', 'Status' => StatusDokumenTerposting::class, 'DibatalkanPada' => 'datetime'];
    }
}
