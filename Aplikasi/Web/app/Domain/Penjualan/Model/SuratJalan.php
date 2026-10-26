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
use App\Domain\Penjualan\Enum\StatusPengirimanGrosir;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Surat jalan grosir `SJ/{OUTLET}/{YYMM}/{SEQ4}` (F-12, §9.7, BR-12.2): dokumen penyerahan barang, sekaligus titik
 * pengakuan HPP, pendapatan, dan PPN keluaran (J-12.1). Angkanya di-snapshot di sini, termasuk tarif PPN & pengali DPP
 * pada tanggal penyerahan.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property int $IdPesananGrosir
 * @property int $IdPelanggan
 * @property int $IdOutlet
 * @property int $IdGudang
 * @property Carbon $Tanggal
 * @property StatusDokumenTerposting $Status
 * @property string|null $TarifPpn
 * @property int|null $PengaliDppPembilang
 * @property int|null $PengaliDppPenyebut
 * @property string $Subtotal
 * @property string $Diskon
 * @property string $DasarPengenaanPajak
 * @property string $Pajak
 * @property string $Total
 * @property array<string, string>|null $RincianPajak
 * @property string $TotalHpp
 * @property string|null $NamaPengirim
 * @property string|null $NomorKendaraan
 * @property string|null $NamaPenerima
 * @property string|null $Catatan
 * @property StatusPengirimanGrosir $StatusPengiriman kabar lapangan (bukan status akuntansi), boleh berubah setelah dicatat
 * @property int|null $IdArmada
 * @property Carbon|null $BerangkatPada
 * @property Carbon|null $DiterimaPada
 * @property string|null $CatatanPengiriman
 * @property int|null $IdFakturPenjualan
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
 * @property-read PesananGrosir $PesananGrosir
 * @property-read Collection<int, SuratJalanDetail> $Detail
 */
final class SuratJalan extends ModelDasar
{
    use JagaDokumenTerposting;
    use MilikTenant;

    public const JENIS_DOKUMEN = 'SuratJalan';

    private const KOLOM_STATUS = [
        'Status', 'IdJurnalPembatalan', 'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal', 'DiubahOleh',
        // Diisi saat surat jalan masuk faktur penjualan (J-12.2); bukan perubahan isi dokumen.
        'IdFakturPenjualan',
        // Hasil posting dokumen ini sendiri, di transaksi yang sama: HPP baru diketahui setelah mutasi stok dicatat
        // (nilai HPP berjalan) dan nomor jurnal setelah jurnalnya dicatat. Cermin `PenerimaanBarang`.
        'IdJurnal', 'TotalHpp',
        // Pelacakan pengiriman (kabar lapangan): berubah-ubah tanpa menyentuh angka dokumen. `NamaPengirim`, `NomorKendaraan`
        // dan `NamaPenerima` ikut diperbarui karena itulah yang tercetak.
        'StatusPengiriman', 'IdArmada', 'BerangkatPada', 'DiterimaPada', 'CatatanPengiriman', 'NamaPengirim', 'NomorKendaraan', 'NamaPenerima',
    ];

    protected $table = 'SuratJalan';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Status' => 'Diposting',
        'TarifPpn' => null,
        'PengaliDppPembilang' => null,
        'PengaliDppPenyebut' => null,
        'RincianPajak' => null,
        'NamaPengirim' => null,
        'NomorKendaraan' => null,
        'NamaPenerima' => null,
        'Catatan' => null,
        'IdFakturPenjualan' => null,
        'StatusPengiriman' => 'Disiapkan',
        'IdArmada' => null,
        'BerangkatPada' => null,
        'DiterimaPada' => null,
        'CatatanPengiriman' => null,
    ];

    /**
     * Surat jalan tidak pernah diedit: begitu tersimpan, hanya kolom status & penautan faktur yang boleh bergerak.
     *
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
            throw new LogicException("Status surat jalan {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    public function AmbilTotal(): Uang
    {
        return Uang::Dari($this->Total);
    }

    /**
     * Rincian pajak per kode jenis pajak sebagai `Uang`.
     *
     * @return array<string, Uang>
     */
    public function AmbilRincianPajak(): array
    {
        return array_map(fn (string $jumlah): Uang => Uang::Dari($jumlah), $this->RincianPajak ?? []);
    }

    /**
     * @return BelongsTo<PesananGrosir, $this>
     */
    public function PesananGrosir(): BelongsTo
    {
        return $this->belongsTo(PesananGrosir::class, 'IdPesananGrosir', 'Id');
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
     * @return HasMany<SuratJalanDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(SuratJalanDetail::class, 'IdSuratJalan', 'Id')->orderBy('Urutan');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Tanggal' => 'date',
            'Status' => StatusDokumenTerposting::class,
            'StatusPengiriman' => StatusPengirimanGrosir::class,
            'BerangkatPada' => 'datetime',
            'DiterimaPada' => 'datetime',
            'TarifPpn' => 'decimal:6',
            'PengaliDppPembilang' => 'integer',
            'PengaliDppPenyebut' => 'integer',
            'Subtotal' => 'decimal:2',
            'Diskon' => 'decimal:2',
            'DasarPengenaanPajak' => 'decimal:2',
            'Pajak' => 'decimal:2',
            'Total' => 'decimal:2',
            'RincianPajak' => 'array',
            'TotalHpp' => 'decimal:2',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
