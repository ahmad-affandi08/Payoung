<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Integrasi\Snap\Enum\MetodeBayarSnap;
use App\Domain\Tenant\Enum\MetodePembayaranLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Pembayaran tagihan langganan (P-08 langkah 3, PRD §15.3). Fase 0: transfer manual dengan bukti yang disimpan
 * privat; diverifikasi Keuangan/Super Admin. Tidak pernah dihapus; status hanya Menunggu → Diterima/Ditolak.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdTagihanLangganan
 * @property MetodePembayaranLangganan $Metode
 * @property StatusPembayaranLangganan $Status
 * @property string $Jumlah
 * @property Carbon|null $TanggalTransfer
 * @property string|null $BankPengirim
 * @property string|null $NamaPengirim
 * @property string|null $KodeRekeningTujuan
 * @property string|null $BankTujuan
 * @property string|null $NomorRekeningTujuan
 * @property string|null $PathBukti
 * @property string|null $NamaFileBukti
 * @property string|null $MimeBukti
 * @property int|null $UkuranBukti
 * @property string|null $RefGateway
 * @property string|null $MetodeBayar
 * @property string|null $KanalBayar
 * @property array<string, string>|null $RincianBayar
 * @property Carbon|null $KedaluwarsaPada
 * @property int|null $IdPenggunaPengunggah
 * @property string|null $EmailPemberitahuan
 * @property string|null $NamaPemberitahuan
 * @property int|null $IdPenggunaPengelolaVerifikator
 * @property Carbon|null $DiverifikasiPada
 * @property string|null $JumlahDiterima
 * @property string|null $AlasanTolak
 * @property Carbon|null $DibuatPada
 * @property-read TagihanLangganan $TagihanLangganan
 */
final class PembayaranLangganan extends ModelDasar
{
    use MilikTenant;

    protected $table = 'PembayaranLangganan';

    /** @var list<string> */
    protected $hidden = ['PathBukti'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'Metode' => 'TransferManual',
        'Status' => 'Menunggu',
        'TanggalTransfer' => null,
        'BankPengirim' => null,
        'NamaPengirim' => null,
        'KodeRekeningTujuan' => null,
        'BankTujuan' => null,
        'NomorRekeningTujuan' => null,
        'PathBukti' => null,
        'NamaFileBukti' => null,
        'MimeBukti' => null,
        'UkuranBukti' => null,
        'RefGateway' => null,
        'MetodeBayar' => null,
        'KanalBayar' => null,
        'RincianBayar' => null,
        'KedaluwarsaPada' => null,
        'IdPenggunaPengunggah' => null,
        'EmailPemberitahuan' => null,
        'NamaPemberitahuan' => null,
        'IdPenggunaPengelolaVerifikator' => null,
        'DiverifikasiPada' => null,
        'JumlahDiterima' => null,
        'AlasanTolak' => null,
    ];

    protected static function booted(): void
    {
        self::updating(static function (PembayaranLangganan $pembayaran): void {
            $asal = $pembayaran->getOriginal('Status');

            if ($asal instanceof StatusPembayaranLangganan && $asal !== StatusPembayaranLangganan::Menunggu) {
                throw new LogicException('Pembayaran yang sudah diverifikasi tidak boleh diubah.');
            }

            if ($pembayaran->isDirty('Status') && $asal instanceof StatusPembayaranLangganan && ! $asal->BisaBerubahKe($pembayaran->Status)) {
                throw new LogicException("Pembayaran {$asal->value} tidak bisa berubah menjadi {$pembayaran->Status->value}.");
            }
        });

        self::deleting(static function (): void {
            throw new LogicException('Pembayaran langganan tidak boleh dihapus.');
        });
    }

    /**
     * @return BelongsTo<TagihanLangganan, $this>
     */
    public function TagihanLangganan(): BelongsTo
    {
        return $this->belongsTo(TagihanLangganan::class, 'IdTagihanLangganan', 'Id');
    }

    public function AmbilJumlah(): Uang
    {
        return Uang::Dari($this->Jumlah);
    }

    /** D-159: cara bayar SNAP yang dipilih tenant; null untuk DOKU Checkout, transfer manual, dan kupon. */
    public function AmbilMetodeSnap(): ?MetodeBayarSnap
    {
        return $this->MetodeBayar === null ? null : MetodeBayarSnap::tryFrom($this->MetodeBayar);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Metode' => MetodePembayaranLangganan::class,
            'Status' => StatusPembayaranLangganan::class,
            'Jumlah' => 'decimal:2',
            'TanggalTransfer' => 'date',
            'UkuranBukti' => 'integer',
            'DiverifikasiPada' => 'datetime',
            'JumlahDiterima' => 'decimal:2',
            'RincianBayar' => 'array',
            'KedaluwarsaPada' => 'datetime',
        ];
    }
}
