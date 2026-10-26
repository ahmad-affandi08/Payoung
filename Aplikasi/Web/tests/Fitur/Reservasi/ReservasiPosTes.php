<?php

declare(strict_types=1);

use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Pemenuhan\Enum\StatusReservasi;
use App\Domain\Pemenuhan\Enum\SumberReservasi;
use App\Domain\Pemenuhan\Model\Reservasi;
use App\Domain\Penjualan\Model\Penjualan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-07 mode service bagian 2: antrian reservasi di aplikasi kasir (online), check-in pelanggan, dan penjualan yang
 * merujuk reservasi (`Penjualan.Buat` `UuidReservasi`) menyelesaikan & menautkannya di transaksi yang sama. Reservasi
 * tidak dikenal/selesai = penjualan tetap diterima + tinjauan `Reservasi`.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:30', 'Asia/Jakarta'));
});

it('antrian hari ini berisi layanan & staf. Check-in idempoten. Penjualan menyelesaikan & menautkan reservasi', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Salon Cantik Ayu Solo');
    $layanan = BantuanKatalog::BuatProduk(['Nama' => 'Creambath Ginseng', 'Jenis' => JenisProduk::Jasa, 'DurasiMenit' => 60], '85000.00');
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $maya = Karyawan::query()->create(['Nama' => 'Maya Senior Stylist']);
    $buat = fn (string $jam, string $nama): Reservasi => Reservasi::query()->create([
        'IdOutlet' => $k['Outlet']->Id,
        'Nomor' => 'RS/2026/10/'.Str::padLeft((string) (Reservasi::query()->count() + 1), 4, '0'),
        'NamaPelanggan' => $nama,
        'NoHp' => '6281234567890',
        'IdProduk' => $layanan->Id,
        'IdKaryawan' => $maya->Id,
        'MulaiPada' => CarbonImmutable::parse("2026-10-13 {$jam}", 'Asia/Jakarta')->utc(),
        'SelesaiPada' => CarbonImmutable::parse("2026-10-13 {$jam}", 'Asia/Jakarta')->addHour()->utc(),
        'Status' => StatusReservasi::Dikonfirmasi,
        'Sumber' => SumberReservasi::BackOffice,
        'KodeAkses' => Str::upper(Str::random(12)),
    ]);
    $satu = $buat('10:00', 'Rina Wulandari');
    $buat('14:00', 'Sari Dewi');

    $this->withToken($k['Token'])->getJson('/api/pos/v1/reservasi')->assertOk()
        ->assertJsonCount(2, 'Reservasi')
        ->assertJsonPath('Reservasi.0.Nomor', 'RS/2026/10/0001')
        ->assertJsonPath('Reservasi.0.UuidProduk', $layanan->Uuid)
        ->assertJsonPath('Reservasi.0.NamaLayanan', 'Creambath Ginseng')
        ->assertJsonPath('Reservasi.0.UuidStaf', $maya->Uuid)
        ->assertJsonPath('Reservasi.0.NoHp', '0812-3456-7890');
    $this->withToken($k['Token'])->getJson('/api/pos/v1/reservasi?tanggal=2026-10-14')->assertOk()->assertJsonCount(0, 'Reservasi');

    $this->withToken($k['Token'])->postJson("/api/pos/v1/reservasi/{$satu->Uuid}/hadir", ['UuidPengguna' => $k['Kasir']->Uuid])
        ->assertOk()->assertJsonPath('Reservasi.Status', 'Hadir');
    $this->withToken($k['Token'])->postJson("/api/pos/v1/reservasi/{$satu->Uuid}/hadir", ['UuidPengguna' => $k['Kasir']->Uuid])
        ->assertOk()->assertJsonPath('Reservasi.Status', 'Hadir');

    $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]], ['UuidReservasi' => $satu->Uuid]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $penjualan = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
    expect($satu->refresh()->Status)->toBe(StatusReservasi::Selesai)
        ->and($satu->IdPenjualan)->toBe($penjualan->Id)
        ->and($penjualan->PerluTinjauan)->toBeFalse()
        ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'Reservasi')->where('IdDokumen', $satu->Id)->orderBy('Id')->pluck('StatusKe')->all())->toBe(['Hadir', 'Selesai']);

    // Kirim ulang item yang sama (idempoten) tidak mengubah apa pun.
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
});

it('reservasi tidak dikenal atau sudah selesai: penjualan tetap diterima + tinjauan. Check-in butuh izin & outlet sendiri', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Barbershop Gagah Klaten');
    $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);

    $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]], ['UuidReservasi' => (string) Str::ulid()]);
    expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $penjualan = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
    expect($penjualan->PerluTinjauan)->toBeTrue()->and($penjualan->AlasanTinjauan)->toContain('Reservasi: reservasi tidak ditemukan');

    $this->withToken($k['Token'])->postJson('/api/pos/v1/reservasi/'.Str::ulid().'/hadir', ['UuidPengguna' => $k['Kasir']->Uuid])->assertNotFound();
    $this->withToken($k['Token'])->postJson('/api/pos/v1/reservasi/'.Str::ulid().'/hadir', ['UuidPengguna' => (string) Str::ulid()])->assertForbidden();
});
