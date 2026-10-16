<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Invoice penjualan jasa/barang tanpa stok `INV/{OUTLET}/{YYMM}/{SEQ4}` (F-21, D-166). Berbeda dari
 * `FakturPenjualan` grosir: invoice ini mengakui pendapatannya sendiri saat diterbitkan, tanpa surat jalan.
 *
 * Draf (`Status` Draf) bebas diubah dan belum bernomor; setelah `Terbit` hanya kolom yang disebut `AmbilKolomBolehBerubah()`
 * yang boleh berubah (aturan #8). Draf yang tidak jadi dibatalkan, bukan dihapus: tidak ada dokumen yang dihapus.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string|null $Nomor
 * @property string $KodePublik
 * @property JenisInvoice $Jenis
 * @property StatusInvoice $Status
 * @property int $IdPelanggan
 * @property int $IdOutlet
 * @property Carbon $Tanggal
 * @property Carbon $JatuhTempo
 * @property int $TerminHari
 * @property string|null $Perihal
 * @property string|null $NomorReferensi
 * @property bool $HargaTermasukPajak
 * @property string|null $TarifPpn
 * @property int|null $PengaliDppPembilang
 * @property int|null $PengaliDppPenyebut
 * @property string $Subtotal
 * @property string $Diskon
 * @property string $DasarPengenaanPajak
 * @property string $Pajak
 * @property string $Total
 * @property array<string, string>|null $RincianPajak
 * @property string $PotonganUangMukaDpp
 * @property string $PotonganUangMukaPajak
 * @property string $Tagihan
 * @property string|null $KodePph
 * @property string|null $TarifPph
 * @property string $DasarPph
 * @property string $NilaiPph
 * @property string|null $Catatan
 * @property string|null $Syarat
 * @property string|null $InfoPembayaran
 * @property int|null $IdInvoiceAkhir
 * @property Carbon|null $DiterbitkanPada
 * @property Carbon|null $TerkirimPada
 * @property Carbon|null $DilihatPada
 * @property int $JumlahDilihat
 * @property int|null $IdJurnal
 * @property int|null $IdJurnalPembatalan
 * @property int|null $DibuatOleh
 * @property int|null $DiubahOleh
 * @property int|null $DibatalkanOleh
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property-read Pelanggan $Pelanggan
 * @property-read Outlet $Outlet
 * @property-read Collection<int, InvoiceDetail> $Detail
 */
final class Invoice extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'Invoice';

    /** Kolom yang tetap boleh berubah setelah invoice terbit: pembatalan, pelacakan kirim/lihat, dan penandaan DP terpakai. */
    private const KOLOM_SETELAH_TERBIT = [
        'Status', 'IdJurnalPembatalan', 'DibatalkanOleh', 'DibatalkanPada', 'AlasanBatal', 'DiubahOleh',
        'TerkirimPada', 'DilihatPada', 'JumlahDilihat', 'IdInvoiceAkhir', 'NomorReferensi',
    ];

    protected $table = 'Invoice';

    /** @var array<string, mixed> */
    protected $attributes = [
        'Jenis' => 'Penjualan',
        'Status' => 'Draf',
        'TerminHari' => 0,
        'HargaTermasukPajak' => false,
        'PotonganUangMukaDpp' => '0.00',
        'PotonganUangMukaPajak' => '0.00',
        'DasarPph' => '0.00',
        'NilaiPph' => '0.00',
        'JumlahDilihat' => 0,
    ];

    protected static function booted(): void
    {
        self::updating(static function (Invoice $invoice): void {
            // Draf (status asal Draf) bebas berubah, termasuk saat diterbitkan; setelah itu hanya kolom pelacakan.
            if ($invoice->getRawOriginal('Status') === StatusInvoice::Draf->value) {
                return;
            }

            $terlarang = array_diff(array_keys($invoice->getDirty()), [...self::KOLOM_SETELAH_TERBIT, 'DiubahPada']);

            if ($terlarang !== []) {
                throw new LogicException('Invoice yang sudah terbit tidak bisa diubah: '.implode(', ', $terlarang).'. Koreksi lewat pembatalan lalu invoice baru.');
            }
        });

        self::deleting(static function (): void {
            throw new LogicException('Invoice tidak pernah dihapus; batalkan saja.');
        });
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusInvoice $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status invoice {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    public function AmbilTotal(): Uang
    {
        return Uang::Dari($this->Total);
    }

    public function AmbilTagihan(): Uang
    {
        return Uang::Dari($this->Tagihan);
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
     * @return HasMany<InvoiceDetail, $this>
     */
    public function Detail(): HasMany
    {
        return $this->hasMany(InvoiceDetail::class, 'IdInvoice', 'Id')->orderBy('Urutan');
    }

    /**
     * Invoice uang muka yang dipotong dari invoice akhir ini.
     *
     * @return HasMany<Invoice, $this>
     */
    public function UangMukaTerpakai(): HasMany
    {
        return $this->hasMany(self::class, 'IdInvoiceAkhir', 'Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisInvoice::class,
            'Status' => StatusInvoice::class,
            'Tanggal' => 'date',
            'JatuhTempo' => 'date',
            'TerminHari' => 'integer',
            'HargaTermasukPajak' => 'boolean',
            'TarifPpn' => 'decimal:6',
            'PengaliDppPembilang' => 'integer',
            'PengaliDppPenyebut' => 'integer',
            'Subtotal' => 'decimal:2',
            'Diskon' => 'decimal:2',
            'DasarPengenaanPajak' => 'decimal:2',
            'Pajak' => 'decimal:2',
            'Total' => 'decimal:2',
            'RincianPajak' => 'array',
            'PotonganUangMukaDpp' => 'decimal:2',
            'PotonganUangMukaPajak' => 'decimal:2',
            'Tagihan' => 'decimal:2',
            'TarifPph' => 'decimal:6',
            'DasarPph' => 'decimal:2',
            'NilaiPph' => 'decimal:2',
            'DiterbitkanPada' => 'datetime',
            'TerkirimPada' => 'datetime',
            'DilihatPada' => 'datetime',
            'JumlahDilihat' => 'integer',
            'DibatalkanPada' => 'datetime',
        ];
    }
}
