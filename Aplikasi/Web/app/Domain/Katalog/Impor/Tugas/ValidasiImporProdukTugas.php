<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Impor\Tugas;

use App\Domain\Katalog\Impor\Enum\StatusImporProduk;
use App\Domain\Katalog\Impor\Layanan\KonteksTugasImpor;
use App\Domain\Katalog\Impor\Layanan\PemvalidasiImpor;
use App\Domain\Katalog\Impor\Layanan\PengirimTugasImpor;
use App\Domain\Katalog\Impor\Model\ImporProduk;
use App\Domain\Organisasi\Enum\IzinTenant;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Validasi impor produk di antrean (F-03 BR-03.6). Membawa `IdTenant` dan menetapkan konteks tenant lebih dulu.
 * Berjalan di worker `queue:work --stop-when-empty --max-time=50` dari scheduler: setelah
 * `katalog.Impor.MaksimalDetikPerTugas` detik, tugas mengirim dirinya lagi dan melanjutkan dari baris tersimpan.
 * Unik per impor sampai mulai diproses (kiriman ulang dari dalam tugas tetap diterima).
 */
final class ValidasiImporProdukTugas implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idPengguna,
        public readonly int $idImporProduk,
    ) {}

    public function uniqueId(): string
    {
        return 'validasi-'.$this->idImporProduk;
    }

    public function handle(KonteksTugasImpor $konteks, PemvalidasiImpor $pemvalidasi): void
    {
        $konteks->Jalankan($this->idTenant, $this->idPengguna, function () use ($pemvalidasi): void {
            $impor = ImporProduk::query()->find($this->idImporProduk);

            if ($impor === null || $impor->Status !== StatusImporProduk::Memvalidasi) {
                return;
            }

            if (! $pemvalidasi->Jalankan($impor, (int) config('katalog.Impor.MaksimalDetikPerTugas', 40))) {
                PengirimTugasImpor::KirimLanjutan(new self($this->idTenant, $this->idPengguna, $this->idImporProduk));
            }
        }, IzinTenant::ProdukKelola, function (string $alasan): void {
            PengirimTugasImpor::Gagalkan($this->idImporProduk, 'Impor dihentikan karena '.$alasan.'. Baris yang sudah diimpor tetap tersimpan. Pengguna yang berizin bisa melanjutkan impor.');
        });
    }

    public function failed(?Throwable $galat): void
    {
        app(KonteksTugasImpor::class)->Jalankan($this->idTenant, $this->idPengguna, function (): void {
            PengirimTugasImpor::Gagalkan($this->idImporProduk, 'Pemeriksaan berkas terhenti karena masalah sistem. Unggah ulang berkas, atau hubungi dukungan bila berulang.');
        });
    }
}
