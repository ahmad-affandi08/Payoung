<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\JagaDokumenTerposting;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pelanggan\Model\Pelanggan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Faktur Pajak per transaksi kasir (TAX-07, PRD v6.27, D-147): menautkan satu penjualan `Lunas` ke satu pelanggan
 * ber-NPWP/NIK. Tanpa dampak stok maupun jurnal. Angka faktur tidak disalin: DPP dan PPN dihitung dari snapshot
 * `PenjualanDetail`/`PenjualanPajak` saat ekspor, jadi tidak ada angka kedua yang bisa menyimpang dari penjualannya.
 * Setelah terbit hanya nomor Faktur Pajak dan status pembatalan yang boleh berubah.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdOutlet
 * @property int $IdPenjualan
 * @property int $IdPelanggan
 * @property Carbon $Tanggal
 * @property StatusDokumenTerposting $Status
 * @property string|null $NomorFakturPajak
 * @property string|null $Catatan
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 * @property-read Pelanggan $Pelanggan
 * @property-read Penjualan $Penjualan
 */
final class FakturPajakPenjualan extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    public const JENIS_DOKUMEN = 'FakturPajakPenjualan';

    private const KOLOM_STATUS = [
        'Status', 'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal', 'DiubahOleh',
        // Nomor Faktur Pajak datang dari Coretax setelah faktur diunggah, jadi baru bisa diisi kemudian.
        'NomorFakturPajak',
    ];

    protected $table = 'FakturPajakPenjualan';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Diposting',
        'NomorFakturPajak' => null,
        'Catatan' => null,
    ];

    /**
     * @return list<string>
     */
    public function AmbilKolomBolehBerubah(): array
    {
        return self::KOLOM_STATUS;
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusDokumenTerposting $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status Faktur Pajak {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return BelongsTo<Pelanggan, $this>
     */
    public function Pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'IdPelanggan', 'Id');
    }

    /**
     * @return BelongsTo<Penjualan, $this>
     */
    public function Penjualan(): BelongsTo
    {
        return $this->belongsTo(Penjualan::class, 'IdPenjualan', 'Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'Status' => StatusDokumenTerposting::class,
            'DibatalkanPada' => 'datetime',
        ];
    }
}
