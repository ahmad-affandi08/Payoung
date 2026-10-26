<?php

declare(strict_types=1);

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Pelanggan\Aksi\KirimPengingatPiutangOtomatis;
use App\Domain\Pelanggan\Aksi\SimpanPengaturanPengingatPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Penjualan\Aksi\AntrekanPengirimanInvoice;
use App\Domain\Penjualan\Aksi\BatalkanInvoice;
use App\Domain\Penjualan\Aksi\CatatPembayaranInvoice;
use App\Domain\Penjualan\Aksi\KirimPengingatInvoiceOtomatis;
use App\Domain\Penjualan\Aksi\SimpanInvoice;
use App\Domain\Penjualan\Aksi\SimpanInvoiceBerulang;
use App\Domain\Penjualan\Aksi\TerbitkanInvoice;
use App\Domain\Penjualan\Data\DataBarisInvoice;
use App\Domain\Penjualan\Data\DataInvoice;
use App\Domain\Penjualan\Data\DataInvoiceBerulang;
use App\Domain\Penjualan\Enum\FrekuensiInvoiceBerulang;
use App\Domain\Penjualan\Enum\JenisBarisInvoice;
use App\Domain\Penjualan\Enum\JenisInvoice;
use App\Domain\Penjualan\Enum\JenisPengirimanInvoice;
use App\Domain\Penjualan\Enum\ModeInvoiceBerulang;
use App\Domain\Penjualan\Enum\StatusPengirimanInvoice;
use App\Domain\Penjualan\Layanan\KodeInvoicePublik;
use App\Domain\Penjualan\Layanan\PengirimInvoice;
use App\Domain\Penjualan\Layanan\PenyediaTindakanInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\InvoiceBerulang;
use App\Domain\Penjualan\Model\PengirimanInvoice;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Kirim invoice & pengingat tagihan ke WhatsApp pelanggan (F-21, D-33: tanpa email): syarat kirim, jeda 10 menit, satu
 * pengingat per tahap, pembatalan sebelum terkirim, kegagalan penyedia tanpa membocorkan nomor, dan nada pesan pengingat
 * mengikuti selisih hari ke jatuh tempo.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9301']])]);
    $this->k = BantuanPersediaan::SiapkanTenant('Konsultan Maju Bersama');
    OverrideTenant::query()->create([
        'IdTenant' => $this->k['Tenant']->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => PemeriksaFiturTenant::KUNCI_WHATSAPP,
        'BerakhirPada' => now()->addDays(90),
        'Alasan' => 'Uji kirim invoice',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    $this->klien = Pelanggan::query()->create(['Nama' => 'PT Sumber Makmur Sentosa', 'NoHp' => '6281355550101', 'TerminHari' => 14]);
    $this->jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa konsultasi manajemen per bulan', 'Jenis' => JenisProduk::Jasa], '5000000.00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function InvoiceTerbitKirimUji($tes, bool $terbit = true): Invoice
{
    $draf = app(SimpanInvoice::class)->Jalankan(new DataInvoice(
        jenis: JenisInvoice::Penjualan,
        uuidPelanggan: $tes->klien->Uuid,
        idOutlet: $tes->k['Outlet']->Id,
        tanggal: CarbonImmutable::parse('2026-09-27'),
        terminHari: null,
        baris: [new DataBarisInvoice(null, 'Konsultasi manajemen', null, JenisBarisInvoice::Jasa, 'bln', Kuantitas::Dari('1'), Uang::Dari('10000000.00'), Uang::Nol(), null)],
        perihal: 'Jasa konsultasi September 2026',
    ), $tes->k['Pemilik']->Id);

    return $terbit ? app(TerbitkanInvoice::class)->Jalankan($draf->Uuid, $tes->k['Pemilik']->Id) : $draf;
}

function KirimanInvoiceTerekam(): int
{
    return collect(Http::recorded())->filter(fn (array $p): bool => str_contains($p[0]->url(), 'api.fonnte.com/send'))->count();
}

function GalatKirimInvoiceUji(callable $aksi): string
{
    try {
        $aksi();
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat->kode;
    }

    return 'tidak-ada-galat';
}

it('mengirim invoice terbit ke WhatsApp pelanggan dengan tautan publik dan mengisi TerkirimPada', function (): void {
    $invoice = InvoiceTerbitKirimUji($this);
    $antre = app(AntrekanPengirimanInvoice::class);

    $antre->Jalankan($this->k['Tenant']->Id, $invoice, $this->k['Pemilik']->Id);

    $kiriman = PengirimanInvoice::query()->sole();
    expect($kiriman->Status)->toBe(StatusPengirimanInvoice::Terkirim)
        ->and($kiriman->Jenis)->toBe(JenisPengirimanInvoice::Invoice)
        ->and($kiriman->IdPesanPenyedia)->toBe('9301');
    expect($invoice->refresh()->TerkirimPada)->not->toBeNull();
    $pesan = collect(Http::recorded())->map(fn (array $p) => $p[0])->first(fn ($r) => str_contains($r->url(), 'api.fonnte.com/send'));
    $teks = (string) $pesan->data()['message'];
    expect($teks)->toContain('Halo PT Sumber Makmur Sentosa')
        ->and($teks)->toContain('berikut invoice dari')
        ->and($teks)->toContain($invoice->Nomor)
        ->and($teks)->toContain('Perihal: Jasa konsultasi September 2026')
        ->and($teks)->toContain('Tagihan Rp')
        ->and($teks)->toContain('jatuh tempo 11 Oktober 2026')
        ->and($teks)->toContain(url('/i/'.KodeInvoicePublik::Buat($invoice->IdTenant, $invoice->KodePublik)));
});

it('ditolak: draf atau dibatalkan, pelanggan tanpa nomor sah, WhatsApp tidak aktif, dan kirim ulang dalam 10 menit', function (): void {
    $antre = app(AntrekanPengirimanInvoice::class);
    $draf = InvoiceTerbitKirimUji($this, false);
    expect(GalatKirimInvoiceUji(fn () => $antre->Jalankan($this->k['Tenant']->Id, $draf, 1)))->toBe('InvoiceBelumTerbit');

    $invoice = app(TerbitkanInvoice::class)->Jalankan($draf->Uuid, $this->k['Pemilik']->Id);
    $antre->Jalankan($this->k['Tenant']->Id, $invoice, $this->k['Pemilik']->Id);
    expect(GalatKirimInvoiceUji(fn () => $antre->Jalankan($this->k['Tenant']->Id, $invoice, $this->k['Pemilik']->Id)))->toBe('InvoiceBaruDikirim')
        ->and(KirimanInvoiceTerekam())->toBe(1);

    // Pengingat adalah jenis lain: tidak ikut jeda kiriman invoice.
    $antre->Jalankan($this->k['Tenant']->Id, $invoice, $this->k['Pemilik']->Id, JenisPengirimanInvoice::Pengingat);
    expect(KirimanInvoiceTerekam())->toBe(2);

    $this->travel(11)->minutes();
    $antre->Jalankan($this->k['Tenant']->Id, $invoice, $this->k['Pemilik']->Id);
    expect(KirimanInvoiceTerekam())->toBe(3);

    Pelanggan::query()->whereKey($this->klien->Id)->update(['NoHp' => '021-555']);
    $this->travel(11)->minutes();
    expect(GalatKirimInvoiceUji(fn () => $antre->Jalankan($this->k['Tenant']->Id, $invoice, $this->k['Pemilik']->Id)))->toBe('KontakTidakTersedia');

    Pelanggan::query()->whereKey($this->klien->Id)->update(['NoHp' => '6281355550101']);
    config(['integrasi.Whatsapp' => ['Penyedia' => '', 'Pengaturan' => [], 'Kredensial' => []]]);
    expect(GalatKirimInvoiceUji(fn () => $antre->Jalankan($this->k['Tenant']->Id, $invoice, $this->k['Pemilik']->Id)))->toBe('WhatsappTidakAktif');

    $batal = InvoiceTerbitKirimUji($this);
    app(BatalkanInvoice::class)->Jalankan($batal->Uuid, 'Salah pelanggan', $this->k['Pemilik']->Id);
    expect(GalatKirimInvoiceUji(fn () => $antre->Jalankan($this->k['Tenant']->Id, $batal->refresh(), 1)))->toBe('InvoiceBelumTerbit');
});

it('pengingat hanya untuk invoice yang masih punya sisa, dan satu kali per tahap', function (): void {
    $antre = app(AntrekanPengirimanInvoice::class);
    $invoice = InvoiceTerbitKirimUji($this);

    $antre->Jalankan($this->k['Tenant']->Id, $invoice, null, JenisPengirimanInvoice::Pengingat, 'H-3');
    expect(PengirimanInvoice::query()->sole()->Tahap)->toBe('H-3')
        ->and(GalatKirimInvoiceUji(fn () => $antre->Jalankan($this->k['Tenant']->Id, $invoice, null, JenisPengirimanInvoice::Pengingat, 'H-3')))->toBe('PengingatSudahDikirim');
    $antre->Jalankan($this->k['Tenant']->Id, $invoice, null, JenisPengirimanInvoice::Pengingat, 'H0');
    expect(PengirimanInvoice::query()->count())->toBe(2);

    app(CatatPembayaranInvoice::class)->Jalankan($invoice->Uuid, BantuanPembelian::AkunKas('1-1200')->Uuid, CarbonImmutable::parse('2026-09-27'), null, Uang::Nol(), null, null, $this->k['Pemilik']->Id);
    expect(GalatKirimInvoiceUji(fn () => $antre->Jalankan($this->k['Tenant']->Id, $invoice, null, JenisPengirimanInvoice::Pengingat, 'H+3')))->toBe('InvoiceSudahLunas');
});

it('invoice dibatalkan sebelum tugas berjalan menjadi Dibatalkan tanpa pesan keluar', function (): void {
    $invoice = InvoiceTerbitKirimUji($this);
    $kiriman = PengirimanInvoice::query()->create(['IdInvoice' => $invoice->Id, 'Jenis' => JenisPengirimanInvoice::Invoice, 'Tujuan' => '6281355550101', 'Status' => StatusPengirimanInvoice::Diantrekan]);
    app(BatalkanInvoice::class)->Jalankan($invoice->Uuid, 'Salah pelanggan', $this->k['Pemilik']->Id);

    expect(app(PengirimInvoice::class)->Kirim($kiriman->Id, false))->toBeFalse()
        ->and($kiriman->fresh()?->Status)->toBe(StatusPengirimanInvoice::Dibatalkan)
        ->and(KirimanInvoiceTerekam())->toBe(0);
});

it('pengingat yang lunas sebelum terkirim dibatalkan. Kegagalan penyedia dicoba ulang lalu Gagal tanpa nomor', function (): void {
    $invoice = InvoiceTerbitKirimUji($this);
    $lunas = PengirimanInvoice::query()->create(['IdInvoice' => $invoice->Id, 'Jenis' => JenisPengirimanInvoice::Pengingat, 'Tahap' => 'H0', 'Tujuan' => '6281355550101', 'Status' => StatusPengirimanInvoice::Diantrekan]);
    app(CatatPembayaranInvoice::class)->Jalankan($invoice->Uuid, BantuanPembelian::AkunKas('1-1200')->Uuid, CarbonImmutable::parse('2026-09-27'), null, Uang::Nol(), null, null, $this->k['Pemilik']->Id);
    expect(app(PengirimInvoice::class)->Kirim($lunas->Id, false))->toBeFalse()
        ->and($lunas->fresh()?->Status)->toBe(StatusPengirimanInvoice::Dibatalkan)
        ->and(KirimanInvoiceTerekam())->toBe(0);

    $baru = InvoiceTerbitKirimUji($this);
    Http::swap(new Factory);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => false, 'reason' => 'target 6281355550101 tidak valid'])]);
    $gagal = PengirimanInvoice::query()->create(['IdInvoice' => $baru->Id, 'Jenis' => JenisPengirimanInvoice::Invoice, 'Tujuan' => '6281355550101', 'Status' => StatusPengirimanInvoice::Diantrekan]);

    expect(app(PengirimInvoice::class)->Kirim($gagal->Id, false))->toBeTrue()
        ->and($gagal->fresh()?->Status)->toBe(StatusPengirimanInvoice::Diantrekan);
    expect(app(PengirimInvoice::class)->Kirim($gagal->Id, true))->toBeFalse();
    $akhir = $gagal->fresh();
    expect($akhir?->Status)->toBe(StatusPengirimanInvoice::Gagal)
        ->and($akhir?->PesanGalat)->not->toContain('6281355550101')
        ->and($akhir?->Percobaan)->toBe(2)
        ->and($baru->refresh()->TerkirimPada)->toBeNull();
});

it('nada pesan pengingat mengikuti selisih hari ke jatuh tempo', function (): void {
    $sisa = Uang::Dari('2500000.00');

    expect(PengirimInvoice::SusunRingkas(JenisPengirimanInvoice::Pengingat, $sisa, '2026-10-11', '2026-10-08'))->toBe('sisa tagihan Rp 2.500.000 jatuh tempo 3 hari lagi (11 Oktober 2026)')
        ->and(PengirimInvoice::SusunRingkas(JenisPengirimanInvoice::Pengingat, $sisa, '2026-10-11', '2026-10-11'))->toBe('sisa tagihan Rp 2.500.000 jatuh tempo hari ini (11 Oktober 2026)')
        ->and(PengirimInvoice::SusunRingkas(JenisPengirimanInvoice::Pengingat, $sisa, '2026-10-11', '2026-10-14'))->toBe('sisa tagihan Rp 2.500.000 sudah lewat jatuh tempo 3 hari (11 Oktober 2026)')
        ->and(PengirimInvoice::SusunRingkas(JenisPengirimanInvoice::Invoice, $sisa, '2026-10-11', '2026-10-14'))->toBe('tagihan Rp 2.500.000, jatuh tempo 11 Oktober 2026');
});

it('pengingat otomatis: memakai pengaturan pengingat piutang, sekali per tahap, dan piutang bersumber invoice tidak diingatkan dua kali', function (): void {
    $invoice = InvoiceTerbitKirimUji($this); // jatuh tempo 11 Okt 2026
    $antre = app(KirimPengingatInvoiceOtomatis::class);
    $generik = app(KirimPengingatPiutangOtomatis::class);
    $idTenant = $this->k['Tenant']->Id;

    // Mati (bawaan): tidak ada yang dikirim.
    expect($antre->Jalankan($idTenant, CarbonImmutable::parse('2026-10-09')))->toBe(0);

    app(SimpanPengaturanPengingatPiutang::class)->Jalankan(true, 3, true, $this->k['Pemilik']->Id);

    // 5 hari sebelum jatuh tempo: di luar jendela 3 hari.
    expect($antre->Jalankan($idTenant, CarbonImmutable::parse('2026-10-06')))->toBe(0);

    // 2 hari sebelum: masuk jendela, terkirim sekali; hari berikutnya tidak diulang.
    expect($antre->Jalankan($idTenant, CarbonImmutable::parse('2026-10-09')))->toBe(1)
        ->and($antre->Jalankan($idTenant, CarbonImmutable::parse('2026-10-10')))->toBe(0);
    $sebelum = PengirimanInvoice::query()->sole();
    expect($sebelum->Jenis)->toBe(JenisPengirimanInvoice::Pengingat)
        ->and($sebelum->Tahap)->toBe('Sebelum')
        ->and($sebelum->DikirimOleh)->toBeNull()
        ->and($sebelum->Status)->toBe(StatusPengirimanInvoice::Terkirim);
    $teks = (string) collect(Http::recorded())->map(fn (array $p) => $p[0])->last()->data()['message'];
    expect($teks)->toContain('ini pengingat dari')->and($teks)->toContain($invoice->Nomor)->and($teks)->toContain('/i/');

    // Lewat jatuh tempo 2 hari: tahap kedua, sekali saja.
    expect($antre->Jalankan($idTenant, CarbonImmutable::parse('2026-10-13')))->toBe(1)
        ->and($antre->Jalankan($idTenant, CarbonImmutable::parse('2026-10-14')))->toBe(0)
        ->and(PengirimanInvoice::query()->count())->toBe(2);

    // Lewat dari jendela 7 hari: tidak lagi.
    $lama = InvoiceTerbitKirimUji($this);
    Invoice::query()->whereKey($lama->Id)->toBase()->update(['JatuhTempo' => '2026-09-01']);
    Piutang::query()->where('IdInvoice', $lama->Id)->toBase()->update(['JatuhTempo' => '2026-09-01']);
    expect($antre->Jalankan($idTenant, CarbonImmutable::parse('2026-10-14')))->toBe(0);

    // Putaran pengingat piutang umum melewatkan piutang bersumber invoice, jadi tidak ada pesan kedua.
    expect($generik->Jalankan($idTenant, CarbonImmutable::parse('2026-10-09')))->toBe(0);
});

it('perintah invoice:kirim-pengingat berjalan per tenant tanpa masalah', function (): void {
    InvoiceTerbitKirimUji($this);
    app(SimpanPengaturanPengingatPiutang::class)->Jalankan(true, 14, true, $this->k['Pemilik']->Id);

    $this->artisan('invoice:kirim-pengingat', ['--tenant' => [$this->k['Tenant']->Id]])->assertSuccessful();
    expect(PengirimanInvoice::query()->where('Tahap', 'Sebelum')->count())->toBe(1);
});

it('Kotak Tindakan invoice: lewat tempo, templat berulang gagal, draf lama, dan belum dikirim', function (): void {
    $penyedia = app(PenyediaTindakanInvoice::class);
    $konteks = fn (CarbonImmutable $hari, bool $izin = true) => new DataKonteksTindakan($this->k['Tenant']->Id, $this->k['Pemilik']->Id, false, $izin ? ['invoice.kelola'] : [], null, $hari);
    $butir = fn (CarbonImmutable $hari) => collect($penyedia->Kumpulkan($konteks($hari)))->filter(fn ($b) => $b->jumlah > 0)->keyBy('kunci');

    expect($butir(CarbonImmutable::parse('2026-09-27'))->all())->toBe([]);

    $terbit = InvoiceTerbitKirimUji($this); // jatuh tempo 11 Okt 2026
    $draf = InvoiceTerbitKirimUji($this, false);
    Carbon::setTestNow('2026-10-20 03:00:00');
    $hari = CarbonImmutable::parse('2026-10-20');
    $kumpul = $butir($hari);

    expect($kumpul->keys()->sort()->values()->all())->toBe(['invoice.belum-dikirim', 'invoice.draf-lama', 'invoice.lewat-tempo'])
        ->and($kumpul['invoice.lewat-tempo']->jumlah)->toBe(1)
        ->and($kumpul['invoice.lewat-tempo']->tingkat->name)->toBe('Penting')
        ->and($kumpul['invoice.lewat-tempo']->rincian[0]->judul)->toBe($terbit->Nomor)
        ->and($kumpul['invoice.draf-lama']->rincian[0]->tautan)->toBe('/kelola/invoice/'.$draf->Uuid)
        ->and($kumpul['invoice.belum-dikirim']->jumlah)->toBe(1);

    // Dikirim: butir "belum dikirim" hilang. Dibayar lunas: butir lewat tempo hilang.
    Invoice::query()->whereKey($terbit->Id)->toBase()->update(['TerkirimPada' => now()]);
    app(CatatPembayaranInvoice::class)->Jalankan($terbit->Uuid, BantuanPembelian::AkunKas('1-1200')->Uuid, CarbonImmutable::parse('2026-10-20'), null, Uang::Nol(), null, null, $this->k['Pemilik']->Id);
    expect($butir($hari)->keys()->all())->toBe(['invoice.draf-lama']);

    // Templat berulang yang gagal terbit muncul sebagai butir Penting.
    $templat = app(SimpanInvoiceBerulang::class)->Jalankan(new DataInvoiceBerulang(
        'Retainer',
        FrekuensiInvoiceBerulang::Bulanan,
        1,
        CarbonImmutable::parse('2026-10-20'),
        null,
        null,
        ModeInvoiceBerulang::Terbitkan,
        new DataInvoice(JenisInvoice::Penjualan, $this->klien->Uuid, $this->k['Outlet']->Id, CarbonImmutable::parse('2026-10-20'), null, [new DataBarisInvoice(null, 'Retainer', null, JenisBarisInvoice::Jasa, null, Kuantitas::Dari('1'), Uang::Dari('1000000'), Uang::Nol(), null)]),
    ), $this->k['Pemilik']->Id);
    InvoiceBerulang::query()->whereKey($templat->Id)->toBase()->update(['GalatTerakhir' => 'Pelanggan PT Sumber Makmur Sentosa sudah diarsipkan.', 'GalatPada' => now()]);
    expect($butir($hari)['invoice-berulang.gagal']->rincian[0]->judul)->toBe('Retainer');

    // Tanpa izin: tidak ada butir sama sekali.
    expect($penyedia->Kumpulkan($konteks($hari, false)))->toBe([]);
});
