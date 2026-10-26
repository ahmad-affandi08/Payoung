<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Penjualan\Enum\MetodeRefund;
use App\Domain\Penjualan\Enum\StatusReturPenjualan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Retur penjualan (PRD §15 `ReturPenjualan`, F-09 fase 1): dokumen retur terpisah dari penjualan asal, dibuat di
 * aplikasi POS dan diterima lewat sinkron `ReturPenjualan.Buat`. Snapshot nilai hasil hitung server. Append-only;
 * hanya `IdJurnal` diisi sekali di transaksi penerimaan (setelah jurnal J-09.2 dicatat).
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int|null $IdPenjualanAsal null hanya untuk retur tanpa struk (K28)
 * @property bool $TanpaStruk
 * @property int|null $IdPelanggan
 * @property list<array{Kode: string, Tarif: string, Dpp: string, Jumlah: string}>|null $RincianPajak
 * @property int $IdOutlet
 * @property int $IdShift
 * @property int $IdPerangkat
 * @property string $Nomor
 * @property StatusReturPenjualan $Status
 * @property string $Alasan
 * @property MetodeRefund $MetodeRefund
 * @property int $IdPengguna
 * @property int $IdPenyetuju
 * @property Carbon $TanggalBisnis
 * @property string $TotalNilai
 * @property string $TotalPajak
 * @property string $TotalBiayaLayanan
 * @property string $TotalBiayaKirim
 * @property string $TotalRefund
 * @property string $RefundTunai
 * @property string $TotalHpp
 * @property bool $PerluTinjauan
 * @property string|null $AlasanTinjauan
 * @property int|null $IdJurnal
 * @property Carbon $DibuatOfflinePada
 * @property Carbon $DiterimaPada
 */
final class ReturPenjualan extends ModelDasar
{
    use MilikTenant;

    /** Nilai `RiwayatStatusDokumen.JenisDokumen` untuk dokumen ini. */
    public const JENIS_DOKUMEN = 'ReturPenjualan';

    protected $table = 'ReturPenjualan';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusReturPenjualan::class,
            'MetodeRefund' => MetodeRefund::class,
            'TanggalBisnis' => 'date',
            'TotalNilai' => 'decimal:2',
            'TotalPajak' => 'decimal:2',
            'TotalBiayaLayanan' => 'decimal:2',
            'TotalBiayaKirim' => 'decimal:2',
            'TotalRefund' => 'decimal:2',
            'RefundTunai' => 'decimal:2',
            'TotalHpp' => 'decimal:2',
            'PerluTinjauan' => 'boolean',
            'TanpaStruk' => 'boolean',
            'RincianPajak' => 'array',
            'DibuatOfflinePada' => 'datetime',
            'DiterimaPada' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (ReturPenjualan $retur): void {
            foreach (array_keys($retur->getDirty()) as $kolom) {
                if ($kolom !== self::UPDATED_AT && ! ($kolom === 'IdJurnal' && $retur->getOriginal('IdJurnal') === null)) {
                    throw new LogicException("Retur penjualan append-only: kolom {$kolom} tidak boleh diubah.");
                }
            }
        });

        self::deleting(function (): void {
            throw new LogicException('Retur penjualan tidak boleh dihapus.');
        });
    }

    /**
     * @return BelongsTo<Penjualan, $this>
     */
    public function PenjualanAsal(): BelongsTo
    {
        return $this->belongsTo(Penjualan::class, 'IdPenjualanAsal', 'Id');
    }

    /**
     * @return HasMany<ReturPenjualanDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(ReturPenjualanDetail::class, 'IdReturPenjualan', 'Id');
    }

    /**
     * @return HasMany<ReturPenjualanPembayaran, $this>
     */
    public function Pembayaran(): HasMany
    {
        return $this->hasMany(ReturPenjualanPembayaran::class, 'IdReturPenjualan', 'Id');
    }
}
