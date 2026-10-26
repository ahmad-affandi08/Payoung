<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Penjualan\Enum\StatusPengirimanInvoice;
use App\Domain\Penjualan\Layanan\KodeInvoicePublik;
use App\Domain\Penjualan\Layanan\PenyediaTindakanInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\Penawaran;
use App\Domain\Penjualan\Model\PengirimanPenawaran;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Model\OverrideTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Penawaran harga (F-21 bagian 3, D-168) lewat rute: draf → terbit → dikirim → diputuskan pelanggan di halaman publik
 * `/p/{kode}` → dijadikan draf invoice. Angka dihitung server; penawaran terbit terkunci; kedaluwarsa diturunkan dari tanggal;
 * satu penawaran menjadi paling banyak satu invoice aktif; izin `invoice.kelola`; isolasi tenant.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['9301']])]);
    $this->k = BantuanPersediaan::SiapkanTenant('Agensi Kreatif Nusantara');
    BantuanFitur::Beri($this->k['Tenant']->Id, 'invoice.penagihan');
    OverrideTenant::query()->create([
        'IdTenant' => $this->k['Tenant']->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => PemeriksaFiturTenant::KUNCI_WHATSAPP,
        'BerakhirPada' => now()->addDays(90),
        'Alasan' => 'Uji kirim penawaran lewat WhatsApp',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    $this->klien = Pelanggan::query()->create(['Nama' => 'CV Karya Bersama Abadi', 'NoHp' => '6281355550202', 'Email' => 'rahasia@karya.id', 'TerminHari' => 14]);
    $this->jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa desain identitas merek', 'Jenis' => JenisProduk::Jasa], '8000000.00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array<string, mixed> */
function IsianPenawaranHttp($tes, array $ubah = []): array
{
    return array_replace([
        'UuidPelanggan' => $tes->klien->Uuid,
        'UuidOutlet' => $tes->k['Outlet']->Uuid,
        'Tanggal' => '2026-09-27',
        'BerlakuSampai' => '2026-10-11',
        'Perihal' => 'Penawaran desain identitas merek',
        'HargaTermasukPajak' => false,
        'Syarat' => 'Pembayaran 50% di muka.',
        'Baris' => [[
            'UuidProduk' => $tes->jasa->Uuid,
            'Nama' => 'Desain logo & panduan merek',
            'JenisBaris' => 'Jasa',
            'SimbolSatuan' => 'paket',
            'Jumlah' => '1',
            'Harga' => '12000000',
        ]],
    ], $ubah);
}

function KodePublikPenawaran(Penawaran $penawaran): string
{
    return KodeInvoicePublik::Buat($penawaran->IdTenant, $penawaran->KodePublik);
}

describe('Penawaran: alur back-office', function (): void {
    it('form, simpan & terbitkan, daftar, detail, dan cetak. Angka dihitung server dan penawaran terbit bernomor PNW', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->get('/kelola/penawaran/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Penawaran/Form')
            ->where('Isian', null)
            ->where('MasaBerlakuBawaan', 14)
            ->has('OpsiOutlet')
            ->has('OpsiKelompokPajak'));

        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Total' => '1.00', 'Lanjutkan' => true]))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();
        expect($penawaran->Status->value)->toBe('Terbit')
            ->and($penawaran->Nomor)->toStartWith('PNW/')
            ->and($penawaran->Total)->toBe('12000000.00')
            ->and($penawaran->TerminHari)->toBe(14)
            ->and($penawaran->DiterbitkanPada)->not->toBeNull();

        $this->get("/kelola/penawaran/{$penawaran->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Penawaran/Detail')
            ->where('Penawaran.Nomor', $penawaran->Nomor)
            ->where('Penawaran.StatusTampil', 'MenungguKeputusan')
            ->where('Penawaran.NamaPelanggan', 'CV Karya Bersama Abadi')
            ->where('Penawaran.TautanPublik', url('/p/'.KodePublikPenawaran($penawaran)))
            ->where('Tindakan.Kirim', true)
            ->where('Tindakan.Ubah', false)
            ->where('Tindakan.JadikanInvoice', true)
            ->where('Tindakan.Salin', true)
            ->where('Baris.0.Nama', 'Desain logo & panduan merek'));

        $this->getJson('/kelola/penawaran')->assertOk()
            ->assertJsonPath('Data.0.Uuid', $penawaran->Uuid)
            ->assertJsonPath('Data.0.StatusTampil', 'MenungguKeputusan')
            ->assertJsonPath('Data.0.NamaPelanggan', 'CV Karya Bersama Abadi');
        $this->getJson('/kelola/penawaran?saring[Status]=Kedaluwarsa')->assertOk()->assertJsonCount(0, 'Data');
        $this->getJson('/kelola/penawaran?saring[Status]=MenungguKeputusan')->assertOk()->assertJsonCount(1, 'Data');
        $this->getJson('/kelola/penawaran?cari=identitas')->assertOk()->assertJsonCount(1, 'Data');

        $this->get("/kelola/penawaran/{$penawaran->Uuid}/cetak")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Penawaran/Cetak')
            ->where('Penawaran.Nomor', $penawaran->Nomor)
            ->where('Pembeli.Nama', 'CV Karya Bersama Abadi')
            ->missing('Jurnal'));

        expect(LogAudit::query()->where('Peristiwa', 'penawaran.buat')->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'penawaran.terbit')->count())->toBe(1);
    });

    it('penawaran tidak berdampak ke jurnal, piutang, maupun stok', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $jurnalSebelum = Jurnal::query()->count();
        $piutangSebelum = Piutang::query()->count();

        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();

        expect(Jurnal::query()->count())->toBe($jurnalSebelum)
            ->and(Piutang::query()->count())->toBe($piutangSebelum);
    });

    it('draf bisa diubah lalu diterbitkan. Setelah terbit tidak bisa diubah lewat rute maupun langsung di model', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();
        expect($penawaran->Nomor)->toBeNull()->and($penawaran->Status->value)->toBe('Draf');

        $this->get("/kelola/penawaran/{$penawaran->Uuid}/ubah")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Penawaran/Form')
            ->where('Isian.Uuid', $penawaran->Uuid)
            ->where('Isian.BerlakuSampai', '2026-10-11')
            ->where('Isian.Baris.0.Nama', 'Desain logo & panduan merek'));

        $this->put("/kelola/penawaran/{$penawaran->Uuid}", IsianPenawaranHttp($this, ['Perihal' => 'Revisi', 'Baris' => [[
            'Nama' => 'Retainer desain', 'JenisBaris' => 'Jasa', 'Jumlah' => '3', 'Harga' => '2000000',
        ]]]))->assertSessionHasNoErrors();
        expect($penawaran->refresh()->Total)->toBe('6000000.00')->and($penawaran->Perihal)->toBe('Revisi');

        $this->post("/kelola/penawaran/{$penawaran->Uuid}/terbitkan")->assertSessionHasNoErrors();
        expect($penawaran->refresh()->Status->value)->toBe('Terbit');
        $nomor = $penawaran->Nomor;

        // Menerbitkan lagi tidak menomori ulang.
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/terbitkan")->assertSessionHasNoErrors();
        expect($penawaran->refresh()->Nomor)->toBe($nomor);

        $this->put("/kelola/penawaran/{$penawaran->Uuid}", IsianPenawaranHttp($this))->assertSessionHasErrors('Umum');
        $this->get("/kelola/penawaran/{$penawaran->Uuid}/ubah")->assertRedirect("/kelola/penawaran/{$penawaran->Uuid}");
        expect($penawaran->refresh()->Total)->toBe('6000000.00');

        $penawaran->Total = '1.00';
        expect(fn () => $penawaran->save())->toThrow(LogicException::class);
        expect(fn () => $penawaran->delete())->toThrow(LogicException::class);
    });

    it('isian ditolak: barang berstok, tanpa baris, masa berlaku sebelum tanggal atau lebih dari 365 hari', function (): void {
        $stok = BantuanKatalog::BuatProduk(['Nama' => 'Beras Pulen 5 kg'], '70000.00');
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Baris' => [['UuidProduk' => $stok->Uuid, 'Nama' => 'Beras', 'JenisBaris' => 'Barang', 'Jumlah' => '1', 'Harga' => '70000']]]))->assertSessionHasErrors();
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Baris' => []]))->assertSessionHasErrors('Baris');
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['BerlakuSampai' => '2026-09-20']))->assertSessionHasErrors('BerlakuSampai');
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['BerlakuSampai' => '2027-12-31']))->assertSessionHasErrors('BerlakuSampai');
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['BerlakuSampai' => null]))->assertSessionHasErrors('BerlakuSampai');
        expect(Penawaran::query()->count())->toBe(0);
    });

    it('menerbitkan penawaran yang masa berlakunya sudah lewat ditolak', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Tanggal' => '2026-09-01', 'BerlakuSampai' => '2026-09-15']))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();

        $this->post("/kelola/penawaran/{$penawaran->Uuid}/terbitkan")->assertSessionHasErrors('BerlakuSampai');
        expect($penawaran->refresh()->Status->value)->toBe('Draf');
    });

    it('pembatalan meminta alasan. Penawaran tidak dikenal 404. Salin menghasilkan isian baru tanpa Uuid', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();

        $this->get("/kelola/penawaran/buat?salin={$penawaran->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Isian.Uuid', null)
            ->where('Isian.Nomor', null)
            ->where('Isian.Perihal', 'Penawaran desain identitas merek')
            ->where('Isian.Baris.0.Nama', 'Desain logo & panduan merek'));
        $this->get('/kelola/penawaran/buat?salin='.strtoupper((string) Str::ulid()))->assertNotFound();

        $this->post("/kelola/penawaran/{$penawaran->Uuid}/batalkan", ['Alasan' => 'x'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/batalkan", ['Alasan' => 'Klien mundur dari proyek'])->assertSessionHasNoErrors();
        expect($penawaran->refresh()->Status->value)->toBe('Dibatalkan')->and($penawaran->AlasanBatal)->toBe('Klien mundur dari proyek');

        // Idempoten, dan tidak bisa dikirim atau diputuskan lagi.
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/batalkan", ['Alasan' => 'Klien mundur dari proyek'])->assertSessionHasNoErrors();
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/kirim")->assertSessionHasErrors('Umum');
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/putuskan", ['Keputusan' => 'Terima'])->assertSessionHasErrors('Umum');
        $this->get('/kelola/penawaran/'.strtoupper((string) Str::ulid()))->assertNotFound();
    });
});

describe('Penawaran: halaman publik dan keputusan pelanggan', function (): void {
    it('kode rahasia: draf dan kode salah 404. Terbit tampil tanpa kontak pembeli dan menghitung dilihat hanya dari pengunjung', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this))->assertSessionHasNoErrors();
        $draf = Penawaran::query()->sole();
        $this->get('/p/'.KodePublikPenawaran($draf))->assertNotFound();

        $this->post("/kelola/penawaran/{$draf->Uuid}/terbitkan")->assertSessionHasNoErrors();
        $kode = KodePublikPenawaran($draf->refresh());

        // Staf yang sedang masuk (pratinjau) tidak dihitung.
        $this->get('/p/'.$kode)->assertOk();
        expect($draf->refresh()->JumlahDilihat)->toBe(0);

        Auth::logout();
        $this->get('/p/'.$kode)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Publik/PenawaranDigital')
            ->where('Dokumen.Penawaran.Nomor', $draf->Nomor)
            ->where('Dokumen.Penawaran.BisaDiputuskan', true)
            ->where('Dokumen.Penawaran.StatusTampil', 'MenungguKeputusan')
            ->where('Dokumen.Pembeli.Nama', 'CV Karya Bersama Abadi')
            ->where('Dokumen.Pembeli.NoHp', '')
            ->where('Dokumen.Pembeli.Email', null)
            ->where('Dokumen.Baris.0.Nama', 'Desain logo & panduan merek')
            ->missing('Dokumen.Jurnal'));
        $this->get('/p/'.$kode)->assertOk();
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        $draf->refresh();
        expect($draf->JumlahDilihat)->toBe(2)->and($draf->DilihatPada)->not->toBeNull();

        $this->get('/p/'.str_replace(substr($draf->KodePublik, 0, 4), 'xxxx', $kode))->assertNotFound();
        $this->get('/p/tidak-valid')->assertNotFound();
    });

    it('pelanggan menerima: nama wajib, status Diterima tercatat, keputusan ganda diabaikan, kebalikannya ditolak', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();
        $kode = KodePublikPenawaran($penawaran);
        Auth::logout();

        $this->post("/p/{$kode}/putuskan", ['Keputusan' => 'Terima'])->assertSessionHasErrors('Nama');
        $this->post("/p/{$kode}/putuskan", ['Keputusan' => 'Terima', 'Nama' => 'B'])->assertSessionHasErrors('Nama');
        $this->post("/p/{$kode}/putuskan", ['Keputusan' => 'Mungkin', 'Nama' => 'Budi Santoso'])->assertSessionHasErrors('Keputusan');

        $this->post("/p/{$kode}/putuskan", ['Keputusan' => 'Terima', 'Nama' => 'Budi Santoso', 'Catatan' => 'Mulai Oktober ya.'])->assertSessionHasNoErrors()->assertSessionHas('Kilat');
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        $penawaran->refresh();
        expect($penawaran->Status->value)->toBe('Diterima')
            ->and($penawaran->SumberKeputusan)->toBe('Pelanggan')
            ->and($penawaran->NamaPemutus)->toBe('Budi Santoso')
            ->and($penawaran->CatatanKeputusan)->toBe('Mulai Oktober ya.')
            ->and($penawaran->DiputuskanPada)->not->toBeNull();

        // Mengulang keputusan yang sama aman; membalik ditolak dan tidak mengubah apa pun.
        $this->post("/p/{$kode}/putuskan", ['Keputusan' => 'Terima', 'Nama' => 'Budi Santoso'])->assertSessionHasNoErrors();
        $this->post("/p/{$kode}/putuskan", ['Keputusan' => 'Tolak', 'Nama' => 'Orang Lain'])->assertSessionHasErrors('Umum');
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        expect($penawaran->refresh()->Status->value)->toBe('Diterima')->and($penawaran->NamaPemutus)->toBe('Budi Santoso');

        $this->get('/p/'.$kode)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dokumen.Penawaran.BisaDiputuskan', false)
            ->where('Dokumen.Penawaran.StatusTampil', 'Diterima')
            ->where('Dokumen.Penawaran.NamaPemutus', 'Budi Santoso'));

        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        expect(LogAudit::query()->where('Peristiwa', 'penawaran.diputuskan')->count())->toBe(1);
    });

    it('pelanggan menolak: status Ditolak final, tidak bisa dijadikan invoice', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();
        $kode = KodePublikPenawaran($penawaran);
        Auth::logout();

        $this->post("/p/{$kode}/putuskan", ['Keputusan' => 'Tolak', 'Nama' => 'Budi Santoso', 'Catatan' => 'Terlalu mahal.'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        expect($penawaran->refresh()->Status->value)->toBe('Ditolak');

        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/jadikan-invoice")->assertSessionHasErrors('Umum');
        expect(Invoice::query()->count())->toBe(0);
    });

    it('kedaluwarsa diturunkan dari tanggal: pelanggan tidak bisa memutuskan, staf masih bisa mengonfirmasi, tidak bisa dikirim', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();
        $kode = KodePublikPenawaran($penawaran);

        Carbon::setTestNow('2026-10-20 03:00:00');
        $this->getJson('/kelola/penawaran?saring[Status]=Kedaluwarsa')->assertOk()->assertJsonCount(1, 'Data')->assertJsonPath('Data.0.LabelStatusTampil', 'Kedaluwarsa');
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/kirim")->assertSessionHasErrors('Umum');

        Auth::logout();
        $this->get('/p/'.$kode)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Dokumen.Penawaran.StatusTampil', 'Kedaluwarsa')
            ->where('Dokumen.Penawaran.BisaDiputuskan', false));
        $this->post("/p/{$kode}/putuskan", ['Keputusan' => 'Terima', 'Nama' => 'Budi Santoso'])->assertSessionHasErrors('Umum');
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        expect($penawaran->refresh()->Status->value)->toBe('Terbit');

        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/putuskan", ['Keputusan' => 'Terima', 'Nama' => 'Budi (via telepon)'])->assertSessionHasNoErrors();
        expect($penawaran->refresh()->Status->value)->toBe('Diterima')->and($penawaran->SumberKeputusan)->toBe('Staf');
    });

    it('staf menandai ditolak atau diterima. Keputusan memerlukan penawaran terbit', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();

        $this->post("/kelola/penawaran/{$penawaran->Uuid}/putuskan", ['Keputusan' => 'Terima'])->assertSessionHasErrors('Umum');
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/terbitkan")->assertSessionHasNoErrors();
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/putuskan", ['Keputusan' => 'Tolak', 'Catatan' => 'Pakai vendor lain'])->assertSessionHasNoErrors();
        expect($penawaran->refresh()->Status->value)->toBe('Ditolak')->and($penawaran->SumberKeputusan)->toBe('Staf');
    });
});

describe('Penawaran: dijadikan invoice', function (): void {
    it('menyalin baris ke draf invoice bertanggal hari ini. Penawaran menjadi diterima (staf) dan menunjuk invoice. Klik ganda tidak menggandakan', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['NomorReferensi' => 'PO-7788', 'Lanjutkan' => true]))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();

        Carbon::setTestNow('2026-09-29 03:00:00');
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/jadikan-invoice")->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();
        expect($invoice->Status->value)->toBe('Draf')
            ->and($invoice->Nomor)->toBeNull()
            ->and($invoice->Tanggal->toDateString())->toBe('2026-09-29')
            ->and($invoice->TerminHari)->toBe(14)
            ->and($invoice->Total)->toBe('12000000.00')
            ->and($invoice->Perihal)->toBe('Penawaran desain identitas merek')
            ->and($invoice->NomorReferensi)->toBe('PO-7788')
            ->and($invoice->Syarat)->toBe('Pembayaran 50% di muka.')
            ->and($invoice->Detail()->count())->toBe(1);

        $penawaran->refresh();
        expect($penawaran->Status->value)->toBe('Diterima')
            ->and($penawaran->SumberKeputusan)->toBe('Staf')
            ->and($penawaran->IdInvoice)->toBe($invoice->Id);

        $this->post("/kelola/penawaran/{$penawaran->Uuid}/jadikan-invoice")->assertSessionHasNoErrors();
        expect(Invoice::query()->count())->toBe(1);

        $this->get("/kelola/penawaran/{$penawaran->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Penawaran.StatusTampil', 'DiInvoicekan')
            ->where('Penawaran.Invoice.Uuid', $invoice->Uuid)
            ->where('Tindakan.Batalkan', false));
        expect(LogAudit::query()->where('Peristiwa', 'penawaran.diinvoicekan')->count())->toBe(1);
    });

    it('invoice hasilnya diterbitkan: penawaran terkunci; dibatalkan: penawaran bisa dijadikan invoice lagi', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/jadikan-invoice")->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();

        // Selama invoice aktif, penawaran tidak boleh dibatalkan.
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/batalkan", ['Alasan' => 'Salah tagih'])->assertSessionHasErrors('Umum');

        $this->post("/kelola/invoice/{$invoice->Uuid}/terbitkan")->assertSessionHasNoErrors();
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/jadikan-invoice")->assertSessionHasErrors('Umum');
        expect(Invoice::query()->count())->toBe(1);

        $this->post("/kelola/invoice/{$invoice->Uuid}/batalkan", ['Alasan' => 'Salah pelanggan'])->assertSessionHasNoErrors();
        $this->get("/kelola/penawaran/{$penawaran->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Penawaran.StatusTampil', 'Diterima')
            ->where('Tindakan.JadikanInvoice', true));
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/jadikan-invoice")->assertSessionHasNoErrors();
        expect(Invoice::query()->count())->toBe(2)
            ->and($penawaran->refresh()->IdInvoice)->toBe(Invoice::query()->orderByDesc('Id')->value('Id'));
    });

    it('draf penawaran belum bisa dijadikan invoice', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();

        $this->post("/kelola/penawaran/{$penawaran->Uuid}/jadikan-invoice")->assertSessionHasErrors('Umum');
        expect(Invoice::query()->count())->toBe(0);
    });
});

describe('Penawaran: kirim WhatsApp', function (): void {
    it('terkirim ke nomor pelanggan dengan tautan publik. Nomor tidak masuk audit. Kirim ulang kena jeda. Hanya yang menunggu keputusan', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();

        // Draf belum bisa dikirim.
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/kirim")->assertSessionHasErrors('Umum');
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/terbitkan")->assertSessionHasNoErrors();

        $this->post("/kelola/penawaran/{$penawaran->Uuid}/kirim")->assertSessionHasNoErrors();
        $pengiriman = PengirimanPenawaran::query()->sole();
        expect($pengiriman->Status)->toBe(StatusPengirimanInvoice::Terkirim)
            ->and($pengiriman->Tujuan)->toBe('6281355550202');
        $teks = (string) collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'api.fonnte.com/send'))->sole()->data()['message'];
        $penawaran->refresh();
        expect($teks)->toContain('Halo CV Karya Bersama Abadi')
            ->and($teks)->toContain((string) $penawaran->Nomor)
            ->and($teks)->toContain('/p/'.KodePublikPenawaran($penawaran))
            ->and($teks)->toContain('Rp 12.000.000')
            ->and($penawaran->TerkirimPada)->not->toBeNull();
        expect(json_encode(LogAudit::query()->where('Peristiwa', 'penawaran.kirim')->sole()->NilaiBaru))->not->toContain('6281355550202');

        $this->post("/kelola/penawaran/{$penawaran->Uuid}/kirim")->assertSessionHasErrors('Umum');
        expect(PengirimanPenawaran::query()->count())->toBe(1);

        $this->post("/kelola/penawaran/{$penawaran->Uuid}/putuskan", ['Keputusan' => 'Terima'])->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-09-27 04:00:00');
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/kirim")->assertSessionHasErrors('Umum');
    });

    it('pelanggan tanpa nomor HP sah ditolak dengan pesan yang jelas', function (): void {
        $this->klien->update(['NoHp' => '123']);
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();

        $this->post("/kelola/penawaran/{$penawaran->Uuid}/kirim")->assertSessionHasErrors('Umum');
        expect(PengirimanPenawaran::query()->count())->toBe(0);
    });
});

describe('Penawaran: Kotak Tindakan dan tautan ke invoice', function (): void {
    it('Kotak Tindakan: diterima belum ditagih dan terbit belum ditanggapi. Hilang setelah invoice aktif atau diputuskan', function (): void {
        $penyedia = app(PenyediaTindakanInvoice::class);
        $konteks = fn (CarbonImmutable $hari, bool $izin = true) => new DataKonteksTindakan($this->k['Tenant']->Id, $this->k['Pemilik']->Id, false, $izin ? ['invoice.kelola'] : [], null, $hari);
        $butir = fn (CarbonImmutable $hari) => collect($penyedia->Kumpulkan($konteks($hari)))->filter(fn ($b) => $b->jumlah > 0)->keyBy('kunci');
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);

        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true, 'BerlakuSampai' => '2026-11-30']))->assertSessionHasNoErrors();
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true, 'Perihal' => 'Penawaran kedua', 'BerlakuSampai' => '2026-11-30']))->assertSessionHasNoErrors();
        [$pertama, $kedua] = Penawaran::query()->orderBy('Id')->get()->all();
        expect($butir(CarbonImmutable::parse('2026-09-28'))->all())->toBe([]);

        // Lebih dari 7 hari sejak terbit dan belum ditanggapi.
        Carbon::setTestNow('2026-10-10 03:00:00');
        $hari = CarbonImmutable::parse('2026-10-10');
        $kumpul = $butir($hari);
        expect($kumpul->keys()->all())->toBe(['penawaran.belum-ditanggapi'])
            ->and($kumpul['penawaran.belum-ditanggapi']->jumlah)->toBe(2)
            ->and($kumpul['penawaran.belum-ditanggapi']->tingkat->name)->toBe('Perhatian');

        // Diterima tetapi belum ditagih.
        $this->post("/kelola/penawaran/{$pertama->Uuid}/putuskan", ['Keputusan' => 'Terima'])->assertSessionHasNoErrors();
        $kumpul = $butir($hari);
        expect($kumpul->keys()->sort()->values()->all())->toBe(['penawaran.belum-ditanggapi', 'penawaran.diterima-belum-invoice'])
            ->and($kumpul['penawaran.diterima-belum-invoice']->jumlah)->toBe(1)
            ->and($kumpul['penawaran.diterima-belum-invoice']->rincian[0]->judul)->toBe($pertama->refresh()->Nomor)
            ->and($kumpul['penawaran.belum-ditanggapi']->jumlah)->toBe(1);

        // Dijadikan invoice aktif: butir hilang; invoice dibatalkan: muncul lagi.
        $this->post("/kelola/penawaran/{$pertama->Uuid}/jadikan-invoice")->assertSessionHasNoErrors();
        expect($butir($hari)->has('penawaran.diterima-belum-invoice'))->toBeFalse();
        $invoice = Invoice::query()->sole();
        $this->post("/kelola/invoice/{$invoice->Uuid}/batalkan", ['Alasan' => 'Salah pelanggan'])->assertSessionHasNoErrors();
        expect($butir($hari)->has('penawaran.diterima-belum-invoice'))->toBeTrue();

        // Ditolak: tidak lagi menunggu tanggapan. Tanpa izin: tidak ada butir sama sekali.
        $this->post("/kelola/penawaran/{$kedua->Uuid}/putuskan", ['Keputusan' => 'Tolak'])->assertSessionHasNoErrors();
        expect($butir($hari)->has('penawaran.belum-ditanggapi'))->toBeFalse()
            ->and($penyedia->Kumpulkan($konteks($hari, false)))->toBe([]);
    });

    it('detail invoice hasil penawaran menunjuk penawaran asalnya', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/jadikan-invoice")->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();

        $this->get("/kelola/invoice/{$invoice->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Invoice.Penawaran.Uuid', $penawaran->Uuid)
            ->where('Invoice.Penawaran.Nomor', $penawaran->Nomor));

        // Invoice biasa tanpa penawaran: null.
        $this->post('/kelola/invoice', ['Jenis' => 'Penjualan', 'UuidPelanggan' => $this->klien->Uuid, 'UuidOutlet' => $this->k['Outlet']->Uuid, 'Tanggal' => '2026-09-27', 'HargaTermasukPajak' => false, 'Baris' => [['Nama' => 'Jasa lain', 'JenisBaris' => 'Jasa', 'Jumlah' => '1', 'Harga' => '1000000']]])->assertSessionHasNoErrors();
        $lain = Invoice::query()->where('Id', '!=', $invoice->Id)->sole();
        $this->get("/kelola/invoice/{$lain->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h->where('Invoice.Penawaran', null));
    });
});

describe('Penawaran: izin dan isolasi tenant', function (): void {
    it('tanpa izin invoice.kelola ditolak untuk semua rute tulis dan baca', function (): void {
        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/penawaran')->assertForbidden();
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this))->assertForbidden();
        expect(Penawaran::query()->count())->toBe(0);
    });

    it('penawaran tenant lain 404 dan halaman publiknya tidak terbuka dengan kode tenant lain', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $penawaran = Penawaran::query()->sole();

        $lain = BantuanPersediaan::SiapkanTenant('Agensi Lain Jaya');
        BantuanFitur::Beri($lain['Tenant']->Id, 'invoice.penagihan');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->get("/kelola/penawaran/{$penawaran->Uuid}")->assertNotFound();
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/terbitkan")->assertNotFound();
        $this->post("/kelola/penawaran/{$penawaran->Uuid}/jadikan-invoice")->assertNotFound();
        $this->getJson('/kelola/penawaran')->assertOk()->assertJsonCount(0, 'Data');

        Auth::logout();
        $this->get('/p/'.KodeInvoicePublik::Buat($lain['Tenant']->Id, $penawaran->KodePublik))->assertNotFound();
        $this->post('/p/'.KodeInvoicePublik::Buat($lain['Tenant']->Id, $penawaran->KodePublik).'/putuskan', ['Keputusan' => 'Terima', 'Nama' => 'Budi Santoso'])->assertNotFound();
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
        expect($penawaran->refresh()->Status->value)->toBe('Terbit');
    });

    it('ekspor CSV mengikuti filter dan ditolak untuk kasir', function (): void {
        BantuanPersediaan::MasukSebagai($this, $this->k['Tenant']->Id);
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Lanjutkan' => true]))->assertSessionHasNoErrors();
        $this->post('/kelola/penawaran', IsianPenawaranHttp($this, ['Perihal' => 'Draf rahasia']))->assertSessionHasNoErrors();
        $nomor = Penawaran::query()->where('Status', 'Terbit')->sole()->Nomor;

        $csv = $this->get('/kelola/penawaran/ekspor?format=csv&saring[Status]=MenungguKeputusan')->assertOk()->streamedContent();
        expect($csv)->toContain((string) $nomor)->toContain('CV Karya Bersama Abadi')->not->toContain('Draf rahasia');

        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/penawaran/ekspor?format=csv')->assertForbidden();
    });
});
