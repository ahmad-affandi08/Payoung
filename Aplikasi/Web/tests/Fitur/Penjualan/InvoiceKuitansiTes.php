<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Penjualan\Layanan\KodeInvoicePublik;
use App\Domain\Penjualan\Model\Invoice;
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
 * Kuitansi pembayaran invoice (F-21 bagian 5, D-170): satu keluaran untuk cetak A4 back-office dan halaman publik
 * `/i/{kode}/kuitansi/{pembayaran}`. Nomor kuitansi = nomor pelunasan; uang diterima = dilunasi − PPh yang dipotong pembeli;
 * sisa tagihan dihitung sesudah pembayaran itu menurut urutan waktu, tanpa menghitung pelunasan yang dibatalkan.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->k = BantuanPersediaan::SiapkanTenant('Konsultan Kuitansi Prima');
    BantuanFitur::Beri($this->k['Tenant']->Id, 'invoice.penagihan');
    $this->klien = Pelanggan::query()->create(['Nama' => 'PT Pembayar Setia Abadi', 'NoHp' => '6281355550404', 'Email' => 'rahasia@setia.id', 'Alamat' => 'Jl. Slamet Riyadi 12, Surakarta', 'TerminHari' => 14]);
    $this->jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa pelatihan', 'Jenis' => JenisProduk::Jasa], '10000000.00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Invoice 10 juta ber-PPh 2% (200 ribu) yang dibayar dua kali: 4 juta, lalu 6 juta termasuk PPh 200 ribu. */
function InvoiceDibayarDuaKaliUji($tes): array
{
    BantuanPersediaan::MasukSebagai($tes, $tes->k['Tenant']->Id);
    $tes->post('/kelola/invoice', [
        'Jenis' => 'Penjualan', 'UuidPelanggan' => $tes->klien->Uuid, 'UuidOutlet' => $tes->k['Outlet']->Uuid, 'Tanggal' => '2026-09-27',
        'Perihal' => 'Pelatihan manajemen proyek', 'HargaTermasukPajak' => false, 'KodePph' => 'PPh 23', 'TarifPph' => '2', 'Lanjutkan' => true,
        'Baris' => [['UuidProduk' => $tes->jasa->Uuid, 'Nama' => 'Pelatihan', 'JenisBaris' => 'Jasa', 'Jumlah' => '1', 'Harga' => '10000000']],
    ])->assertSessionHasNoErrors();
    $invoice = Invoice::query()->sole();
    $akun = BantuanPembelian::AkunKas('1-1200')->Uuid;
    $tes->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", ['UuidAkun' => $akun, 'Tanggal' => '2026-09-27', 'Jumlah' => '4000000'])->assertSessionHasNoErrors();
    Carbon::setTestNow('2026-09-29 03:00:00');
    $tes->post("/kelola/invoice/{$invoice->Uuid}/pembayaran", ['UuidAkun' => $akun, 'Tanggal' => '2026-09-29', 'Jumlah' => '6000000', 'PotonganPph' => '200000', 'NomorBuktiPotong' => '0007-BP23-2026'])->assertSessionHasNoErrors();
    $pembayaran = PembayaranPiutang::query()->orderBy('Id')->get();

    return [$invoice, $pembayaran[0], $pembayaran[1]];
}

describe('Kuitansi invoice', function (): void {
    it('cetak back-office: nomor pelunasan, uang diterima tanpa PPh, sisa sesudah tiap pembayaran, tanpa akun kas', function (): void {
        [$invoice, $pertama, $kedua] = InvoiceDibayarDuaKaliUji($this);

        $this->get("/kelola/invoice/{$invoice->Uuid}/kuitansi/{$pertama->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Invoice/Kuitansi')
            ->where('Kuitansi.Nomor', $pertama->Nomor)
            ->where('Kuitansi.Jumlah', '4000000.00')
            ->where('Kuitansi.PotonganPph', '0.00')
            ->where('Kuitansi.UangDiterima', '4000000.00')
            ->where('Kuitansi.SisaSetelah', '6000000.00')
            ->where('Kuitansi.Status', 'Diposting')
            ->where('Invoice.Nomor', $invoice->Nomor)
            ->where('Invoice.Perihal', 'Pelatihan manajemen proyek')
            ->where('Penjual.Nama', fn ($v) => is_string($v) && $v !== '')
            ->where('Pembeli.Nama', 'PT Pembayar Setia Abadi')
            ->missing('Kuitansi.IdAkun')
            ->missing('Jurnal'));

        $this->get("/kelola/invoice/{$invoice->Uuid}/kuitansi/{$kedua->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Kuitansi.Nomor', $kedua->Nomor)
            ->where('Kuitansi.Jumlah', '6000000.00')
            ->where('Kuitansi.PotonganPph', '200000.00')
            ->where('Kuitansi.UangDiterima', '5800000.00')
            ->where('Kuitansi.NomorBuktiPotong', '0007-BP23-2026')
            ->where('Kuitansi.KodePph', 'PPh 23')
            ->where('Kuitansi.SisaSetelah', '0.00'));
    });

    it('halaman publik memakai kode rahasia invoice: tanpa kontak pembeli; kode, pembayaran, atau tenant yang salah 404', function (): void {
        [$invoice, $pertama] = InvoiceDibayarDuaKaliUji($this);
        $kode = KodeInvoicePublik::Buat($invoice->IdTenant, $invoice->KodePublik);
        $lain = BantuanPersediaan::SiapkanTenant('Konsultan Lain Kuitansi');
        Auth::logout();

        $this->get("/i/{$kode}/kuitansi/{$pertama->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Publik/KuitansiInvoice')
            ->where('KodeInvoice', $kode)
            ->where('Dokumen.Kuitansi.Nomor', $pertama->Nomor)
            ->where('Dokumen.Pembeli.NoHp', '')
            ->where('Dokumen.Pembeli.Email', null));

        $this->get("/i/{$kode}/kuitansi/".strtoupper((string) Str::ulid()))->assertNotFound();
        $this->get("/i/{$kode}/kuitansi/tidak-valid")->assertNotFound();
        $this->get('/i/'.KodeInvoicePublik::Buat($lain['Tenant']->Id, $invoice->KodePublik)."/kuitansi/{$pertama->Uuid}")->assertNotFound();
        $this->get('/i/'.KodeInvoicePublik::Buat($invoice->IdTenant, str_repeat('a', 32))."/kuitansi/{$pertama->Uuid}")->assertNotFound();
    });

    it('halaman invoice publik dan cetak memuat Uuid pembayaran untuk tautan kuitansi', function (): void {
        [$invoice, $pertama, $kedua] = InvoiceDibayarDuaKaliUji($this);
        $kode = KodeInvoicePublik::Buat($invoice->IdTenant, $invoice->KodePublik);

        $this->get("/kelola/invoice/{$invoice->Uuid}/cetak")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Pelunasan', fn ($daftar) => collect($daftar)->pluck('Uuid')->sort()->values()->all() === collect([$pertama->Uuid, $kedua->Uuid])->sort()->values()->all()));
        Auth::logout();
        $this->get("/i/{$kode}")->assertInertia(fn (AssertableInertia $h) => $h->has('Dokumen.Pelunasan.0.Uuid'));
    });

    it('kuitansi pembayaran milik invoice lain 404; draf tidak punya kuitansi', function (): void {
        [$invoice, $pertama] = InvoiceDibayarDuaKaliUji($this);
        $this->post('/kelola/invoice', [
            'Jenis' => 'Penjualan', 'UuidPelanggan' => $this->klien->Uuid, 'UuidOutlet' => $this->k['Outlet']->Uuid, 'Tanggal' => '2026-09-29',
            'HargaTermasukPajak' => false, 'Baris' => [['Nama' => 'Jasa lain', 'JenisBaris' => 'Jasa', 'Jumlah' => '1', 'Harga' => '1000000']],
        ])->assertSessionHasNoErrors();
        $draf = Invoice::query()->where('Status', 'Draf')->sole();

        $this->get("/kelola/invoice/{$draf->Uuid}/kuitansi/{$pertama->Uuid}")->assertNotFound();
        $kodeDraf = KodeInvoicePublik::Buat($draf->IdTenant, $draf->KodePublik);
        Auth::logout();
        $this->get("/i/{$kodeDraf}/kuitansi/{$pertama->Uuid}")->assertNotFound();
    });

    it('pelunasan yang dibatalkan: kuitansinya bertanda dibatalkan dan tidak dihitung dalam sisa kuitansi lain', function (): void {
        [$invoice, $pertama, $kedua] = InvoiceDibayarDuaKaliUji($this);

        $this->post("/kelola/piutang/pelunasan/{$pertama->Uuid}/batalkan", ['Alasan' => 'Salah catat pembayaran'])->assertSessionHasNoErrors();

        $this->get("/kelola/invoice/{$invoice->Uuid}/kuitansi/{$pertama->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Kuitansi.Status', 'Dibatalkan')
            ->where('Kuitansi.AlasanBatal', 'Salah catat pembayaran'));
        $this->get("/kelola/invoice/{$invoice->Uuid}/kuitansi/{$kedua->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Kuitansi.Status', 'Diposting')
            ->where('Kuitansi.AlasanBatal', null)
            ->where('Kuitansi.SisaSetelah', '4000000.00'));
    });

    it('tanpa izin invoice.kelola kuitansi back-office ditolak; tenant lain 404', function (): void {
        [$invoice, $pertama] = InvoiceDibayarDuaKaliUji($this);

        BantuanKatalog::MasukSebagai($this, $this->k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get("/kelola/invoice/{$invoice->Uuid}/kuitansi/{$pertama->Uuid}")->assertForbidden();

        $lain = BantuanPersediaan::SiapkanTenant('Konsultan Lain Kuitansi');
        BantuanFitur::Beri($lain['Tenant']->Id, 'invoice.penagihan');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->get("/kelola/invoice/{$invoice->Uuid}/kuitansi/{$pertama->Uuid}")->assertNotFound();
        BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
    });
});
