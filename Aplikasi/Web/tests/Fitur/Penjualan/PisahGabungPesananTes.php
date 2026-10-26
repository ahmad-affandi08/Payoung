<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Pemenuhan\Model\TiketDapur;
use App\Domain\Penjualan\Enum\StatusPesananTerbuka;
use App\Domain\Penjualan\Model\PesananTerbuka;
use App\Domain\Penjualan\Model\PesananTerbukaDetail;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * v1.99 pisah tagihan & gabung meja (F-07 mode meja): `PesananTerbuka.PindahBaris` memindah baris aktif antar pesanan
 * terbuka di outlet yang sama. Pisah = buka pesanan baru + pindah sebagian item, lalu masing-masing dibayar; gabung =
 * pindah semua item + tutup asal (`Digabung`). Idempoten; tiket dapur tidak berubah.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

describe('v1.99 pisah tagihan & gabung meja', function (): void {
    it('pisah: item terpilih pindah ke pesanan baru, keduanya dibayar terpisah. Stok & jurnal dari penjualan, tiket dapur tetap', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);
        $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        $uuid = $buka['Uuid'];
        $tambah = BantuanPesananTerbuka::ItemTambah($k, $uuid, [[$k['Kopi'], '2', '25000.00'], [$k['Nasi'], '1', '35000.00']]);
        $uuidNasi = $tambah['Data']['Baris'][1]['Uuid'];
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$buka, $tambah]))->toBe([['Diterima', null], ['Diterima', null]]);

        // Tamu kedua bayar nasinya sendiri: pesanan baru (tanpa meja, berlabel) + pindah baris.
        $pisah = BantuanPesananTerbuka::ItemBuka($k, null, label: 'Meja 7 | Tagihan 2', tamu: 1);
        $pindah = BantuanPesananTerbuka::Item($k, 'PindahBaris', $uuid, ['UuidTujuan' => $pisah['Uuid'], 'UuidBaris' => [$uuidNasi]], 'DipindahPada');
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$pisah, $pindah]))->toBe([['Diterima', null], ['Diterima', null]])
            ->and(BantuanPesananTerbuka::Kirim($this, $k, [$pindah]))->toBe([['Duplikat', null]]);

        $asal = PesananTerbuka::query()->where('Uuid', $uuid)->sole();
        $baru = PesananTerbuka::query()->where('Uuid', $pisah['Uuid'])->sole();
        expect(PesananTerbukaDetail::query()->where('Uuid', $uuidNasi)->sole()->IdPesananTerbuka)->toBe($baru->Id)
            ->and($asal->Status)->toBe(StatusPesananTerbuka::Terbuka)
            ->and(TiketDapur::query()->count())->toBe(2)
            ->and(LogAudit::query()->where('Peristiwa', 'pesanan-terbuka.pindah-item')->count())->toBe(1);

        // Snapshot: nasi tampil di pesanan baru, tidak lagi di pesanan asal.
        $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-terbuka')->assertOk()
            ->assertJsonCount(2, 'Pesanan')
            ->assertJsonCount(1, 'Pesanan.0.Baris')
            ->assertJsonPath('Pesanan.1.Baris.0.Uuid', $uuidNasi);

        $bayar1 = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Kopi'], 'Jumlah' => '2', 'Harga' => '25000.00']]], ['UuidPesananTerbuka' => $uuid]);
        $bayar2 = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $k['Nasi'], 'Jumlah' => '1', 'Harga' => '35000.00']]], ['UuidPesananTerbuka' => $pisah['Uuid']]);
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$bayar1, $bayar2]))->toBe([['Diterima', null], ['Diterima', null]])
            ->and($asal->refresh()->Status)->toBe(StatusPesananTerbuka::Dibayar)
            ->and($baru->refresh()->Status)->toBe(StatusPesananTerbuka::Dibayar)
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);
    });

    it('gabung: semua item meja 9 pindah ke meja 7, meja 9 ditutup Digabung. Pesanan tertutup menolak pindah. Beda outlet ditolak', function (): void {
        $k = BantuanPesananTerbuka::SiapkanRestoran($this);
        $buka7 = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
        $tambah7 = BantuanPesananTerbuka::ItemTambah($k, $buka7['Uuid'], [[$k['Kopi'], '1', '25000.00']]);
        $buka9 = BantuanPesananTerbuka::ItemBuka($k, $k['Meja9']);
        $tambah9 = BantuanPesananTerbuka::ItemTambah($k, $buka9['Uuid'], [[$k['Nasi'], '2', '35000.00'], [$k['Kopi'], '1', '25000.00']]);
        BantuanPesananTerbuka::Kirim($this, $k, [$buka7, $tambah7, $buka9, $tambah9]);

        $semua = array_map(fn (array $b): string => $b['Uuid'], $tambah9['Data']['Baris']);
        $gabung = BantuanPesananTerbuka::Item($k, 'PindahBaris', $buka9['Uuid'], ['UuidTujuan' => $buka7['Uuid'], 'UuidBaris' => $semua, 'TutupAsal' => true], 'DipindahPada');
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$gabung]))->toBe([['Diterima', null]]);

        $meja9 = PesananTerbuka::query()->where('Uuid', $buka9['Uuid'])->sole();
        $meja7 = PesananTerbuka::query()->where('Uuid', $buka7['Uuid'])->sole();
        expect($meja9->Status)->toBe(StatusPesananTerbuka::Digabung)
            ->and($meja9->DitutupPada)->not->toBeNull()
            ->and(PesananTerbukaDetail::query()->where('IdPesananTerbuka', $meja7->Id)->count())->toBe(3);

        $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-terbuka')->assertOk()
            ->assertJsonCount(1, 'Pesanan')
            ->assertJsonPath('Ditutup.0.Uuid', $buka9['Uuid'])
            ->assertJsonPath('Ditutup.0.Status', 'Digabung');

        // Pesanan yang sudah digabung tidak bisa jadi tujuan; asal = tujuan ditolak.
        $buka10 = BantuanPesananTerbuka::ItemBuka($k, null, label: 'Bungkus Pak Budi', tamu: 1);
        $tambah10 = BantuanPesananTerbuka::ItemTambah($k, $buka10['Uuid'], [[$k['Kopi'], '1', '25000.00']]);
        BantuanPesananTerbuka::Kirim($this, $k, [$buka10, $tambah10]);
        $keTutup = BantuanPesananTerbuka::Item($k, 'PindahBaris', $buka10['Uuid'], ['UuidTujuan' => $buka9['Uuid'], 'UuidBaris' => [$tambah10['Data']['Baris'][0]['Uuid']]], 'DipindahPada');
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$keTutup])[0])->toBe(['Ditolak', 'PesananSudahDitutup']);
        $keDiri = BantuanPesananTerbuka::Item($k, 'PindahBaris', $buka10['Uuid'], ['UuidTujuan' => $buka10['Uuid'], 'UuidBaris' => [$tambah10['Data']['Baris'][0]['Uuid']]], 'DipindahPada');
        expect(BantuanPesananTerbuka::Kirim($this, $k, [$keDiri])[0])->toBe(['Ditolak', 'TujuanSama']);
    });
});
