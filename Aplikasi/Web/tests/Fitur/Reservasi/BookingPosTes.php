<?php

declare(strict_types=1);

use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pemenuhan\Enum\SumberReservasi;
use App\Domain\Pemenuhan\Model\Reservasi;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * K-20 (§9.8, F-07 mode service): booking salon dari kasir. Kalender satu tanggal (layanan, staf berjadwal + jam kerja,
 * reservasi), slot kosong per layanan/staf, dan buat booking sumber Pos lewat `BuatReservasi` (bentrok = 409). Kasir
 * wajib ber-izin `penjualan.buat`/`reservasi.kelola`; perangkat tenant lain tidak melihat layanan/staf tenant ini.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 08:00', 'Asia/Jakarta'));
});

it('kalender & slot staf. Booking dari kasir mengisi slot. Slot yang sama ditolak 409, tanpa izin 403', function (): void {
    $k = BantuanPenjualan::Siapkan($this, 'Salon Cantik Ayu Solo Baru');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $creambath = BantuanKatalog::BuatProduk(['Nama' => 'Creambath Ginseng Rambut Panjang', 'Jenis' => JenisProduk::Jasa, 'DurasiMenit' => 60], '85000.00');
    $maya = Karyawan::query()->create(['Nama' => 'Maya Senior Stylist']);
    $dewi = Karyawan::query()->create(['Nama' => 'Dewi Junior Stylist']);
    JadwalKerja::query()->create(['IdKaryawan' => $maya->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-10-13', 'JamMulai' => '09:00', 'JamSelesai' => '12:00']);
    JadwalKerja::query()->create(['IdKaryawan' => $dewi->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-10-13', 'JamMulai' => '13:00', 'JamSelesai' => '15:00']);
    $pos = fn () => $this->withToken($k['Token']);

    $pos()->getJson('/api/pos/v1/reservasi/kalender?tanggal=2026-10-13')->assertOk()
        ->assertJsonPath('Tanggal', '2026-10-13')
        ->assertJsonPath('Layanan.0.Uuid', $creambath->Uuid)
        ->assertJsonPath('Layanan.0.DurasiMenit', 60)
        ->assertJsonPath('Staf.0.Nama', 'Dewi Junior Stylist')
        ->assertJsonPath('Staf.0.JamMulai', '13:00')
        ->assertJsonPath('Staf.1.Nama', 'Maya Senior Stylist')
        ->assertJsonCount(0, 'Reservasi');

    $slotMaya = $pos()->getJson("/api/pos/v1/reservasi/slot?UuidLayanan={$creambath->Uuid}&Tanggal=2026-10-13&UuidStaf={$maya->Uuid}")->assertOk()->json('Slot');
    expect(array_column($slotMaya, 'Jam'))->toBe(['09:00', '09:30', '10:00', '10:30', '11:00']);

    $isian = [
        'UuidPengguna' => $k['Kasir']->Uuid,
        'UuidLayanan' => $creambath->Uuid,
        'Tanggal' => '2026-10-13',
        'Jam' => '10:00',
        'UuidStaf' => $maya->Uuid,
        'NamaPelanggan' => 'Rina Wulandari',
        'NoHp' => '0812-3456-7890',
        'Catatan' => 'Rambut sebahu, minta tanpa pewangi',
    ];
    $pos()->postJson('/api/pos/v1/reservasi', $isian)->assertCreated()
        ->assertJsonPath('Reservasi.NamaStaf', 'Maya Senior Stylist')
        ->assertJsonPath('Reservasi.Status', 'Dikonfirmasi')
        ->assertJsonPath('Reservasi.NoHp', '0812-3456-7890');
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $r = Reservasi::query()->sole();
    expect($r->Sumber)->toBe(SumberReservasi::Pos)
        ->and($r->MulaiPada->setTimezone('Asia/Jakarta')->format('H:i'))->toBe('10:00');

    // Slot 09:30–11:00 Maya kini bentrok; jam yang sama ditolak; tanpa staf dipilih → staf lain yang kosong (tidak ada jam 10:00).
    expect(array_column($pos()->getJson("/api/pos/v1/reservasi/slot?UuidLayanan={$creambath->Uuid}&Tanggal=2026-10-13&UuidStaf={$maya->Uuid}")->json('Slot'), 'Jam'))->toBe(['09:00', '11:00']);
    $pos()->postJson('/api/pos/v1/reservasi', [...$isian, 'NamaPelanggan' => 'Sari Dewi', 'NoHp' => '0813-1111-2222'])
        ->assertStatus(409)->assertJsonPath('Galat.Kode', 'SlotTidakTersedia');
    $pos()->postJson('/api/pos/v1/reservasi', [...$isian, 'UuidStaf' => null, 'Jam' => '13:00', 'NoHp' => '0813-1111-2222'])
        ->assertCreated()->assertJsonPath('Reservasi.NamaStaf', 'Dewi Junior Stylist');
    $pos()->getJson('/api/pos/v1/reservasi/kalender?tanggal=2026-10-13')->assertOk()->assertJsonCount(2, 'Reservasi');

    // Pengguna tanpa izin penjualan/reservasi (Akuntan) = 403; perangkat tenant lain tidak melihat layanan & staf ini.
    $akuntan = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Akuntan);
    $pos()->postJson('/api/pos/v1/reservasi', [...$isian, 'UuidPengguna' => $akuntan->Uuid, 'Jam' => '09:00'])
        ->assertForbidden()->assertJsonPath('Galat.Kode', 'TanpaIzin');
    $lain = BantuanPenjualan::Siapkan($this, 'Barbershop Lain Jaya');
    $this->withToken($lain['Token'])->getJson('/api/pos/v1/reservasi/kalender?tanggal=2026-10-13')->assertOk()
        ->assertJsonCount(0, 'Layanan')->assertJsonCount(0, 'Staf')->assertJsonCount(0, 'Reservasi');
    $this->withToken($lain['Token'])->getJson("/api/pos/v1/reservasi/slot?UuidLayanan={$creambath->Uuid}&Tanggal=2026-10-13")->assertNotFound();
});
