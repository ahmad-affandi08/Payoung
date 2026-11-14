<?php

declare(strict_types=1);

namespace App\Console\Perintah;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Lisensi\Aksi\PasangLisensi;
use App\Domain\Lisensi\Galat\LisensiTidakSah;
use App\Domain\Lisensi\Model\LisensiTerpasang;
use App\Domain\Organisasi\Data\DataPemilikBaru;
use App\Domain\Tenant\Model\Fitur;
use App\Http\Permintaan\Autentikasi\DaftarPermintaan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * D-35: memasang berkas lisensi di server pembeli. Pemasangan pertama juga membuat usaha & akun Owner (kata sandi
 * diminta tersembunyi, tidak pernah dicetak/dicatat). Menjalankannya lagi dengan berkas baru mengganti lisensi
 * (tambah outlet/perangkat, pindah domain) tanpa menyentuh data usaha.
 */
final class PasangLisensiPerintah extends Command
{
    protected $signature = 'lisensi:pasang
        {berkas : Berkas lisensi dari Payoung}
        {--nama-usaha= : Nama usaha (pemasangan pertama)}
        {--nama= : Nama lengkap Owner (pemasangan pertama)}
        {--email= : Email Owner untuk masuk dashboard (pemasangan pertama)}
        {--hp= : Nomor WhatsApp Owner, misal 081234567890 (pemasangan pertama)}';

    protected $description = 'Memasang atau mengganti berkas lisensi Payoung di server ini (edisi Lisensi, D-35).';

    public function handle(PasangLisensi $pasang): int
    {
        $berkas = Str::of(is_string($this->argument('berkas')) ? $this->argument('berkas') : '')->toString();
        $isi = is_readable($berkas) ? file_get_contents($berkas) : false;

        if ($isi === false) {
            $this->error("Berkas {$berkas} tidak bisa dibaca.");

            return self::FAILURE;
        }

        $pertama = ! LisensiTerpasang::query()->exists();
        $namaUsaha = null;
        $pemilik = null;

        if ($pertama) {
            $isian = $this->TanyaPemilik();

            if ($isian === null) {
                return self::FAILURE;
            }

            [$namaUsaha, $pemilik] = $isian;
        }

        try {
            $data = $pasang->Jalankan($isi, $namaUsaha, $pemilik);
        } catch (LisensiTidakSah|PelanggaranAturanBisnis $galat) {
            $this->error($galat->getMessage());

            return self::FAILURE;
        }

        $this->info("Lisensi {$data->nomor} untuk {$data->namaPemegang} terpasang di domain {$data->domain}.");
        $this->line(sprintf(
            'Batas: outlet %s, perangkat per outlet %s, pengguna %s. Semua fitur aktif, berlaku selamanya.',
            $data->batasOutlet ?? 'tak terbatas',
            $data->batasPerangkatPerOutlet ?? 'tak terbatas',
            $data->batasPengguna ?? 'tak terbatas',
        ));

        // Katalog fitur diisi `db:seed`; tanpa itu lisensi sah tidak memberi fitur apa pun.
        if (! Fitur::query()->exists()) {
            $this->warn('Katalog fitur masih kosong: jalankan php artisan db:seed --force agar semua fitur aktif.');
        }

        if ($pertama && $pemilik !== null) {
            $this->info("Masuk ke https://{$data->domain}/masuk dengan {$pemilik->email}.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: DataPemilikBaru}|null null bila isian tidak lolos validasi (galat sudah dicetak)
     */
    private function TanyaPemilik(): ?array
    {
        $ambil = fn (string $opsi, string $tanya): string => trim(is_string($this->option($opsi)) && $this->option($opsi) !== ''
            ? $this->option($opsi)
            : (string) $this->ask($tanya));

        $namaUsaha = $ambil('nama-usaha', 'Nama usaha');
        $nama = $ambil('nama', 'Nama lengkap Owner');
        $email = Str::lower($ambil('email', 'Email Owner'));
        $hp = $ambil('hp', 'Nomor WhatsApp Owner');
        $kataSandi = (string) $this->secret('Kata sandi Owner (minimal 12 karakter, huruf dan angka)');
        $konfirmasi = (string) $this->secret('Ulangi kata sandi');

        $validasi = Validator::make(
            ['NamaUsaha' => $namaUsaha, 'Nama' => $nama, 'Email' => $email, 'NoHp' => $hp, 'KataSandi' => $kataSandi, 'KonfirmasiKataSandi' => $konfirmasi],
            [
                'NamaUsaha' => ['required', 'string', 'max:150'],
                'Nama' => ['required', 'string', 'max:150'],
                'Email' => ['required', 'email', 'max:191'],
                'NoHp' => ['required', 'string', 'regex:'.DaftarPermintaan::POLA_NO_HP],
                'KataSandi' => ['required', 'string', 'max:255', Password::min(12)->letters()->numbers()],
                'KonfirmasiKataSandi' => ['required', 'same:KataSandi'],
            ],
        );

        if ($validasi->fails()) {
            foreach ($validasi->errors()->all() as $pesan) {
                $this->error($pesan);
            }

            return null;
        }

        return [$namaUsaha, new DataPemilikBaru(nama: $nama, email: $email, noHp: DaftarPermintaan::NormalkanNoHp($hp), kataSandi: $kataSandi)];
    }
}
