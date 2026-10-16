<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Layanan\KodeInvoicePublik;
use App\Domain\Penjualan\Layanan\PenyediaTindakanInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\KonfirmasiPembayaranInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Konfirmasi pembayaran dari pelanggan (F-21 bagian 4, D-169): "Saya sudah bayar" di halaman publik invoice. Itu klaim yang
 * menunggu verifikasi, bukan pembayaran: tidak ada jurnal atau perubahan sisa sampai penagih menerimanya lewat pencatatan
 * pembayaran, yang menautkan klaim ke pelunasannya dalam transaksi yang sama.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->k = BantuanPersediaan::SiapkanTenant('Konsultan Cermat Mandiri');
    BantuanFitur::Beri($this->k['Tenant']->Id, 'invoice.penagihan');
    $this->klien = Pelanggan::query()->create(['Nama' => 'PT Mitra Andalan Sejati', 'NoHp' => '6281355550303', 'TerminHari' => 14]);
    $this->jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa audit internal', 'Jenis' => JenisProduk::Jasa], '10000000.00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Membuat dan menerbitkan invoice 10 juta lewat rute; mengembalikan modelnya. */
function InvoiceTerbitKonfirmasiUji($tes, string $harga = '10000000'): Invoice
{
    BantuanPersediaan::MasukSebagai($tes, $tes->k['Tenant']->Id);
    $tes->post('/kelola/invoice', [
        'Jenis' => 'Penjualan', 'UuidPelanggan' => $tes->klien->Uuid, 'UuidOutlet' => $tes->k['Outlet']->Uuid, 'Tanggal' => '2026-09-27',
        'HargaTermasukPajak' => false, 'Lanjutkan' => true,
        'Baris' => [['UuidProduk' => $tes->jasa->Uuid, 'Nama' => 'Audit internal', 'JenisBaris' => 'Jasa', 'Jumlah' => '1', 'Harga' => $harga]],
    ])->assertSessionHasNoErrors();

    return Invoice::query()->orderByDesc('Id')->firstOrFail();
}

function KodeKonfirmasiUji(Invoice $invoice): string
{
    return KodeInvoicePublik::Buat($invoice->IdTenant, $invoice->KodePublik);
}

/** @return array<string, mixed> */
function IsianKonfirmasiUji(array $ubah = []): array
{
    return array_replace(['Nama' => 'Budi Santoso', 'TanggalBayar' => '2026-09-27', 'Jumlah' => '10000000', 'Catatan' => 'Transfer BCA'], $ubah);
}

describe('Konfirmasi pembayaran: dari pelanggan', function (): void {
    it('klaim tercatat menunggu tanpa jurnal atau perubahan sisa; halaman publik menampilkannya tanpa nama pengirim', function (): void {
        $invoice = InvoiceTerbitKonfirmasiUji($this);
        $kode = KodeKonfirmasiUji($invoice);
        $jurnal = Jurnal::query()->count();
        Auth::logout();

        $this->get('/i/'.$kode)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('KodeInvoice', $kode)
            ->where('Dokumen.Konfirmasi.Bisa', true)
            ->where('Dokumen.Konfirmasi.Sisa', '10000000.00')
            ->where('Dokumen.Konfirmasi.HariIni', '2026-09-27')
            ->where('Dokumen.Konfirmasi.Menunggu', []));

        $this->post('/i/'.$kode.'/konfirmasi-bayar', IsianKonfirmasiUji())->assertSessionHasNoErrors()->assertSessionHas('Kilat');
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        $konfirmasi = KonfirmasiPembayaranInvoice::query()->sole();
        expect($konfirmasi->Status->value)->toBe('Menunggu')
            ->and($konfirmasi->NamaPengirim)->toBe('Budi Santoso')
            ->and($konfirmasi->Jumlah)->toBe('10000000.00')
            ->and($konfirmasi->TanggalBayar->toDateString())->toBe('2026-09-27')
            ->and($konfirmasi->Catatan)->toBe('Transfer BCA')
            ->and(Jurnal::query()->count())->toBe($jurnal)
            ->and(PembayaranPiutang::query()->count())->toBe(0)
            ->and(Piutang::query()->where('IdInvoice', $invoice->Id)->sole()->AmbilSisa()->KeString())->toBe('10000000.00');

        $this->get('/i/'.$kode)->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dokumen.Konfirmasi.Menunggu.0.Jumlah', '10000000.00')
            ->where('Dokumen.Konfirmasi.Menunggu.0.TanggalBayar', '2026-09-27')
            ->missing('Dokumen.Konfirmasi.Menunggu.0.NamaPengirim'));

        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        $audit = LogAudit::query()->where('Peristiwa', 'invoice.konfirmasi-bayar')->sole();
        expect($audit->IdPengguna)->toBeNull()->and(json_encode($audit->NilaiBaru))->not->toContain('Budi Santoso');
    });

    it('validasi: nama, jumlah melebihi sisa, tanggal masa depan atau terlalu lampau, dan format', function (): void {
        $this->withoutMiddleware(ThrottleRequests::class); // sembilan percobaan salah berturut-turut; throttle diuji terpisah
        $invoice = InvoiceTerbitKonfirmasiUji($this);
        $kode = KodeKonfirmasiUji($invoice);
        Auth::logout();
        $url = '/i/'.$kode.'/konfirmasi-bayar';

        $this->post($url, IsianKonfirmasiUji(['Nama' => '']))->assertSessionHasErrors('Nama');
        $this->post($url, IsianKonfirmasiUji(['Nama' => 'B']))->assertSessionHasErrors('Nama');
        $this->post($url, IsianKonfirmasiUji(['Jumlah' => '10000001']))->assertSessionHasErrors('Jumlah');
        $this->post($url, IsianKonfirmasiUji(['Jumlah' => '0']))->assertSessionHasErrors('Jumlah');
        $this->post($url, IsianKonfirmasiUji(['Jumlah' => 'sepuluh']))->assertSessionHasErrors('Jumlah');
        $this->post($url, IsianKonfirmasiUji(['TanggalBayar' => '2026-09-28']))->assertSessionHasErrors('TanggalBayar');
        $this->post($url, IsianKonfirmasiUji(['TanggalBayar' => '2026-07-01']))->assertSessionHasErrors('TanggalBayar');
        $this->post($url, IsianKonfirmasiUji(['TanggalBayar' => 'kemarin']))->assertSessionHasErrors('TanggalBayar');
        $this->post($url, IsianKonfirmasiUji(['Catatan' => str_repeat('x', 301)]))->assertSessionHasErrors('Catatan');
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        expect(KonfirmasiPembayaranInvoice::query()->count())->toBe(0);
    });

    it('klaim identik tidak digandakan; paling banyak tiga klaim menunggu per invoice', function (): void {
        $invoice = InvoiceTerbitKonfirmasiUji($this);
        $kode = KodeKonfirmasiUji($invoice);
        Auth::logout();
        $url = '/i/'.$kode.'/konfirmasi-bayar';

        $this->post($url, IsianKonfirmasiUji(['Jumlah' => '1000000']))->assertSessionHasNoErrors();
        $this->post($url, IsianKonfirmasiUji(['Jumlah' => '1000000']))->assertSessionHasNoErrors();
        $this->post($url, IsianKonfirmasiUji(['Jumlah' => '2000000']))->assertSessionHasNoErrors();
        $this->post($url, IsianKonfirmasiUji(['Jumlah' => '3000000']))->assertSessionHasNoErrors();
        $this->post($url, IsianKonfirmasiUji(['Jumlah' => '4000000']))->assertSessionHasErrors('Umum');
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        expect(KonfirmasiPembayaranInvoice::query()->count())->toBe(3);
    });

    it('draf, kode salah, tenant lain, dan invoice yang sudah lunas atau dibatalkan tidak menerima klaim', function (): void {
        $invoice = InvoiceTerbitKonfirmasiUji($this);
        $kode = KodeKonfirmasiUji($invoice);
        $this->post('/kelola/invoice', [
            'Jenis' => 'Penjualan', 'UuidPelanggan' => $this->klien->Uuid, 'UuidOutlet' => $this->k['Outlet']->Uuid, 'Tanggal' => '2026-09-27',
            'HargaTermasukPajak' => false, 'Baris' => [['Nama' => 'Draf', 'JenisBaris' => 'Jasa', 'Jumlah' => '1', 'Harga' => '1000000']],
        ])->assertSessionHasNoErrors();
        $draf = Invoice::query()->where('Status', 'Draf')->sole();
        $lain = BantuanPersediaan::SiapkanTenant('Konsultan Lain Mandiri');

        Auth::logout();
        $this->post('/i/'.KodeKonfirmasiUji($draf).'/konfirmasi-bayar', IsianKonfirmasiUji())->assertNotFound();
        $this->post('/i/tidak-valid/konfirmasi-bayar', IsianKonfirmasiUji())->assertNotFound();
        $this->post('/i/'.KodeInvoicePublik::Buat($lain['Tenant']->Id, $invoice->KodePublik).'/konfirmasi-bayar', IsianKonfirmasiUji())->assertNotFound();

        // Sudah lunas: formulir tidak ditawarkan dan klaim ditolak.
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", ['UuidAkun' => BantuanPembelian::AkunKas('1-1200')->Uuid, 'Tanggal' => '2026-09-27'])->assertSessionHasNoErrors();
        Auth::logout();
        $this->get('/i/'.$kode)->assertInertia(fn (AssertableInertia $h) => $h->where('Dokumen.Konfirmasi.Bisa', false)->where('Dokumen.Konfirmasi.Sisa', null));
        $this->post('/i/'.$kode.'/konfirmasi-bayar', IsianKonfirmasiUji())->assertSessionHasErrors('Umum');

        // Dibatalkan (setelah pembayarannya dibatalkan) juga tidak menerima klaim.
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        $baru = Invoice::query()->where('Status', 'Terbit')->where('Id', '!=', $invoice->Id)->first();
        expect($baru)->toBeNull()->and(KonfirmasiPembayaranInvoice::query()->count())->toBe(0);
    });

    it('formulir lama tanpa CSRF atau terlalu sering dibatasi throttle 5 per menit', function (): void {
        $invoice = InvoiceTerbitKonfirmasiUji($this);
        $kode = KodeKonfirmasiUji($invoice);
        Auth::logout();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/i/'.$kode.'/konfirmasi-bayar', IsianKonfirmasiUji(['Nama' => 'x']))->assertStatus(302);
        }

        $this->post('/i/'.$kode.'/konfirmasi-bayar', IsianKonfirmasiUji())->assertStatus(429);
    });
});

describe('Konfirmasi pembayaran: verifikasi penagih', function (): void {
    it('detail menampilkan klaim; menerima lewat pencatatan pembayaran menautkan klaim ke pelunasan', function (): void {
        $invoice = InvoiceTerbitKonfirmasiUji($this);
        $kode = KodeKonfirmasiUji($invoice);
        Auth::logout();
        $this->post('/i/'.$kode.'/konfirmasi-bayar', IsianKonfirmasiUji(['Jumlah' => '4000000']))->assertSessionHasNoErrors();
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $penagih = Auth::id();
        $konfirmasi = KonfirmasiPembayaranInvoice::query()->sole();

        $this->get("/kelola/invoice/{$invoice->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Konfirmasi.0.Uuid', $konfirmasi->Uuid)
            ->where('Konfirmasi.0.NamaPengirim', 'Budi Santoso')
            ->where('Konfirmasi.0.Jumlah', '4000000.00')
            ->where('Konfirmasi.0.Status', 'Menunggu')
            ->where('Konfirmasi.0.BisaDiputuskan', true)
            ->where('Konfirmasi.0.TidakDiperlukan', false));

        $this->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", [
            'UuidAkun' => BantuanPembelian::AkunKas('1-1200')->Uuid, 'Tanggal' => '2026-09-27', 'Jumlah' => '4000000', 'UuidKonfirmasi' => $konfirmasi->Uuid,
        ])->assertSessionHasNoErrors();
        $konfirmasi->refresh();
        expect($konfirmasi->Status->value)->toBe('Diterima')
            ->and($konfirmasi->DiputuskanOleh)->toBe($penagih)
            ->and($konfirmasi->DiputuskanPada)->not->toBeNull()
            ->and($konfirmasi->IdPembayaranPiutang)->toBe(PembayaranPiutang::query()->sole()->Id)
            ->and(Piutang::query()->where('IdInvoice', $invoice->Id)->sole()->AmbilSisa()->KeString())->toBe('6000000.00')
            ->and(LogAudit::query()->where('Peristiwa', 'invoice.konfirmasi-terima')->count())->toBe(1);

        $this->get("/kelola/invoice/{$invoice->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Konfirmasi.0.Status', 'Diterima')
            ->where('Konfirmasi.0.BisaDiputuskan', false));
    });

    it('pembayaran yang gagal tidak menandai klaim; klaim milik invoice lain atau sudah diputuskan ditolak', function (): void {
        $invoice = InvoiceTerbitKonfirmasiUji($this);
        $lain = InvoiceTerbitKonfirmasiUji($this);
        Auth::logout();
        $this->post('/i/'.KodeKonfirmasiUji($invoice).'/konfirmasi-bayar', IsianKonfirmasiUji())->assertSessionHasNoErrors();
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $konfirmasi = KonfirmasiPembayaranInvoice::query()->sole();
        $akun = BantuanPembelian::AkunKas('1-1200')->Uuid;

        // Jumlah melebihi sisa: pelunasan gagal, klaim tetap menunggu (satu transaksi).
        $this->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", ['UuidAkun' => $akun, 'Tanggal' => '2026-09-27', 'Jumlah' => '99999999', 'UuidKonfirmasi' => $konfirmasi->Uuid])->assertSessionHasErrors();
        expect($konfirmasi->refresh()->Status->value)->toBe('Menunggu')->and(PembayaranPiutang::query()->count())->toBe(0);

        // Klaim milik invoice lain tidak bisa dipakai.
        $this->post("/kelola/invoice/{$lain->Uuid}/pembayaran", ['UuidAkun' => $akun, 'Tanggal' => '2026-09-27', 'UuidKonfirmasi' => $konfirmasi->Uuid])->assertSessionHasErrors('Umum');
        expect(PembayaranPiutang::query()->count())->toBe(0);

        $this->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", ['UuidAkun' => $akun, 'Tanggal' => '2026-09-27', 'UuidKonfirmasi' => $konfirmasi->Uuid])->assertSessionHasNoErrors();
        // Klaim yang sama tidak bisa dipakai dua kali.
        $this->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", ['UuidAkun' => $akun, 'Tanggal' => '2026-09-27', 'UuidKonfirmasi' => $konfirmasi->Uuid])->assertSessionHasErrors();
        $this->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", ['UuidAkun' => $akun, 'Tanggal' => '2026-09-27', 'UuidKonfirmasi' => 'bukan-ulid'])->assertSessionHasErrors('UuidKonfirmasi');
    });

    it('menolak klaim: alasan wajib, idempoten, tidak bisa menolak yang sudah diterima; invoice tetap terbuka', function (): void {
        $invoice = InvoiceTerbitKonfirmasiUji($this);
        Auth::logout();
        $this->post('/i/'.KodeKonfirmasiUji($invoice).'/konfirmasi-bayar', IsianKonfirmasiUji(['Jumlah' => '2000000']))->assertSessionHasNoErrors();
        $this->post('/i/'.KodeKonfirmasiUji($invoice).'/konfirmasi-bayar', IsianKonfirmasiUji(['Jumlah' => '3000000']))->assertSessionHasNoErrors();
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $penagih = Auth::id();
        [$pertama, $kedua] = KonfirmasiPembayaranInvoice::query()->orderBy('Id')->get()->all();
        $tolak = fn (KonfirmasiPembayaranInvoice $k, string $alasan) => $this->post("/kelola/invoice/{$invoice->Uuid}/konfirmasi/{$k->Uuid}/tolak", ['Alasan' => $alasan]);

        $tolak($pertama, 'x')->assertSessionHasErrors('Alasan');
        $tolak($pertama, 'Uang tidak ada di mutasi bank')->assertSessionHasNoErrors();
        $pertama->refresh();
        expect($pertama->Status->value)->toBe('Ditolak')->and($pertama->AlasanTolak)->toBe('Uang tidak ada di mutasi bank')->and($pertama->DiputuskanOleh)->toBe($penagih);
        $tolak($pertama, 'Uang tidak ada di mutasi bank')->assertSessionHasNoErrors();

        $this->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", ['UuidAkun' => BantuanPembelian::AkunKas('1-1200')->Uuid, 'Tanggal' => '2026-09-27', 'Jumlah' => '3000000', 'UuidKonfirmasi' => $kedua->Uuid])->assertSessionHasNoErrors();
        $tolak($kedua, 'Salah catat sebelumnya')->assertSessionHasErrors('Umum');
        expect($kedua->refresh()->Status->value)->toBe('Diterima')
            ->and(LogAudit::query()->where('Peristiwa', 'invoice.konfirmasi-tolak')->count())->toBe(1);

        // Klaim ditolak ditolak lagi oleh pelanggan? Pelanggan boleh mengirim klaim baru setelahnya.
        $this->get('/kelola/invoice/'.strtoupper((string) Str::ulid()).'/konfirmasi/'.strtoupper((string) Str::ulid()).'/tolak')->assertStatus(405);
        $this->post("/kelola/invoice/{$invoice->Uuid}/konfirmasi/".strtoupper((string) Str::ulid()).'/tolak', ['Alasan' => 'Tidak ada klaim ini'])->assertSessionHasErrors('Umum');
    });

    it('klaim menunggu pada invoice yang kemudian lunas ditandai tidak diperlukan lagi dan tidak bisa diputuskan', function (): void {
        $invoice = InvoiceTerbitKonfirmasiUji($this);
        Auth::logout();
        $this->post('/i/'.KodeKonfirmasiUji($invoice).'/konfirmasi-bayar', IsianKonfirmasiUji())->assertSessionHasNoErrors();
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", ['UuidAkun' => BantuanPembelian::AkunKas('1-1200')->Uuid, 'Tanggal' => '2026-09-27'])->assertSessionHasNoErrors();

        $this->get("/kelola/invoice/{$invoice->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Konfirmasi.0.Status', 'Menunggu')
            ->where('Konfirmasi.0.BisaDiputuskan', false)
            ->where('Konfirmasi.0.TidakDiperlukan', true));
    });

    it('Kotak Tindakan: klaim menunggu pada invoice bersisa muncul sebagai Penting dan hilang bila diputuskan atau invoice lunas', function (): void {
        $penyedia = app(PenyediaTindakanInvoice::class);
        $konteks = fn (bool $izin = true) => new DataKonteksTindakan($this->k['Tenant']->Id, $this->k['Pemilik']->Id, false, $izin ? ['invoice.kelola'] : [], null, CarbonImmutable::parse('2026-09-27'));
        $butir = fn () => collect($penyedia->Kumpulkan($konteks()))->filter(fn ($b) => $b->jumlah > 0)->keyBy('kunci');

        $invoice = InvoiceTerbitKonfirmasiUji($this);
        expect($butir()->has('invoice.konfirmasi-bayar'))->toBeFalse();
        Auth::logout();
        $this->post('/i/'.KodeKonfirmasiUji($invoice).'/konfirmasi-bayar', IsianKonfirmasiUji(['Jumlah' => '2500000']))->assertSessionHasNoErrors();
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $item = $butir()['invoice.konfirmasi-bayar'];
        expect($item->jumlah)->toBe(1)
            ->and($item->tingkat->name)->toBe('Penting')
            ->and($item->rincian[0]->judul)->toBe('Budi Santoso')
            ->and($item->rincian[0]->tautan)->toBe('/kelola/invoice/'.$invoice->Uuid)
            ->and($item->rincian[0]->keterangan)->toContain('2.500.000');
        expect($penyedia->Kumpulkan($konteks(false)))->toBe([]);

        $this->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", ['UuidAkun' => BantuanPembelian::AkunKas('1-1200')->Uuid, 'Tanggal' => '2026-09-27'])->assertSessionHasNoErrors();
        expect($butir()->has('invoice.konfirmasi-bayar'))->toBeFalse();
    });

    it('kasir tanpa izin invoice.kelola tidak bisa menolak; isi klaim tidak bisa diubah dan klaim tidak bisa dihapus di model', function (): void {
        $invoice = InvoiceTerbitKonfirmasiUji($this);
        Auth::logout();
        $this->post('/i/'.KodeKonfirmasiUji($invoice).'/konfirmasi-bayar', IsianKonfirmasiUji())->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        $konfirmasi = KonfirmasiPembayaranInvoice::query()->sole();

        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->post("/kelola/invoice/{$invoice->Uuid}/konfirmasi/{$konfirmasi->Uuid}/tolak", ['Alasan' => 'Tidak berwenang'])->assertForbidden();

        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        $konfirmasi->refresh();
        expect($konfirmasi->Status->value)->toBe('Menunggu');
        $konfirmasi->Jumlah = '1.00';
        expect(fn () => $konfirmasi->save())->toThrow(LogicException::class);
        expect(fn () => $konfirmasi->delete())->toThrow(LogicException::class);
    });
});
