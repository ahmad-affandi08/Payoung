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
 * Retur grosir & nota kredit `RG/{OUTLET}/{YYMM}/{SEQ4}` (F-12, §9.7, BR-12.7, J-12.4): barang kembali dari pembeli
 * grosir atas satu surat jalan, dengan pendapatan, PPN, dan HPP dibalik sebanding jumlah yang dikembalikan.
 *
 * `MengurangiPiutang` di-snapshot saat retur dicatat: true = tagihannya sudah terbit sehingga yang berkurang
 * `PiutangUsaha` beserta baris `Piutang` fakturnya; false = surat jalannya belum difakturkan sehingga yang berkurang
 * `PiutangBelumDifakturkan`. Disimpan supaya pembatalannya membalik ke akun yang sama walau fakturnya berubah kemudian.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdSuratJalan
 * @property int $IdPelanggan
 * @property int $IdOutlet
 * @property int|null $IdFakturPenjualan
 * @property Carbon $Tanggal
 * @property StatusDokumenTerposting $Status
 * @property string $Alasan
 * @property bool $MengurangiPiutang
 * @property string|null $TarifPpn
 * @property int|null $PengaliDppPembilang
 * @property int|null $PengaliDppPenyebut
 * @property string $Subtotal
 * @property string $Diskon
 * @property string $DasarPengenaanPajak
 * @property string $Pajak
 * @property string $Total
 * @property string $NilaiRefund bagian nilai retur yang dikembalikan sebagai uang (melebihi sisa tagihan faktur)
 * @property int|null $IdAkunRefund akun kas/bank sumber pengembalian uang
 * @property array<string, string>|null $RincianPajak
 * @property string $TotalHpp
 * @property string|null $Catatan
 * @property bool $PerluTinjauan
 * @property string|null $AlasanTinjauan
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
 * @property-read SuratJalan $SuratJalan
 * @property-read Collection<int, ReturGrosirDetail> $Detail
 */
final class ReturGrosir extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    public const JENIS_DOKUMEN = 'ReturGrosir';

    private const KOLOM_STATUS = [
        'Status', 'IdJurnalPembatalan', 'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal', 'DiubahOleh',
        // Hasil posting dokumen ini sendiri, di transaksi yang sama.
        'IdJurnal', 'TotalHpp', 'PerluTinjauan', 'AlasanTinjauan', 'NilaiRefund', 'IdAkunRefund',
    ];

    protected $table = 'ReturGrosir';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Diposting',
        'IdFakturPenjualan' => null,
        'MengurangiPiutang' => false,
        'NilaiRefund' => '0.00',
        'IdAkunRefund' => null,
        'TarifPpn' => null,
        'PengaliDppPembilang' => null,
        'PengaliDppPenyebut' => null,
        'RincianPajak' => null,
        'Catatan' => null,
        'PerluTinjauan' => false,
        'AlasanTinjauan' => null,
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
            throw new LogicException("Status retur grosir {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    public function AmbilTotal(): Uang
    {
        return Uang::Dari($this->Total);
    }

    /**
     * @return array<string, Uang>
     */
    public function AmbilRincianPajak(): array
    {
        return array_map(fn (string $jumlah): Uang => Uang::Dari($jumlah), $this->RincianPajak ?? []);
    }

    /**
     * @return BelongsTo<SuratJalan, $this>
     */
    public function SuratJalan(): BelongsTo
    {
        return $this->belongsTo(SuratJalan::class, 'IdSuratJalan', 'Id');
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
     * @return HasMany<ReturGrosirDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(ReturGrosirDetail::class, 'IdReturGrosir', 'Id')->orderBy('Urutan');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'Status' => StatusDokumenTerposting::class,
            'MengurangiPiutang' => 'boolean',
            'TarifPpn' => 'decimal:6',
            'PengaliDppPembilang' => 'integer',
            'PengaliDppPenyebut' => 'integer',
            'Subtotal' => 'decimal:2',
            'Diskon' => 'decimal:2',
            'DasarPengenaanPajak' => 'decimal:2',
            'Pajak' => 'decimal:2',
            'Total' => 'decimal:2',
            'NilaiRefund' => 'decimal:2',
            'RincianPajak' => 'array',
            'TotalHpp' => 'decimal:2',
            'PerluTinjauan' => 'boolean',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
