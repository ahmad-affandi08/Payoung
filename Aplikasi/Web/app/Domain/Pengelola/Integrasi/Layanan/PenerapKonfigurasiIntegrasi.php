<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Integrasi\Layanan;

use App\Domain\Pengelola\Integrasi\Enum\JenisIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\LingkunganIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use App\Domain\Pengelola\Integrasi\Model\KonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Penguji\PenyusunKonfigurasiLaravel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menerapkan konfigurasi integrasi aktif lingkungan server ini ke konfigurasi Laravel saat aplikasi berjalan (P-05).
 * Kode lain (termasuk tenant, misal CAPTCHA registrasi F-00) membaca `config('integrasi...')`, `mail`, atau disk
 * `Objek`, tidak pernah model ini. Cache hanya menyimpan baris mentah yang kredensialnya masih terenkripsi.
 */
final class PenerapKonfigurasiIntegrasi
{
    public const KUNCI_CACHE = 'integrasi.konfigurasi-aktif';

    /**
     * Cache berumur pendek: cache juga dihapus setelah setiap perubahan, tetapi request yang membaca sebelum commit
     * bisa menulis ulang data lama; TTL membatasi data basi itu paling lama sekian detik (BR-P05.4).
     */
    public const DETIK_CACHE = 60;

    public static function LupakanCache(): void
    {
        Cache::forget(self::KUNCI_CACHE);
    }

    public function Terapkan(): void
    {
        foreach ($this->AmbilKonfigurasiAktif() as $konfigurasi) {
            match ($konfigurasi->Jenis) {
                JenisIntegrasi::Email => config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp' => PenyusunKonfigurasiLaravel::MailerSmtp($konfigurasi->Pengaturan, $konfigurasi->Kredensial),
                    'mail.from.address' => (string) ($konfigurasi->Pengaturan['AlamatPengirim'] ?? ''),
                    'mail.from.name' => (string) ($konfigurasi->Pengaturan['NamaPengirim'] ?? ''),
                    'integrasi.EmailAktif' => true,
                ]),
                JenisIntegrasi::Captcha => config([
                    'integrasi.Turnstile.KunciSitus' => (string) ($konfigurasi->Pengaturan['KunciSitus'] ?? ''),
                    'integrasi.Turnstile.KunciRahasia' => $konfigurasi->Kredensial['KunciRahasia'] ?? '',
                ]),
                JenisIntegrasi::Penyimpanan => config([
                    'filesystems.disks.Objek' => PenyusunKonfigurasiLaravel::DiskS3($konfigurasi->Pengaturan, $konfigurasi->Kredensial),
                    'integrasi.PenyimpananObjekAktif' => true,
                ]),
                // D-216: pengirim email kampanye prospek, terpisah dari `mail.default` (email transaksional).
                JenisIntegrasi::EmailKampanye => config([
                    'mail.mailers.kampanye' => PenyusunKonfigurasiLaravel::MailerSmtp($konfigurasi->Pengaturan, $konfigurasi->Kredensial),
                    'integrasi.EmailKampanye' => [
                        'AlamatPengirim' => (string) ($konfigurasi->Pengaturan['AlamatPengirim'] ?? ''),
                        'NamaPengirim' => (string) ($konfigurasi->Pengaturan['NamaPengirim'] ?? ''),
                    ],
                    'integrasi.EmailKampanyeAktif' => true,
                ]),
                // v2.06: gerbang pembayaran diatur tiap tenant (`GerbangPembayaranTenant`); baris platform lama diabaikan.
                JenisIntegrasi::GerbangPembayaran => null,
                // v2.04 WhatsApp, v2.69 Push (FCM), v2.70 gerbang billing, v4.70 Masuk dengan Google, DOKU Partner (pendaftaran merchant): bentuknya sama — penyedia, pengaturan,
                // dan kredensial diterbitkan apa adanya, lalu dibaca layanan pemakainya lewat `config('integrasi.*')`.
                // `match` ini tanpa `default` dengan sengaja: menambah jenis integrasi baru tanpa menerbitkannya di
                // sini akan gagal keras, bukan diam-diam tidak aktif. Dijaga `PenerapKonfigurasiIntegrasiTes`.
                JenisIntegrasi::Whatsapp, JenisIntegrasi::Push, JenisIntegrasi::GerbangBilling, JenisIntegrasi::LoginSosial, JenisIntegrasi::PendaftaranMerchant, JenisIntegrasi::AnalitikGoogle => config([
                    'integrasi.'.$konfigurasi->Jenis->value => [
                        'Penyedia' => $konfigurasi->Penyedia->value,
                        'Pengaturan' => $konfigurasi->Pengaturan,
                        'Kredensial' => $konfigurasi->Kredensial,
                    ],
                ]),
            };
        }
    }

    /**
     * @return list<KonfigurasiIntegrasi>
     */
    private function AmbilKonfigurasiAktif(): array
    {
        try {
            /** @var list<array<string, mixed>> $baris */
            $baris = Cache::remember(self::KUNCI_CACHE, self::DETIK_CACHE, fn (): array => array_values(KonfigurasiIntegrasi::query()
                ->where('Lingkungan', LingkunganIntegrasi::AmbilSaatIni()->value)
                ->where('Aktif', true)
                ->get()
                ->map(fn (KonfigurasiIntegrasi $konfigurasi): array => $konfigurasi->getAttributes())
                ->all()));
        } catch (QueryException) {
            // Tabel belum dimigrasi (instalasi baru): aplikasi tetap berjalan dengan konfigurasi .env.
            return [];
        } catch (Throwable $galat) {
            // Cache store mati, dsb.: jangan menjatuhkan semua request tenant & POS, pakai konfigurasi .env.
            Log::error('Konfigurasi integrasi tidak bisa dimuat.', ['Pesan' => $galat->getMessage()]);

            return [];
        }

        $daftar = [];

        foreach ($baris as $atribut) {
            // Penyedia/jenis yang sudah dihapus dari kode (mis. gerbang lama sebelum migrasi pembersih jalan) dilewati:
            // membaca barisnya lewat enum akan melempar `ValueError` dan menjatuhkan boot, termasuk `php artisan migrate`
            // yang justru akan membersihkan baris itu.
            if (! is_string($atribut['Penyedia'] ?? null) || PenyediaIntegrasi::tryFrom($atribut['Penyedia']) === null
                || ! is_string($atribut['Jenis'] ?? null) || JenisIntegrasi::tryFrom($atribut['Jenis']) === null) {
                continue;
            }

            try {
                // Uji dekripsi di sini agar APP_KEY yang berganti tidak menjatuhkan boot semua request.
                Crypt::decryptString(is_string($atribut['Kredensial'] ?? null) ? $atribut['Kredensial'] : '');
                $daftar[] = (new KonfigurasiIntegrasi)->newFromBuilder($atribut);
            } catch (DecryptException) {
                Log::error('Kredensial integrasi tidak bisa didekripsi; periksa APP_KEY/APP_PREVIOUS_KEYS.', ['Jenis' => $atribut['Jenis'] ?? null]);
            }
        }

        return $daftar;
    }
}
