<?php

declare(strict_types=1);

use App\Domain\Lisensi\Enum\EdisiAplikasi;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\MejaPesanSendiri;
use App\Domain\Pelanggan\Layanan\TautanBerhentiLangganan;
use App\Domain\Penjualan\Layanan\KodeInvoicePublik;
use App\Domain\Penjualan\Layanan\KodeStrukDigital;
use App\Domain\Penjualan\Layanan\PenentuKonteksKios;
use App\Domain\Situs\Layanan\AturanSlugSitus;
use App\Domain\Situs\Model\ArtikelBantuan;
use App\Domain\Situs\Model\ArtikelSitus;
use App\Http\Kontroler\Autentikasi\KataSandiKontroler;
use App\Http\Kontroler\Autentikasi\KeamananAkunKontroler;
use App\Http\Kontroler\Autentikasi\LupaKataSandiKontroler;
use App\Http\Kontroler\Autentikasi\MasukGoogleKontroler;
use App\Http\Kontroler\Autentikasi\PendaftaranKontroler;
use App\Http\Kontroler\Autentikasi\PersetujuanLegalKontroler;
use App\Http\Kontroler\Autentikasi\SesiKontroler;
use App\Http\Kontroler\Autentikasi\VerifikasiEmailKontroler;
use App\Http\Kontroler\Dukungan\SesiDukunganKontroler;
use App\Http\Kontroler\Kelola\BantuanKontroler;
use App\Http\Kontroler\Kelola\BerandaKelolaKontroler;
use App\Http\Kontroler\Kelola\LanggananKontroler;
use App\Http\Kontroler\Kelola\NotifikasiKontroler;
use App\Http\Kontroler\Kelola\ObrolanKontroler;
use App\Http\Kontroler\Kelola\PusatBantuanKontroler as BantuanKontekstualKontroler;
use App\Http\Kontroler\Kelola\TerimaUndanganKontroler;
use App\Http\Kontroler\Kelola\TindakanKontroler;
use App\Http\Kontroler\Kelola\TurProdukKontroler;
use App\Http\Kontroler\Publik\AbsenTautanKontroler;
use App\Http\Kontroler\Publik\AkunTokoOnlineKontroler;
use App\Http\Kontroler\Publik\BerhentiLanggananKontroler;
use App\Http\Kontroler\Publik\DokumenLegalPublikKontroler;
use App\Http\Kontroler\Publik\InvoicePublikKontroler;
use App\Http\Kontroler\Publik\KiosKontroler;
use App\Http\Kontroler\Publik\KompatibilitasPerangkatKontroler as KompatibilitasPerangkatPublikKontroler;
use App\Http\Kontroler\Publik\PenawaranPublikKontroler;
use App\Http\Kontroler\Publik\PengembangKontroler;
use App\Http\Kontroler\Publik\PersetujuanServisKontroler;
use App\Http\Kontroler\Publik\PesanSendiriKontroler;
use App\Http\Kontroler\Publik\PortalKurirKontroler;
use App\Http\Kontroler\Publik\ProspekSitusKontroler;
use App\Http\Kontroler\Publik\PusatBantuanKontroler;
use App\Http\Kontroler\Publik\ReservasiPublikKontroler;
use App\Http\Kontroler\Publik\SitusKontroler;
use App\Http\Kontroler\Publik\StatusPlatformKontroler;
use App\Http\Kontroler\Publik\StrukDigitalKontroler;
use App\Http\Kontroler\Publik\TokoOnlineKontroler;
use App\Http\Perantara\ArahkanDomainAplikasi;
use App\Http\Perantara\BagikanDataInertia;
use App\Http\Perantara\BagikanDataSitus;
use App\Http\Perantara\BatasiTenantDitangguhkan;
use App\Http\Perantara\IdentifikasiTenantSesi;
use App\Http\Perantara\PasangAksesDukungan;
use App\Http\Perantara\PasangBingkaiPratinjau;
use App\Http\Perantara\Pengelola\BagikanDataInertiaPengelola;
use App\Http\Perantara\Pengelola\BatasiIpPengelola;
use App\Http\Perantara\Pengelola\CatatAuditPengelola;
use App\Http\Perantara\Pengelola\TolakDomainPengelola;
use App\Http\Perantara\SiapkanAuditTenant;
use App\Http\Perantara\WajibDuaFaktorTenant;
use App\Http\Perantara\WajibFiturPaket;
use App\Http\Perantara\WajibGantiKataSandiTenant;
use App\Http\Perantara\WajibIzinTenant;
use App\Http\Perantara\WajibPanduanAwal;
use App\Http\Perantara\WajibPersetujuanLegal;
use App\Http\Rute\ValidatorHalamanSitus;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

// D-35 edisi Lisensi (dashboard dipasang pembeli di server & domainnya sendiri): tanpa Platform Pengelola, situs
// pemasaran, pendaftaran publik, langganan, maupun tiket bantuan ke Payoung. Hanya dashboard tenant & halaman publik toko.
$saas = ! EdisiAplikasi::CekLisensi();

// Platform Pengelola di subdomain sendiri (PRD §13.8). Didaftarkan lebih dulu agar menang atas rute tenant.
if ($saas) {
    Route::domain(config('pengelola.Domain'))
        ->middleware([BatasiIpPengelola::class, BagikanDataInertiaPengelola::class, CatatAuditPengelola::class])
        ->group(base_path('routes/Pengelola.php'));
}

$izin = static fn (IzinTenant $izin): string => WajibIzinTenant::class.':'.$izin->value;

if ($saas) {
    // D-21 Situs pemasaran: gambar pustaka dilayani di semua host (juga tampil di editor konsol); peta situs XML untuk mesin
    // pencari (didaftarkan di Google Search Console). robots.txt berkas statis di public/.
    Route::get('/gambar-situs/{gambarSitus}', [SitusKontroler::class, 'Gambar'])
        ->where('gambarSitus', '[0-9A-HJKMNP-TV-Z]{26}')
        ->middleware('throttle:300,1')
        ->name('situs.gambar');
    Route::get('/peta-situs', [SitusKontroler::class, 'PetaSitus'])->middleware(ArahkanDomainAplikasi::class)->name('situs.peta');

    // D-21 beranda situs pemasaran (halaman berblok dari konsol, bundle `Situs.tsx`).
    Route::middleware([TolakDomainPengelola::class, ArahkanDomainAplikasi::class, BagikanDataSitus::class])->group(function (): void {
        Route::get('/', [SitusKontroler::class, 'Beranda'])->name('beranda');
        Route::get('/pratinjau-situs/{halamanSitus}', [SitusKontroler::class, 'Pratinjau'])
            ->where('halamanSitus', '[0-9A-HJKMNP-TV-Z]{26}')
            // Tanda tangan relatif: konsol menandatangani jalur lalu memasang domain pemasaran (D-20).
            ->middleware(['signed:relative', PasangBingkaiPratinjau::class])
            ->name('situs.pratinjau');
        // Bagian B: formulir kontak/minta demo (perangkap bot + batas per nomor di Aksi).
        Route::post('/prospek', [ProspekSitusKontroler::class, 'Kirim'])->middleware('throttle:5,1')->name('situs.prospek.kirim');
        // Bagian B2: blog (artikel terbit dari konsol).
        Route::get('/blog', [SitusKontroler::class, 'Blog'])->name('situs.blog.daftar');
        Route::get('/blog/{slugArtikel}', [SitusKontroler::class, 'Artikel'])->where('slugArtikel', ArtikelSitus::POLA_SLUG)->name('situs.blog.artikel');
        // PLT-12: Pusat Bantuan publik (artikel terbit dari konsol).
        Route::get('/pusat-bantuan', [PusatBantuanKontroler::class, 'Daftar'])->name('situs.bantuan.daftar');
        Route::get('/pusat-bantuan/{slugBantuan}', [PusatBantuanKontroler::class, 'Artikel'])->where('slugBantuan', ArtikelBantuan::POLA_SLUG)->name('situs.bantuan.artikel');
        // X7 bagian 3: portal dokumentasi pengembang (Open API v1 + webhook; spesifikasi di public/pengembang/openapi-v1.json).
        Route::get('/pengembang', [PengembangKontroler::class, 'Tampilkan'])->name('situs.pengembang');
        // P-06 dokumen legal publik. D-28: ikut shell & bundle situs, jadi ada kepala, kaki, dan jalan kembali.
        Route::get('/legal/{jenis}', [DokumenLegalPublikKontroler::class, 'Tampilkan'])->name('legal.tampil');
        // P-11 PGL-21 halaman status layanan publik (insiden yang diumumkan) + JSON untuk hosting statis/pemantau.
        Route::get('/status', [StatusPlatformKontroler::class, 'Tampilkan'])->middleware('throttle:60,1')->name('publik.status');
        Route::get('/status/data', [StatusPlatformKontroler::class, 'Json'])->middleware('throttle:120,1')->name('publik.status.data');
        // v1.98 Hardware Compatibility List publik (PRD §17.2.5a). D-92: ikut shell & bundle situs (kepala, kaki, pencarian).
        Route::get('/kompatibilitas-perangkat', [KompatibilitasPerangkatPublikKontroler::class, 'Tampilkan'])
            ->middleware('throttle:60,1')
            ->name('publik.kompatibilitas-perangkat');
    });

    // D-21 halaman situs pemasaran (`/fitur`, `/solusi/kafe-resto`, …). Didaftarkan sebelum rute `/{slugTenant}` (toko
    // online F-17) karena pola regex keduanya sama; `ValidatorHalamanSitus` membuat rute ini hanya cocok untuk slug yang
    // memang halaman situs terbit, sehingga slug tenant tetap jatuh ke toko online dan jalur sistem tidak tertutup.
    Route::middleware([TolakDomainPengelola::class, ArahkanDomainAplikasi::class, BagikanDataSitus::class])
        ->get('/{slugHalaman}', [SitusKontroler::class, 'Halaman'])
        ->where('slugHalaman', AturanSlugSitus::POLA)
        ->name(ValidatorHalamanSitus::NAMA_RUTE);
} else {
    // D-35: alamat utama server pembeli langsung ke halaman masuk dashboard.
    Route::get('/', fn () => redirect()->route('masuk'))->name('beranda');
}

// Rute back-office (/kelola/...) dan web publik ditambahkan per flow (PRD §13.6, D-06). D-20: domain pemasaran hanya
// melayani situs pemasaran, legal, dan kompatibilitas perangkat; sisanya dialihkan ke domain tenant.
Route::middleware([TolakDomainPengelola::class, ArahkanDomainAplikasi::class, BagikanDataInertia::class])->group(function () use ($izin, $saas): void {
    // POS-11 struk digital publik (kode = tenant basis-36 . Uuid penjualan).
    Route::get('/s/{kodeStruk}', [StrukDigitalKontroler::class, 'Tampilkan'])
        ->where('kodeStruk', KodeStrukDigital::POLA)
        ->middleware('throttle:60,1')
        ->name('publik.struk-digital');
    // F-21 invoice publik (kode = tenant basis-36 . rahasia 32 karakter).
    Route::get('/i/{kodeInvoice}', [InvoicePublikKontroler::class, 'Tampilkan'])
        ->where('kodeInvoice', KodeInvoicePublik::POLA)
        ->middleware('throttle:60,1')
        ->name('publik.invoice');
    // F-21 bagian 5: kuitansi satu pembayaran invoice (kunci = kode rahasia invoice).
    Route::get('/i/{kodeInvoice}/kuitansi/{pembayaran}', [InvoicePublikKontroler::class, 'Kuitansi'])
        ->where('kodeInvoice', KodeInvoicePublik::POLA)
        ->where('pembayaran', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
        ->middleware('throttle:60,1')
        ->name('publik.invoice.kuitansi');
    // F-21 bagian 4: pelanggan menyatakan sudah membayar (klaim, bukan pembayaran).
    Route::post('/i/{kodeInvoice}/konfirmasi-bayar', [InvoicePublikKontroler::class, 'KonfirmasiBayar'])
        ->where('kodeInvoice', KodeInvoicePublik::POLA)
        ->middleware('throttle:5,1')
        ->name('publik.invoice.konfirmasi-bayar');
    // F-21 bagian 3 penawaran publik (kode seperti invoice); keputusan pelanggan dibatasi lebih ketat.
    Route::get('/p/{kodePenawaran}', [PenawaranPublikKontroler::class, 'Tampilkan'])
        ->where('kodePenawaran', KodeInvoicePublik::POLA)
        ->middleware('throttle:60,1')
        ->name('publik.penawaran');
    Route::post('/p/{kodePenawaran}/putuskan', [PenawaranPublikKontroler::class, 'Putuskan'])
        ->where('kodePenawaran', KodeInvoicePublik::POLA)
        ->middleware('throttle:10,1')
        ->name('publik.penawaran.putuskan');
    // F7 (CRM-08): penilaian pelanggan atas transaksi dari struk digital.
    Route::post('/s/{kodeStruk}/umpan-balik', [StrukDigitalKontroler::class, 'KirimUmpanBalik'])
        ->where('kodeStruk', KodeStrukDigital::POLA)
        ->middleware('throttle:10,1')
        ->name('publik.struk-digital.umpan-balik');
    // CRM-07: berhenti menerima pesan promosi dari tautan bertanda tangan di pesan kampanye (UU PDP).
    Route::get('/berhenti-langganan/{kode}', [BerhentiLanggananKontroler::class, 'Tampilkan'])
        ->where('kode', TautanBerhentiLangganan::POLA)
        ->middleware('throttle:30,1')
        ->name(TautanBerhentiLangganan::NAMA_RUTE);
    Route::post('/berhenti-langganan/{kode}', [BerhentiLanggananKontroler::class, 'Kirim'])
        ->where('kode', TautanBerhentiLangganan::POLA)
        ->middleware('throttle:30,1')
        ->name('publik.berhenti-langganan.kirim');

    // D-57 Masuk dengan Google. Di luar grup `guest`: tujuan "tautkan" dipakai pengguna yang sudah masuk (Keamanan akun).
    Route::get('/masuk/google', [MasukGoogleKontroler::class, 'Mulai'])->middleware('throttle:20,1')->name('masuk.google');
    Route::get('/masuk/google/panggilan-balik', [MasukGoogleKontroler::class, 'PanggilanBalik'])->middleware('throttle:30,1')->name('masuk.google.panggilan-balik');

    // F-00 Registrasi & autentikasi tenant.
    // PGL-16: sisi tenant akses dukungan. Masuk memakai token sekali pakai dari konsol (60 detik); sisanya halaman info.
    Route::get('/dukungan/masuk/{idTenant}/{token}', [SesiDukunganKontroler::class, 'Masuk'])
        ->where(['idTenant' => '[0-9]{1,12}', 'token' => '[A-Za-z0-9]{48}'])
        ->middleware('throttle:20,1')
        ->name('dukungan.masuk');
    Route::get('/dukungan/berakhir', [SesiDukunganKontroler::class, 'Berakhir'])->middleware('throttle:60,1')->name('dukungan.berakhir');

    Route::middleware('guest:web')->group(function () use ($saas): void {
        if ($saas) {
            Route::get('/daftar', [PendaftaranKontroler::class, 'Tampilkan'])->name('daftar');
            Route::post('/daftar', [PendaftaranKontroler::class, 'Daftar'])->middleware('throttle:pendaftaran')->name('daftar.kirim');
            // D-57: lengkapi data usaha setelah Google membuktikan email (tanpa kata sandi dan CAPTCHA).
            Route::get('/daftar/google', [MasukGoogleKontroler::class, 'TampilkanLengkapi'])->name('daftar.google');
            Route::post('/daftar/google', [MasukGoogleKontroler::class, 'Lengkapi'])->middleware('throttle:pendaftaran')->name('daftar.google.kirim');
        }

        Route::get('/masuk', [SesiKontroler::class, 'TampilkanMasuk'])->name('masuk');
        Route::post('/masuk', [SesiKontroler::class, 'Masuk'])->name('masuk.kirim');

        // Auth tenant: langkah kedua masuk untuk akun ber-2FA, lupa & atur ulang kata sandi (BR-00.8, BR-00.9).
        Route::get('/masuk/dua-faktor', [SesiKontroler::class, 'TampilkanDuaFaktor'])->name('masuk.dua-faktor');
        Route::post('/masuk/dua-faktor', [SesiKontroler::class, 'VerifikasiDuaFaktor'])->name('masuk.dua-faktor.kirim');
        Route::get('/lupa-kata-sandi', [LupaKataSandiKontroler::class, 'TampilkanPermintaan'])->name('lupa-kata-sandi');
        Route::post('/lupa-kata-sandi', [LupaKataSandiKontroler::class, 'KirimTautan'])->name('lupa-kata-sandi.kirim');
        Route::get('/atur-ulang-kata-sandi/{token}', [LupaKataSandiKontroler::class, 'TampilkanAturUlang'])->name('atur-ulang-kata-sandi');
        Route::post('/atur-ulang-kata-sandi', [LupaKataSandiKontroler::class, 'AturUlang'])->middleware(SiapkanAuditTenant::class)->name('atur-ulang-kata-sandi.kirim');
    });

    // F-02 Undangan anggota tenant: bisa dibuka tanpa masuk (akun baru) atau sudah masuk (akun ditautkan).
    Route::get('/undangan/{token}', [TerimaUndanganKontroler::class, 'Tampilkan'])->name('undangan.tampil');
    Route::post('/undangan/{token}', [TerimaUndanganKontroler::class, 'Terima'])->middleware(['throttle:10,1', SiapkanAuditTenant::class])->name('undangan.terima');

    Route::get('/verifikasi-email/{pengguna}/{hash}', [VerifikasiEmailKontroler::class, 'Verifikasi'])
        // Tanda tangan relatif: jalurnya ditandatangani lalu domain tenant dipasang (D-20), sehingga tautan tetap
        // sah walau host penandatangan berbeda atau skema terbaca http di balik proxy.
        ->middleware('signed:relative')
        ->name('verifikasi-email');

    // BR-00.5: konfirmasi ganti email (alamat baru disandikan di jalur, ditandatangani).
    Route::get('/ganti-email/{pengguna}/{hash}/{email}', [VerifikasiEmailKontroler::class, 'GantiEmail'])
        ->middleware('signed:relative')
        ->name('ganti-email');

    // Auth tenant: AuthenticateSession mengakhiri sesi lain setelah kata sandi diatur ulang (BR-00.9).
    // PGL-16: `PasangAksesDukungan` memeriksa sesi akses dukungan pada setiap permintaan (pengguna biasa lewat tanpa diubah).
    Route::middleware(['auth:web', AuthenticateSession::class, PasangAksesDukungan::class])->group(function () use ($izin, $saas): void {
        Route::post('/keluar', [SesiKontroler::class, 'Keluar'])->name('keluar');
        Route::post('/dukungan/akhiri', [SesiDukunganKontroler::class, 'Akhiri'])->name('dukungan.akhiri');
        // D-22: ganti kata sandi (wajib bila kata sandi awal dibuat admin tenant) sebelum memilih usaha.
        Route::get('/ganti-kata-sandi', [KataSandiKontroler::class, 'Tampilkan'])->name('kata-sandi.ganti');
        Route::post('/ganti-kata-sandi', [KataSandiKontroler::class, 'Simpan'])->middleware('throttle:10,1')->name('kata-sandi.simpan');
        Route::get('/pilih-tenant', [SesiKontroler::class, 'TampilkanPilihTenant'])->middleware(WajibGantiKataSandiTenant::class)->name('pilih-tenant');
        Route::post('/pilih-tenant', [SesiKontroler::class, 'PilihTenant'])->middleware(WajibGantiKataSandiTenant::class)->name('pilih-tenant.kirim');
        Route::post('/verifikasi-email/kirim-ulang', [VerifikasiEmailKontroler::class, 'KirimUlang'])->name('verifikasi-email.kirim-ulang');

        // Auth tenant: persetujuan ulang dokumen legal (BR-P06.5) lalu 2FA wajib (BR-00.8), setelah tenant aktif diketahui.
        // F-00: saat langganan Ditangguhkan, perubahan data ditolak kecuali langganan, keamanan, bantuan, dan legal.
        Route::middleware([WajibGantiKataSandiTenant::class, IdentifikasiTenantSesi::class, WajibPersetujuanLegal::class, WajibDuaFaktorTenant::class, BatasiTenantDitangguhkan::class, WajibPanduanAwal::class, WajibFiturPaket::class])->prefix('kelola')->group(function () use ($izin, $saas): void {
            Route::get('/', [BerandaKelolaKontroler::class, 'Beranda'])->name('kelola.beranda');
            // D-23 C: Kotak Tindakan (butir disaring izin & outlet; menandai dicek butuh `tindakan.tinjau`).
            Route::get('/tindakan', [TindakanKontroler::class, 'Daftar'])->name('kelola.tindakan.daftar');
            Route::post('/tindakan/tinjau', [TindakanKontroler::class, 'Tandai'])->middleware([SiapkanAuditTenant::class, $izin(IzinTenant::TindakanTinjau)])->name('kelola.tindakan.tinjau');
            // F31 (PLT-08): pusat notifikasi web (lonceng di kepala), milik pengguna sendiri.
            Route::get('/notifikasi', [NotifikasiKontroler::class, 'Daftar'])->name('kelola.notifikasi.daftar');
            Route::post('/notifikasi/dibaca', [NotifikasiKontroler::class, 'TandaiDibaca'])->middleware('throttle:60,1')->name('kelola.notifikasi.dibaca');
            Route::put('/notifikasi/pengaturan', [NotifikasiKontroler::class, 'UbahPengaturan'])->middleware('throttle:30,1')->name('kelola.notifikasi.pengaturan');
            // PLT-12: tur produk interaktif, hasilnya milik pengguna sendiri (dilihat/dilewati, bisa diulang).
            Route::post('/tur/{kodeTur}', [TurProdukKontroler::class, 'Catat'])->where('kodeTur', '[a-z0-9.]+')->middleware('throttle:30,1')->name('kelola.tur.catat');
            Route::delete('/tur/{kodeTur}', [TurProdukKontroler::class, 'Ulangi'])->where('kodeTur', '[a-z0-9.]+')->middleware('throttle:30,1')->name('kelola.tur.ulangi');
            // D-23 D: berlangganan ringkasan pagi Kotak Tindakan lewat email (pilihan pribadi tiap pengguna).
            Route::put('/tindakan/ringkasan-whatsapp', [TindakanKontroler::class, 'UbahRingkasanWhatsapp'])->middleware(SiapkanAuditTenant::class)->name('kelola.tindakan.ringkasan-whatsapp');

            Route::middleware(SiapkanAuditTenant::class)->group(function () use ($izin, $saas): void {
                // D-35: edisi Lisensi dibeli sekali, tanpa langganan & tagihan.
                if ($saas) {
                    // Langganan & tagihan (pembayaran online via gerbang billing). Izin `langganan.kelola` khusus Pemilik (§19.1).
                    Route::middleware($izin(IzinTenant::LanggananKelola))->group(function (): void {
                        Route::get('/langganan', [LanggananKontroler::class, 'Tampilkan'])->name('kelola.langganan.tampil');
                        Route::post('/langganan/tagihan', [LanggananKontroler::class, 'BuatTagihan'])->name('kelola.langganan.tagihan.buat');
                        // D-49: beli add-on mandiri (tagihan prorata), berhenti, dan lanjutkan.
                        Route::post('/langganan/addon/beli', [LanggananKontroler::class, 'BeliAddon'])->middleware('throttle:10,1')->name('kelola.langganan.addon.beli');
                        Route::post('/langganan/addon/{addon}/berhenti', [LanggananKontroler::class, 'HentikanAddon'])->middleware('throttle:10,1')->name('kelola.langganan.addon.berhenti');
                        Route::post('/langganan/addon/{addon}/lanjut', [LanggananKontroler::class, 'LanjutkanAddon'])->middleware('throttle:10,1')->name('kelola.langganan.addon.lanjut');
                        // D-23: minta add-on lewat tiket dukungan (jalur cadangan).
                        Route::post('/langganan/addon', [LanggananKontroler::class, 'MintaAddon'])->middleware('throttle:10,1')->name('kelola.langganan.addon.minta');
                        Route::get('/langganan/tagihan/{tagihan}', [LanggananKontroler::class, 'TampilkanTagihan'])->name('kelola.langganan.tagihan.tampil');
                        // D-159: status pembayaran untuk halaman bayar Payoung (polling).
                        Route::get('/langganan/tagihan/{tagihan}/status-bayar', [LanggananKontroler::class, 'StatusBayar'])->middleware('throttle:60,1')->name('kelola.langganan.tagihan.status-bayar');
                        // BR-P08.11: buat transaksi DOKU Checkout atau pembayaran SNAP (D-159) untuk tagihan ini. Dibatasi laju karena
                        // setiap klik membuat satu transaksi di DOKU.
                        Route::post('/langganan/tagihan/{tagihan}/bayar-online', [LanggananKontroler::class, 'BayarOnline'])->middleware('throttle:10,1')->name('kelola.langganan.tagihan.bayar-online');
                        // D-112: bukti transfer hanya untuk tagihan manual (paket harga negosiasi).
                        Route::post('/langganan/tagihan/{tagihan}/bukti', [LanggananKontroler::class, 'UnggahBukti'])->middleware('throttle:10,1')->name('kelola.langganan.tagihan.bukti');
                        Route::post('/langganan/tagihan/{tagihan}/batalkan', [LanggananKontroler::class, 'Batalkan'])->name('kelola.langganan.tagihan.batalkan');
                        // BR-P04.4: turun paket mandiri (berlaku perpanjangan berikutnya / akhir periode untuk Gratis).
                        Route::post('/langganan/turun-paket', [LanggananKontroler::class, 'AjukanTurunPaket'])->middleware('throttle:10,1')->name('kelola.langganan.turun-paket.ajukan');
                        Route::post('/langganan/pindah-gratis', [LanggananKontroler::class, 'PindahKeGratis'])->middleware('throttle:5,1')->name('kelola.langganan.pindah-gratis');
                        Route::post('/langganan/turun-paket/{turunPaket}/batalkan', [LanggananKontroler::class, 'BatalkanTurunPaket'])->name('kelola.langganan.turun-paket.batalkan');
                    });
                }

                // Auth tenant: keamanan akun (2FA) dan persetujuan ulang dokumen legal (BR-00.8, BR-P06.5).
                Route::get('/keamanan', [KeamananAkunKontroler::class, 'Tampilkan'])->name('kelola.keamanan');
                Route::post('/keamanan/dua-faktor', [KeamananAkunKontroler::class, 'AktifkanDuaFaktor'])->name('kelola.keamanan.dua-faktor.aktifkan');
                Route::delete('/keamanan/dua-faktor', [KeamananAkunKontroler::class, 'NonaktifkanDuaFaktor'])->name('kelola.keamanan.dua-faktor.nonaktifkan');
                // D-57: lepas tautan akun Google (menautkan lewat /masuk/google?tujuan=tautkan).
                Route::post('/keamanan/email', [KeamananAkunKontroler::class, 'GantiEmail'])->middleware('throttle:10,1')->name('kelola.keamanan.email.ganti');
                Route::delete('/keamanan/google', [KeamananAkunKontroler::class, 'LepasGoogle'])->middleware('throttle:10,1')->name('kelola.keamanan.google.lepas');
                Route::get('/persetujuan-legal', [PersetujuanLegalKontroler::class, 'Tampilkan'])->name('kelola.persetujuan-legal');
                Route::post('/persetujuan-legal', [PersetujuanLegalKontroler::class, 'Setujui'])->name('kelola.persetujuan-legal.setujui');

                // D-35: tiket bantuan diterima Platform Pengelola Payoung, yang tidak ada di edisi Lisensi.
                if ($saas) {
                    // PLT-12: bantuan kontekstual (artikel Pusat Bantuan untuk halaman yang sedang dibuka); isinya publik.
                    Route::get('/pusat-bantuan', [BantuanKontekstualKontroler::class, 'Ambil'])->middleware('throttle:120,1')->name('kelola.pusat-bantuan');
                    // P-09 Bantuan (tiket dukungan). Parameter tiket = Uuid, dicari lewat MilikTenant di kueri (bukan route
                    // model binding, yang berjalan sebelum tenant aktif ditetapkan).
                    Route::middleware($izin(IzinTenant::BantuanTiketLihat))->group(function (): void {
                        Route::get('/bantuan', [BantuanKontroler::class, 'Daftar'])->name('kelola.bantuan.daftar');
                        Route::get('/bantuan/{tiketDukungan}/lampiran/{lampiran}', [BantuanKontroler::class, 'UnduhLampiran'])->name('kelola.bantuan.lampiran');
                    });
                    Route::middleware($izin(IzinTenant::BantuanTiketKelola))->group(function (): void {
                        Route::get('/bantuan/buat', [BantuanKontroler::class, 'Buat'])->name('kelola.bantuan.buat');
                        Route::post('/bantuan', [BantuanKontroler::class, 'Simpan'])->middleware('throttle:10,1')->name('kelola.bantuan.simpan');
                        Route::post('/bantuan/{tiketDukungan}/balasan', [BantuanKontroler::class, 'Balas'])->middleware('throttle:30,1')->name('kelola.bantuan.balas');
                        Route::post('/bantuan/{tiketDukungan}/selesaikan', [BantuanKontroler::class, 'Selesaikan'])->name('kelola.bantuan.selesaikan');
                    });
                    // PLT-12 (D-163): obrolan langsung di lembar Bantuan (JSON, polling). Awalan `/obrolan` sengaja terpisah
                    // dari `/bantuan/{tiketDukungan}` supaya tidak tertangkap parameter tiket.
                    Route::get('/obrolan', [ObrolanKontroler::class, 'Keadaan'])->middleware([$izin(IzinTenant::BantuanTiketLihat), 'throttle:60,1'])->name('kelola.obrolan.keadaan');
                    Route::middleware($izin(IzinTenant::BantuanTiketKelola))->group(function (): void {
                        Route::post('/obrolan', [ObrolanKontroler::class, 'Kirim'])->middleware('throttle:30,1')->name('kelola.obrolan.kirim');
                        Route::post('/obrolan/akhiri', [ObrolanKontroler::class, 'Akhiri'])->middleware('throttle:10,1')->name('kelola.obrolan.akhiri');
                    });
                    Route::get('/bantuan/{tiketDukungan}', [BantuanKontroler::class, 'Tampilkan'])->middleware($izin(IzinTenant::BantuanTiketLihat))->name('kelola.bantuan.tampil');
                }
            });

            // F-02 Setup organisasi: outlet, lokasi stok, merek, pengguna & peran, log audit.
            Route::group([], base_path('routes/Organisasi.php'));
            // F-02b Perangkat POS & PIN kasir.
            Route::group([], base_path('routes/Perangkat.php'));
            // F-01 Panduan awal (onboarding wizard & template sektor).
            Route::group([], base_path('routes/PanduanAwal.php'));
            // F-01 Pengaturan: indeks semua pengaturan + profil usaha di luar wizard.
            Route::group([], base_path('routes/Pengaturan.php'));
            // F-03 Master produk, harga & pajak (satu file rute per tim).
            Route::group([], base_path('routes/Katalog.php'));
            Route::group([], base_path('routes/KatalogHarga.php'));
            Route::group([], base_path('routes/KatalogKomposisi.php'));
            Route::group([], base_path('routes/KatalogImpor.php'));
            // F-05a Stok awal & buku stok: impor stok awal didaftarkan sebelum rute stok awal, lalu jurnal.
            Route::group([], base_path('routes/PersediaanImpor.php'));
            Route::group([], base_path('routes/Persediaan.php'));
            // F-05b Transfer stok, stok opname, penyesuaian stok.
            Route::group([], base_path('routes/PersediaanDokumen.php'));
            // F-04 fase 1 Pembelian (pemasok, PO, penerimaan, faktur, hutang, retur, belanja stok).
            Route::group([], base_path('routes/Pembelian.php'));
            Route::group([], base_path('routes/Akuntansi.php'));
            Route::group([], base_path('routes/Kasir.php'));
            // F-07b Penjualan dari POS (daftar & detail back-office).
            Route::group([], base_path('routes/Penjualan.php'));
            // F-14a Laporan inti (penjualan, pajak, stok).
            Route::group([], base_path('routes/Laporan.php'));
            // D-124 Pusat Unduhan: ekspor laporan besar yang disiapkan di latar belakang.
            Route::group([], base_path('routes/Unduhan.php'));
            // F-16a Pelanggan (CRM-01).
            Route::group([], base_path('routes/Pelanggan.php'));
            // F-16c Promo (CRM-05).
            Route::group([], base_path('routes/Promo.php'));
            // F-12 Piutang pelanggan & pelunasan.
            Route::group([], base_path('routes/Piutang.php'));
            // Grosir (F-12, §9.7, D-32): pesanan grosir, surat jalan, faktur penjualan.
            Route::group([], base_path('routes/Grosir.php'));
            // F-21 Invoice & penagihan (D-166).
            Route::group([], base_path('routes/Invoice.php'));
            // F-18 Karyawan, jadwal kerja, absensi.
            Route::group([], base_path('routes/Karyawan.php'));
            // F-07 mode service: reservasi layanan.
            Route::group([], base_path('routes/Reservasi.php'));
            // Laundry (§9.9): tiket & status proses cucian.
            Route::group([], base_path('routes/Laundry.php'));
            // Bengkel (§9.10): perintah kerja, kendaraan pelanggan, persetujuan estimasi, servis berkala.
            Route::group([], base_path('routes/Bengkel.php'));
            // F-17/F-10c: toko online, pesanan, zona ongkir, kurir, dan pengiriman.
            Route::group([], base_path('routes/TokoOnline.php'));
        });
    });

    // F-07 mode service (SLS-07): reservasi online tanpa login `/{slugTenant}/reservasi` (slug situs pemasaran bagian
    // kedua `reservasi` ditolak `AturanSlugSitus`). Batas laju per IP; kode akses 12 karakter untuk lihat/batal.
    Route::prefix('/{slugTenant}/reservasi')
        ->where(['slugTenant' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
        ->group(function (): void {
            Route::get('/', [ReservasiPublikKontroler::class, 'Tampilkan'])->middleware('throttle:60,1')->name('publik.reservasi');
            Route::get('/slot', [ReservasiPublikKontroler::class, 'Slot'])->middleware('throttle:120,1')->name('publik.reservasi.slot');
            Route::post('/', [ReservasiPublikKontroler::class, 'Simpan'])->middleware('throttle:10,1')->name('publik.reservasi.simpan');
            Route::get('/{kodeAkses}', [ReservasiPublikKontroler::class, 'Status'])->where('kodeAkses', '[A-Za-z0-9]{12}')->middleware('throttle:60,1')->name('publik.reservasi.status');
            Route::post('/{kodeAkses}/batal', [ReservasiPublikKontroler::class, 'Batal'])->where('kodeAkses', '[A-Za-z0-9]{12}')->middleware('throttle:10,1')->name('publik.reservasi.batal');
        });

    // F-17 Self-Order QR Meja (X12), tanpa login. Didaftarkan paling akhir dengan pola slug & token ketat (token 32
    // karakter) agar tidak menaungi rute sistem; slug yang bentrok dengan rute sistem memang tidak pernah dibuat
    // (`tenant.SlugTerlarang`). Rute JSON tanpa CSRF (tidak memakai sesi/kredensial), dibatasi per meja & IP.
    Route::prefix('/{slugTenant}/meja/{tokenMeja}')
        ->where(['slugTenant' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'tokenMeja' => MejaPesanSendiri::POLA_TOKEN])
        ->group(function (): void {
            Route::get('/', [PesanSendiriKontroler::class, 'Tampilkan'])->middleware('throttle:pesan-sendiri-60')->name('publik.pesan-sendiri');
            Route::get('/gambar/{produk}', [PesanSendiriKontroler::class, 'Gambar'])
                ->where('produk', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
                ->middleware('throttle:pesan-sendiri-300')
                ->name('publik.pesan-sendiri.gambar');
            Route::get('/pesanan/{uuid}', [PesanSendiriKontroler::class, 'Status'])
                ->where('uuid', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
                ->middleware('throttle:pesan-sendiri-60')
                ->name('publik.pesan-sendiri.status');
            Route::post('/hitung', [PesanSendiriKontroler::class, 'Hitung'])
                ->withoutMiddleware(ValidateCsrfToken::class)
                ->middleware('throttle:pesan-sendiri-60')
                ->name('publik.pesan-sendiri.hitung');
            Route::post('/pesan', [PesanSendiriKontroler::class, 'Pesan'])
                ->withoutMiddleware(ValidateCsrfToken::class)
                ->middleware('throttle:pesan-sendiri-20')
                ->name('publik.pesan-sendiri.pesan');
            // BR-17.2: menu disegarkan berkala (produk "86" langsung hilang) + SLS-04 bayar QRIS dari HP tamu.
            Route::get('/menu', [PesanSendiriKontroler::class, 'Menu'])->middleware('throttle:pesan-sendiri-60')->name('publik.pesan-sendiri.menu');
            Route::post('/pesan-qris', [PesanSendiriKontroler::class, 'PesanQris'])
                ->withoutMiddleware(ValidateCsrfToken::class)
                ->middleware('throttle:pesan-sendiri-20')
                ->name('publik.pesan-sendiri.pesan-qris');
            Route::post('/pesan-qris/{kodeAkses}/bayar', [PesanSendiriKontroler::class, 'BayarQris'])
                ->where('kodeAkses', '[A-Za-z0-9]{16}')
                ->withoutMiddleware(ValidateCsrfToken::class)
                ->middleware('throttle:pesan-sendiri-20')
                ->name('publik.pesan-sendiri.bayar-qris');
            Route::get('/pesan-qris/{kodeAkses}/status', [PesanSendiriKontroler::class, 'StatusBayarQris'])
                ->where('kodeAkses', '[A-Za-z0-9]{16}')
                ->middleware('throttle:pesan-sendiri-60')
                ->name('publik.pesan-sendiri.status-qris');
        });

    // F-17 bagian 4: kios pesan sendiri di layar sentuh outlet (tanpa login, tanpa data pribadi) + layar antrian.
    // Tautan rahasia per outlet (`Outlet.TokenKios`); `kios` dicadangkan di `AturanSlugSitus`.
    Route::prefix('/{slugTenant}/kios/{tokenKios}')
        ->where(['slugTenant' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'tokenKios' => PenentuKonteksKios::POLA_TOKEN])
        ->group(function (): void {
            Route::get('/', [KiosKontroler::class, 'Tampilkan'])->middleware('throttle:kios-120')->name('publik.kios');
            Route::get('/menu', [KiosKontroler::class, 'Menu'])->middleware('throttle:kios-120')->name('publik.kios.menu');
            Route::get('/gambar/{produk}', [KiosKontroler::class, 'Gambar'])
                ->where('produk', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('throttle:kios-600')->name('publik.kios.gambar');
            Route::get('/antrian', [KiosKontroler::class, 'Antrian'])->middleware('throttle:kios-120')->name('publik.kios.antrian');
            Route::get('/antrian/data', [KiosKontroler::class, 'DataAntrian'])->middleware('throttle:kios-120')->name('publik.kios.antrian.data');
            Route::post('/hitung', [KiosKontroler::class, 'Hitung'])->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:kios-120')->name('publik.kios.hitung');
            Route::post('/pesan', [KiosKontroler::class, 'Pesan'])->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:kios-30')->name('publik.kios.pesan');
            Route::get('/pesanan/{kodeAkses}', [KiosKontroler::class, 'Status'])
                ->where('kodeAkses', '[A-Za-z0-9]{16}')->middleware('throttle:kios-120')->name('publik.kios.status');
            Route::post('/pesanan/{kodeAkses}/bayar', [KiosKontroler::class, 'Bayar'])
                ->where('kodeAkses', '[A-Za-z0-9]{16}')->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:kios-30')->name('publik.kios.bayar');
            Route::get('/pesanan/{kodeAkses}/status-bayar', [KiosKontroler::class, 'StatusBayar'])
                ->where('kodeAkses', '[A-Za-z0-9]{16}')->middleware('throttle:kios-120')->name('publik.kios.status-bayar');
        });

    // Bengkel (§9.10): persetujuan estimasi servis tanpa akun lewat tautan rahasia (WhatsApp) dari bengkel. Slug
    // situs pemasaran bagian kedua `servis` ditolak `AturanSlugSitus` supaya tidak menaungi rute ini.
    Route::prefix('/{slugTenant}/servis/{tokenServis}')
        ->where(['slugTenant' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'tokenServis' => '[A-Za-z0-9]{40}'])
        ->group(function (): void {
            Route::get('/', [PersetujuanServisKontroler::class, 'Tampilkan'])->middleware('throttle:60,1')->name('publik.persetujuan-servis');
            Route::post('/setujui', [PersetujuanServisKontroler::class, 'Setujui'])->middleware('throttle:10,1')->name('publik.persetujuan-servis.setujui');
            Route::post('/tolak', [PersetujuanServisKontroler::class, 'Tolak'])->middleware('throttle:10,1')->name('publik.persetujuan-servis.tolak');
        });

    // F-18 bagian 4 (D-37, D-198): absen lewat tautan pribadi karyawan (jalan masuk kedua di samping login, D-180).
    Route::prefix('/{slugTenant}/absen/{tokenAbsen}')
        ->where(['slugTenant' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'tokenAbsen' => '[A-Za-z0-9]{40}'])
        ->group(function (): void {
            Route::get('/', [AbsenTautanKontroler::class, 'Tampilkan'])->middleware('throttle:60,1')->name('publik.absen-tautan');
            Route::get('/manifest', [AbsenTautanKontroler::class, 'Manifest'])->middleware('throttle:60,1')->name('publik.absen-tautan.manifest');
            Route::get('/pekerja-layanan', [AbsenTautanKontroler::class, 'PekerjaLayanan'])->middleware('throttle:60,1')->name('publik.absen-tautan.pekerja-layanan');
            Route::post('/wajah', [AbsenTautanKontroler::class, 'DaftarWajah'])->middleware('throttle:absensi-web')->name('publik.absen-tautan.wajah');
            Route::post('/cek-wajah', [AbsenTautanKontroler::class, 'CekWajah'])->middleware('throttle:cek-wajah')->name('publik.absen-tautan.cek-wajah');
            Route::post('/masuk', [AbsenTautanKontroler::class, 'Masuk'])->middleware('throttle:absensi-web')->name('publik.absen-tautan.masuk');
            Route::post('/keluar', [AbsenTautanKontroler::class, 'Keluar'])->middleware('throttle:absensi-web')->name('publik.absen-tautan.keluar');
            Route::post('/minta-koreksi', [AbsenTautanKontroler::class, 'MintaKoreksi'])->middleware('throttle:10,1')->name('publik.absen-tautan.minta-koreksi');
        });

    // F-10 (v3.49): portal kurir tanpa akun lewat tautan rahasia dari toko.
    Route::prefix('/{slugTenant}/kurir/{tokenKurir}')
        ->where(['slugTenant' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'tokenKurir' => '[A-Za-z0-9]{40}'])
        ->group(function (): void {
            Route::get('/', [PortalKurirKontroler::class, 'Tampilkan'])->middleware('throttle:60,1')->name('publik.portal-kurir');
            Route::post('/pengiriman/{pengiriman}/kirim', [PortalKurirKontroler::class, 'Kirim'])->where('pengiriman', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('throttle:30,1')->name('publik.portal-kurir.kirim');
            Route::post('/pengiriman/{pengiriman}/terima', [PortalKurirKontroler::class, 'Terima'])->where('pengiriman', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('throttle:30,1')->name('publik.portal-kurir.terima');
            Route::post('/pengiriman/{pengiriman}/gagal', [PortalKurirKontroler::class, 'Gagal'])->where('pengiriman', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('throttle:30,1')->name('publik.portal-kurir.gagal');
        });

    // F-17/F-10c: toko online tenant, checkout bayar saat ambil/COD, dan status pesanan publik.
    Route::prefix('/{slugTenant}')
        ->where(['slugTenant' => '[a-z0-9]+(?:-[a-z0-9]+)*'])
        ->group(function (): void {
            Route::get('/gambar/{produk}', [TokoOnlineKontroler::class, 'Gambar'])->where('produk', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->middleware('throttle:300,1')->name('publik.toko-online.gambar');
            Route::post('/keranjang/hitung', [TokoOnlineKontroler::class, 'Hitung'])->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:60,1')->name('publik.toko-online.hitung');
            Route::post('/pesan', [TokoOnlineKontroler::class, 'Pesan'])->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:20,1')->name('publik.toko-online.pesan');
            Route::get('/pesanan/{kodeAkses}', [TokoOnlineKontroler::class, 'Status'])->where('kodeAkses', '[A-Za-z0-9]{16}')->middleware('throttle:60,1')->name('publik.toko-online.status');
            Route::post('/pesanan/{kodeAkses}/bayar', [TokoOnlineKontroler::class, 'Bayar'])->where('kodeAkses', '[A-Za-z0-9]{16}')
                ->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:20,1')->name('publik.toko-online.bayar');
            Route::get('/pesanan/{kodeAkses}/status-bayar', [TokoOnlineKontroler::class, 'StatusBayar'])->where('kodeAkses', '[A-Za-z0-9]{16}')
                ->middleware('throttle:120,1')->name('publik.toko-online.status-bayar');
            // F-17 bagian 3: akun pembeli opsional (masuk dengan kode WhatsApp). Berbeda dengan checkout tamu, rute ini
            // memakai cookie sesi, jadi CSRF tetap dijaga (halaman mengirim X-XSRF-TOKEN).
            Route::get('/akun', [AkunTokoOnlineKontroler::class, 'Tampilkan'])->middleware('throttle:60,1')->name('publik.toko-online.akun');
            Route::post('/akun/kode', [AkunTokoOnlineKontroler::class, 'MintaKode'])->middleware('throttle:10,1')->name('publik.toko-online.akun.kode');
            Route::post('/akun/masuk', [AkunTokoOnlineKontroler::class, 'Masuk'])->middleware('throttle:20,1')->name('publik.toko-online.akun.masuk');
            Route::post('/akun/daftar', [AkunTokoOnlineKontroler::class, 'Daftar'])->middleware('throttle:10,1')->name('publik.toko-online.akun.daftar');
            Route::put('/akun/profil', [AkunTokoOnlineKontroler::class, 'PerbaruiProfil'])->middleware('throttle:20,1')->name('publik.toko-online.akun.profil');
            Route::post('/akun/keluar', [AkunTokoOnlineKontroler::class, 'Keluar'])->middleware('throttle:20,1')->name('publik.toko-online.akun.keluar');
            Route::get('/', [TokoOnlineKontroler::class, 'Tampilkan'])->middleware('throttle:60,1')->name('publik.toko-online');
        });
});
