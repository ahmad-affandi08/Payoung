<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-18 (v3.34): koreksi absensi manual oleh pengelola — catat absensi yang terlewat (Sumber Manual) dan koreksi jam
 * absensi dari kasir, dengan alasan wajib, jam waktu outlet, shift lewat tengah malam, tanpa tumpang tindih, terekam
 * di baris & audit, dan hanya untuk `karyawan.kelola`.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'Asia/Jakarta'));
});

/** @return array{0: array<string, mixed>, 1: Karyawan} */
function SiapkanKoreksiAbsensi(TestCase $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Kedai Koreksi Absensi');
    BantuanOrganisasi::Masuk($tes, $k['Pemilik'], $k['Tenant']->Id);
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $karyawan = Karyawan::query()->create(['Nama' => 'Rina Wulandari Kusumaningrum', 'IdOutlet' => $k['Outlet']->Id]);

    return [$k, $karyawan];
}

it('mencatat absensi yang terlewat (Manual) dengan jam waktu outlet, termasuk shift lewat tengah malam', function (): void {
    [$k, $rina] = SiapkanKoreksiAbsensi($this);

    $this->post('/kelola/karyawan/absensi', [
        'Karyawan' => $rina->Uuid, 'Outlet' => $k['Outlet']->Uuid, 'Tanggal' => '2026-09-30',
        'JamMasuk' => '20:00', 'JamKeluar' => '02:30', 'KeluarHariBerikutnya' => true, 'Alasan' => 'Lupa absen, HP kasir mati',
    ])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    $a = Absensi::query()->sole();
    expect($a->Sumber)->toBe('Manual')
        ->and($a->TanggalBisnis->toDateString())->toBe('2026-09-30')
        ->and($a->MasukPada->toIso8601ZuluString())->toBe('2026-09-30T13:00:00Z')
        ->and($a->KeluarPada?->toIso8601ZuluString())->toBe('2026-09-30T19:30:00Z')
        ->and($a->DikoreksiOleh)->toBe($k['Pemilik']->Id)
        ->and($a->AlasanKoreksi)->toBe('Lupa absen, HP kasir mati')
        ->and(LogAudit::query()->where('Peristiwa', 'absensi.tambah-manual')->count())->toBe(1);

    $this->getJson('/kelola/karyawan/absensi')->assertOk()
        ->assertJsonPath('Data.0.Sumber', 'Manual')->assertJsonPath('Data.0.Dikoreksi', true)
        ->assertJsonPath('Data.0.JamMasuk', '20:00')->assertJsonPath('Data.0.JamKeluar', '02:30')->assertJsonPath('Data.0.KeluarBeda', true);
});

it('koreksi jam absensi kasir: nilai lama di audit. Alasan wajib. Keluar sebelum masuk, masa depan, dan tumpang tindih ditolak', function (): void {
    [$k, $rina] = SiapkanKoreksiAbsensi($this);
    $a = Absensi::query()->create([
        'IdKaryawan' => $rina->Id, 'IdOutlet' => $k['Outlet']->Id, 'TanggalBisnis' => '2026-10-01',
        'MasukPada' => '2026-10-01T02:05:00Z', 'KeluarPada' => null,
    ]);
    Absensi::query()->create([
        'IdKaryawan' => $rina->Id, 'IdOutlet' => $k['Outlet']->Id, 'TanggalBisnis' => '2026-09-30',
        'MasukPada' => '2026-09-30T01:00:00Z', 'KeluarPada' => '2026-09-30T10:00:00Z',
    ]);
    $kirim = fn (array $isi) => $this->put("/kelola/karyawan/absensi/{$a->Uuid}", ['Tanggal' => '2026-10-01', ...$isi]);

    $kirim(['JamMasuk' => '09:00', 'JamKeluar' => '17:00', 'Alasan' => ''])->assertSessionHasErrors('Alasan');
    $kirim(['JamMasuk' => '09:00', 'JamKeluar' => '08:00', 'Alasan' => 'Salah tekan jam keluar'])->assertSessionHasErrors('JamKeluar');
    $kirim(['JamMasuk' => '13:00', 'JamKeluar' => null, 'Alasan' => 'Salah tekan jam masuk'])->assertSessionHasErrors('JamMasuk');
    $this->put("/kelola/karyawan/absensi/{$a->Uuid}", ['Tanggal' => '2026-09-30', 'JamMasuk' => '12:00', 'JamKeluar' => '15:00', 'Alasan' => 'Pindah ke hari kemarin'])
        ->assertSessionHasErrors('JamMasuk');

    $kirim(['JamMasuk' => '08:55', 'JamKeluar' => '11:30', 'Alasan' => 'Kasir lupa absen keluar'])->assertSessionHasNoErrors();

    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect($a->refresh()->Sumber)->toBe('Pos')
        ->and($a->MasukPada->toIso8601ZuluString())->toBe('2026-10-01T01:55:00Z')
        ->and($a->KeluarPada?->toIso8601ZuluString())->toBe('2026-10-01T04:30:00Z');
    $audit = LogAudit::query()->where('Peristiwa', 'absensi.koreksi')->sole();
    expect($audit->NilaiLama['MasukPada'] ?? null)->toBe('2026-10-01T02:05:00Z')
        ->and(array_key_exists('KeluarPada', $audit->NilaiLama ?? []))->toBeTrue()
        ->and($audit->NilaiLama['KeluarPada'] ?? null)->toBeNull()
        ->and($audit->NilaiBaru['Alasan'] ?? null)->toBe('Kasir lupa absen keluar');
});

it('hanya karyawan.kelola yang boleh mengoreksi, yang lain hanya melihat (BolehKoreksi=false)', function (): void {
    [$k, $rina] = SiapkanKoreksiAbsensi($this);
    $this->get('/kelola/karyawan/absensi')->assertInertia(fn (AssertableInertia $h) => $h->where('BolehKoreksi', true));

    BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Supervisor);
    $this->get('/kelola/karyawan/absensi')->assertInertia(fn (AssertableInertia $h) => $h->where('BolehKoreksi', false));
    $this->post('/kelola/karyawan/absensi', [
        'Karyawan' => $rina->Uuid, 'Outlet' => $k['Outlet']->Uuid, 'Tanggal' => '2026-10-01', 'JamMasuk' => '08:00', 'Alasan' => 'Lupa absen pagi',
    ])->assertForbidden();
    $this->put('/kelola/karyawan/absensi/'.str_repeat('0', 26), ['Tanggal' => '2026-10-01', 'JamMasuk' => '08:00', 'Alasan' => 'Lupa absen pagi'])
        ->assertForbidden();
    BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
    expect(Absensi::query()->count())->toBe(0);
});
