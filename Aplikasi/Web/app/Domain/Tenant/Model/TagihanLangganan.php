<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\SiklusTagihan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Tagihan langganan platform ke tenant (P-08, F-19, PRD §15.3). Dokumen keuangan: tidak pernah dihapus, dan angka
 * tagihan (paket, harga, kupon, pajak, total) tidak berubah setelah terbit (CLAUDE.md #8). Yang boleh berubah hanya
 * status beserta cap waktunya dan periode layanan yang diisi saat Lunas. Status hanya lewat transisi sah.
 *
 * Invariant: Total = Subtotal − Diskon + JumlahPpn; DasarPengenaanPajak = ⌊(Subtotal − Diskon) × PengaliDpp⌋.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property string $Nomor
 * @property JenisTagihanLangganan $Jenis
 * @property StatusTagihanLangganan $Status
 * @property int $IdPaket
 * @property int|null $IdHargaPaket kosong pada tagihan manual (harga kesepakatan, D-112)
 * @property bool $Manual diterbitkan Keuangan/Super Admin untuk paket harga negosiasi (D-112)
 * @property string|null $Keterangan
 * @property int|null $IdPaketAsal paket sebelum naik paket (jenis `NaikPaket`, D-98) atau sebelum turun paket (perpanjangan ke paket lebih murah, BR-P04.4)
 * @property SiklusTagihan $Siklus
 * @property int $JumlahBulan
 * @property int|null $HariDitagih hari yang ditagih pada jenis `NaikPaket` (prorata)
 * @property int|null $HariPeriode hari satu periode pada jenis `NaikPaket`
 * @property string $Subtotal
 * @property int|null $IdKuponLangganan
 * @property string|null $KodeKupon
 * @property string $Diskon
 * @property int|null $IdTarifPajak
 * @property string $TarifPpn
 * @property int $PengaliDppPembilang
 * @property int $PengaliDppPenyebut
 * @property string $DasarPengenaanPajak
 * @property string $JumlahPpn
 * @property string $Total
 * @property Carbon $TerbitPada
 * @property Carbon $JatuhTempoPada
 * @property Carbon|null $DibayarPada
 * @property Carbon|null $DibatalkanPada
 * @property string|null $AlasanBatal
 * @property Carbon|null $DihapusBukuPada
 * @property string|null $AlasanHapusBuku
 * @property Carbon|null $DikembalikanPada
 * @property Carbon|null $PeriodeMulai
 * @property Carbon|null $PeriodeSelesai
 * @property Carbon|null $MulaiLanggananPaket
 * @property string|null $RefGateway
 * @property string|null $PengingatTerakhir tahap pengingat terakhir yang dikirim (`TahapPengingatTagihan`)
 * @property Carbon|null $PengingatTerakhirPada
 * @property int|null $IdPenggunaPembuat
 * @property int|null $IdPenggunaPengelolaPembuat
 * @property Carbon|null $DibuatPada
 * @property-read Paket $Paket
 * @property-read Collection<int, PembayaranLangganan> $Pembayaran
 * @property-read Collection<int, TagihanLanggananAddon> $RincianAddon
 */
final class TagihanLangganan extends ModelDasar
{
    use MilikTenant;

    /** Kolom yang boleh berubah setelah terbit. */
    public const KOLOM_BOLEH_BERUBAH = [
        'Status', 'DibayarPada', 'DibatalkanPada', 'AlasanBatal', 'DihapusBukuPada', 'AlasanHapusBuku', 'DikembalikanPada', 'PeriodeMulai', 'PeriodeSelesai', 'MulaiLanggananPaket',
        'RefGateway', 'PengingatTerakhir', 'PengingatTerakhirPada', self::UPDATED_AT,
    ];

    protected $table = 'TagihanLangganan';

    /** @var array<string, mixed> */
    protected $attributes = [
        'IdHargaPaket' => null,
        'Manual' => false,
        'Keterangan' => null,
        'IdPenggunaPengelolaPembuat' => null,
        'DihapusBukuPada' => null,
        'AlasanHapusBuku' => null,
        'DikembalikanPada' => null,
        'IdKuponLangganan' => null,
        'IdPaketAsal' => null,
        'HariDitagih' => null,
        'HariPeriode' => null,
        'KodeKupon' => null,
        'Diskon' => '0.00',
        'IdTarifPajak' => null,
        'TarifPpn' => '0.000000',
        'PengaliDppPembilang' => 1,
        'PengaliDppPenyebut' => 1,
        'DasarPengenaanPajak' => '0.00',
        'JumlahPpn' => '0.00',
        'DibayarPada' => null,
        'DibatalkanPada' => null,
        'AlasanBatal' => null,
        'PeriodeMulai' => null,
        'PeriodeSelesai' => null,
        'MulaiLanggananPaket' => null,
        'RefGateway' => null,
        'PengingatTerakhir' => null,
        'PengingatTerakhirPada' => null,
        'IdPenggunaPembuat' => null,
    ];

    protected static function booted(): void
    {
        self::updating(static function (TagihanLangganan $tagihan): void {
            $kolomBerubah = array_diff(array_keys($tagihan->getDirty()), self::KOLOM_BOLEH_BERUBAH);

            if ($kolomBerubah !== []) {
                throw new LogicException('Angka tagihan langganan tidak boleh diubah setelah terbit: '.implode(', ', $kolomBerubah).'.');
            }

            $asal = $tagihan->getOriginal('Status');

            if ($tagihan->isDirty('Status') && $asal instanceof StatusTagihanLangganan && ! $asal->BisaBerubahKe($tagihan->Status)) {
                throw new LogicException("Tagihan {$asal->value} tidak bisa berubah menjadi {$tagihan->Status->value} (P-08).");
            }
        });

        self::deleting(static function (): void {
            throw new LogicException('Tagihan langganan tidak boleh dihapus. Batalkan lewat status Dibatalkan.');
        });
    }

    /**
     * @return BelongsTo<Paket, $this>
     */
    public function Paket(): BelongsTo
    {
        return $this->belongsTo(Paket::class, 'IdPaket', 'Id');
    }

    /**
     * @return HasMany<PembayaranLangganan, $this>
     */
    public function Pembayaran(): HasMany
    {
        return $this->hasMany(PembayaranLangganan::class, 'IdTagihanLangganan', 'Id');
    }

    /**
     * Rincian add-on (D-49): satu baris prorata pada tagihan `Addon`, satu baris per add-on yang diperpanjang pada
     * tagihan `Perpanjangan`.
     *
     * @return HasMany<TagihanLanggananAddon, $this>
     */
    public function RincianAddon(): HasMany
    {
        return $this->hasMany(TagihanLanggananAddon::class, 'IdTagihanLangganan', 'Id');
    }

    /**
     * @return BelongsTo<Paket, $this>
     */
    public function PaketAsal(): BelongsTo
    {
        return $this->belongsTo(Paket::class, 'IdPaketAsal', 'Id');
    }

    /** Nama layanan yang ditagih untuk pesan ke pemilik: nama paket, atau nama add-on pada tagihan `Addon`. */
    public function AmbilNamaLayanan(): string
    {
        if ($this->Jenis === JenisTagihanLangganan::NaikPaket) {
            return "Naik ke paket {$this->Paket->Nama}";
        }

        if ($this->Jenis !== JenisTagihanLangganan::Addon) {
            return $this->Paket->Nama;
        }

        $nama = $this->RincianAddon->pluck('NamaAddon')->all();

        return $nama === [] ? 'Add-on' : 'Add-on '.implode(', ', $nama);
    }

    public function AmbilTotal(): Uang
    {
        return Uang::Dari($this->Total);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Jenis' => JenisTagihanLangganan::class,
            'Status' => StatusTagihanLangganan::class,
            'Siklus' => SiklusTagihan::class,
            'JumlahBulan' => 'integer',
            'HariDitagih' => 'integer',
            'HariPeriode' => 'integer',
            'Subtotal' => 'decimal:2',
            'Diskon' => 'decimal:2',
            'TarifPpn' => 'decimal:6',
            'PengaliDppPembilang' => 'integer',
            'PengaliDppPenyebut' => 'integer',
            'DasarPengenaanPajak' => 'decimal:2',
            'JumlahPpn' => 'decimal:2',
            'Total' => 'decimal:2',
            'TerbitPada' => 'datetime',
            'JatuhTempoPada' => 'datetime',
            'DibayarPada' => 'datetime',
            'DibatalkanPada' => 'datetime',
            'DihapusBukuPada' => 'datetime',
            'DikembalikanPada' => 'datetime',
            'Manual' => 'boolean',
            'PeriodeMulai' => 'datetime',
            'PeriodeSelesai' => 'datetime',
            'MulaiLanggananPaket' => 'date',
            'PengingatTerakhirPada' => 'datetime',
        ];
    }
}
