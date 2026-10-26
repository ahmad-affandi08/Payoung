<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Impor\Tugas;

use App\Domain\Katalog\Impor\Layanan\KonteksTugasImpor;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Persediaan\Enum\StatusImporStokAwal;
use App\Domain\Persediaan\Impor\Layanan\PemvalidasiImporStokAwal;
use App\Domain\Persediaan\Impor\Layanan\PengirimTugasImporStokAwal;
use App\Domain\Persediaan\Model\ImporStokAwal;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Validasi impor stok awal (DesainF05a C.7), langsung untuk berkas kecil atau di antrean untuk berkas besar.
 * Membawa `IdTenant` dan menetapkan konteks tenant (+ pengunggah sebagai pelaku) lebih dulu (CLAUDE.md #11).
 * Setelah `persediaan.Impor.MaksimalDetikPerTugas` detik tugas mengirim dirinya lagi dan melanjutkan dari baris
 * tersimpan terakhir. Unik per impor sampai mulai diproses.
 */
final class ValidasiImporStokAwalTugas implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const PESAN_GAGAL = 'Pemeriksaan berkas terhenti karena masalah sistem. Unggah ulang berkas, atau hubungi dukungan bila berulang.';

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idPengguna,
        public readonly int $idImporStokAwal,
    ) {}

    public function uniqueId(): string
    {
        return 'validasi-stok-awal-'.$this->idImporStokAwal;
    }

    public function handle(KonteksTugasImpor $konteks, PemvalidasiImporStokAwal $pemvalidasi): void
    {
        $konteks->Jalankan($this->idTenant, $this->idPengguna, function () use ($pemvalidasi): void {
            $impor = ImporStokAwal::query()->find($this->idImporStokAwal);

            if ($impor === null || $impor->Status !== StatusImporStokAwal::Memvalidasi) {
                return;
            }

            if (! $pemvalidasi->Jalankan($impor, (int) config('persediaan.Impor.MaksimalDetikPerTugas', 40))) {
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
