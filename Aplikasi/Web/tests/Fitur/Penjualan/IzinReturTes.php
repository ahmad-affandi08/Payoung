<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\PeranIzin;
use App\Domain\Penjualan\Model\PenjualanDetail;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * K-22 (F-09): izin `penjualan.retur` terpisah dari `penjualan.void`. Penyetuju retur wajib ber-izin retur, penyetuju
 * void wajib ber-izin void; migrasi memberi izin retur kepada setiap peran yang sebelumnya ber-izin void.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('supervisor tanpa izin retur tidak bisa menyetujui retur tetapi tetap bisa void. Kasir ber-izin retur menyetujui sendiri', function (): void {
    $k = BantuanPenjualan::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $jual = fn () => BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00']]]);
    $p = $jual();
    $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $peranSupervisor = BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::Supervisor);
    PeranIzin::query()->where('IdPeran', $peranSupervisor->Id)->where('KunciIzin', 'penjualan.retur')->delete();

    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '1']])]))
        ->toBe([['Ditolak', 'PenyetujuTidakBerwenang']]);

    // Kasir diberi izin retur (tanpa void): boleh menyetujui retur sendiri, tetapi tidak boleh menyetujui void.
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $peranKasir = BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::Kasir);
    PeranIzin::query()->create(['IdPeran' => $peranKasir->Id, 'KunciIzin' => 'penjualan.retur']);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
        BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $d, 'Jumlah' => '1']], ['Penyetuju' => $k['Kasir']]),
        BantuanPenjualan::ItemVoid($k, $jual(), ['Penyetuju' => $k['Kasir']]),
    ]))->toBe([['Diterima', null], ['Ditolak', 'PenyetujuTidakBerwenang']]);

    // Supervisor (masih ber-izin void) tetap bisa menyetujui void.
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jual())]))->toBe([['Diterima', null]]);
});

it('migrasi memberi penjualan.retur kepada setiap peran ber-izin penjualan.void, tanpa duplikat', function (): void {
    $k = BantuanKasir::Siapkan($this);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    PeranIzin::query()->where('KunciIzin', 'penjualan.retur')->delete();
    $supervisor = BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::Supervisor);
    $kasir = BantuanOrganisasi::Peran($k['Tenant']->Id, PeranTenantBawaan::Kasir);

    $migrasi = require database_path('migrations/2026_11_12_000141_TambahIzinPenjualanRetur.php');
    $migrasi->up();
    $migrasi->up();

    expect(PeranIzin::query()->where('IdPeran', $supervisor->Id)->where('KunciIzin', 'penjualan.retur')->count())->toBe(1)
        ->and(PeranIzin::query()->where('IdPeran', $kasir->Id)->where('KunciIzin', 'penjualan.retur')->exists())->toBeFalse()
        ->and(PeranIzin::query()->where('KunciIzin', 'penjualan.retur')->count())
        ->toBe(PeranIzin::query()->where('KunciIzin', 'penjualan.void')->count());
});
