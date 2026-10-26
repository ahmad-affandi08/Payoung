<?php

declare(strict_types=1);

use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * §10.10: tab "Per pelanggan" (omzet bersih per pelanggan + baris "Tanpa pelanggan", retur mengurangi pelanggan penjualan
 * asalnya) dan "Perbandingan outlet" (angka per outlet + margin + porsi). Ekspor CSV ikut tab.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
});

/**
 * Ani: 2 + 1 unit; Budi: 1 unit (retur di tes lain); tanpa pelanggan: 2 unit. Semua minyak @ 38.500, HPP 30.000.
 *
 * @return array<string, mixed>
 */
function SiapkanPenjualanPelanggan(mixed $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Warung Kopi Pelanggan Klaten');
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '30');
    $ani = Pelanggan::query()->create(['Nama' => 'Ani Wijayanti', 'NoHp' => '6281355550101']);
    $budi = Pelanggan::query()->create(['Nama' => 'Budi Santoso', 'NoHp' => '6281355550102']);
    $jual = function (int $jumlah, ?Pelanggan $pelanggan) use ($tes, $k, $minyak) {
        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => (string) $jumlah, 'Harga' => '38500.00']]], $pelanggan === null ? [] : ['UuidPelanggan' => $pelanggan->Uuid]);
        expect(BantuanKasir::KirimRingkas($tes, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        return $item;
    };
    $jual(2, $ani);
    $jual(1, $ani);
    $jual(2, null);
    $itemBudi = $jual(1, $budi);

    return [...$k, 'Minyak' => $minyak, 'Ani' => $ani, 'Budi' => $budi, 'UuidPenjualanBudi' => $itemBudi['Uuid']];
}

it('per pelanggan: omzet bersih, porsi, baris tanpa pelanggan, urut omzet. Ekspor CSV', function (): void {
    $k = SiapkanPenjualanPelanggan($this);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&tab=pelanggan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->component('Kelola/Laporan/Penjualan')
        ->where('Saring.Tab', 'pelanggan')
        ->has('Isi', 3)
        ->where('Isi.0.NamaPelanggan', 'Ani Wijayanti')
        ->where('Isi.0.Kunci', $k['Ani']->Uuid)
        ->where('Isi.0.Bersih', '115500.00')
        ->where('Isi.0.JumlahTransaksi', 2)
        ->where('Isi.0.Porsi', '50.00')
        ->where('Isi.0.RataRataKeranjang', '57750.00')
        ->where('Isi.1.NamaPelanggan', 'Tanpa pelanggan')
        ->where('Isi.1.Kunci', '')
        ->where('Isi.1.Bersih', '77000.00')
        ->where('Isi.1.Porsi', '33.33')
        ->where('Isi.2.NamaPelanggan', 'Budi Santoso')
        ->where('Isi.2.Bersih', '38500.00')
        ->where('Isi.2.Porsi', '16.67'));

    $csv = $this->get('/kelola/laporan/penjualan/ekspor?dari=2026-10-01&sampai=2026-10-07&tab=pelanggan')->assertOk()->streamedContent();
    expect($csv)->toContain('Pelanggan')->toContain('Ani Wijayanti')->toContain('Tanpa pelanggan')->toContain('Porsi');
});

it('per pelanggan: retur mengurangi pelanggan penjualan asalnya, bukan baris tanpa pelanggan', function (): void {
    $k = SiapkanPenjualanPelanggan($this);
    $penjualan = Penjualan::query()->where('Uuid', $k['UuidPenjualanBudi'])->sole();
    $detail = PenjualanDetail::query()->where('IdPenjualan', $penjualan->Id)->sole();
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $penjualan, [['Detail' => $detail, 'Jumlah' => '1']])]))->toBe([['Diterima', null]]);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&tab=pelanggan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Isi.2.NamaPelanggan', 'Budi Santoso')
        ->where('Isi.2.Retur', '38500.00')
        ->where('Isi.2.Bersih', '0.00')
        ->where('Isi.1.NamaPelanggan', 'Tanpa pelanggan')
        ->where('Isi.1.Retur', '0.00'));
});

it('perbandingan outlet: angka per outlet, margin laba kotor, porsi omzet. Ekspor CSV', function (): void {
    $k = SiapkanPenjualanPelanggan($this);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&tab=outlet')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->where('Saring.Tab', 'outlet')
        ->has('Isi', 1)
        ->where('Isi.0.NamaOutlet', $k['Outlet']->Nama)
        ->where('Isi.0.Kunci', $k['Outlet']->Uuid)
        ->where('Isi.0.Bersih', '231000.00')
        ->where('Isi.0.Hpp', '180000.00')
        ->where('Isi.0.LabaKotor', '51000.00')
        ->where('Isi.0.JumlahTransaksi', 4)
        ->where('Isi.0.Margin', '22.08')
        ->where('Isi.0.Porsi', '100.00'));

    $csv = $this->get('/kelola/laporan/penjualan/ekspor?dari=2026-10-01&sampai=2026-10-07&tab=outlet')->assertOk()->streamedContent();
    expect($csv)->toContain('Outlet')->toContain('Margin %')->toContain($k['Outlet']->Nama);
});

it('perbandingan outlet dan per pelanggan hanya memuat outlet yang boleh diakses pengguna', function (): void {
    $k = SiapkanPenjualanPelanggan($this);
    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

    $this->get('/kelola/laporan/penjualan?dari=2026-10-01&sampai=2026-10-07&tab=outlet&outlet='.BantuanKasir::Uuid())->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
        ->has('Isi', 0));
});
