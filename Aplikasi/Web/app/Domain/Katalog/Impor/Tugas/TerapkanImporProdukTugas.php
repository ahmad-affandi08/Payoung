<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Impor\Tugas;

use App\Domain\Katalog\Impor\Enum\StatusImporProduk;
use App\Domain\Katalog\Impor\Layanan\KonteksTugasImpor;
use App\Domain\Katalog\Impor\Layanan\PenerapImpor;
use App\Domain\Katalog\Impor\Layanan\PengirimTugasImpor;
use App\Domain\Katalog\Impor\Model\ImporProduk;
use App\Domain\Organisasi\Enum\IzinTenant;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Penerapan impor produk di antrean (F-03 BR-03.6): per potongan `katalog.Impor.UkuranPotongan` baris, satu transaksi
 * per potongan, tepat sekali per baris (`PenerapImpor`). Membawa `IdTenant` dan menetapkan konteks tenant lebih dulu.
 * Setelah `katalog.Impor.MaksimalDetikPerTugas` detik tugas mengirim dirinya lagi; menjalankan ulang aman (hanya baris
 * yang belum diterapkan yang diproses).
 */
final class TerapkanImporProdukTugas implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        return 'terapkan-'.$this->idImporProduk;
    }

    public function handle(KonteksTugasImpor $konteks, PenerapImpor $penerap): void
    {
        $konteks->Jalankan($this->idTenant, $this->idPengguna, function () use ($penerap): void {
            $impor = ImporProduk::query()->find($this->idImporProduk);

            if ($impor === null || $impor->Status !== StatusImporProduk::Menerapkan) {
                return;
            }

            if (! $penerap->Jalankan($impor, (int) config('katalog.Impor.MaksimalDetikPerTugas', 40))) {
                PengirimTugasImpor::KirimLanjutan(new self($this->idTenant, $this->idPengguna, $this->idImporProduk));
            }
        }, IzinTenant::ProdukKelola, function (string $alasan): void {
            PengirimTugasImpor::Gagalkan($this->idImporProduk, 'Impor dihentikan karena '.$alasan.'. Baris yang sudah diimpor tetap tersimpan; pengguna yang berizin bisa melanjutkan impor.');
        });
    }

    public function failed(?Throwable $galat): void
    {
        app(KonteksTugasImpor::class)->Jalankan($this->idTenant, $this->idPengguna, function (): void {
            PengirimTugasImpor::Gagalkan($this->idImporProduk, 'Impor terhenti karena masalah sistem. Baris yang sudah diimpor tidak diulang; klik Lanjutkan impor untuk meneruskan.');
        });
    }
}
