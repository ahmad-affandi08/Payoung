<?php

declare(strict_types=1);

use App\Domain\Pemenuhan\Model\TiketDapur;
use App\Domain\Penjualan\Model\Penjualan;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * v3.52 nomor antrian & nama pemesan penjualan bayar-dulu (§9.2 QSR): disimpan di Penjualan, tampil di detail
 * back-office, dan menjadi label tiket dapur mode cepat (`#042 Budi`). Perangkat lama yang tidak mengirimnya tetap
 * diterima (label = catatan seperti sebelumnya); nama lebih dari 60 karakter ditolak validasi.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

describe('Nomor antrian & nama pemesan (v3.52)', function (): void {
    it('tersimpan, jadi label tiket dapur mode cepat, dan tampil di detail penjualan', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);
        $jual = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Nasi'], 'Jumlah' => '1', 'Harga' => '35000.00']]], [
            'KirimDapur' => true,
            'NomorAntrian' => '042',
            'NamaPemesan' => 'Budi',
            'Catatan' => 'Tanpa kecap',
        ]);
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$jual]))->toBe([['Diterima', null]]);

        $penjualan = Penjualan::query()->where('Uuid', $jual['Uuid'])->sole();
        expect($penjualan->NomorAntrian)->toBe('042')->and($penjualan->NamaPemesan)->toBe('Budi')
            ->and(TiketDapur::query()->where('IdPenjualan', $penjualan->Id)->sole()->Label)->toBe('#042 Budi');

        BantuanOrganisasi::Masuk($this, $k['Pemilik'], $k['Tenant']->Id);
        $this->get("/kelola/penjualan/{$penjualan->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Penjualan.NomorAntrian', '042')
            ->where('Penjualan.NamaPemesan', 'Budi'));
    });

    it('perangkat lama tanpa antrian: label tetap catatan. Nama pemesan terlalu panjang ditolak', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);
        $lama = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Nasi'], 'Jumlah' => '1', 'Harga' => '35000.00']]], [
            'KirimDapur' => true,
            'Catatan' => 'Meja teras',
        ]);
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$lama]))->toBe([['Diterima', null]]);
        $penjualan = Penjualan::query()->where('Uuid', $lama['Uuid'])->sole();
        expect($penjualan->NomorAntrian)->toBeNull()
            ->and(TiketDapur::query()->where('IdPenjualan', $penjualan->Id)->sole()->Label)->toBe('Meja teras');

        $panjang = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Nasi'], 'Jumlah' => '1', 'Harga' => '35000.00']]], [
            'NamaPemesan' => str_repeat('a', 61),
        ]);
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$panjang])[0][0])->toBe('Ditolak');
    });
});
