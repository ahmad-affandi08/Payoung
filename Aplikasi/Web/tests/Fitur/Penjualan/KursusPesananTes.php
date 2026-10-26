<?php

declare(strict_types=1);

use App\Domain\Pemenuhan\Model\TiketDapur;
use App\Domain\Penjualan\Enum\KursusPesanan;
use App\Domain\Penjualan\Model\PesananTerbukaDetail;
use Tests\Pendukung\Penjualan\BantuanPesananTerbuka;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * K-13 (§9.1): kursus Pembuka/Utama/Penutup dengan "tahan & kirim". Kursus tersimpan per baris dan tampil di snapshot;
 * kursus yang ditahan dikirim belakangan lewat `PesananTerbuka.KirimDapur` (hanya baris kursus itu) sebagai ronde baru.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('kursus tersimpan & tampil di snapshot. Pembuka dikirim, utama ditahan lalu dikirim sebagai ronde baru. Kursus tak dikenal ditolak', function (): void {
    $k = BantuanPesananTerbuka::SiapkanRestoran($this);
    $buka = BantuanPesananTerbuka::ItemBuka($k, $k['Meja']);
    $uuid = $buka['Uuid'];
    $pembuka = BantuanPesananTerbuka::ItemTambah($k, $uuid, [[$k['Kopi'], '1', '25000.00']]);
    $pembuka['Data']['Baris'][0]['Kursus'] = 'Pembuka';
    $utama = BantuanPesananTerbuka::ItemTambah($k, $uuid, [[$k['Nasi'], '2', '35000.00']], 2, false);
    $utama['Data']['Baris'][0]['Kursus'] = 'Utama';
    expect(BantuanPesananTerbuka::Kirim($this, $k, [$buka, $pembuka, $utama]))->toBe([['Diterima', null], ['Diterima', null], ['Diterima', null]]);

    $uuidUtama = $utama['Data']['Baris'][0]['Uuid'];
    expect(PesananTerbukaDetail::query()->where('Uuid', $uuidUtama)->sole()->Kursus)->toBe(KursusPesanan::Utama)
        ->and(PesananTerbukaDetail::query()->where('Uuid', $uuidUtama)->sole()->DikirimKeDapurPada)->toBeNull()
        ->and(TiketDapur::query()->count())->toBe(1);

    $this->withToken($k['Token'])->getJson('/api/pos/v1/pesanan-terbuka')->assertOk()
        ->assertJsonPath('Pesanan.0.Baris.0.Kursus', 'Pembuka')
        ->assertJsonPath('Pesanan.0.Baris.1.Kursus', 'Utama')
        ->assertJsonPath('Pesanan.0.Baris.1.DikirimKeDapur', false);

    // Kirim kursus Utama.
    $kirim = BantuanPesananTerbuka::Item($k, 'KirimDapur', $uuid, ['Ronde' => 3, 'UuidBaris' => [$uuidUtama]], 'DikirimPada');
    expect(BantuanPesananTerbuka::Kirim($this, $k, [$kirim]))->toBe([['Diterima', null]]);
    $baris = PesananTerbukaDetail::query()->where('Uuid', $uuidUtama)->sole();
    expect($baris->DikirimKeDapurPada)->not->toBeNull()->and($baris->Ronde)->toBe(3)->and($baris->Kursus)->toBe(KursusPesanan::Utama)
        ->and(TiketDapur::query()->where('Ronde', 3)->sole()->IdStasiunDapur)->toBe($k['Dapur']->Id);

    // Tanpa kursus tetap boleh (perangkat lama); kursus karangan ditolak.
    expect(BantuanPesananTerbuka::Kirim($this, $k, [BantuanPesananTerbuka::ItemTambah($k, $uuid, [[$k['Kopi'], '1', '25000.00']], 4)]))->toBe([['Diterima', null]]);
    $salah = BantuanPesananTerbuka::ItemTambah($k, $uuid, [[$k['Kopi'], '1', '25000.00']], 5);
    $salah['Data']['Baris'][0]['Kursus'] = 'Camilan';
    expect(BantuanPesananTerbuka::Kirim($this, $k, [$salah]))->toBe([['Ditolak', 'DataTidakValid']]);
});
