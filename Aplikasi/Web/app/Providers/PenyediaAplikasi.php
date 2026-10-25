<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Database\MakroSkema;
use App\Domain\Bersama\Peristiwa\PeristiwaIntegrasi;
use App\Domain\Bersama\Sinkron\Kontrak\PenjagaAsalItemSinkron;
use App\Domain\Bersama\Sinkron\Layanan\PenandaSinkronPos;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Bersama\Unduhan\Layanan\BatasEksporLaporan;
use App\Domain\Dukungan\Kontrak\GerbangPengelola;
use App\Domain\Dukungan\Layanan\KonteksAksesDukungan;
use App\Domain\Dukungan\Peristiwa\TiketDukunganDibalasPelapor;
use App\Domain\Dukungan\Peristiwa\TiketDukunganDibuat;
use App\Domain\Integrasi\ApiPublik\Penangan\AntrekanWebhookIntegrasi;
use App\Domain\Integrasi\ApiPublik\Penangan\AntrekanWebhookPenjualan;
use App\Domain\Integrasi\Billing\PembuatPenyediaTagihan;
use App\Domain\Integrasi\Billing\PenyediaTagihanPlatform;
use App\Domain\Integrasi\Merchant\PembuatPendaftarMerchant;
use App\Domain\Integrasi\Merchant\PendaftarMerchantPembayaran;
use App\Domain\Katalog\Layanan\PemberitahuProdukDiubah;
use App\Domain\Lisensi\Kontrak\PengaturIntegrasiServer;
use App\Domain\Lisensi\Kontrak\PenyiapDataBawaan;
use App\Domain\Organisasi\Layanan\PenjagaAsalSinkronPerangkat;
use App\Domain\Organisasi\Model\Perangkat;
use App\Domain\Organisasi\Model\TokenAksesPengguna;
use App\Domain\PanduanAwal\Model\TemplateSektor;
use App\Domain\PanduanAwal\Model\TemplateSektorVersi;
use App\Domain\Pengelola\DataBawaan\Aksi\SiapkanDataBawaanLisensi;
use App\Domain\Pengelola\Dukungan\Layanan\GerbangPengelolaDukungan;
use App\Domain\Pengelola\Dukungan\Penangan\BeritahuPenanggungJawabBalasanPelapor;
use App\Domain\Pengelola\Dukungan\Penangan\BeritahuTimTiketDukunganBaru;
use App\Domain\Pengelola\Integrasi\Layanan\PenerapKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Layanan\PengaturIntegrasiServerLisensi;
use App\Domain\Pengelola\Operasional\Layanan\PencatatRiwayatJadwal;
use App\Domain\Pengelola\Operasional\Penangan\PeriksaOperasionalSaatCekSehat;
use App\Domain\Pengelola\Tagihan\Penangan\KirimSurelPelunasanGerbang;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Penjualan\Penangan\BeritahuTransaksiBesar;
use App\Domain\Penjualan\Peristiwa\PenjualanDiterima;
use App\Domain\Penjualan\Peristiwa\PenjualanDivoid;
use App\Domain\Penjualan\Peristiwa\ReturPenjualanDiterima;
use App\Domain\Tenant\Peristiwa\TagihanLanggananDilunasiGerbang;
use App\Http\Perantara\AutentikasiPemilik;
use App\Http\Perantara\AutentikasiPerangkat;
use App\Http\Rute\ValidatorHalamanSitus;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Throwable;

final class PenyediaAplikasi extends ServiceProvider
{
    public function register(): void
    {
        // Rute ter-cache tetap menjalankan `ValidatorHalamanSitus` (lihat `PakaiKoleksiRuteBiasa`); tanpa ini
        // `php artisan optimize` membuat `/masuk` dashboard tertangkap rute halaman situs.
        RouteServiceProvider::loadCachedRoutesUsing(function (): void {
            $this->app->booted(function (): void {
                require $this->app->getCachedRoutesPath();
                self::PakaiKoleksiRuteBiasa($this->app->make(Router::class));
            });
        });

        // "scoped": dibuat ulang untuk setiap request/job sehingga tenant tidak terbawa antar-request.
        $this->app->scoped(KonteksTenant::class);
        $this->app->scoped(PencatatAuditPengelola::class);

        // P-09: KonteksPengelola menyimpan status "di dalam JalankanLintasTenant" per request/job.
        $this->app->scoped(KonteksPengelola::class);
        // F-02: pencatat log audit tenant (pelaku & IP diisi perantara per request).
        $this->app->scoped(PencatatAudit::class);
        $this->app->scoped(PenandaSinkronPos::class);
        // X7: satu webhook `produk.diubah` per produk per transaksi.
        $this->app->scoped(PemberitahuProdukDiubah::class);
        // Audit P0 F-01: perangkat asal item outbox (cache perangkat per permintaan).
        $this->app->scoped(PenjagaAsalItemSinkron::class, PenjagaAsalSinkronPerangkat::class);
        // D-124: batas baris ekspor laporan (5.000 langsung; lebih besar hanya saat disiapkan di latar belakang).
        $this->app->scoped(BatasEksporLaporan::class);
        // D-35: back-office edisi Lisensi mengatur integrasi server lewat kontrak, bukan domain Pengelola langsung.
        $this->app->bind(PengaturIntegrasiServer::class, PengaturIntegrasiServerLisensi::class);
        $this->app->bind(PenyiapDataBawaan::class, SiapkanDataBawaanLisensi::class);
        // PGL-16: sisi tenant memakai kontrak, sehingga kode tenant tidak menyentuh domain Pengelola.
        $this->app->bind(GerbangPengelola::class, GerbangPengelolaDukungan::class);
        // PGL-16: sesi akses dukungan yang sah hanya hidup selama satu permintaan/job.
        $this->app->scoped(KonteksAksesDukungan::class);
        // D-179: tagihan langganan memakai port penyedia; adaptor dipilih dari `integrasi.PenyediaTagihan`.
        // D-179: pendaftaran merchant (KYB) lewat port yang sama polanya.
        $this->app->bind(PendaftarMerchantPembayaran::class, static fn (Application $aplikasi): PendaftarMerchantPembayaran => $aplikasi->make(PembuatPendaftarMerchant::class)->Buat());
        $this->app->bind(PenyediaTagihanPlatform::class, static fn (Application $aplikasi): PenyediaTagihanPlatform => $aplikasi->make(PembuatPenyediaTagihan::class)->Buat());
    }

    /**
     * Rute ter-cache dicocokkan matcher Symfony yang hanya memeriksa pola, sehingga validator rute kustom
     * (`ValidatorHalamanSitus`) terlewati: `/{slugHalaman}` yang terdaftar lebih dulu menangkap `/masuk`, `/daftar`,
     * dan slug toko online, lalu dashboard dialihkan ke domain pemasaran (404). Rute ter-cache dipindah ke koleksi
     * rute biasa yang menjalankan semua validator; berkas cache tetap dipakai (tanpa membaca ulang `routes/`).
     * No-op bila rute tidak ter-cache.
     */
    public static function PakaiKoleksiRuteBiasa(Router $router): void
    {
        $terkompilasi = $router->getRoutes();

        if (! $terkompilasi instanceof CompiledRouteCollection) {
            return;
        }

        $biasa = new RouteCollection;

        foreach ($terkompilasi->getRoutes() as $rute) {
            $biasa->add($rute);
        }

        $router->setRoutes($biasa);
    }

    public function boot(): void
    {
        MakroSkema::Daftarkan();

        // D-21: rute `situs.halaman` hanya cocok untuk slug yang memang halaman situs (lihat `ValidatorHalamanSitus`).
        $validator = Route::getValidators();

        if (collect($validator)->doesntContain(fn (object $v): bool => $v instanceof ValidatorHalamanSitus)) {
            Route::$validators = [...$validator, new ValidatorHalamanSitus];
        }

        // Versi template dicari per nomor versi di dalam template pada URL, misal /template-sektor/FNB-CAF/versi/2 (P-03).
        // Didaftarkan di provider, bukan di berkas rute: `route:cache` (php artisan optimize) tidak memuat ulang
        // `routes/`, sehingga binder di sana hilang dan setiap halaman versi menjadi 404.
        $this->app->make(Router::class)->bind('templateSektorVersi', static function (string $nilai, Route $rute): TemplateSektorVersi {
            $template = $rute->parameter('templateSektor');
            $kode = $template instanceof TemplateSektor ? $template->Kode : (is_string($template) ? $template : '');

            return TemplateSektorVersi::query()
                ->whereHas('TemplateSektor', fn ($kueri) => $kueri->where('Kode', $kode))
                ->where('Versi', ctype_digit($nilai) ? (int) $nilai : 0)
                ->firstOrFail();
        });

        // Pabrik model berada di Database\Pabrik dengan akhiran "Pabrik" (PRD §13.7).
        Factory::guessFactoryNamesUsing(static function (string $model): string {
            $kelasPabrik = 'Database\\Pabrik\\'.class_basename($model).'Pabrik';

            if (! is_subclass_of($kelasPabrik, Factory::class)) {
                throw new LogicException("Pabrik {$kelasPabrik} untuk model {$model} tidak ditemukan.");
            }

            return $kelasPabrik;
        });

        // Mencegah lazy loading (N+1), atribut tak dikenal, dan mass assignment diam-diam saat pengembangan.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Audit PAY-P2-06: di produksi pelanggaran yang sama TERLIHAT (log), tidak lagi diam, tanpa menjatuhkan
        // permintaan: lazy loading (N+1) dan atribut yang dibuang diam-diam saat mass assignment.
        if ($this->app->isProduction()) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relasi): void {
                Log::warning('Lazy loading terdeteksi (N+1).', ['Model' => $model::class, 'Relasi' => $relasi]);
            });
            Model::preventSilentlyDiscardingAttributes();
            Model::handleDiscardedAttributeViolationUsing(static function (Model $model, array $atribut): void {
                Log::warning('Atribut dibuang diam-diam saat mass assignment.', ['Model' => $model::class, 'Atribut' => $atribut]);
            });
        }

        // BR-00.4: batas percobaan registrasi per IP; pesan tampil di formulir, bukan halaman galat 429.
        RateLimiter::for('pendaftaran', static fn (Request $permintaan) => Limit::perHour((int) config('tenant.BatasRegistrasiPerJam'))
            ->by((string) $permintaan->ip())
            ->response(static fn () => back()->withErrors(['Umum' => 'Terlalu banyak percobaan pendaftaran dari jaringan ini. Coba lagi dalam satu jam.'])));

        // API POS: batas per rute dan per perangkat (bukan per IP bersama). Perangkat-perangkat satu outlet biasanya di
        // balik satu IP (NAT); kunci per IP membuat polling pesanan terbuka/KDS dari beberapa perangkat saling
        // menghabiskan jatah. Rute tanpa perangkat (aktivasi) tetap dibatasi per IP.
        foreach ([10, 20, 30, 60, 120, 600] as $perMenit) {
            RateLimiter::for("pos-{$perMenit}", static function (Request $permintaan) use ($perMenit): Limit {
                $perangkat = $permintaan->attributes->get(AutentikasiPerangkat::ATRIBUT);
                $rute = (string) $permintaan->route()?->getName();

                return Limit::perMinute($perMenit)->by($rute.'|'.($perangkat instanceof Perangkat ? 'perangkat:'.$perangkat->Id : 'ip:'.$permintaan->ip()));
            });
        }

        // F-17 Self-Order QR Meja (tanpa login): per rute, per meja (token), per IP. Tamu satu restoran biasanya di balik
        // satu IP wifi, jadi kunci per IP saja membuat meja-meja saling menghabiskan jatah polling status.
        foreach ([20, 60, 300] as $perMenit) {
            RateLimiter::for("pesan-sendiri-{$perMenit}", static function (Request $permintaan) use ($perMenit): Limit {
                $rute = $permintaan->route();
                $token = $rute?->parameter('tokenMeja');

                return Limit::perMinute($perMenit)->by($rute?->getName().'|'.(is_string($token) ? $token : '').'|'.$permintaan->ip());
            });
        }

        // F-17 bagian 4 (kios pesan sendiri): satu tablet outlet di balik satu IP memanggil rute ini terus-menerus, jadi
        // kuncinya per rute + token kios + IP dengan batas lebih longgar dari QR meja.
        foreach ([30, 120, 600] as $perMenit) {
            RateLimiter::for("kios-{$perMenit}", static function (Request $permintaan) use ($perMenit): Limit {
                $rute = $permintaan->route();
                $token = $rute?->parameter('tokenKios');

                return Limit::perMinute($perMenit)->by($rute?->getName().'|'.(is_string($token) ? $token : '').'|'.$permintaan->ip());
            });
        }

        // API Pemilik (OWN-01): batas per rute per pengguna (token); sebelum masuk per IP.
        foreach ([30, 60] as $perMenit) {
            RateLimiter::for("pemilik-{$perMenit}", static function (Request $permintaan) use ($perMenit): Limit {
                $token = $permintaan->attributes->get(AutentikasiPemilik::ATRIBUT_TOKEN);
                $rute = (string) $permintaan->route()?->getName();

                return Limit::perMinute($perMenit)->by($rute.'|'.($token instanceof TokenAksesPengguna ? 'pengguna:'.$token->IdPengguna : 'ip:'.$permintaan->ip()));
            });
        }

        // F-18 absensi web (D-37): karyawan satu toko biasanya di balik satu IP wifi, jadi batas ketat dikunci per tautan
        // (hash token) dan batas IP dibuat longgar supaya karyawan-karyawan satu outlet tidak saling menghabiskan jatah.
        RateLimiter::for('absensi-web', static function (Request $permintaan): array {
            // D-180/D-198: absen berlogin dibatasi per akun; lewat tautan pribadi (tanpa login) per tautan (hash token),
            // dan keduanya per IP.
            $idPengguna = $permintaan->user()?->getAuthIdentifier();

            return [
                Limit::perMinute(10)->by('absen:'.self::KunciPemakaiAbsen($permintaan, $idPengguna)),
                Limit::perMinute(120)->by('absen-ip:'.$permintaan->ip()),
            ];
        });

        // D-186: cek kecocokan wajah langsung di layar absen dipanggil sekitar sekali per detik selama kamera menyala,
        // jadi jatahnya jauh di atas absen sungguhan tetapi tetap dibatasi per akun dan per IP.
        RateLimiter::for('cek-wajah', static function (Request $permintaan): array {
            $idPengguna = $permintaan->user()?->getAuthIdentifier();

            return [
                Limit::perMinute(90)->by('cek-wajah:'.self::KunciPemakaiAbsen($permintaan, $idPengguna)),
                Limit::perMinute(600)->by('cek-wajah-ip:'.$permintaan->ip()),
            ];
        });

        // X7: Open API v1, 120 permintaan/menit per token (throttle berjalan sebelum autentikasi, jadi kunci dari hash
        // token Bearer) dan 600/menit per IP, supaya token acak yang berganti-ganti tidak lolos dari batas.
        RateLimiter::for('api-publik', static function (Request $permintaan): array {
            $bearer = $permintaan->bearerToken();

            return [
                Limit::perMinute(120)->by('token:'.hash('sha256', is_string($bearer) ? $bearer : '')),
                Limit::perMinute(600)->by('ip:'.$permintaan->ip()),
            ];
        });

        // F-08: webhook gerbang pembayaran, per penyedia per IP (gerbang mengirim dari sedikit IP; ulangan dibatasi).
        RateLimiter::for('webhook', static fn (Request $permintaan): Limit => Limit::perMinute(300)
            ->by((string) $permintaan->route('penyedia').'|'.$permintaan->ip()));

        // PGL-15: pesan masuk dukungan dari penyedia email/WhatsApp, per IP.
        RateLimiter::for('webhook-dukungan', static fn (Request $permintaan): Limit => Limit::perMinute(120)->by((string) $permintaan->ip()));

        // Audit PAY-P1-04: laporan CSP Report-Only dari peramban; batas per IP supaya tidak jadi pengisi log.
        RateLimiter::for('laporan-csp', static fn (Request $permintaan): Limit => Limit::perMinute(30)->by((string) $permintaan->ip()));

        $this->PeringatiKonfigurasiProduksiBerbahaya();

        // P-05: email, CAPTCHA, dan penyimpanan objek memakai konfigurasi aktif dari Platform Pengelola.
        $this->app->make(PenerapKonfigurasiIntegrasi::class)->Terapkan();

        // P-09: pemberitahuan tiket dukungan (penangan di antrean, setelah commit).
        Event::listen(TiketDukunganDibuat::class, BeritahuTimTiketDukunganBaru::class);
        // BR-P08.11: email pelunasan tagihan langganan lewat gerbang (templat sama dengan jalur transfer manual).
        Event::listen(TagihanLanggananDilunasiGerbang::class, KirimSurelPelunasanGerbang::class);
        Event::listen(TiketDukunganDibalasPelapor::class, BeritahuPenanggungJawabBalasanPelapor::class);

        // X7 bagian 2: webhook keluar tenant untuk penjualan selesai, void, dan retur (antrean, setelah commit).
        foreach ([PenjualanDiterima::class, PenjualanDivoid::class, ReturPenjualanDiterima::class] as $peristiwa) {
            Event::listen($peristiwa, AntrekanWebhookPenjualan::class);
            // OWN-03: kabar void, retur, dan diskon besar ke pengguna berizin.
            Event::listen($peristiwa, BeritahuTransaksiBesar::class);
        }

        Event::listen(PeristiwaIntegrasi::class, AntrekanWebhookIntegrasi::class);

        // D-209: riwayat jalannya perintah terjadwal untuk halaman Jadwal di Operasional.
        Event::listen(ScheduledTaskStarting::class, [PencatatRiwayatJadwal::class, 'SaatMulai']);
        Event::listen([ScheduledTaskFinished::class, ScheduledBackgroundTaskFinished::class], [PencatatRiwayatJadwal::class, 'SaatSelesai']);
        Event::listen(ScheduledTaskFailed::class, [PencatatRiwayatJadwal::class, 'SaatGagal']);

        // P-11 BR-P11.1: /sehat (uptime monitor eksternal) ikut memeriksa alert, agar scheduler mati tetap terdeteksi.
        Event::listen(DiagnosingHealth::class, PeriksaOperasionalSaatCekSehat::class);

        if ($this->app->runningUnitTests()) {
            $this->loadMigrationsFrom(base_path('tests/Pendukung/Migrasi'));
        }
    }

    /**
     * Audit PAY-P2-09: contoh pengembangan bernilai `APP_DEBUG=true` dan email ke log. Bila ikut tersalin ke produksi,
     * jejak galat bocor dan email tidak terkirim (nilai produksi yang benar: Panduan/PasangDiHosting.md §7b). Dicatat
     * kritis (sekali sehari, lewat cache), bukan menghentikan aplikasi: menjatuhkan situs yang sedang berjalan lebih
     * buruk daripada peringatannya.
     */
    /** Kunci pembatas absen: id akun bila berlogin, hash token tautan bila lewat tautan pribadi, selain itu "tamu". */
    private static function KunciPemakaiAbsen(Request $permintaan, mixed $idPengguna): string
    {
        if (is_scalar($idPengguna)) {
            return (string) $idPengguna;
        }

        $token = $permintaan->route('tokenAbsen');

        return is_string($token) ? 'tautan:'.hash('sha256', $token) : 'tamu';
    }

    private function PeringatiKonfigurasiProduksiBerbahaya(): void
    {
        if (! $this->app->isProduction() || $this->app->runningInConsole()) {
            return;
        }

        $masalah = array_keys(array_filter([
            'APP_DEBUG=true' => config('app.debug') === true,
            'MAIL_MAILER=log (email tidak terkirim)' => config('mail.default') === 'log',
            'SESSION_SECURE_COOKIE tidak true' => config('session.secure') !== true,
        ]));

        if ($masalah === []) {
            return;
        }

        try {
            if (Cache::add('peringatan-konfigurasi-produksi', 1, 86400)) {
                Log::critical('Konfigurasi produksi berbahaya; periksa nilai lingkungan (Panduan/PasangDiHosting.md §7b).', ['Masalah' => $masalah]);
            }
        } catch (Throwable) {
            // Cache (database) belum siap, misalnya saat migrasi pertama: peringatan ini tidak boleh menggagalkan boot.
        }
    }
}
