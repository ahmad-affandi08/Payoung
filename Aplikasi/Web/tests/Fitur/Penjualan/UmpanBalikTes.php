<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Penjualan\Layanan\KodeStrukDigital;
use App\Domain\Penjualan\Model\UmpanBalikTransaksi;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F7 (CRM-08): penilaian 1–5 + komentar dari struk digital `/s/{kodeStruk}`; satu per transaksi (tidak menimpa),
 * tidak untuk transaksi void / kedaluwarsa / dimatikan; tab Umpan balik di laporan penjualan.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array<string, mixed>
 */
function SiapkanUmpanBalik(mixed $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Kedai Kopi Umpan Balik Solo');
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '30');
    $p = BantuanPenjualan::Jual($tes, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);

    return [...$k, 'Minyak' => $minyak, 'Penjualan' => $p, 'Kode' => KodeStrukDigital::Buat($k['Tenant']->Id, $p->Uuid)];
}

it('struk menawarkan penilaian; kiriman pertama tersimpan, halaman menampilkan penilaian, kiriman kedua tidak menimpa', function (): void {
    $k = SiapkanUmpanBalik($this);

    $this->get('/s/'.$k['Kode'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Struk.UmpanBalik', ['Diminta' => true, 'Nilai' => null, 'Komentar' => null]));

    $this->post("/s/{$k['Kode']}/umpan-balik", ['Nilai' => 4, 'Komentar' => "  Kopinya enak, antrean agak lama.\x07  "])
        ->assertRedirect('/s/'.$k['Kode'])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $umpan = UmpanBalikTransaksi::query()->sole();
    expect($umpan->Nilai)->toBe(4)
        ->and($umpan->Komentar)->toBe('Kopinya enak, antrean agak lama.')
        ->and($umpan->IdPenjualan)->toBe($k['Penjualan']->Id)
        ->and($umpan->IdOutlet)->toBe($k['Outlet']->Id);

    $this->get('/s/'.$k['Kode'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Struk.UmpanBalik', ['Diminta' => false, 'Nilai' => 4, 'Komentar' => 'Kopinya enak, antrean agak lama.']));

    // Kiriman kedua (juga dari tab lain) tidak menimpa penilaian pertama.
    $this->post("/s/{$k['Kode']}/umpan-balik", ['Nilai' => 1, 'Komentar' => 'Berubah pikiran'])->assertRedirect('/s/'.$k['Kode']);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(UmpanBalikTransaksi::query()->count())->toBe(1)->and(UmpanBalikTransaksi::query()->sole()->Nilai)->toBe(4);
});

it('validasi: nilai 1–5 wajib, komentar maks 500 karakter, kode tidak dikenal 404, penilaian tanpa komentar disimpan null', function (): void {
    $k = SiapkanUmpanBalik($this);

    $this->post("/s/{$k['Kode']}/umpan-balik", ['Nilai' => 0])->assertSessionHasErrors('Nilai');
    $this->post("/s/{$k['Kode']}/umpan-balik", ['Nilai' => 6])->assertSessionHasErrors('Nilai');
    $this->post("/s/{$k['Kode']}/umpan-balik", [])->assertSessionHasErrors('Nilai');
    $this->post("/s/{$k['Kode']}/umpan-balik", ['Nilai' => 3, 'Komentar' => str_repeat('a', 501)])->assertSessionHasErrors('Komentar');
    $this->post('/s/'.KodeStrukDigital::Buat($k['Tenant']->Id, (string) Str::ulid()).'/umpan-balik', ['Nilai' => 5])->assertNotFound();
    $this->post('/s/zzzzzzzzzzzz.'.$k['Penjualan']->Uuid.'/umpan-balik', ['Nilai' => 5])->assertNotFound();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(UmpanBalikTransaksi::query()->count())->toBe(0);

    $this->post("/s/{$k['Kode']}/umpan-balik", ['Nilai' => 5, 'Komentar' => '   '])->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(UmpanBalikTransaksi::query()->sole()->Komentar)->toBeNull();
});

it('tenant lain tidak bisa menilai transaksi ini lewat kode tenant lain', function (): void {
    $a = SiapkanUmpanBalik($this);
    $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
    BantuanOrganisasi::AturKonteks($b['Tenant']->Id);

    $this->post('/s/'.KodeStrukDigital::Buat($b['Tenant']->Id, $a['Penjualan']->Uuid).'/umpan-balik', ['Nilai' => 5])->assertNotFound();
    BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
    expect(UmpanBalikTransaksi::query()->count())->toBe(0);
});

it('transaksi void tidak ditawarkan dan ditolak; saklar pengaturan struk mematikan penilaian', function (): void {
    $k = SiapkanUmpanBalik($this);
    $kedua = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Minyak'], 'Jumlah' => '1', 'Harga' => '38500.00']]]);
    $kodeKedua = KodeStrukDigital::Buat($k['Tenant']->Id, $kedua->Uuid);
    BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $kedua)]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

    $this->get('/s/'.$kodeKedua)->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Struk.UmpanBalik', null));
    $this->post("/s/{$kodeKedua}/umpan-balik", ['Nilai' => 5])->assertSessionHasErrors('Umum');

    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
    $this->put('/kelola/kasir/struk', [
        'TampilkanLogo' => true, 'TampilkanAlamat' => true, 'TampilkanTelepon' => true, 'TampilkanNpwp' => true,
        'TampilkanKasir' => true, 'TampilkanPelanggan' => true, 'TampilkanHemat' => true, 'TampilkanUmpanBalik' => false,
        'NamaDicetak' => null, 'TeksKepala' => [], 'CatatanKaki' => null, 'TeksPenutup' => null,
    ])->assertRedirect('/kelola/kasir/struk');
    $this->get('/s/'.$k['Kode'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Struk.UmpanBalik', null));
    $this->post("/s/{$k['Kode']}/umpan-balik", ['Nilai' => 5])->assertSessionHasErrors('Umum');
    $this->get('/kelola/kasir/struk')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Pengaturan.TampilkanUmpanBalik', false));
});

it('batas 30 hari: transaksi lama tidak ditawarkan dan ditolak; waktu berjalan menutupnya', function (): void {
    $k = SiapkanUmpanBalik($this);
    $this->get('/s/'.$k['Kode'])->assertInertia(fn (AssertableInertia $h) => $h->where('Struk.UmpanBalik.Diminta', true));

    $this->travelTo(now()->addDays(31));
    $this->get('/s/'.$k['Kode'])->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Struk.UmpanBalik', null));
    $this->post("/s/{$k['Kode']}/umpan-balik", ['Nilai' => 5])->assertSessionHasErrors('Umum');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(UmpanBalikTransaksi::query()->count())->toBe(0);
});

it('tab Umpan balik di laporan penjualan: rata-rata, sebaran, tingkat respons, daftar terbaru; ekspor CSV', function (): void {
    $k = SiapkanUmpanBalik($this);
    $jual = fn () => BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $k['Minyak'], 'Jumlah' => '1', 'Harga' => '38500.00']]]);
    $kedua = $jual();
    $ketiga = $jual();
    $jual();   // tanpa penilaian
    $this->post("/s/{$k['Kode']}/umpan-balik", ['Nilai' => 5, 'Komentar' => 'Mantap'])->assertSessionHasNoErrors();
    $this->post('/s/'.KodeStrukDigital::Buat($k['Tenant']->Id, $kedua->Uuid).'/umpan-balik', ['Nilai' => 2, 'Komentar' => 'Lama sekali'])->assertSessionHasNoErrors();
    $this->post('/s/'.KodeStrukDigital::Buat($k['Tenant']->Id, $ketiga->Uuid).'/umpan-balik', ['Nilai' => 5])->assertSessionHasNoErrors();
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?tab=umpan-balik')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Tab', 'umpan-balik')
        ->where('Isi.Ringkasan.Jumlah', 3)
        ->where('Isi.Ringkasan.RataRata', '4.00')
        ->where('Isi.Ringkasan.PerNilai', ['1' => 0, '2' => 1, '3' => 0, '4' => 0, '5' => 2])
        ->where('Isi.Ringkasan.JumlahTransaksi', 4)
        ->where('Isi.Ringkasan.TingkatRespons', '75.00')
        ->has('Isi.Baris', 3)
        ->where('Isi.Baris.0.NamaOutlet', $k['Outlet']->Nama));

    $csv = $this->get('/kelola/laporan/penjualan/ekspor?tab=umpan-balik')->assertOk()->streamedContent();
    expect($csv)->toContain('Komentar')->toContain('Lama sekali')->toContain('Mantap');
});
