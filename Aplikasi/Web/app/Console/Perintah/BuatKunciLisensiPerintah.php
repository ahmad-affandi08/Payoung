<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Lisensi\Layanan\PenandaLisensi;
use Illuminate\Console\Command;

/**
 * D-35: membuat pasangan kunci penerbit lisensi. Dijalankan SEKALI oleh pemilik produk di mesin pribadi, bukan di
 * server produksi maupun server pembeli. Kunci publik ditempel ke `config/lisensi.php` lalu di-commit; kunci privat
 * hanya ditulis ke berkas yang diminta (izin 0600) dan tidak pernah dicetak ke layar atau log.
 */
final class BuatKunciLisensiPerintah extends Command
{
    protected $signature = 'lisensi:buat-kunci {berkasKunciPrivat : Berkas tujuan kunci privat (di luar repo, misal ~/payoung-lisensi.kunci)}';

    protected $description = 'Membuat pasangan kunci Ed25519 penerbit lisensi Payoung (D-35).';

    public function handle(PenandaLisensi $penanda): int
    {
        $berkas = (string) $this->argument('berkasKunciPrivat');

        if (file_exists($berkas)) {
            $this->error("Berkas {$berkas} sudah ada. Kunci lama tidak ditimpa: lisensi yang sudah terbit bergantung padanya.");

            return self::FAILURE;
        }

        $akarRepo = realpath(base_path('..'.DIRECTORY_SEPARATOR.'..'));
        $folderTujuan = realpath(dirname($berkas));

        if ($folderTujuan === false) {
            $this->error('Folder tujuan kunci privat tidak ada.');

            return self::FAILURE;
        }

        if ($akarRepo !== false && is_dir($akarRepo.DIRECTORY_SEPARATOR.'.git') && str_starts_with($folderTujuan.DIRECTORY_SEPARATOR, $akarRepo.DIRECTORY_SEPARATOR)) {
            $this->error('Simpan kunci privat di luar folder repo agar tidak ikut ter-commit.');

            return self::FAILURE;
        }

        $kunci = $penanda->BuatPasanganKunci();

        // Dibuat dengan mode `x` (gagal bila sudah ada, atomik) di bawah umask 0077 agar berkas tidak pernah sempat
        // terbaca pengguna lain sebelum izinnya dipersempit.
        $umaskLama = umask(0077);
        $pegangan = fopen($berkas, 'x');
        umask($umaskLama);

        if ($pegangan === false || fwrite($pegangan, $kunci['KunciPrivat']."\n") === false) {
            $this->error("Tidak bisa menulis {$berkas}.");

            return self::FAILURE;
        }

        fclose($pegangan);
        chmod($berkas, 0600);

        $this->info("Kunci privat disimpan di {$berkas}. Cadangkan di tempat aman. Kalau hilang, lisensi baru tidak bisa diterbitkan.");
        $this->line('Tempel kunci publik ini ke config/lisensi.php (KunciPublik) lalu commit:');
        $this->line($kunci['KunciPublik']);

        return self::SUCCESS;
    }
}
