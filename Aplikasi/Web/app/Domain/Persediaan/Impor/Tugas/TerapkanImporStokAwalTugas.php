<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Impor\Tugas;

use App\Domain\Katalog\Impor\Layanan\KonteksTugasImpor;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Persediaan\Enum\StatusImporStokAwal;
use App\Domain\Persediaan\Impor\Layanan\PenerapImporStokAwal;
use App\Domain\Persediaan\Impor\Layanan\PengirimTugasImporStokAwal;
use App\Domain\Persediaan\Model\ImporStokAwal;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Penerapan impor stok awal (DesainF05a C.7): membuat dokumen stok awal **Draf** per lokasi stok lewat
 * `SimpanStokAwal`, satu transaksi per dokumen (≤ `persediaan.StokAwal.MaksimalBaris` baris). Tidak pernah
 * memposting. Membawa `IdTenant` dan menetapkan konteks tenant lebih dulu. Setelah
 * `persediaan.Impor.MaksimalDetikPerTugas` detik tugas mengirim dirinya lagi; menjalankan ulang aman karena baris
 * yang sudah masuk draf (`ImporStokAwalBaris.IdStokAwal`) tidak diproses lagi.
 */
final class TerapkanImporStokAwalTugas implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const PESAN_GAGAL = 'Pembuatan draf terhenti karena masalah sistem. Draf yang sudah dibuat tidak diulang. Klik Lanjutkan impor untuk meneruskan.';

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idPengguna,
        public readonly int $idImporStokAwal,
    ) {}

    public function uniqueId(): string
    {
        return 'terapkan-stok-awal-'.$this->idImporStokAwal;
    }

    public function handle(KonteksTugasImpor $konteks, PenerapImporStokAwal $penerap): void
    {
        $konteks->Jalankan($this->idTenant, $this->idPengguna, function () use ($penerap): void {
            $impor = ImporStokAwal::query()->find($this->idImporStokAwal);

            if ($impor === null || $impor->Status !== StatusImporStokAwal::Menerapkan) {
                return;
            }

            if (! $penerap->Jalankan($impor, (int) config('persediaan.Impor.MaksimalDetikPerTugas', 40))) {
                PengirimTugasImporStokAwal::KirimLanjutan(new self($this->idTenant, $this->idPengguna, $this->idImporStokAwal));
            }
        }, IzinTenant::PersediaanKelola, function (string $alasan): void {
            PengirimTugasImporStokAwal::Gagalkan($this->idImporStokAwal, 'Impor dihentikan karena '.$alasan.'. Baris yang sudah diimpor tetap tersimpan. Pengguna yang berizin bisa melanjutkan impor.');
        });
    }

    public function failed(?Throwable $galat): void
    {
        app(KonteksTugasImpor::class)->Jalankan($this->idTenant, $this->idPengguna, function (): void {
            PengirimTugasImporStokAwal::Gagalkan($this->idImporStokAwal, self::PESAN_GAGAL);
        });
    }
}
