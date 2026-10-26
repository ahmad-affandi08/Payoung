<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Model\JagaDokumenTerposting;
use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pelanggan\Model\Pelanggan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Faktur penjualan grosir `FJ/{OUTLET}/{YYMM}/{SEQ4}` (F-12, §9.7, BR-12.4): penagihan atas satu atau beberapa surat
 * jalan milik satu pelanggan dalam satu bulan kalender. Barisnya adalah baris surat jalan yang ditautkan, bukan tabel
 * baris tersendiri.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdPelanggan
 * @property int $IdOutlet
 * @property Carbon $Tanggal
 * @property Carbon $JatuhTempo
 * @property StatusDokumenTerposting $Status
 * @property int $TerminHari
 * @property string $PeriodePenyerahan
 * @property string|null $NomorFakturPajak
 * @property string|null $TarifPpn
 * @property int|null $PengaliDppPembilang
 * @property int|null $PengaliDppPenyebut
 * @property string $Subtotal
 * @property string $Diskon
 * @property string $DasarPengenaanPajak
 * @property string $Pajak
 * @property string $Total
 * @property array<string, string>|null $RincianPajak
 * @property string|null $Catatan
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DibuatPada
 * @property Carbon|null $DiubahPada
 * @property-read Pelanggan $Pelanggan
 * @property-read Outlet $Outlet
 * @property-read Collection<int, SuratJalan> $SuratJalan
 */
final class FakturPenjualan extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    public const JENIS_DOKUMEN = 'FakturPenjualan';

    private const KOLOM_STATUS = [
        'Status', 'IdJurnalPembatalan', 'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal', 'DiubahOleh',
        // Nomor Faktur Pajak datang dari e-Faktur/Coretax setelah faktur diterbitkan, jadi boleh diisi kemudian.
        'NomorFakturPajak',
        // Hasil posting dokumen ini sendiri, di transaksi yang sama: nomor jurnal baru ada setelah jurnalnya dicatat.
        'IdJurnal',
    ];

    protected $table = 'FakturPenjualan';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Diposting',
        'TerminHari' => 0,
        'NomorFakturPajak' => null,
        'TarifPpn' => null,
        'PengaliDppPembilang' => null,
        'PengaliDppPenyebut' => null,
        'RincianPajak' => null,
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
            throw new LogicException("Status faktur penjualan {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    public function AmbilTotal(): Uang
    {
        return Uang::Dari($this->Total);
    }

    /**
     * @return BelongsTo<Pelanggan, $this>
     */
    public function Pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'IdPelanggan', 'Id');
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function Outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'IdOutlet', 'Id');
    }

    /**
     * Surat jalan yang ditagihkan faktur ini; inilah "baris" faktur.
     *
     * @return HasMany<SuratJalan, $this>
     */
    public function SuratJalan(): HasMany
    {
        return $this->hasMany(SuratJalan::class, 'IdFakturPenjualan', 'Id')->orderBy('Tanggal')->orderBy('Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'JatuhTempo' => 'date',
            'Status' => StatusDokumenTerposting::class,
            'TerminHari' => 'integer',
            'TarifPpn' => 'decimal:6',
            'PengaliDppPembilang' => 'integer',
            'PengaliDppPenyebut' => 'integer',
            'Subtotal' => 'decimal:2',
            'Diskon' => 'decimal:2',
            'DasarPengenaanPajak' => 'decimal:2',
            'Pajak' => 'decimal:2',
            'Total' => 'decimal:2',
            'RincianPajak' => 'array',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
