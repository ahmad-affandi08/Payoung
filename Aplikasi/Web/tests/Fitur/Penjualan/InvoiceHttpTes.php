<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Penjualan\Enum\StatusPengirimanInvoice;
use App\Domain\Penjualan\Layanan\KodeInvoicePublik;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\InvoiceBerulang;
use App\Domain\Penjualan\Model\PengirimanInvoice;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Back-office invoice lewat rute (routes/Invoice.php, F-21 D-166): alur lengkap draf → terbit → bayar (dengan PPh) → cetak,
 * halaman publik `/i/{kode}` (kode rahasia, hitung dilihat, 404 untuk draf/salah), kirim WhatsApp, izin `invoice.kelola`,
 * dan isolasi tenant. Angka tidak datang dari klien: total & pajak dihitung server dari baris.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9201']])]);
    $this->k = BantuanPersediaan::SiapkanTenant('Konsultan Maju Bersama');
    BantuanFitur::Beri($this->k['Tenant']->Id, 'invoice.penagihan');
    OverrideTenant::query()->create([
        'IdTenant' => $this->k['Tenant']->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => PemeriksaFiturTenant::KUNCI_WHATSAPP,
        'BerakhirPada' => now()->addDays(90),
        'Alasan' => 'Uji kirim invoice lewat WhatsApp',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    $this->klien = Pelanggan::query()->create(['Nama' => 'PT Sumber Makmur Sentosa', 'NoHp' => '6281355550101', 'TerminHari' => 14, 'Alamat' => 'Jl. Merdeka No. 10, Surakarta']);
    $this->jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa konsultasi manajemen per bulan', 'Jenis' => JenisProduk::Jasa], '5000000.00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array<string, mixed> */
function IsianInvoiceHttp($tes, array $ubah = []): array
{
    return array_replace([
        'Jenis' => 'Penjualan',
        'UuidPelanggan' => $tes->klien->Uuid,
        'UuidOutlet' => $tes->k['Outlet']->Uuid,
        'Tanggal' => '2026-09-27',
        'TerminHari' => null,
        'Perihal' => 'Jasa konsultasi September 2026',
        'HargaTermasukPajak' => false,
        'Baris' => [[
            'UuidProduk' => $tes->jasa->Uuid,
            'Nama' => 'Konsultasi manajemen',
            'JenisBaris' => 'Jasa',
            'SimbolSatuan' => 'bln',
            'Jumlah' => '1',
            'Harga' => '10000000',
        ]],
    ], $ubah);
}

describe('HTTP back-office invoice', function (): void {
    it('alur lengkap lewat rute: form, draf, terbit, daftar, cetak, bayar dengan PPh, dan pengiriman WhatsApp', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->get('/kelola/invoice/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Invoice/Form')
            ->where('Jenis', 'Penjualan')
            ->where('Isian', null)
            ->has('OpsiOutlet')
            ->has('OpsiKelompokPajak'));

        // Angka tidak dikirim klien; hanya baris. Simpan & terbitkan memakai `Lanjutkan`.
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['KodePph' => 'PPh 23', 'TarifPph' => '2', 'Lanjutkan' => true]))->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();
        expect($invoice->Status->value)->toBe('Terbit')
            ->and($invoice->Nomor)->toStartWith('INV/')
            ->and($invoice->Total)->toBe('10000000.00')
            ->and($invoice->NilaiPph)->toBe('200000.00')
            ->and($invoice->JatuhTempo->toDateString())->toBe('2026-10-11');

        $this->get("/kelola/invoice/{$invoice->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Invoice/Detail')
            ->where('Invoice.Nomor', $invoice->Nomor)
            ->where('Invoice.StatusBayar', 'BelumDibayar')
            ->where('Invoice.Sisa', '10000000.00')
            ->where('Tindakan.CatatPembayaran', true)
            ->where('Tindakan.Kirim', true)
            ->where('Tindakan.Ubah', false)
            ->has('OpsiAkun'));

        $this->getJson('/kelola/invoice')->assertOk()
            ->assertJsonPath('Data.0.Uuid', $invoice->Uuid)
            ->assertJsonPath('Data.0.StatusBayar', 'BelumDibayar')
            ->assertJsonPath('Data.0.NamaPelanggan', 'PT Sumber Makmur Sentosa');
        $this->getJson('/kelola/invoice?saring[Pembayaran]=Lunas')->assertOk()->assertJsonCount(0, 'Data');
        $this->getJson('/kelola/invoice?saring[Pembayaran]=Terbuka')->assertOk()->assertJsonCount(1, 'Data');
        $this->getJson('/kelola/invoice?cari=konsultasi')->assertOk()->assertJsonCount(1, 'Data');

        $this->get("/kelola/invoice/{$invoice->Uuid}/cetak")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Invoice/Cetak')
            ->where('Invoice.PphDipotongPembeli', '200000.00')
            ->where('Pembeli.Nama', 'PT Sumber Makmur Sentosa')
            ->where('Baris.0.Nama', 'Konsultasi manajemen')
            ->missing('Jurnal'));

        // Bayar penuh: uang masuk = tagihan − PPh yang dipotong pelanggan.
        $this->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", [
            'UuidAkun' => BantuanPembelian::AkunKas('1-1200')->Uuid,
            'Tanggal' => '2026-09-27',
            'PotonganPph' => '200000',
            'NomorBuktiPotong' => '0001-BP23-2026',
        ])->assertSessionHasNoErrors();
        $this->getJson('/kelola/invoice')->assertJsonPath('Data.0.StatusBayar', 'Lunas');
        $this->get("/kelola/invoice/{$invoice->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Invoice.StatusBayar', 'Lunas')
            ->where('Pelunasan.0.PotonganPph', '200000.00')
            ->where('Tindakan.CatatPembayaran', false));

        $this->post("/kelola/invoice/{$invoice->Uuid}/kirim", ['Jenis' => 'Invoice'])->assertSessionHasNoErrors();
        $pengiriman = PengirimanInvoice::query()->sole();
        expect($pengiriman->Status)->toBe(StatusPengirimanInvoice::Terkirim)
            ->and($pengiriman->Tujuan)->toBe('6281355550101');
        $teks = (string) collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'api.fonnte.com/send'))->sole()->data()['message'];
        expect($teks)->toContain('Halo PT Sumber Makmur Sentosa')
            ->and($teks)->toContain($invoice->Nomor)
            ->and($teks)->toContain('/i/'.KodeInvoicePublik::Buat($invoice->IdTenant, $invoice->KodePublik));
        expect(json_encode(LogAudit::query()->where('Peristiwa', 'invoice.kirim')->sole()->NilaiBaru))->not->toContain('6281355550101');
        expect($invoice->refresh()->TerkirimPada)->not->toBeNull();
    });

    it('draf bisa diubah lalu diterbitkan dari detail. Angka tidak bisa dikarang klien. Setelah terbit tidak bisa diubah', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Total' => '1.00', 'Tagihan' => '1.00']))->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();
        expect($invoice->Total)->toBe('10000000.00')->and($invoice->Nomor)->toBeNull();

        $this->get("/kelola/invoice/{$invoice->Uuid}/ubah")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Invoice/Form')
            ->where('Isian.Uuid', $invoice->Uuid)
            ->where('Isian.Perihal', 'Jasa konsultasi September 2026')
            ->where('Isian.Baris.0.Nama', 'Konsultasi manajemen'));

        $this->put("/kelola/invoice/{$invoice->Uuid}", IsianInvoiceHttp($this, ['Perihal' => 'Revisi', 'Baris' => [[
            'Nama' => 'Retainer bulanan', 'JenisBaris' => 'Jasa', 'Jumlah' => '2', 'Harga' => '1500000',
        ]]]))->assertSessionHasNoErrors();
        expect($invoice->refresh()->Total)->toBe('3000000.00')->and($invoice->Perihal)->toBe('Revisi');

        $this->post("/kelola/invoice/{$invoice->Uuid}/terbitkan")->assertSessionHasNoErrors();
        expect($invoice->refresh()->Status->value)->toBe('Terbit');

        $this->put("/kelola/invoice/{$invoice->Uuid}", IsianInvoiceHttp($this))->assertSessionHasErrors('Umum');
        $this->get("/kelola/invoice/{$invoice->Uuid}/ubah")->assertRedirect("/kelola/invoice/{$invoice->Uuid}");
        expect($invoice->refresh()->Total)->toBe('3000000.00');
    });

    it('pembatalan lewat rute meminta alasan. Invoice tidak dikenal 404', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();

        $this->post("/kelola/invoice/{$invoice->Uuid}/batalkan", ['Alasan' => 'x'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/invoice/{$invoice->Uuid}/batalkan", ['Alasan' => 'Salah pelanggan'])->assertSessionHasNoErrors();
        expect($invoice->refresh()->Status->value)->toBe('Dibatalkan');

        $this->get('/kelola/invoice/'.strtoupper((string) Str::ulid()))->assertNotFound();
    });

    it('pencarian jasa hanya mengembalikan jasa/tanpa stok beserta harga katalog. Pelanggan dicari lewat dropdown', function (): void {
        BantuanKatalog::BuatProduk(['Nama' => 'Beras Pulen 5 kg'], '70000.00');
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->getJson('/kelola/invoice/produk/cari?kata=')->assertOk()
            ->assertJsonCount(1, 'Data')
            ->assertJsonPath('Data.0.Nama', 'Jasa konsultasi manajemen per bulan')
            ->assertJsonPath('Data.0.Harga', '5000000.00');
        $this->getJson('/kelola/invoice/produk/cari?kata=beras')->assertOk()->assertJsonCount(0, 'Data');
        $this->getJson('/kelola/invoice/pelanggan/cari?kata=sumber')->assertOk()->assertJsonCount(1, 'Data')->assertJsonPath('Data.0.Uuid', $this->klien->Uuid);
    });

    it('uang muka tersedia untuk pelanggan: hanya DP terbit yang belum dipotong', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Jenis' => 'UangMuka', 'Lanjutkan' => true, 'Baris' => [['Nama' => 'DP proyek', 'JenisBaris' => 'Jasa', 'Jumlah' => '1', 'Harga' => '2000000']]]))->assertSessionHasNoErrors();
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Jenis' => 'UangMuka', 'Baris' => [['Nama' => 'DP draf', 'JenisBaris' => 'Jasa', 'Jumlah' => '1', 'Harga' => '1000000']]]))->assertSessionHasNoErrors();

        $this->getJson("/kelola/invoice/uang-muka?pelanggan={$this->klien->Uuid}")->assertOk()
            ->assertJsonCount(1, 'Data')
            ->assertJsonPath('Data.0.Total', '2000000.00');
        $this->getJson('/kelola/invoice/uang-muka?pelanggan=tidak-ada')->assertOk()->assertJsonCount(0, 'Data');
    });

    it('tanpa izin invoice.kelola ditolak. Fitur invoice.penagihan harus ada di paket untuk aksi tulis', function (): void {
        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/invoice')->assertForbidden();
        $this->post('/kelola/invoice', IsianInvoiceHttp($this))->assertForbidden();
        expect(Invoice::query()->count())->toBe(0);
    });

    it('dokumen tenant lain tidak terlihat (404) dan tidak bisa dibuka lewat halaman publik tenant lain', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();
        $kode = KodeInvoicePublik::Buat($invoice->IdTenant, $invoice->KodePublik);

        $lain = BantuanPersediaan::SiapkanTenant('Konsultan Lain Jaya');
        BantuanFitur::Beri($lain['Tenant']->Id, 'invoice.penagihan');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->get("/kelola/invoice/{$invoice->Uuid}")->assertNotFound();
        $this->getJson('/kelola/invoice')->assertOk()->assertJsonCount(0, 'Data');

        // Kode yang menyebut tenant lain dengan rahasia yang sama tidak membuka apa pun.
        Auth::logout();
        $salah = KodeInvoicePublik::Buat($lain['Tenant']->Id, $invoice->KodePublik);
        $this->get('/i/'.$salah)->assertNotFound();
        expect($kode)->not->toBe($salah);
    });
});

describe('HTTP aksi massal invoice', function (): void {
    it('menerbitkan draf, mengirim, dan mengingatkan banyak invoice sekaligus, yang tidak bisa dilewati dengan alasannya', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/invoice', IsianInvoiceHttp($this))->assertSessionHasNoErrors();
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Perihal' => 'Jasa kedua']))->assertSessionHasNoErrors();
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Perihal' => 'Jasa ketiga', 'Lanjutkan' => true]))->assertSessionHasNoErrors();
        $uuid = Invoice::query()->orderBy('Id')->pluck('Uuid')->all();
        expect($uuid)->toHaveCount(3);

        // Dua draf diterbitkan; yang ketiga sudah terbit sehingga dilewati.
        $this->post('/kelola/invoice/massal/terbitkan', ['Uuid' => $uuid])->assertSessionHasNoErrors()->assertSessionHas('Kilat');
        expect(Invoice::query()->where('Status', 'Terbit')->count())->toBe(3);

        $this->post('/kelola/invoice/massal/terbitkan', ['Uuid' => $uuid])->assertSessionHasErrors('Umum');

        $this->post('/kelola/invoice/massal/kirim', ['Uuid' => $uuid])->assertSessionHasNoErrors();
        expect(PengirimanInvoice::query()->where('Status', StatusPengirimanInvoice::Terkirim)->count())->toBe(3);
        // Kirim ulang langsung: semua kena jeda 10 menit, tidak ada yang diproses.
        $this->post('/kelola/invoice/massal/kirim', ['Uuid' => $uuid])->assertSessionHasErrors('Umum');

        $this->post('/kelola/invoice/massal/ingatkan', ['Uuid' => $uuid])->assertSessionHasNoErrors();
        expect(PengirimanInvoice::query()->where('Jenis', 'Pengingat')->count())->toBe(3);

        $this->post('/kelola/invoice/massal/tidak-dikenal', ['Uuid' => $uuid])->assertNotFound();
        $this->post('/kelola/invoice/massal/kirim', ['Uuid' => []])->assertSessionHasErrors('Uuid');
        $this->post('/kelola/invoice/massal/kirim', ['Uuid' => [strtoupper((string) Str::ulid())]])->assertSessionHasErrors('Uuid');
    });

    it('invoice tenant lain tidak ikut diproses dan kasir ditolak', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/invoice', IsianInvoiceHttp($this))->assertSessionHasNoErrors();
        $uuid = Invoice::query()->sole()->Uuid;

        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->post('/kelola/invoice/massal/terbitkan', ['Uuid' => [$uuid]])->assertForbidden();
        expect(Invoice::query()->sole()->Status->value)->toBe('Draf');

        $lain = BantuanPersediaan::SiapkanTenant('Konsultan Lain Jaya');
        BantuanFitur::Beri($lain['Tenant']->Id, 'invoice.penagihan');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->post('/kelola/invoice/massal/terbitkan', ['Uuid' => [$uuid]])->assertSessionHasErrors('Uuid');
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        expect(Invoice::query()->sole()->Status->value)->toBe('Draf');
    });
});

describe('HTTP ringkasan penagihan dan ekspor', function (): void {
    it('Beranda dan daftar menampilkan ditagih, diterima, beredar, dan lewat jatuh tempo. Kosong bila belum ada invoice', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->get('/kelola/invoice')->assertInertia(fn (AssertableInertia $h) => $h->where('Ringkasan', null));
        $this->get('/kelola')->assertInertia(fn (AssertableInertia $h) => $h->where('Penagihan', null));

        // Dua invoice terbit: satu dibayar penuh dengan PPh, satu lewat jatuh tempo (termin 0 dan tanggal lampau).
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Lanjutkan' => true, 'KodePph' => 'PPh 23', 'TarifPph' => '2']))->assertSessionHasNoErrors();
        $dibayar = Invoice::query()->sole();
        $this->post("/kelola/invoice/{$dibayar->Uuid}/pembayaran", [
            'UuidAkun' => BantuanPembelian::AkunKas('1-1200')->Uuid,
            'Tanggal' => '2026-09-27',
            'PotonganPph' => '200000',
        ])->assertSessionHasNoErrors();
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Lanjutkan' => true, 'Tanggal' => '2026-09-01', 'TerminHari' => 7, 'Baris' => [['Nama' => 'Retainer', 'JenisBaris' => 'Jasa', 'Jumlah' => '1', 'Harga' => '3000000']]]))->assertSessionHasNoErrors();
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Perihal' => 'Draf baru']))->assertSessionHasNoErrors();

        $this->get('/kelola/invoice')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Ringkasan.Dari', '2026-09-01')
            ->where('Ringkasan.Sampai', '2026-09-30')
            ->where('Ringkasan.Ditagih', '13000000.00')
            ->where('Ringkasan.Diterima', '10000000.00')
            ->where('Ringkasan.Beredar.Jumlah', 1)
            ->where('Ringkasan.Beredar.Sisa', '3000000.00')
            ->where('Ringkasan.LewatTempo.Jumlah', 1)
            ->where('Ringkasan.LewatTempo.Sisa', '3000000.00')
            ->where('Ringkasan.Draf', 1));
        $this->get('/kelola')->assertInertia(fn (AssertableInertia $h) => $h->where('Penagihan.Ditagih', '13000000.00'));
    });

    it('ekspor CSV mengikuti filter tabel dan memuat angka yang sama dengan layar', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Perihal' => 'Draf rahasia']))->assertSessionHasNoErrors();
        $nomor = Invoice::query()->where('Status', 'Terbit')->sole()->Nomor;

        $csv = $this->get('/kelola/invoice/ekspor?format=csv')->assertOk()->streamedContent();
        expect($csv)->toContain('Nomor')->toContain((string) $nomor)->toContain('PT Sumber Makmur Sentosa')->toContain('10000000');

        $terbit = $this->get('/kelola/invoice/ekspor?format=csv&saring[Status]=Terbit')->assertOk()->streamedContent();
        expect($terbit)->toContain((string) $nomor)->not->toContain('Draf rahasia');

        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/invoice/ekspor?format=csv')->assertForbidden();
    });
});

describe('HTTP invoice berulang', function (): void {
    /** @return array<string, mixed> */
    function IsianBerulangHttp($tes, array $ubah = []): array
    {
        return array_replace([
            ...IsianInvoiceHttp($tes),
            'Nama' => 'Hosting PT Sumber Makmur',
            'Frekuensi' => 'Bulanan',
            'Interval' => 1,
            'TanggalMulai' => '2026-09-27',
            'Mode' => 'Terbitkan',
            'Perihal' => 'Maintenance {periode}',
        ], $ubah);
    }

    it('alur lengkap lewat rute: buat, daftar, detail, ubah, jeda, lanjut, hentikan', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->get('/kelola/invoice/berulang/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Invoice/Berulang/Form')
            ->where('Isian', null)
            ->has('OpsiFrekuensi', 3)
            ->has('OpsiMode', 3));

        $this->post('/kelola/invoice/berulang', IsianBerulangHttp($this))->assertSessionHasNoErrors();
        $templat = InvoiceBerulang::query()->sole();
        expect($templat->Status->value)->toBe('Aktif')->and($templat->BerikutnyaPada?->toDateString())->toBe('2026-09-27');

        $this->getJson('/kelola/invoice/berulang')->assertOk()
            ->assertJsonPath('Data.0.Nama', 'Hosting PT Sumber Makmur')
            ->assertJsonPath('Data.0.Jadwal', 'Setiap bulan')
            ->assertJsonPath('Data.0.LabelStatus', 'Aktif');
        $this->getJson('/kelola/invoice/berulang?saring[Status]=Dijeda')->assertOk()->assertJsonCount(0, 'Data');

        $this->get("/kelola/invoice/berulang/{$templat->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Invoice/Berulang/Detail')
            ->where('Templat.Nama', 'Hosting PT Sumber Makmur')
            ->where('Tindakan.Jeda', true)
            ->where('Tindakan.Lanjut', false)
            ->where('Baris.0.Nama', 'Konsultasi manajemen')
            ->has('InvoiceTerbit', 0));

        $this->get("/kelola/invoice/berulang/{$templat->Uuid}/ubah")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Invoice/Berulang/Form')
            ->where('Isian.Nama', 'Hosting PT Sumber Makmur')
            ->where('Isian.JadwalTerkunci', false)
            ->where('Isian.Baris.0.Harga', '10000000.00'));
        $this->put("/kelola/invoice/berulang/{$templat->Uuid}", IsianBerulangHttp($this, ['Nama' => 'Hosting (revisi)', 'Mode' => 'BuatDraf']))->assertSessionHasNoErrors();
        expect($templat->refresh()->Nama)->toBe('Hosting (revisi)')->and($templat->Mode->value)->toBe('BuatDraf');

        $this->post("/kelola/invoice/berulang/{$templat->Uuid}/jeda")->assertSessionHasNoErrors();
        expect($templat->refresh()->Status->value)->toBe('Dijeda');
        $this->get("/kelola/invoice/berulang/{$templat->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h->where('Tindakan.Lanjut', true)->where('Tindakan.Jeda', false));
        $this->post("/kelola/invoice/berulang/{$templat->Uuid}/lanjut")->assertSessionHasNoErrors();
        expect($templat->refresh()->Status->value)->toBe('Aktif');

        $this->post("/kelola/invoice/berulang/{$templat->Uuid}/hentikan")->assertSessionHasNoErrors();
        expect($templat->refresh()->Status->value)->toBe('Selesai');
        $this->get("/kelola/invoice/berulang/{$templat->Uuid}/ubah")->assertRedirect("/kelola/invoice/berulang/{$templat->Uuid}");
        $this->post("/kelola/invoice/berulang/{$templat->Uuid}/lanjut")->assertSessionHasErrors('Umum');
    });

    it('menolak isian tidak valid dan barang berstok', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->post('/kelola/invoice/berulang', IsianBerulangHttp($this, ['Nama' => '']))->assertSessionHasErrors('Nama');
        $this->post('/kelola/invoice/berulang', IsianBerulangHttp($this, ['Frekuensi' => 'Harian']))->assertSessionHasErrors('Frekuensi');
        $this->post('/kelola/invoice/berulang', IsianBerulangHttp($this, ['Interval' => 0]))->assertSessionHasErrors('Interval');
        $this->post('/kelola/invoice/berulang', IsianBerulangHttp($this, ['TanggalMulai' => '2026-09-01']))->assertSessionHasErrors('TanggalMulai');
        $this->post('/kelola/invoice/berulang', IsianBerulangHttp($this, ['TanggalBerakhir' => '2026-09-01']))->assertSessionHasErrors('TanggalBerakhir');
        $this->post('/kelola/invoice/berulang', IsianBerulangHttp($this, ['Baris' => []]))->assertSessionHasErrors('Baris');

        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Pulen 5 kg'], '70000.00');
        $this->post('/kelola/invoice/berulang', IsianBerulangHttp($this, ['Baris' => [['UuidProduk' => $beras->Uuid, 'Nama' => 'Beras', 'JenisBaris' => 'Barang', 'Jumlah' => '1', 'Harga' => '70000']]]))
            ->assertSessionHasErrors();
        expect(InvoiceBerulang::query()->count())->toBe(0);
    });

    it('kasir ditolak dan templat tenant lain 404', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/invoice/berulang', IsianBerulangHttp($this))->assertSessionHasNoErrors();
        $templat = InvoiceBerulang::query()->sole();

        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/invoice/berulang')->assertForbidden();
        $this->post("/kelola/invoice/berulang/{$templat->Uuid}/hentikan")->assertForbidden();

        $lain = BantuanPersediaan::SiapkanTenant('Konsultan Lain Jaya');
        BantuanFitur::Beri($lain['Tenant']->Id, 'invoice.penagihan');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->get("/kelola/invoice/berulang/{$templat->Uuid}")->assertNotFound();
        $this->post("/kelola/invoice/berulang/{$templat->Uuid}/hentikan")->assertNotFound();
        $this->getJson('/kelola/invoice/berulang')->assertOk()->assertJsonCount(0, 'Data');
    });

    it('invoice yang terbit dari templat tampil di detailnya', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/invoice/berulang', IsianBerulangHttp($this))->assertSessionHasNoErrors();
        $templat = InvoiceBerulang::query()->sole();

        $this->artisan('invoice:jalankan-berulang', ['--tenant' => [$this->k['Tenant']->Id]])->assertSuccessful();
        $invoice = Invoice::query()->sole();

        $this->get("/kelola/invoice/berulang/{$templat->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Templat.JumlahTerbit', 1)
            ->where('Templat.BerikutnyaPada', '2026-10-27')
            ->where('InvoiceTerbit.0.Uuid', $invoice->Uuid)
            ->where('InvoiceTerbit.0.Nomor', $invoice->Nomor));
        $this->get("/kelola/invoice/berulang/{$templat->Uuid}/ubah")->assertInertia(fn (AssertableInertia $h) => $h->where('Isian.JadwalTerkunci', true));
    });
});

describe('HTTP halaman invoice publik', function (): void {
    it('menampilkan invoice terbit tanpa login, menghitung dilihat hanya dari pengunjung, dan tidak membuka draf', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/invoice', IsianInvoiceHttp($this))->assertSessionHasNoErrors();
        $draf = Invoice::query()->sole();
        $this->get('/i/'.KodeInvoicePublik::Buat($draf->IdTenant, $draf->KodePublik))->assertNotFound();

        $this->post("/kelola/invoice/{$draf->Uuid}/terbitkan")->assertSessionHasNoErrors();
        $invoice = $draf->refresh();
        $kode = KodeInvoicePublik::Buat($invoice->IdTenant, $invoice->KodePublik);

        // Staf yang sedang masuk membuka pratinjau: tidak dihitung sebagai dilihat pelanggan.
        $this->get("/i/{$kode}")->assertOk();
        expect($invoice->refresh()->JumlahDilihat)->toBe(0)->and($invoice->DilihatPada)->toBeNull();

        Auth::logout();
        $this->get("/i/{$kode}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Publik/InvoiceDigital')
            ->where('Dokumen.Invoice.Nomor', $invoice->Nomor)
            ->where('Dokumen.Pembeli.Nama', 'PT Sumber Makmur Sentosa')
            ->where('Dokumen.Pembeli.NoHp', '')
            ->where('Dokumen.Pembeli.Email', null)
            ->where('Dokumen.Penjual.Nama', fn ($nama) => is_string($nama) && $nama !== '')
            ->missing('Dokumen.Jurnal')
            ->missing('Dokumen.Riwayat'));
        $this->get("/i/{$kode}")->assertOk();
        expect($invoice->refresh()->JumlahDilihat)->toBe(2)->and($invoice->DilihatPada)->not->toBeNull();

        $this->get('/i/tidak-valid')->assertNotFound();
        $this->get('/i/'.KodeInvoicePublik::Buat($invoice->IdTenant, Str::random(32)))->assertNotFound();
    });

    it('invoice yang dibatalkan tetap dibuka dengan tanda dibatalkan', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/invoice', IsianInvoiceHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();
        $this->post("/kelola/invoice/{$invoice->Uuid}/batalkan", ['Alasan' => 'Salah pelanggan'])->assertSessionHasNoErrors();

        Auth::logout();
        $this->get('/i/'.KodeInvoicePublik::Buat($invoice->IdTenant, $invoice->KodePublik))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dokumen.Invoice.Status', 'Dibatalkan')
            ->where('Dokumen.Invoice.AlasanBatal', 'Salah pelanggan'));
    });
});
