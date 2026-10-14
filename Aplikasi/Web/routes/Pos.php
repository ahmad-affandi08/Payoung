<?php

declare(strict_types=1);

use App\Http\Kontroler\Pos\V1\BantuanKontroler;
use App\Http\Kontroler\Pos\V1\BatchProdukKontroler;
use App\Http\Kontroler\Pos\V1\DapurKontroler;
use App\Http\Kontroler\Pos\V1\DataAwalKontroler;
use App\Http\Kontroler\Pos\V1\GambarProdukKontroler;
use App\Http\Kontroler\Pos\V1\GambarQrisKontroler;
use App\Http\Kontroler\Pos\V1\GiftCardKontroler;
use App\Http\Kontroler\Pos\V1\GudangKontroler;
use App\Http\Kontroler\Pos\V1\KasirKontroler;
use App\Http\Kontroler\Pos\V1\KatalogKontroler;
use App\Http\Kontroler\Pos\V1\KetersediaanProdukKontroler;
use App\Http\Kontroler\Pos\V1\KonfigurasiAplikasiKontroler;
use App\Http\Kontroler\Pos\V1\LaundryKontroler;
use App\Http\Kontroler\Pos\V1\LogoStrukKontroler;
use App\Http\Kontroler\Pos\V1\MejaKontroler;
use App\Http\Kontroler\Pos\V1\PelangganKontroler;
use App\Http\Kontroler\Pos\V1\PenjualanKontroler;
use App\Http\Kontroler\Pos\V1\PerangkatKontroler;
use App\Http\Kontroler\Pos\V1\PerintahKerjaKontroler;
use App\Http\Kontroler\Pos\V1\PersetujuanJarakJauhKontroler;
use App\Http\Kontroler\Pos\V1\PesananOnlineKontroler;
use App\Http\Kontroler\Pos\V1\PesananPenjualanKontroler;
use App\Http\Kontroler\Pos\V1\PesananTerbukaKontroler;
use App\Http\Kontroler\Pos\V1\PesanKeluarKontroler;
use App\Http\Kontroler\Pos\V1\PesanSendiriKontroler;
use App\Http\Kontroler\Pos\V1\PromoKontroler;
use App\Http\Kontroler\Pos\V1\ReservasiKontroler;
use App\Http\Kontroler\Pos\V1\ReservasiMejaKontroler;
use App\Http\Kontroler\Pos\V1\RingkasanHarianKontroler;
use App\Http\Kontroler\Pos\V1\SalesmanKontroler;
use App\Http\Kontroler\Pos\V1\ShiftKontroler;
use App\Http\Kontroler\Pos\V1\SinkronKontroler;
use App\Http\Kontroler\Pos\V1\StokTersediaKontroler;
use App\Http\Kontroler\Pos\V1\TagihanQrisKontroler;
use App\Http\Kontroler\Pos\V1\VoucherKontroler;
use App\Http\Perantara\AutentikasiPerangkat;
use App\Http\Perantara\IdempotensiPos;
use App\Http\Perantara\PastikanLanggananPosAktif;
use App\Http\Perantara\WajibFiturPaket;
use Illuminate\Support\Facades\Route;

/*
 * API Aplikasi POS Flutter (PRD §13.6, §16.1, §16.3), didaftarkan dari bootstrap/app.php dengan prefix `/api/pos/v1`
 * dan grup `api` (tanpa sesi/CSRF). Autentikasi device token lewat `AutentikasiPerangkat`. Galat selalu berformat
 * `{"Galat": {"Kode", "Pesan", "Detail"}}`. Kontrak kompatibel mundur 2 versi minor aplikasi. Batas laju `throttle:pos-N`
 * = N per menit per rute per perangkat (per IP untuk aktivasi), lihat PenyediaAplikasi.
 */

// F-02b: tukar kode aktivasi (belum punya token). Dibatasi per IP agar kode 8 karakter tidak bisa ditebak massal.
Route::post('/perangkat/aktivasi', [PerangkatKontroler::class, 'Aktivasi'])
    ->middleware('throttle:pos-10')
    ->name('pos.perangkat.aktivasi');

// Audit F-12: mutasi boleh membawa `Idempotency-Key` (respons diputar ulang untuk permintaan yang sama).
Route::middleware([AutentikasiPerangkat::class, IdempotensiPos::class])->group(function (): void {
    // F-02b: versi aplikasi & status langganan; tetap terbuka saat langganan ditangguhkan.
    Route::get('/konfigurasi-aplikasi', [KonfigurasiAplikasiKontroler::class, 'Tampilkan'])->name('pos.konfigurasi-aplikasi');

    // F-06: kirim batch outbox (shift, mutasi kas; F-07b penjualan). Sengaja di luar penjaga langganan agar
    // data yang dibuat offline sebelum langganan ditangguhkan tetap bisa tersimpan di server (tanpa kehilangan data).
    // v1.96: profil hardware & hasil Wizard Uji Perangkat (dukungan teknis); tetap terbuka saat langganan ditangguhkan.
    Route::post('/perangkat/profil-hardware', [PerangkatKontroler::class, 'SimpanProfilHardware'])
        ->middleware('throttle:pos-10')
        ->name('pos.perangkat.profil-hardware');

    // K-21: laporan galat aplikasi kasir (log harian `galat-perangkat`, tanpa data pribadi).
    Route::post('/perangkat/galat', [PerangkatKontroler::class, 'LaporGalat'])->middleware('throttle:pos-10')->name('pos.perangkat.galat');
    // PGL-16 bagian 2: unggah log lokal yang diminta dukungan (diterima hanya bila ada permintaan yang berlaku).
    Route::post('/perangkat/log', [PerangkatKontroler::class, 'UnggahLog'])->middleware('throttle:pos-10')->name('pos.perangkat.log');
    Route::post('/sinkron/kirim', [SinkronKontroler::class, 'Kirim'])->middleware('throttle:pos-120')->name('pos.sinkron.kirim');

    // P-09: tombol Bantuan di aplikasi kasir (tiket + konteks otomatis). Di luar penjaga langganan: tenant yang
    // ditangguhkan justru paling perlu menghubungi dukungan.
    Route::get('/bantuan/tiket', [BantuanKontroler::class, 'Daftar'])->middleware('throttle:pos-30')->name('pos.bantuan.tiket.daftar');
    Route::post('/bantuan/tiket', [BantuanKontroler::class, 'Buat'])->middleware('throttle:pos-10')->name('pos.bantuan.tiket.buat');
    Route::get('/bantuan/tiket/{tiket}', [BantuanKontroler::class, 'Tampilkan'])->middleware('throttle:pos-30')
        ->where('tiket', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('pos.bantuan.tiket.tampil');
    Route::post('/bantuan/tiket/{tiket}/balas', [BantuanKontroler::class, 'Balas'])->middleware('throttle:pos-10')
        ->where('tiket', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')->name('pos.bantuan.tiket.balas');

    // Shift lama perangkat yang masih terbuka di server dan menahan shift baru (`ShiftSudahTerbuka`): lihat & tutup
    // paksa oleh supervisor dari aplikasi. Di luar penjaga langganan seperti sinkron agar data tidak tertahan.
    Route::get('/shift/terbuka', [ShiftKontroler::class, 'Terbuka'])->middleware('throttle:pos-30')->name('pos.shift.terbuka');
    // BR-06.1 per kasir: shift terbuka milik kasir ini di perangkat lain (dicek aplikasi sebelum membuka shift saat online).
    Route::get('/shift/kasir-terbuka', [ShiftKontroler::class, 'KasirTerbuka'])->middleware('throttle:pos-30')->name('pos.shift.kasir-terbuka');
    Route::post('/shift/{uuidShift}/tutup-paksa', [ShiftKontroler::class, 'TutupPaksa'])->middleware('throttle:pos-10')->where('uuidShift', '[0-9A-HJKMNP-TV-Z]{26}')->name('pos.shift.tutup-paksa');

    // Endpoint berjualan: POS terkunci saat langganan Ditangguhkan/Berhenti.
    Route::middleware([PastikanLanggananPosAktif::class, WajibFiturPaket::class])->group(function (): void {
        // F-02b: masuk kasir dengan PIN (kunci 5 menit setelah 5 kali salah, §20.2).
        Route::post('/kasir/masuk-pin', [KasirKontroler::class, 'MasukPin'])->middleware('throttle:pos-60')->name('pos.kasir.masuk-pin');

        // F-06: data awal kerja offline (staf & verifier PIN offline, kategori kas, pengaturan kasir).
        Route::get('/data-awal', [DataAwalKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.data-awal');

        // F-03 D.3: katalog lengkap/delta (`?sejak=`) dan gambar produk berversi. Gambar diunduh per produk sehingga
        // batasnya lebih longgar daripada katalog.
        Route::get('/katalog', [KatalogKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.katalog');
        Route::get('/katalog/gambar/{produk}', [GambarProdukKontroler::class, 'Unduh'])
            ->middleware('throttle:pos-600')
            ->where('produk', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
            ->name('pos.katalog.gambar');

        // F-07b: gambar QRIS statis metode pembayaran (disimpan offline untuk layar Bayar).
        Route::get('/metode-pembayaran/{metodePembayaran}/gambar-qris', [GambarQrisKontroler::class, 'Unduh'])
            ->middleware('throttle:pos-60')
            ->where('metodePembayaran', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
            ->name('pos.metode-pembayaran.gambar-qris');

        // PRD v1.79: logo usaha untuk kepala struk (disimpan offline, dicetak sebagai gambar raster).
        Route::get('/logo-struk', [LogoStrukKontroler::class, 'Unduh'])->middleware('throttle:pos-60')->name('pos.logo-struk');

        // F-09: cari struk asal untuk retur (perlu online); hanya penjualan outlet perangkat.
        Route::get('/penjualan/cari', [PenjualanKontroler::class, 'Cari'])->middleware('throttle:pos-60')->name('pos.penjualan.cari');
        Route::get('/penjualan/kandidat', [PenjualanKontroler::class, 'Kandidat'])->middleware('throttle:pos-60')->name('pos.penjualan.kandidat');
        // F-16a: cari pelanggan aktif untuk dipilih kasir (pelanggan baru lewat outbox `Pelanggan.Buat`).
        Route::get('/pelanggan', [PelangganKontroler::class, 'Cari'])->middleware('throttle:pos-60')->name('pos.pelanggan.cari');
        // F-12 bagian 2: cari pre-order untuk diambil (perlu online).
        Route::get('/pesanan-penjualan', [PesananPenjualanKontroler::class, 'Cari'])->middleware('throttle:pos-60')->name('pos.pesanan-penjualan.cari');
        $ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';
        // F-16b: saldo poin terkini sebelum tukar poin (wajib online, §18.4).
        // F-16c: promo aktif untuk dievaluasi di perangkat (bisa offline setelah diunduh).
        Route::get('/promo', [PromoKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.promo');
        // F-16c bagian 2: voucher wajib online, dipesan untuk penjualan yang sedang dibuat lalu dilepas bila batal.
        Route::post('/voucher/pesan', [VoucherKontroler::class, 'Pesan'])->middleware('throttle:pos-30')->name('pos.voucher.pesan');
        Route::post('/voucher/lepas', [VoucherKontroler::class, 'Lepas'])->middleware('throttle:pos-60')->name('pos.voucher.lepas');
        Route::get('/pelanggan/{uuidPelanggan}/poin', [PelangganKontroler::class, 'Poin'])
            ->middleware('throttle:pos-60')->where('uuidPelanggan', $ulid)->name('pos.pelanggan.poin');
        // F-16d bagian 1: saldo deposit pelanggan (wajib online saat membayar dengan deposit).
        Route::get('/pelanggan/{uuidPelanggan}/deposit', [PelangganKontroler::class, 'Deposit'])
            ->middleware('throttle:pos-60')->where('uuidPelanggan', $ulid)->name('pos.pelanggan.deposit');
        // CRM-06 (D-153): cek saldo gift card (wajib online saat membayar dengan kartu); 10/menit per perangkat menahan tebakan kode.
        Route::post('/gift-card/cek', [GiftCardKontroler::class, 'Cek'])->middleware('throttle:pos-10')->name('pos.gift-card.cek');
        // F-16d bagian 2: paket sesi aktif pelanggan (wajib online saat memakai sesi).
        Route::get('/pelanggan/{uuidPelanggan}/sesi', [PelangganKontroler::class, 'Sesi'])
            ->middleware('throttle:pos-60')->where('uuidPelanggan', $ulid)->name('pos.pelanggan.sesi');
        // F-07 mode meja fase 1: data meja, pesanan terbuka outlet (ditarik tiap 5–10 detik, ETag), kunci bayar online.
        Route::get('/meja', [MejaKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.meja');
        Route::get('/pesanan-terbuka', [PesananTerbukaKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.pesanan-terbuka');
        Route::post('/pesanan-terbuka/{pesananTerbuka}/kunci-bayar', [PesananTerbukaKontroler::class, 'Kunci'])
            ->middleware('throttle:pos-60')->where('pesananTerbuka', $ulid)->name('pos.pesanan-terbuka.kunci-bayar');
        Route::delete('/pesanan-terbuka/{pesananTerbuka}/kunci-bayar', [PesananTerbukaKontroler::class, 'Lepas'])
            ->middleware('throttle:pos-60')->where('pesananTerbuka', $ulid)->name('pos.pesanan-terbuka.lepas-kunci-bayar');
        // F-10b fase 1: layar dapur (KDS) online.
        Route::get('/dapur/tiket', [DapurKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.dapur.tiket');
        Route::post('/dapur/tiket/{tiketDapur}/status', [DapurKontroler::class, 'UbahStatus'])
            ->middleware('throttle:pos-120')->where('tiketDapur', $ulid)->name('pos.dapur.tiket.status');
        // F-08 QRIS dinamis (wajib online): buat tagihan lewat gerbang aktif, cek status (polling), batalkan.
        Route::post('/qris', [TagihanQrisKontroler::class, 'Buat'])->middleware('throttle:pos-30')->name('pos.qris.buat');
        Route::get('/qris/{tagihanQris}', [TagihanQrisKontroler::class, 'Status'])
            ->middleware('throttle:pos-120')->where('tagihanQris', $ulid)->name('pos.qris.status');
        Route::post('/qris/{tagihanQris}/batal', [TagihanQrisKontroler::class, 'Batal'])
            ->middleware('throttle:pos-30')->where('tagihanQris', $ulid)->name('pos.qris.batal');
        // F-07 mode service bagian 2: antrian reservasi outlet & check-in pelanggan (online).
        // K-20: kalender staf, slot kosong, dan buat booking dari kasir.
        Route::get('/reservasi/kalender', [ReservasiKontroler::class, 'Kalender'])->middleware('throttle:pos-60')->name('pos.reservasi.kalender');
        Route::get('/reservasi/slot', [ReservasiKontroler::class, 'Slot'])->middleware('throttle:pos-120')->name('pos.reservasi.slot');
        Route::post('/reservasi', [ReservasiKontroler::class, 'Buat'])->middleware('throttle:pos-30')->name('pos.reservasi.buat');
        Route::get('/reservasi', [ReservasiKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.reservasi');
        Route::post('/reservasi/{reservasi}/hadir', [ReservasiKontroler::class, 'Hadir'])
            ->middleware('throttle:pos-60')->where('reservasi', $ulid)->name('pos.reservasi.hadir');
        // FnB reservasi meja + DP (D-145): antrian reservasi meja outlet & tamu sudah datang (online).
        Route::get('/reservasi-meja', [ReservasiMejaKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.reservasi-meja');
        Route::post('/reservasi-meja/{reservasi}/hadir', [ReservasiMejaKontroler::class, 'Hadir'])
            ->middleware('throttle:pos-60')->where('reservasi', $ulid)->name('pos.reservasi-meja.hadir');
        // Laundry (§9.9): cari cucian aktif outlet & ubah status proses/diambil (online).
        // X4 persetujuan jarak jauh (online): kasir meminta, Aplikasi Owner memutuskan, kasir menunggu status.
        Route::post('/persetujuan/jarak-jauh', [PersetujuanJarakJauhKontroler::class, 'Ajukan'])->middleware('throttle:pos-30')->name('pos.persetujuan.jarak-jauh');
        Route::get('/persetujuan/jarak-jauh/{persetujuan}', [PersetujuanJarakJauhKontroler::class, 'Tampilkan'])
            ->middleware('throttle:pos-120')->where('persetujuan', $ulid)->name('pos.persetujuan.jarak-jauh.tampil');
        Route::post('/persetujuan/jarak-jauh/{persetujuan}/batal', [PersetujuanJarakJauhKontroler::class, 'Batal'])
            ->middleware('throttle:pos-30')->where('persetujuan', $ulid)->name('pos.persetujuan.jarak-jauh.batal');

        // POS-25 modul Gudang (online): terima barang dari PO, terima transfer masuk, hitung stok opname.
        Route::get('/gudang/pesanan-pembelian', [GudangKontroler::class, 'DaftarPesanan'])->middleware('throttle:pos-30')->name('pos.gudang.pesanan-pembelian');
        Route::post('/gudang/penerimaan', [GudangKontroler::class, 'Terima'])->middleware('throttle:pos-30')->name('pos.gudang.penerimaan');
        Route::get('/gudang/transfer', [GudangKontroler::class, 'DaftarTransfer'])->middleware('throttle:pos-30')->name('pos.gudang.transfer');
        Route::post('/gudang/transfer/{transfer}/terima', [GudangKontroler::class, 'TerimaTransfer'])
            ->middleware('throttle:pos-30')->where('transfer', $ulid)->name('pos.gudang.transfer.terima');
        Route::get('/gudang/opname', [GudangKontroler::class, 'DaftarOpname'])->middleware('throttle:pos-30')->name('pos.gudang.opname');
        Route::post('/gudang/opname/{opname}/hitung', [GudangKontroler::class, 'SimpanHitung'])
            ->middleware('throttle:pos-60')->where('opname', $ulid)->name('pos.gudang.opname.hitung');

        // Modul Salesman bagian 1 (§9.7, SLS-11): data cache offline aplikasi salesman (pelaku dari `X-Id-Kasir`, izin
        // `salesman.kunjungan`). Pesanan & kunjungan dikirim lewat outbox `PesananGrosir.Buat` / `Kunjungan.Catat`.
        Route::get('/salesman/pelanggan', [SalesmanKontroler::class, 'Pelanggan'])->middleware('throttle:pos-60')->name('pos.salesman.pelanggan');
        Route::get('/salesman/pelanggan/{uuidPelanggan}/piutang', [SalesmanKontroler::class, 'Piutang'])
            ->middleware('throttle:pos-60')->where('uuidPelanggan', $ulid)->name('pos.salesman.pelanggan.piutang');
        Route::get('/salesman/stok', [SalesmanKontroler::class, 'Stok'])->middleware('throttle:pos-30')->name('pos.salesman.stok');
        Route::get('/salesman/kunjungan', [SalesmanKontroler::class, 'Kunjungan'])->middleware('throttle:pos-60')->name('pos.salesman.kunjungan');

        // Bengkel (§9.10): perintah kerja siap tagih outlet & satu perintah kerja untuk dimuat ke keranjang (online).
        Route::get('/perintah-kerja', [PerintahKerjaKontroler::class, 'Daftar'])->middleware('throttle:pos-60')->name('pos.perintah-kerja');
        Route::get('/perintah-kerja/{perintahKerja}', [PerintahKerjaKontroler::class, 'Ambil'])
            ->middleware('throttle:pos-120')->where('perintahKerja', $ulid)->name('pos.perintah-kerja.tampil');
        Route::get('/laundry', [LaundryKontroler::class, 'Cari'])->middleware('throttle:pos-30')->name('pos.laundry');
        Route::post('/laundry/{tiket}/status', [LaundryKontroler::class, 'UbahStatus'])
            ->middleware('throttle:pos-60')->where('tiket', $ulid)->name('pos.laundry.status');
        // F-17 Self-Order QR Meja: pesanan tamu menunggu konfirmasi (ditarik berkala), terima/tolak oleh staf.
        // F-17 BR-17.2: tandai habis ("86") dari POS/KDS; tercermin di menu self-order & toko online outlet.
        // K-24: ringkasan akhir hari outlet (semua perangkat) untuk kasir.
        Route::get('/ringkasan-harian', [RingkasanHarianKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.ringkasan-harian');
        // K-19: batch & kedaluwarsa produk di lokasi stok Toko outlet (urut FEFO), info sebelum menjual.
        Route::get('/produk/{produk}/batch', [BatchProdukKontroler::class, 'Ambil'])
            ->middleware('throttle:pos-120')->where('produk', $ulid)->name('pos.produk.batch');
        Route::get('/produk-habis', [KetersediaanProdukKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.produk-habis');
        // F-07 + BR-05.2: sisa stok Toko outlet untuk produk yang tidak boleh minus (kasir menahan jual saat kosong).
        Route::get('/stok-tersedia', [StokTersediaKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.stok-tersedia');
        Route::post('/produk/{produk}/habis', [KetersediaanProdukKontroler::class, 'Ubah'])
            ->middleware('throttle:pos-60')->where('produk', $ulid)->name('pos.produk.habis');
        Route::get('/pesan-sendiri', [PesanSendiriKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.pesan-sendiri');
        Route::post('/pesan-sendiri/{pesananSendiri}/terima', [PesanSendiriKontroler::class, 'Terima'])
            ->middleware('throttle:pos-60')->where('pesananSendiri', $ulid)->name('pos.pesan-sendiri.terima');
        Route::post('/pesan-sendiri/{pesananSendiri}/tolak', [PesanSendiriKontroler::class, 'Tolak'])
            ->middleware('throttle:pos-60')->where('pesananSendiri', $ulid)->name('pos.pesan-sendiri.tolak');
        // F-17 toko online: muat pesanan aktif ke POS; setelah penjualan lunas tersinkron, tautkan secara idempoten.
        Route::get('/pesanan-online', [PesananOnlineKontroler::class, 'Ambil'])->middleware('throttle:pos-30')->name('pos.pesanan-online');
        // BR-17.3 (v3.33): ringkasan untuk polling 10 detik & ubah status dari kasir.
        Route::get('/pesanan-online/ringkas', [PesananOnlineKontroler::class, 'Ringkas'])->middleware('throttle:pos-60')->name('pos.pesanan-online.ringkas');
        Route::post('/pesanan-online/{pesananOnline}/status', [PesananOnlineKontroler::class, 'UbahStatus'])
            ->middleware('throttle:pos-60')->where('pesananOnline', $ulid)->name('pos.pesanan-online.status');
        Route::post('/pesanan-online/{pesananOnline}/tautkan', [PesananOnlineKontroler::class, 'Tautkan'])
            ->middleware('throttle:pos-60')->where('pesananOnline', $ulid)->name('pos.pesanan-online.tautkan');
        // K3: kirim struk digital ke WhatsApp/email pelanggan (wajib online, penjualan sudah tersinkron), diantrekan;
        // status kiriman ditarik aplikasi. Maksimal 5 kiriman per penjualan (aksi) + 20/menit per perangkat.
        Route::post('/penjualan/{uuidPenjualan}/kirim-struk', [PesanKeluarKontroler::class, 'KirimStruk'])
            ->middleware('throttle:pos-20')->where('uuidPenjualan', $ulid)->name('pos.penjualan.kirim-struk');
        Route::get('/pesan-keluar/{uuidPesanKeluar}', [PesanKeluarKontroler::class, 'Tampilkan'])
            ->middleware('throttle:pos-60')->where('uuidPesanKeluar', $ulid)->name('pos.pesan-keluar.tampil');
    });
});
