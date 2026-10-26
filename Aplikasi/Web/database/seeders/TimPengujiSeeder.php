<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Aksi\AturPinSendiri;
use App\Domain\Organisasi\Aksi\BuatPerangkat;
use App\Domain\Organisasi\Data\DataPemilikBaru;
use App\Domain\Organisasi\Enum\JenisPerangkat;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\PanduanAwal\Aksi\SelesaikanPanduanAwal;
use App\Domain\PanduanAwal\Aksi\SiapkanOtomatisPanduan;
use App\Domain\Pengelola\Tenant\Aksi\BuatOverrideTenant;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Aksi\DaftarkanTenant;
use App\Domain\Tenant\Data\DataPendaftaran;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PastikanBatasPaket;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Permintaan\Autentikasi\DaftarPermintaan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * Tenant khusus tim penguji Google Play (pengujian tertutup Payoung POS), dibuat langsung siap pakai: email Owner
 * terverifikasi, panduan awal selesai (template FnB + produk contoh + pajak usulan), PIN kasir Owner terpasang, dan
 * beberapa perangkat kasir beserta kode aktivasinya. Aman dijalankan di produksi (memakai Aksi pendaftaran biasa),
 * berbeda dari `DataDemoLokal`.
 *
 * Tidak ada kredensial di berkas ini. Isian dibaca dari lingkungan atau ditanyakan saat dijalankan interaktif:
 * `PENGUJI_EMAIL`, `PENGUJI_NO_HP` (nomor WhatsApp, harus unik), `PENGUJI_KATA_SANDI` (min. 12 karakter, huruf & angka),
 * `PENGUJI_PIN` (tepat 6 digit, bukan angka sama semua atau deret), `PENGUJI_NAMA_USAHA` (bawaan "Toko Penguji Payoung"),
 * `PENGUJI_JUMLAH_PERANGKAT` (bawaan 50; bila melewati batas paket, batas perangkat per outlet tenant ini
 * dinaikkan lewat override pengelola P-07 selama masa trial, paket Pro tidak diubah). Kode aktivasi dicetak sekali ke layar dan hanya berlaku selama
 * `MENIT_BERLAKU_KODE_AKTIVASI` (naikkan dulu bila kode dibagikan jauh hari). Idempoten: bila Owner dengan email itu
 * sudah ada, tidak ada yang diubah.
 *
 * Jalankan: php artisan db:seed --class=TimPengujiSeeder --force
 *
 * Nama kelas mengikuti `DatabaseSeeder`; `run()` adalah metode framework (§13.7.4).
 */
final class TimPengujiSeeder extends Seeder
{
    private const KODE_PAKET = 'PRO';

    private const KODE_TEMPLATE = 'FNB-CAF';

    /** Trial dipanjangkan supaya tenant penguji tidak turun ke paket Gratis di tengah masa uji 14 hari. */
    private const HARI_TRIAL = 60;

    public function run(
        DaftarkanTenant $daftarkanTenant,
        SiapkanOtomatisPanduan $siapkanPanduan,
        SelesaikanPanduanAwal $selesaikanPanduan,
        AturPinSendiri $aturPin,
        BuatPerangkat $buatPerangkat,
        BuatOverrideTenant $buatOverride,
        PastikanBatasPaket $batasPaket,
        KonteksTenant $konteks,
    ): void {
        $email = mb_strtolower($this->Ambil('PENGUJI_EMAIL', 'Email Owner tenant penguji'));

        if (Pengguna::query()->where('Email', $email)->exists()) {
            $this->command?->warn("Owner {$email} sudah ada. Tidak ada yang diubah. Kode aktivasi baru: Kelola › Perangkat.");

            return;
        }

        $noHp = DaftarPermintaan::NormalkanNoHp($this->Ambil('PENGUJI_NO_HP', 'Nomor WhatsApp Owner, misal 081234567890'));
        $kataSandi = $this->Ambil('PENGUJI_KATA_SANDI', 'Kata sandi Owner (min. 12 karakter, huruf & angka)', rahasia: true);
        $pin = $this->Ambil('PENGUJI_PIN', 'PIN kasir Owner (6 digit)', rahasia: true);
        $namaUsaha = $this->AmbilAtauBawaan('PENGUJI_NAMA_USAHA', 'Toko Penguji Payoung');
        $jumlahPerangkat = max(0, (int) $this->AmbilAtauBawaan('PENGUJI_JUMLAH_PERANGKAT', '50'));

        $this->Validasi($email, $noHp, $kataSandi, $pin, $namaUsaha);

        $hasil = $daftarkanTenant->Jalankan(new DataPendaftaran(
            pemilik: new DataPemilikBaru(nama: 'Tim Penguji Payoung', email: $email, noHp: $noHp, kataSandi: $kataSandi),
            namaUsaha: $namaUsaha,
            kodePaket: self::KODE_PAKET,
            ip: '127.0.0.1',
        ));
        $tenant = $hasil['Tenant'];
        $pemilik = $hasil['Pengguna'];

        // Email dianggap terverifikasi: tenant dibuat oleh tim sendiri, bukan pendaftar luar (BR-00.5 tidak relevan).
        $pemilik->forceFill(['EmailDiverifikasiPada' => now()])->save();

        Langganan::query()->where('IdTenant', $tenant->Id)->update(['TrialBerakhirPada' => now()->addDays(self::HARI_TRIAL)]);

        $this->NaikkanBatasPerangkat($buatOverride, $batasPaket, $tenant, $jumlahPerangkat);

        $konteks->Atur($tenant->Id);

        try {
            $outlet = Outlet::query()->orderBy('Id')->firstOrFail();
            $ringkasan = $siapkanPanduan->Jalankan($outlet, self::KODE_TEMPLATE);
            $selesaikanPanduan->Jalankan($pemilik->Id, $outlet->Id);
            $aturPin->Jalankan($tenant->Id, $pemilik->Id, $pin);

            $this->command?->info("Tenant {$tenant->Nama} dibuat. Owner: {$email} (email terverifikasi, trial {$this->HariTrial()} hari).");
            $this->command?->info("Template {$ringkasan['Template']} diterapkan, {$ringkasan['JumlahProduk']} produk contoh ditambahkan, panduan awal selesai, PIN kasir Owner terpasang.");

            $this->BuatPerangkatPenguji($buatPerangkat, $outlet, $pemilik->Id, $jumlahPerangkat);
        } finally {
            $konteks->Kosongkan();
        }

        $this->command?->warn('Simpan kode di atas sekarang: kode tidak ditampilkan lagi dan sekali pakai. Kata sandi & PIN tidak dicetak.');
    }

    /** BR-P07.7: override sementara (maks. 90 hari, dicatat di audit pengelola) hanya bila batas paket kurang dari jumlah yang diminta. */
    private function NaikkanBatasPerangkat(BuatOverrideTenant $buatOverride, PastikanBatasPaket $batasPaket, Tenant $tenant, int $jumlah): void
    {
        $batas = $batasPaket->AmbilRingkasan($tenant->Id, 'BatasPerangkatPerOutlet', 0)['Batas'];

        if ($batas === null || $jumlah <= $batas) {
            return;
        }

        $pelaku = PenggunaPengelola::query()->orderBy('Id')->first();

        if ($pelaku === null) {
            $this->command?->warn("Belum ada akun pengelola, jadi batas perangkat paket ({$batas}) tidak bisa dinaikkan; hanya {$batas} perangkat yang dibuat.");

            return;
        }

        $buatOverride->Jalankan(
            $pelaku,
            $tenant,
            JenisOverride::Batas,
            'BatasPerangkatPerOutlet',
            $jumlah,
            now()->addDays(self::HARI_TRIAL),
            'Tenant tim penguji Google Play: perangkat kasir lebih banyak dari batas paket selama pengujian tertutup.',
        );
    }

    private function BuatPerangkatPenguji(BuatPerangkat $buatPerangkat, Outlet $outlet, int $idPemilik, int $jumlah): void
    {
        for ($i = 1; $i <= $jumlah; $i++) {
            try {
                $hasil = $buatPerangkat->Jalankan($outlet, "Penguji {$i}", JenisPerangkat::Kasir, $idPemilik);
            } catch (PelanggaranAturanBisnis $galat) {
                $this->command?->warn("Perangkat {$i} tidak dibuat: {$galat->getMessage()}");

                break;
            }

            $this->command?->line(sprintf(
                'Penguji %d | %s | kode aktivasi %s | berlaku sampai %s WIB',
                $i,
                $hasil['Perangkat']->Kode,
                $hasil['Kode'],
                $hasil['KedaluwarsaPada']->copy()->timezone('Asia/Jakarta')->format('d M Y H:i'),
            ));
        }
    }

    private function HariTrial(): int
    {
        return self::HARI_TRIAL;
    }

    private function Validasi(string $email, string $noHp, string $kataSandi, string $pin, string $namaUsaha): void
    {
        $validasi = Validator::make(
            ['Email' => $email, 'NoHp' => $noHp, 'KataSandi' => $kataSandi, 'Pin' => $pin, 'NamaUsaha' => $namaUsaha],
            [
                'Email' => ['required', 'email:rfc', 'max:191'],
                'NoHp' => ['required', 'string', 'regex:'.DaftarPermintaan::POLA_NO_HP],
                'KataSandi' => ['required', 'string', 'max:100', Password::min(12)->letters()->numbers()],
                'Pin' => ['required', 'regex:/^\d{6}$/'],
                'NamaUsaha' => ['required', 'string', 'min:3', 'max:150'],
            ],
        );

        if ($validasi->fails()) {
            throw new RuntimeException('Isian tidak valid: '.implode(' ', $validasi->errors()->all()));
        }
    }

    private function AmbilAtauBawaan(string $kunci, string $bawaan): string
    {
        $nilai = getenv($kunci);

        return is_string($nilai) && $nilai !== '' ? $nilai : $bawaan;
    }

    /** Dari lingkungan (getenv, bukan env(): tidak boleh ikut ter-cache config), bila kosong ditanyakan saat interaktif. */
    private function Ambil(string $kunci, string $pertanyaan, bool $rahasia = false): string
    {
        $nilai = getenv($kunci);

        if (is_string($nilai) && $nilai !== '') {
            return $nilai;
        }

        if ($this->command === null) {
            throw new RuntimeException("Isi {$kunci} di lingkungan, atau jalankan seeder secara interaktif.");
        }

        $jawaban = trim((string) ($rahasia ? $this->command->secret($pertanyaan) : $this->command->ask($pertanyaan)));

        // Tanpa terminal interaktif (--no-interaction) pertanyaan dijawab kosong.
        if ($jawaban === '') {
            throw new RuntimeException("{$pertanyaan} wajib diisi (atau isi {$kunci} di lingkungan).");
        }

        return $jawaban;
    }
}
