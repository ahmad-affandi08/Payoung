<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Tugas;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Layanan\PenjagaOtorisasiTugas;
use App\Domain\Persediaan\Aksi\GagalkanPostingStokAwal;
use App\Domain\Persediaan\Aksi\PostingStokAwal;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\StokAwal;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Catat stok awal besar di antrean (DesainF05a C.6.3). Membawa `IdTenant` dan menetapkan `KonteksTenant` lebih
 * dulu (CLAUDE.md #11); konteks sebelumnya dipulihkan. Hanya dokumen yang masih Memproses yang dicatat
 * (`PostingStokAwal` dengan `dariAntrean`). Pelanggaran aturan bisnis = dokumen kembali ke Draf dengan pesan galat
 * (tidak diulang); galat sistem diulang sampai `tries`, lalu `failed()` mengembalikan dokumen ke Draf.
 */
final class PostingStokAwalTugas implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly int $idTenant,
        public readonly int $idPengguna,
        public readonly int $idStokAwal,
    ) {}

    public function handle(PostingStokAwal $posting, GagalkanPostingStokAwal $gagalkan): void
    {
        $this->JalankanDalamTenant(function () use ($posting, $gagalkan): void {
            $dokumen = StokAwal::query()->find($this->idStokAwal);

            if ($dokumen === null || $dokumen->Status !== StatusStokAwal::Memproses) {
                return;
            }

            // Audit F-03: otorisasi pemosting diperiksa ulang saat tugas berjalan (bisa berubah sejak dikirim).
            $alasan = app(PenjagaOtorisasiTugas::class)->AmbilAlasanDitolak($this->idTenant, $this->idPengguna, IzinTenant::PersediaanStokAwalPosting);

            if ($alasan !== null) {
                $gagalkan->Jalankan($dokumen->Id, 'Pencatatan dihentikan karena '.$alasan.'. Stok dan jurnal tidak berubah.', $this->idPengguna);

                return;
            }

            try {
                $posting->Jalankan($dokumen, $this->idPengguna, dariAntrean: true);
            } catch (PelanggaranAturanBisnis $galat) {
                $gagalkan->Jalankan($dokumen->Id, $galat->getMessage(), $this->idPengguna);
            }
        });
    }

    public function failed(?Throwable $galat): void
    {
        $this->JalankanDalamTenant(function (): void {
            app(GagalkanPostingStokAwal::class)->Jalankan($this->idStokAwal, 'Pencatatan terhenti karena masalah sistem. Stok dan jurnal tidak berubah; coba catat lagi.', $this->idPengguna);
        });
    }

    /**
     * @param  Closure(): void  $kerja
     */
    private function JalankanDalamTenant(Closure $kerja): void
    {
        $konteks = app(KonteksTenant::class);
        $sebelumnya = $konteks->Ambil();
        $konteks->Atur($this->idTenant);

        try {
            $kerja();
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }
    }
}
