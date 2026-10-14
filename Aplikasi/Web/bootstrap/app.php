<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Http\Kontroler\Pengelola\GalatKontroler;
use App\Http\Perantara\ArahkanDomainAplikasi;
use App\Http\Perantara\AutentikasiPemilik;
use App\Http\Perantara\AutentikasiPerangkat;
use App\Http\Perantara\Pengelola\CatatMetrikApi;
use App\Http\Perantara\PasangHeaderKeamanan;
use App\Http\Perantara\Pengelola\SiapkanSesiPengelola;
use App\Http\Perantara\WajibLisensiSah;
use App\Http\Respons\GalatApi;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/sehat',
        // F-02b: API Aplikasi POS (device token, tanpa sesi), prefix /api/pos/v1, nama rute pos.*.
        then: function (): void {
            Route::middleware('api')->prefix('api/pos/v1')->group(base_path('routes/Pos.php'));
            // OWN-01: API Aplikasi Owner (user token, tanpa sesi), prefix /api/pemilik/v1, nama rute pemilik.*.
            Route::middleware('api')->prefix('api/pemilik/v1')->group(base_path('routes/Pemilik.php'));
            // X7: Open API v1 untuk integrasi pihak ketiga (token API tenant bercakupan), prefix /api/v1, nama rute api.v1.*.
            Route::middleware('api')->prefix('api/v1')->group(base_path('routes/ApiPublik.php'));
            // F-08: webhook gerbang pembayaran (tanpa sesi/CSRF), `/webhook/{penyedia}`, nama rute webhook.*.
            Route::middleware('api')->group(base_path('routes/Webhook.php'));
        },
    )
    ->withCommands([__DIR__.'/../app/Console/Perintah'])
    ->withMiddleware(function (Middleware $middleware): void {
        // Audit PAY-P1-05: header yang dipercaya dari proksi (daftar proksinya di config/trustedproxy.php, env
        // `PROKSI_TEPERCAYA`). Header AWS ELB dan prefix sengaja tidak dipercaya: infrastruktur kita tidak memakainya,
        // jadi tidak ada alasan membuka jalur pemalsuan tambahan.
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Harus berjalan sebelum StartSession: cookie sesi pengelola terpisah dari tenant (BR-P01.4).
        $middleware->prepend(SiapkanSesiPengelola::class);

        // Audit F-14: header keamanan (CSP dasar, HSTS, nosniff, Referrer-Policy, Permissions-Policy) di semua respons.
        $middleware->append(PasangHeaderKeamanan::class);
        // P-11 PGL-20: metrik API (jumlah, galat 5xx, latensi) per kelompok untuk dasbor operasional; dicatat saat terminate.
        $middleware->append(CatatMetrikApi::class);

        // D-35 edisi Lisensi: tanpa lisensi sah untuk domain ini, semua permintaan dijawab 503 (no-op di edisi SaaS).
        $middleware->append(WajibLisensiSah::class);

        // API POS: perangkat dikenali sebelum batas laju dihitung, agar limiter `pos-*` memakai kunci per perangkat.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AutentikasiPerangkat::class);
        // API Pemilik: pengguna dikenali dari token sebelum batas laju `pemilik-*` dihitung per pengguna.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AutentikasiPemilik::class);

        // D-20: pengalihan domain pemasaran → tenant terjadi sebelum `auth` mengalihkan tamu ke halaman masuk.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ArahkanDomainAplikasi::class);

        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->getHost() === config('pengelola.Domain') ? route('pengelola.masuk') : route('masuk'),
        );
        $middleware->redirectUsersTo(
            fn (Request $request) => $request->getHost() === config('pengelola.Domain') ? route('pengelola.beranda') : route('kelola.beranda'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Galat di subdomain pengelola ditampilkan sebagai halaman berbahasa Indonesia (PRD §17.6.6).
        $exceptions->respond(fn (SymfonyResponse $respons, Throwable $galat, Request $request) => GalatKontroler::UbahRespons($respons, $request));

        // F-02b: galat framework di API (validasi, 404, 429, ...) juga berformat {"Galat": {...}} (PRD §16.2).
        // F-17: rute JSON pesan sendiri publik memakai format yang sama.
        $exceptions->render(fn (Throwable $galat, Request $request) => $request->is('api/*') || ($request->routeIs('publik.pesan-sendiri.*') && $request->expectsJson())
            ? GalatApi::DariGalat($galat)
            : null);

        $exceptions->dontFlash(['KataSandi', 'KonfirmasiKataSandi', 'Kode']);

        // Tautan verifikasi email kedaluwarsa/rusak adalah kejadian normal (berlaku terbatas, sekali pakai), jadi
        // jangan ditampilkan sebagai "403 Invalid signature" mentah yang tidak bisa ditindaklanjuti pengguna.
        $exceptions->render(function (InvalidSignatureException $galat, Request $request) {
            if (! $request->routeIs('verifikasi-email', 'ganti-email')) {
                return null;
            }

            // Tanda tangan salah dan tanda tangan kedaluwarsa sama-sama melempar galat ini. Keduanya dipisah agar
            // pesannya benar, dan agar penyebab salah tanda tangan bisa ditelusuri dari log alih-alih ditebak.
            // Ganti email (BR-00.5) memakai tanda tangan yang sama; tautan barunya diminta dari Keamanan akun.
            $gantiEmail = $request->routeIs('ganti-email');
            $mintaBaru = $gantiEmail
                ? 'Masuk lalu minta tautan baru dari halaman Keamanan akun.'
                : 'Masuk lalu minta tautan baru lewat tombol kirim ulang.';
            $nama = $gantiEmail ? 'ganti email' : 'verifikasi';
            $tandaTanganBenar = URL::hasCorrectSignature($request, absolute: false);
            $belumKedaluwarsa = URL::signatureHasNotExpired($request);

            if ($tandaTanganBenar && ! $belumKedaluwarsa) {
                $pesan = "Tautan {$nama} sudah lewat masa berlakunya. {$mintaBaru}";
            } else {
                // Cocok sebagai tanda tangan absolut = tautan dibuat kode/cache rute lama (sebelum D-20 relatif).
                $gayaLama = URL::hasCorrectSignature($request, absolute: true);
                Log::warning('Tanda tangan tautan verifikasi/ganti email tidak cocok.', [
                    'Jalur' => $request->path(),
                    'Host' => $request->getHost(),
                    'Skema' => $request->getScheme(),
                    'CocokSebagaiAbsolut' => $gayaLama,
                ]);
                $pesan = $gayaLama
                    ? "Tautan ini dibuat versi lama aplikasi. {$mintaBaru}"
                    : "Tautan {$nama} tidak dikenali. {$mintaBaru}";
            }

            return redirect()->route($request->user('web') === null ? 'masuk' : 'kelola.beranda')
                ->withErrors(['Umum' => $pesan]);
        });

        // Pelanggaran aturan bisnis → galat validasi (Inertia) atau format galat seragam (JSON), PRD §16.
        $exceptions->render(function (PelanggaranAturanBisnis $galat, Request $request) {
            if ($request->is('api/*') || ($request->expectsJson() && ! $request->hasHeader('X-Inertia'))) {
                // F-02b: status & detail opsional (misal PIN terkunci 429), bawaan 422.
                return GalatApi::Buat($galat->kode, $galat->getMessage(), $galat->statusHttp, $galat->detail);
            }

            return back()
                ->withInput($request->except(['KataSandi', 'KonfirmasiKataSandi', 'Kode']))
                ->withErrors([$galat->bidang => $galat->getMessage()]);
        });
    })->create();
