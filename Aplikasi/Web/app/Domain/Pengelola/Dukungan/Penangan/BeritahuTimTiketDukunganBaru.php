<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Dukungan\Penangan;

use App\Domain\Dukungan\Layanan\KehadiranPetugasObrolan;
use App\Domain\Dukungan\Peristiwa\TiketDukunganDibuat;
use App\Domain\Pengelola\Dukungan\Surel\TiketDukunganBaru;
use App\Domain\Pengelola\Operasional\Kueri\PenerimaSurelPeran;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Kueri\RingkasanTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * P-09: email tiket baru ke anggota Dukungan aktif (bila belum ada, ke Super Admin). Berjalan di antrean setelah
 * commit; kegagalan kirim tercatat sebagai job gagal di dasbor operasional (P-11).
 */
final class BeritahuTimTiketDukunganBaru implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly PenerimaSurelPeran $penerima,
        private readonly RingkasanTenant $ringkasanTenant,
        private readonly KehadiranPetugasObrolan $kehadiranObrolan,
    ) {}

    public function handle(TiketDukunganDibuat $peristiwa): void
    {
        // PLT-12 (D-163): obrolan baru muncul langsung di inbox petugas yang siap, jadi email hanya untuk obrolan yang
        // tidak punya petugas online (tiketnya tetap menunggu seperti tiket biasa).
        if ($peristiwa->obrolan && $this->kehadiranObrolan->HitungOnline() > 0) {
            return;
        }

        $namaTenant = $this->ringkasanTenant->Ambil([$peristiwa->idTenant])[0]['Nama'] ?? "Tenant #{$peristiwa->idTenant}";
        $batasSla = Carbon::parse($peristiwa->batasSlaPada)->setTimezone('Asia/Jakarta')->translatedFormat('j F Y H:i').' WIB';
        $tautan = route('pengelola.dukungan.tiket.tampil', $peristiwa->uuid);

        foreach ($this->penerima->Ambil(PeranPengelolaBawaan::Dukungan, PeranPengelolaBawaan::SuperAdmin) as $email) {
            Mail::to($email)->send(new TiketDukunganBaru(
                $peristiwa->nomor,
                $peristiwa->judul,
                $namaTenant,
                $peristiwa->kategori,
                $peristiwa->prioritas,
                $batasSla,
                $tautan,
            ));
        }
    }
}
