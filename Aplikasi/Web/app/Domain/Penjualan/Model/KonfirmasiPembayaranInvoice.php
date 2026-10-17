<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Penjualan\Enum\StatusKonfirmasiPembayaran;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Klaim pembayaran dari pelanggan atas satu invoice (F-21 bagian 4, D-169). Bukan pembayaran: tidak ada jurnal atau
 * pelunasan sampai penagih menerimanya lewat pencatatan pembayaran. Isi klaim (nama, tanggal, jumlah, catatan) tidak
 * berubah setelah dibuat; hanya keputusannya yang diisi sekali. Tidak pernah dihapus.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int $IdInvoice
 * @property StatusKonfirmasiPembayaran $Status
 * @property string $NamaPengirim
 * @property Carbon $TanggalBayar
 * @property string $Jumlah
 * @property string|null $Catatan
 * @property string|null $PathBukti jalur foto bukti transfer di disk privat (F-21 bagian 5), null bila tidak dilampirkan
 * @property int|null $DiputuskanOleh
 * @property Carbon|null $DiputuskanPada
 * @property string|null $AlasanTolak
 * @property int|null $IdPembayaranPiutang
 * @property Carbon|null $DibuatPada
 * @property-read Invoice $Invoice
 */
final class KonfirmasiPembayaranInvoice extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'KonfirmasiPembayaranInvoice';

    private const KOLOM_KEPUTUSAN = ['Status', 'DiputuskanOleh', 'DiputuskanPada', 'AlasanTolak', 'IdPembayaranPiutang'];

    protected $table = 'KonfirmasiPembayaranInvoice';

    /** @var array<string, mixed> */
    protected $attributes = ['Status' => 'Menunggu', 'Catatan' => null, 'DiputuskanOleh' => null, 'DiputuskanPada' => null, 'AlasanTolak' => null, 'IdPembayaranPiutang' => null];

    protected static function booted(): void
    {
        self::updating(static function (KonfirmasiPembayaranInvoice $k): void {
            $terlarang = array_diff(array_keys($k->getDirty()), [...self::KOLOM_KEPUTUSAN, 'DiubahPada']);

            if ($terlarang !== []) {
                throw new LogicException('Isi konfirmasi pembayaran tidak bisa diubah: '.implode(', ', $terlarang).'.');
            }
        });

        self::deleting(static function (): void {
            throw new LogicException('Konfirmasi pembayaran tidak pernah dihapus.');
        });
    }

    /**
     * @throws LogicException bila perpindahan status tidak diizinkan
     */
    public function UbahStatus(StatusKonfirmasiPembayaran $tujuan): void
    {
        if (! $this->Status->BisaBerubahKe($tujuan)) {
            throw new LogicException("Status konfirmasi pembayaran {$this->Status->value} tidak bisa berubah ke {$tujuan->value}.");
        }

        $this->Status = $tujuan;
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function Invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'IdInvoice', 'Id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Status' => StatusKonfirmasiPembayaran::class,
            'TanggalBayar' => 'date',
            'Jumlah' => 'decimal:2',
            'DiputuskanPada' => 'datetime',
        ];
    }
}
