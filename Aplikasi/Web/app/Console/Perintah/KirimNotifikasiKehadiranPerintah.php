<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Aksi\KirimNotifikasiKehadiran;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Organisasi\Kueri\KeanggotaanPengguna;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * F-18 bagian 5 (D-44): tiap 5 menit mengirim pengingat shift ke karyawan dan peringatan terlambat/belum masuk ke
 * pengelola lewat WhatsApp, untuk tenant yang mengaktifkannya. Kegagalan satu tenant tidak menghentikan tenant lain.
 */
final class KirimNotifikasiKehadiranPerintah extends Command
{
    protected $signature = 'karyawan:kirim-notifikasi-kehadiran {--tenant=* : Id tenant (kosong = semua)}';

    protected $description = 'Mengirim pengingat shift dan peringatan kehadiran (terlambat/belum masuk) lewat WhatsApp (F-18 bagian 5).';

    public function handle(KeanggotaanPengguna $keanggotaan, KonteksPengelola $pengelola, KonteksTenant $konteks, KirimNotifikasiKehadiran $kirim): int
    {
        $diminta = array_values(array_filter(array_map(
            fn (mixed $nilai): int => is_scalar($nilai) ? (int) $nilai : 0,
            (array) $this->option('tenant'),
        ), fn (int $id): bool => $id > 0));
        $sekarang = CarbonImmutable::now('UTC');

        // Berjalan tiap 5 menit, jadi hanya tenant yang punya jadwal dalam jendela dengan pesan yang belum ditandai yang
        // dikunjungi (jendela sama dengan `KirimNotifikasiKehadiran`). Dulu semua tenant dikunjungi tiap putaran,
        // yaitu minimal satu kueri per tenant hanya untuk menyimpulkan tidak ada yang dikerjakan.
        $daftar = $diminta === []
            ? $pengelola->IdTenantDenganPekerjaan(
                JadwalKerja::class,
                fn ($kueri) => $kueri
                    ->whereBetween('Tanggal', [$sekarang->subDays(2)->toDateString(), $sekarang->addDay()->toDateString()])
                    ->where(fn ($k) => $k->whereNull('PengingatShiftPada')->orWhereNull('PeringatanKehadiranPada')),
            )
            : array_values(array_intersect(array_unique($diminta), $keanggotaan->AmbilSemuaIdTenant()));
        $sebelumnya = $konteks->Ambil();
        $pengingat = 0;
        $peringatan = 0;
        $galat = 0;

        try {
            foreach ($daftar as $idTenant) {
                $konteks->Atur($idTenant);

                try {
                    // Satu proses per tenant: penanda ditulis setelah kirim, jadi dua proses yang tumpang tindih (jadwal dan
                    // pemanggilan manual, atau kunci withoutOverlapping yang kedaluwarsa) bisa mengirim pesan yang sama dua kali.
                    $hasil = Cache::lock("karyawan-notifikasi-kehadiran:{$idTenant}", 600)->get(fn (): array => $kirim->Jalankan($idTenant, CarbonImmutable::now('UTC')));

                    if ($hasil === false) {
                        continue;
                    }

                    $pengingat += $hasil['Pengingat'];
                    $peringatan += $hasil['Peringatan'];
                } catch (Throwable $e) {
                    $galat++;
                    report($e);
                }
            }
        } finally {
            $sebelumnya === null ? $konteks->Kosongkan() : $konteks->Atur($sebelumnya);
        }

        $this->line(count($daftar)." tenant diperiksa, {$pengingat} pengingat shift dan {$peringatan} peringatan terkirim, {$galat} tenant gagal.");

        return $galat === 0 ? self::SUCCESS : self::FAILURE;
    }
}
