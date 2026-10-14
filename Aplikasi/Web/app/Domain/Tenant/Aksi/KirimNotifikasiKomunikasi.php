<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Aksi\BuatNotifikasiPengguna;
use App\Domain\Organisasi\Enum\JenisNotifikasiPengguna;
use App\Domain\Tenant\Enum\BahasaKomunikasi;
use App\Domain\Tenant\Enum\KanalKomunikasi;
use App\Domain\Tenant\Enum\PeristiwaKomunikasi;
use App\Domain\Tenant\Layanan\PenyusunPesanKomunikasi;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mengirim pesan platform sebagai notifikasi aplikasi (PGL-07, kanal `Notifikasi`): teksnya dirakit dari templat
 * (`PenyusunPesanKomunikasi`, cadangan teks bawaan), lalu dibuat sebagai `NotifikasiPengguna` per penerima lewat
 * `BuatNotifikasiPengguna` sehingga pusat notifikasi web, Aplikasi Owner, bisukan jenis, dan push-dimatikan (PLT-08)
 * berlaku sama seperti notifikasi lain. Idempoten per (penerima, kunci): pengiriman ulang tidak menggandakan.
 *
 * Tidak pernah menggagalkan pemanggil: notifikasi pelengkap email/WhatsApp, jadi galat dicatat sebagai peringatan.
 */
final class KirimNotifikasiKomunikasi
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenyusunPesanKomunikasi $penyusun,
        private readonly BuatNotifikasiPengguna $buat,
    ) {}

    /**
     * @param  list<int>  $idPenerima
     * @param  array<string, string>  $variabel
     * @return int jumlah notifikasi baru yang dibuat
     */
    public function Jalankan(
        int $idTenant,
        array $idPenerima,
        PeristiwaKomunikasi $peristiwa,
        array $variabel,
        string $kunciKejadian,
        string $tautan,
        BahasaKomunikasi $bahasa = BahasaKomunikasi::Indonesia,
    ): int {
        $pesan = $this->penyusun->Susun($peristiwa, KanalKomunikasi::Notifikasi, $variabel, $bahasa);
        $judul = $pesan->judul ?? $peristiwa->AmbilLabel();
        $sebelumnya = $this->konteks->Ambil();
        $this->konteks->Atur($idTenant);
        $dibuat = 0;

        try {
            foreach (array_values(array_unique($idPenerima)) as $idPengguna) {
                try {
                    $notifikasi = $this->buat->Jalankan(
                        $idPengguna,
                        JenisNotifikasiPengguna::PesanPlatform,
                        mb_substr("komunikasi:{$peristiwa->value}:{$kunciKejadian}", 0, 191),
                        $judul,
                        $pesan->isi,
                        ['Href' => $tautan, 'Peristiwa' => $peristiwa->value],
                    );
                    $dibuat += $notifikasi?->wasRecentlyCreated === true ? 1 : 0;
                } catch (Throwable $galat) {
                    Log::warning('Notifikasi komunikasi platform gagal dibuat.', ['IdTenant' => $idTenant, 'Peristiwa' => $peristiwa->value, 'Galat' => $galat->getMessage()]);
                }
            }
        } finally {
            $sebelumnya === null ? $this->konteks->Kosongkan() : $this->konteks->Atur($sebelumnya);
        }

        return $dibuat;
    }
}
