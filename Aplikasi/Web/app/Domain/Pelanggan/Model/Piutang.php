<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Model;

use App\Domain\Bersama\Model\ModelDasar;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\MilikTenant;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Piutang (F-12): satu baris per dokumen sumber, dengan sisa = Jumlah − JumlahDibayar − JumlahDikurangi.
 *
 * Sumbernya **tepat satu** dari tiga (BR-12.5, F-21): `IdPenjualan` untuk penjualan tempo di kasir, `IdFakturPenjualan`
 * untuk faktur penjualan grosir, atau `IdInvoice` untuk invoice jasa/barang tanpa stok. Dengan begitu umur piutang, ringkasan aging, pengingat, dan pelunasan F-12 bagian 1
 * berlaku apa adanya untuk keduanya — kueri daftarnya memang tidak pernah menggabung tabel dokumen sumber, karena nomor
 * dokumennya sudah di-snapshot di `Nomor`.
 *
 * @property int $Id
 * @property string $Uuid
 * @property int $IdTenant
 * @property int|null $IdPelanggan
 * @property int|null $IdPenjualan
 * @property int|null $IdFakturPenjualan
 * @property int|null $IdInvoice
 * @property int $IdOutlet
 * @property string $Nomor
 * @property Carbon $TanggalBisnis
 * @property Carbon $JatuhTempo
 * @property string $Jumlah
 * @property string $JumlahDibayar
 * @property string $JumlahDikurangi
 * @property StatusPiutang $Status
 */
final class Piutang extends ModelDasar
{
    use MilikTenant;

    public const JENIS_DOKUMEN = 'Piutang';

    protected $table = 'Piutang';

    /** @var array<string, mixed> */
    protected $attributes = ['JumlahDibayar' => '0.00', 'JumlahDikurangi' => '0.00', 'Status' => 'BelumLunas', 'IdPenjualan' => null, 'IdFakturPenjualan' => null, 'IdInvoice' => null];

    protected static function booted(): void
    {
        // BR-12.5 ditegakkan di lapisan model, bukan hanya di Aksi: piutang tanpa sumber tidak bisa ditagih ke siapa
        // pun, dan piutang dengan dua sumber akan dihitung dua kali di paparan kredit maupun aging.
        self::saving(static function (Piutang $piutang): void {
            $jumlahSumber = count(array_filter([$piutang->IdPenjualan, $piutang->IdFakturPenjualan, $piutang->IdInvoice], static fn (?int $id): bool => $id !== null));

            if ($jumlahSumber !== 1) {
                throw new LogicException('Piutang wajib bersumber tepat satu dari IdPenjualan, IdFakturPenjualan, atau IdInvoice (BR-12.5).');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'TanggalBisnis' => 'date',
            'JatuhTempo' => 'date',
            'Jumlah' => 'decimal:2',
            'JumlahDibayar' => 'decimal:2',
            'JumlahDikurangi' => 'decimal:2',
            'Status' => StatusPiutang::class,
        ];
    }

    public function AmbilSisa(): Uang
    {
        return Uang::Dari($this->Jumlah)->Kurangi(Uang::Dari($this->JumlahDibayar))->Kurangi(Uang::Dari($this->JumlahDikurangi));
    }

    /** Status mengikuti sisa (piutang dibatalkan tidak berubah). */
    public function SelaraskanStatus(): void
    {
        if ($this->Status === StatusPiutang::Dibatalkan || $this->Status === StatusPiutang::DihapusBuku) {
            return;
        }

        $this->Status = match (true) {
            $this->AmbilSisa()->Bandingkan(Uang::Nol()) <= 0 => StatusPiutang::Lunas,
            Uang::Dari($this->JumlahDibayar)->BernilaiNol() => StatusPiutang::BelumLunas,
            default => StatusPiutang::DibayarSebagian,
        };
    }
}
