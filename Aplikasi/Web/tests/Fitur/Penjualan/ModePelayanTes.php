<?php

declare(strict_types=1);

use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pemenuhan\Model\TiketDapur;
use App\Domain\Penjualan\Model\PesananTerbuka;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * v2.00 mode Pelayan: peran bawaan `Pelayan` (izin `pesanan.meja.catat`) mencatat pesanan meja & mengirim ke dapur
 * tanpa izin berjualan; staf tanpa kedua izin ditolak.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('v2.00 mode Pelayan', function (): void {
    it('peran Pelayan hanya melihat produk & mencatat pesanan meja', function (): void {
        expect(PeranTenantBawaan::Pelayan->AmbilIzin())->toBe([IzinTenant::ProdukLihat, IzinTenant::PesananMejaCatat])
            ->and(IzinTenant::PesananMejaCatat->AmbilKelompok())->toBe('Penjualan');
    });

    it('pelayan membuka pesanan & mengirim ke dapur. Staf gudang ditolak TanpaIzin', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);
        $pelayan = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Pelayan);
        $gudang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::StafGudang);

        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja'], pengguna: $pelayan);
        $tambah = BantuanPesananTerbuka::ItemTambah($k, $buka['Uuid'], [[$k['Kopi'], '1', '25000.00']], pengguna: $pelayan);
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$buka, $tambah]))->toBe([['Diterima', null], ['Diterima', null]])
            ->and(PesananTerbuka::query()->where('Uuid', $buka['Uuid'])->sole()->IdPengguna)->toBe($pelayan->Id)
            ->and(TiketDapur::query()->count())->toBe(1);

        $ditolak = BantuanPesananTerbuka::ItemBuka($k, $k['Meja9'], pengguna: $gudang);
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$ditolak]))->toBe([['Ditolak', 'TanpaIzin']]);
    });
});
