<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Tindakan\Data\DataKonteksTindakan;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Penjualan\Layanan\PenyediaTindakanInvoice;
use App\Domain\Penjualan\Model\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Laporan PPh yang dipotong pelanggan (F-21 bagian 5, D-170): satu baris per pelunasan diposting yang memotong PPh, dengan
 * pelanggan (NPWP), invoice, jenis PPh, dan status bukti potong. Pelunasan tanpa PPh dan yang dibatalkan tidak muncul;
 * ringkasan mengikuti saringan yang sama dengan tabel.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->k = BantuanPersediaan::SiapkanTenant('Konsultan Pajak Teliti');
    BantuanFitur::Beri($this->k['Tenant']->Id, 'invoice.penagihan');
    BantuanFitur::Beri($this->k['Tenant']->Id, 'laporan.lengkap');
    $this->klienA = Pelanggan::query()->create(['Nama' => 'PT Pemotong Pertama', 'NoHp' => '6281355550501', 'Npwp' => '01.234.567.8-901.000', 'TerminHari' => 14]);
    $this->klienB = Pelanggan::query()->create(['Nama' => 'CV Pemotong Kedua', 'NoHp' => '6281355550502', 'TerminHari' => 14]);
    $this->jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa pelatihan', 'Jenis' => JenisProduk::Jasa], '10000000.00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function InvoiceBerPphUji($tes, Pelanggan $klien, string $harga = '10000000'): Invoice
{
    $tes->post('/kelola/invoice', [
        'Jenis' => 'Penjualan', 'UuidPelanggan' => $klien->Uuid, 'UuidOutlet' => $tes->k['Outlet']->Uuid, 'Tanggal' => '2026-09-27',
        'HargaTermasukPajak' => false, 'KodePph' => 'PPh 23', 'TarifPph' => '2', 'Lanjutkan' => true,
        'Baris' => [['UuidProduk' => $tes->jasa->Uuid, 'Nama' => 'Pelatihan', 'JenisBaris' => 'Jasa', 'Jumlah' => '1', 'Harga' => $harga]],
    ])->assertSessionHasNoErrors();

    return Invoice::query()->orderByDesc('Id')->firstOrFail();
}

function BayarUji($tes, Invoice $invoice, string $tanggal, string $jumlah, ?string $pph = null, ?string $bukti = null): PembayaranPiutang
{
    $tes->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", array_filter([
        'UuidAkun' => BantuanPembelian::AkunKas('1-1200')->Uuid, 'Tanggal' => $tanggal, 'Jumlah' => $jumlah, 'PotonganPph' => $pph, 'NomorBuktiPotong' => $bukti,
    ], fn ($v) => $v !== null))->assertSessionHasNoErrors();

    return PembayaranPiutang::query()->orderByDesc('Id')->firstOrFail();
}

/** Skenario: A dibayar dua kali (4 juta tanpa PPh, lalu 6 juta ber-PPh 200 ribu + bukti); B dibayar penuh ber-PPh 100 ribu tanpa bukti. */
function SkenarioPphUji($tes): array
{
    BantuanPersediaan::MasukSebagai($tes, $tes->k['Tenant']->Id);
    $a = InvoiceBerPphUji($tes, $tes->klienA);
    $tanpaPph = BayarUji($tes, $a, '2026-09-27', '4000000');
    $denganBukti = BayarUji($tes, $a, '2026-09-28', '6000000', '200000', '0007-BP23-2026');
    $b = InvoiceBerPphUji($tes, $tes->klienB, '5000000');
    $tanpaBukti = BayarUji($tes, $b, '2026-10-02', '5000000', '100000');

    return [$a, $b, $tanpaPph, $denganBukti, $tanpaBukti];
}

describe('Laporan PPh dipotong pelanggan', function (): void {
    it('hanya pelunasan yang memotong PPh. Baris memuat pelanggan, NPWP, invoice, jenis dan tarif PPh, serta status bukti', function (): void {
        [$a, $b, $tanpaPph, $denganBukti, $tanpaBukti] = SkenarioPphUji($this);

        $respons = $this->getJson('/kelola/laporan/pajak/pph-dipotong')->assertOk();
        $respons->assertJsonCount(2, 'Data')->assertJsonPath('Meta.Total', 2);
        $baris = collect($respons->json('Data'))->keyBy('Uuid');
        expect($baris->has($tanpaPph->Uuid))->toBeFalse();

        $bukti = $baris[$denganBukti->Uuid];
        expect($bukti['Nomor'])->toBe($denganBukti->Nomor)
            ->and($bukti['Tanggal'])->toBe('2026-09-28')
            ->and($bukti['NamaPelanggan'])->toBe('PT Pemotong Pertama')
            ->and($bukti['Npwp'])->toBe('01.234.567.8-901.000')
            ->and($bukti['PotonganPph'])->toBe('200000.00')
            ->and($bukti['NomorBuktiPotong'])->toBe('0007-BP23-2026')
            ->and($bukti['BuktiLengkap'])->toBeTrue()
            ->and($bukti['JenisPph'])->toBe('PPh 23')
            ->and($bukti['Dokumen'][0]['Nomor'])->toBe($a->Nomor)
            ->and($bukti['Dokumen'][0]['UuidInvoice'])->toBe($a->Uuid)
            ->and($bukti['Dokumen'][0]['DasarPph'])->toBe('10000000.00');

        $belum = $baris[$tanpaBukti->Uuid];
        expect($belum['NomorBuktiPotong'])->toBeNull()->and($belum['BuktiLengkap'])->toBeFalse()->and($belum['Npwp'])->toBeNull()
            ->and($belum['Dokumen'][0]['Nomor'])->toBe($b->Nomor);

        $respons->assertJsonPath('Ringkasan.Total', '300000.00')
            ->assertJsonPath('Ringkasan.JumlahPelunasan', 2)
            ->assertJsonPath('Ringkasan.SudahBukti.Jumlah', 1)
            ->assertJsonPath('Ringkasan.SudahBukti.Nilai', '200000.00')
            ->assertJsonPath('Ringkasan.BelumBukti.Jumlah', 1)
            ->assertJsonPath('Ringkasan.BelumBukti.Nilai', '100000.00');
    });

    it('halaman Inertia memuat tabel awal. Ringkasan dan baris mengikuti filter bukti, tanggal, pelanggan, dan cari', function (): void {
        [, , , $denganBukti, $tanpaBukti] = SkenarioPphUji($this);

        $this->get('/kelola/laporan/pajak/pph-dipotong')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Laporan/PphDipotong')
            ->where('Pelunasan.Meta.Total', 2)
            ->where('Pelunasan.Ringkasan.Total', '300000.00'));

        $belum = $this->getJson('/kelola/laporan/pajak/pph-dipotong?saring[Bukti]=Belum')->assertOk();
        $belum->assertJsonCount(1, 'Data')->assertJsonPath('Data.0.Uuid', $tanpaBukti->Uuid)
            ->assertJsonPath('Ringkasan.Total', '100000.00')->assertJsonPath('Ringkasan.SudahBukti.Jumlah', 0);
        $this->getJson('/kelola/laporan/pajak/pph-dipotong?saring[Bukti]=Ada')->assertJsonCount(1, 'Data')->assertJsonPath('Data.0.Uuid', $denganBukti->Uuid);

        $this->getJson('/kelola/laporan/pajak/pph-dipotong?saring[Tanggal]=2026-09-01..2026-09-30')->assertJsonCount(1, 'Data')->assertJsonPath('Ringkasan.Total', '200000.00');
        $this->getJson('/kelola/laporan/pajak/pph-dipotong?saring[Tanggal]=2026-10-01..2026-10-31')->assertJsonCount(1, 'Data')->assertJsonPath('Data.0.Uuid', $tanpaBukti->Uuid);
        $this->getJson('/kelola/laporan/pajak/pph-dipotong?saring[Pelanggan]='.$this->klienB->Uuid)->assertJsonCount(1, 'Data')->assertJsonPath('Data.0.Uuid', $tanpaBukti->Uuid);
        $this->getJson('/kelola/laporan/pajak/pph-dipotong?saring[Pelanggan]=01J9TIDAKADA00000000000000')->assertJsonCount(0, 'Data')->assertJsonPath('Ringkasan.Total', '0.00');
        $this->getJson('/kelola/laporan/pajak/pph-dipotong?cari=0007-BP23')->assertJsonCount(1, 'Data')->assertJsonPath('Data.0.Uuid', $denganBukti->Uuid);
        $this->getJson('/kelola/laporan/pajak/pph-dipotong?urut=PotonganPph')->assertJsonPath('Data.0.Uuid', $tanpaBukti->Uuid);
    });

    it('pelunasan yang dibatalkan tidak dihitung', function (): void {
        [, , , $denganBukti] = SkenarioPphUji($this);

        $this->post("/kelola/piutang/pelunasan/{$denganBukti->Uuid}/batalkan", ['Alasan' => 'Salah catat pembayaran'])->assertSessionHasNoErrors();

        $this->getJson('/kelola/laporan/pajak/pph-dipotong')->assertJsonCount(1, 'Data')->assertJsonPath('Ringkasan.Total', '100000.00');
    });

    it('ekspor CSV mengikuti filter dan memuat ringkasan. Kasir tanpa izin laporan keuangan ditolak', function (): void {
        [, , , $denganBukti, $tanpaBukti] = SkenarioPphUji($this);

        $csv = $this->get('/kelola/laporan/pajak/pph-dipotong/ekspor?format=csv&saring[Bukti]=Belum')->assertOk()->streamedContent();
        expect($csv)->toContain($tanpaBukti->Nomor)->toContain('CV Pemotong Kedua')->toContain('Belum diterima')
            ->and($csv)->not->toContain($denganBukti->Nomor)->not->toContain('0007-BP23-2026');
        $semua = $this->get('/kelola/laporan/pajak/pph-dipotong/ekspor?format=csv')->assertOk()->streamedContent();
        expect($semua)->toContain('0007-BP23-2026')->toContain('01.234.567.8-901.000')->toContain('Sudah ada')->toContain('200000.00')->toContain('100000.00');

        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/laporan/pajak/pph-dipotong')->assertForbidden();
        $this->get('/kelola/laporan/pajak/pph-dipotong/ekspor?format=csv')->assertForbidden();
    });

    it('tenant lain tidak melihat PPh milik tenant ini', function (): void {
        SkenarioPphUji($this);
        $lain = BantuanPersediaan::SiapkanTenant('Konsultan Lain Pajak');
        BantuanFitur::Beri($lain['Tenant']->Id, 'laporan.lengkap');

        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->getJson('/kelola/laporan/pajak/pph-dipotong')->assertOk()->assertJsonCount(0, 'Data')->assertJsonPath('Ringkasan.Total', '0.00');
    });

    it('Kotak Tindakan: PPh dipotong lebih dari 30 hari tanpa bukti potong muncul, hilang saat nomor dicatat atau pelunasan dibatalkan', function (): void {
        $penyedia = app(PenyediaTindakanInvoice::class);
        $konteks = fn (string $hari, array $izin) => new DataKonteksTindakan($this->k['Tenant']->Id, $this->k['Pemilik']->Id, false, $izin, null, CarbonImmutable::parse($hari));
        $butir = fn (DataKonteksTindakan $k) => collect($penyedia->Kumpulkan($k))->filter(fn ($x) => $x->jumlah > 0)->keyBy('kunci');
        $izinPenuh = ['invoice.kelola', 'laporan.keuangan.lihat', 'pelanggan.lihat'];

        [, , , , $tanpaBukti] = SkenarioPphUji($this);
        // Pelunasan tanpa bukti baru 3 hari lalu: belum waktunya ditagih.
        expect($butir($konteks('2026-10-05', $izinPenuh))->has('invoice.bukti-potong-belum'))->toBeFalse();

        // 40 hari kemudian (pelunasan 2026-10-02): tertagih; pelunasan ber-bukti dan tanpa PPh tidak ikut.
        Carbon::setTestNow('2026-11-11 03:00:00');
        $item = $butir($konteks('2026-11-11', $izinPenuh))['invoice.bukti-potong-belum'];
        expect($item->jumlah)->toBe(1)
            ->and($item->tingkat->name)->toBe('Perhatian')
            ->and($item->tautan)->toBe('/kelola/laporan/pajak/pph-dipotong?saring[Bukti]=Belum')
            ->and($item->rincian[0]->judul)->toBe($tanpaBukti->Nomor)
            ->and($item->rincian[0]->keterangan)->toContain('100.000')
            ->and($item->rincian[0]->tautan)->toBe('/kelola/piutang/pelunasan/'.$tanpaBukti->Uuid);

        // Tanpa izin laporan keuangan dan lihat pelanggan: tautan ke daftar dan invoice.
        $terbatas = $butir($konteks('2026-11-11', ['invoice.kelola']))['invoice.bukti-potong-belum'];
        expect($terbatas->tautan)->toBe('/kelola/invoice')->and($terbatas->rincian[0]->tautan)->toStartWith('/kelola/invoice/');
        expect($penyedia->Kumpulkan($konteks('2026-11-11', [])))->toBe([]);

        // Nomor bukti dicatat → butir hilang.
        $this->post("/kelola/piutang/pelunasan/{$tanpaBukti->Uuid}/bukti-potong", ['NomorBuktiPotong' => '0009-BP23-2026'])->assertSessionHasNoErrors();
        expect($butir($konteks('2026-11-11', $izinPenuh))->has('invoice.bukti-potong-belum'))->toBeFalse();
    });

    it('Kotak Tindakan: pelunasan yang dibatalkan tidak lagi ditagih bukti potongnya', function (): void {
        $penyedia = app(PenyediaTindakanInvoice::class);
        [, , , , $tanpaBukti] = SkenarioPphUji($this);
        Carbon::setTestNow('2026-11-11 03:00:00');
        $konteks = new DataKonteksTindakan($this->k['Tenant']->Id, $this->k['Pemilik']->Id, false, ['invoice.kelola'], null, CarbonImmutable::parse('2026-11-11'));
        expect(collect($penyedia->Kumpulkan($konteks))->firstWhere('kunci', 'invoice.bukti-potong-belum')->jumlah)->toBe(1);

        $this->post("/kelola/piutang/pelunasan/{$tanpaBukti->Uuid}/batalkan", ['Alasan' => 'Salah catat pembayaran'])->assertSessionHasNoErrors();

        expect(collect($penyedia->Kumpulkan($konteks))->firstWhere('kunci', 'invoice.bukti-potong-belum')->jumlah)->toBe(0);
    });

    it('catat nomor bukti potong belakangan: tersimpan, terlihat di laporan dan detail, dapat diperbarui, dan tercatat di audit', function (): void {
        [, , $tanpaPph, $denganBukti, $tanpaBukti] = SkenarioPphUji($this);

        $this->post("/kelola/piutang/pelunasan/{$tanpaBukti->Uuid}/bukti-potong", ['NomorBuktiPotong' => '  0011-BP23-2026  '])->assertSessionHasNoErrors()
            ->assertRedirect("/kelola/piutang/pelunasan/{$tanpaBukti->Uuid}");
        expect($tanpaBukti->refresh()->NomorBuktiPotong)->toBe('0011-BP23-2026')
            ->and($tanpaBukti->Jumlah)->toBe('5000000.00')
            ->and($tanpaBukti->PotonganPph)->toBe('100000.00');

        $this->getJson('/kelola/laporan/pajak/pph-dipotong?saring[Bukti]=Belum')->assertJsonCount(0, 'Data');
        $this->get("/kelola/piutang/pelunasan/{$tanpaBukti->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Pelunasan.NomorBuktiPotong', '0011-BP23-2026')
            ->where('Pelunasan.PotonganPph', '100000.00')
            ->where('Tindakan.CatatBuktiPotong', true));
        $this->get("/kelola/piutang/pelunasan/{$tanpaPph->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h->where('Tindakan.CatatBuktiPotong', false));

        // Diperbarui (salah ketik) → audit memuat nilai lama dan baru.
        $this->post("/kelola/piutang/pelunasan/{$denganBukti->Uuid}/bukti-potong", ['NomorBuktiPotong' => '0007-BP23-2026-R'])->assertSessionHasNoErrors();
        $log = LogAudit::query()->where('Peristiwa', 'pelunasan-piutang.bukti-potong')->orderByDesc('Id')->firstOrFail();
        expect($denganBukti->refresh()->NomorBuktiPotong)->toBe('0007-BP23-2026-R')
            ->and($log->NilaiLama['NomorBuktiPotong'])->toBe('0007-BP23-2026')
            ->and($log->NilaiBaru['NomorBuktiPotong'])->toBe('0007-BP23-2026-R');

        // Nomor yang sama sekali lagi tidak menambah audit.
        $jumlahLog = LogAudit::query()->where('Peristiwa', 'pelunasan-piutang.bukti-potong')->count();
        $this->post("/kelola/piutang/pelunasan/{$denganBukti->Uuid}/bukti-potong", ['NomorBuktiPotong' => '0007-BP23-2026-R'])->assertSessionHasNoErrors();
        expect(LogAudit::query()->where('Peristiwa', 'pelunasan-piutang.bukti-potong')->count())->toBe($jumlahLog);
    });

    it('catat nomor bukti potong ditolak: kosong, terlalu panjang, tanpa PPh, dibatalkan, dan tanpa izin akuntansi', function (): void {
        [, , $tanpaPph, , $tanpaBukti] = SkenarioPphUji($this);
        $alamat = fn (PembayaranPiutang $p) => "/kelola/piutang/pelunasan/{$p->Uuid}/bukti-potong";

        $this->post($alamat($tanpaBukti), ['NomorBuktiPotong' => ''])->assertSessionHasErrors('NomorBuktiPotong');
        $this->post($alamat($tanpaBukti), ['NomorBuktiPotong' => str_repeat('9', 41)])->assertSessionHasErrors('NomorBuktiPotong');
        $this->post($alamat($tanpaBukti), ['NomorBuktiPotong' => '   '])->assertSessionHasErrors('NomorBuktiPotong');
        $this->post($alamat($tanpaPph), ['NomorBuktiPotong' => '0001'])->assertSessionHasErrors();
        expect($tanpaPph->refresh()->NomorBuktiPotong)->toBeNull();

        $this->post("/kelola/piutang/pelunasan/{$tanpaBukti->Uuid}/batalkan", ['Alasan' => 'Salah catat pembayaran'])->assertSessionHasNoErrors();
        $this->post($alamat($tanpaBukti), ['NomorBuktiPotong' => '0002'])->assertSessionHasErrors();
        expect($tanpaBukti->refresh()->NomorBuktiPotong)->toBeNull();

        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->post($alamat($tanpaBukti), ['NomorBuktiPotong' => '0003'])->assertForbidden();
    });

    it('tenant lain tidak bisa mencatat bukti potong pelunasan tenant ini', function (): void {
        [, , , , $tanpaBukti] = SkenarioPphUji($this);
        $lain = BantuanPersediaan::SiapkanTenant('Konsultan Lain Bukti');
        BantuanFitur::Beri($lain['Tenant']->Id, 'laporan.lengkap');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);

        $this->post("/kelola/piutang/pelunasan/{$tanpaBukti->Uuid}/bukti-potong", ['NomorBuktiPotong' => '0004'])->assertNotFound();
    });

    it('halaman Laporan pajak tetap terbuka dan menautkan ke rincian PPh', function (): void {
        SkenarioPphUji($this);

        $this->get('/kelola/laporan/pajak?dari=2026-09-01&sampai=2026-09-30')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Laporan/Pajak'));
    });
});
