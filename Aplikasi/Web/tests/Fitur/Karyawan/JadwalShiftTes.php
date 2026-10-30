<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Karyawan\Aksi\KelolaTukarShift;
use App\Domain\Karyawan\Enum\StatusTukarShift;
use App\Domain\Karyawan\Layanan\PenghitungKekuranganStaf;
use App\Domain\Karyawan\Model\JadwalKerja;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\PermintaanTukarShift;
use App\Domain\Karyawan\Model\PolaJadwalKerja;
use App\Domain\Karyawan\Model\ShiftKerja;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Pengguna;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-18 jadwal kerja (D-251): shift bernama, beberapa shift per hari, isi massal, pola berulang, kebutuhan staf dan
 * kekurangan staf, pengumuman jadwal lewat WhatsApp, dan tukar shift antar karyawan.
 */

beforeEach(function (): void {
    // Senin 21 September 2026 pukul 10.00 WIB.
    $this->travelTo(CarbonImmutable::parse('2026-09-21 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    Mail::fake();
    config(['integrasi.Whatsapp' => ['Penyedia' => 'Fonnte', 'Pengaturan' => [], 'Kredensial' => ['Token' => 'rahasia-uji']]]);
    Http::fake(['api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['8101']])]);
});

/**
 * @return array{0: array<string, mixed>, 1: Karyawan, 2: Karyawan}
 */
function SiapkanJadwalShift(object $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Kedai Jadwal Shift');
    $rina = Karyawan::query()->create(['Nama' => 'Rina Jadwal', 'IdOutlet' => $k['Outlet']->Id]);
    $dimas = Karyawan::query()->create(['Nama' => 'Dimas Jadwal', 'IdOutlet' => $k['Outlet']->Id]);
    BantuanPersediaan::MasukSebagai($tes, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);

    return [$k, $rina, $dimas];
}

function BuatShiftUji(string $nama = 'Pagi', string $mulai = '08:00', string $selesai = '16:00'): ShiftKerja
{
    return ShiftKerja::query()->create(['Nama' => $nama, 'JamMulai' => $mulai, 'JamSelesai' => $selesai]);
}

describe('shift bernama', function (): void {
    it('buat, ubah, nama ganda ditolak, jam tidak valid ditolak, hapus tidak mengubah jadwal yang sudah memakainya', function (): void {
        [$k, $rina] = SiapkanJadwalShift($this);
        $isian = ['Nama' => 'Pagi', 'JamMulai' => '08:00', 'JamSelesai' => '16:00', 'Aktif' => true];

        $this->post('/kelola/karyawan/jadwal/shift', $isian)->assertSessionHasNoErrors();
        $this->post('/kelola/karyawan/jadwal/shift', $isian)->assertSessionHasErrors('Nama');
        $this->post('/kelola/karyawan/jadwal/shift', [...$isian, 'Nama' => 'Salah', 'JamMulai' => '25:00'])->assertSessionHasErrors('JamMulai');
        $this->post('/kelola/karyawan/jadwal/shift', [...$isian, 'Nama' => 'Sama', 'JamSelesai' => '08:00'])->assertSessionHasErrors('JamMulai');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pagi = ShiftKerja::query()->sole();
        $this->put("/kelola/karyawan/jadwal/shift/{$pagi->Uuid}", [...$isian, 'JamSelesai' => '15:00'])->assertSessionHasNoErrors();
        expect(ShiftKerja::query()->sole()->JamSelesai)->toBe('15:00');

        $this->put('/kelola/karyawan/jadwal', ['UuidOutlet' => $k['Outlet']->Uuid, 'Senin' => '2026-09-21', 'Sel' => [
            ['UuidKaryawan' => $rina->Uuid, 'Tanggal' => '2026-09-21', 'Shift' => [['JamMulai' => '08:00', 'JamSelesai' => '15:00', 'UuidShift' => $pagi->Uuid]]],
        ]])->assertSessionHasNoErrors();
        $this->delete("/kelola/karyawan/jadwal/shift/{$pagi->Uuid}")->assertSessionHasNoErrors();
        $jadwal = JadwalKerja::query()->sole();
        expect(ShiftKerja::query()->count())->toBe(0)
            ->and([$jadwal->JamMulai, $jadwal->JamSelesai, $jadwal->IdShiftKerja])->toBe(['08:00', '15:00', null])
            ->and(LogAudit::query()->where('Peristiwa', 'shift-kerja.simpan')->count())->toBe(2);
    });

    it('tanpa izin karyawan.kelola ditolak', function (): void {
        [$k] = SiapkanJadwalShift($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->post('/kelola/karyawan/jadwal/shift', ['Nama' => 'Pagi', 'JamMulai' => '08:00', 'JamSelesai' => '16:00', 'Aktif' => true])->assertForbidden();
    });
});

describe('beberapa shift per hari', function (): void {
    it('shift terpisah tersimpan semua, tampil di prop jadwal, dan menggantikan isi hari saat disimpan ulang', function (): void {
        [$k, $rina] = SiapkanJadwalShift($this);
        $kirim = fn (array $shift) => $this->put('/kelola/karyawan/jadwal', ['UuidOutlet' => $k['Outlet']->Uuid, 'Senin' => '2026-09-21', 'Sel' => [
            ['UuidKaryawan' => $rina->Uuid, 'Tanggal' => '2026-09-22', 'Shift' => $shift],
        ]]);

        $kirim([['JamMulai' => '08:00', 'JamSelesai' => '12:00'], ['JamMulai' => '17:00', 'JamSelesai' => '21:00']])->assertSessionHasNoErrors();
        expect(JadwalKerja::query()->where('IdKaryawan', $rina->Id)->count())->toBe(2);
        $this->get('/kelola/karyawan/jadwal?minggu=2026-09-21')->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Jadwal.Baris.0.Jadwal.2026-09-22', 2)->has('Jadwal.Baris.0.Jadwal.2026-09-21', 0));

        $kirim([['JamMulai' => '08:00', 'JamSelesai' => '12:00']])->assertSessionHasNoErrors();
        expect(JadwalKerja::query()->where('IdKaryawan', $rina->Id)->count())->toBe(1);
        $kirim([])->assertSessionHasNoErrors();
        expect(JadwalKerja::query()->count())->toBe(0);
    });

    it('shift yang saling tumpang tindih atau tumpang dengan shift malam hari sebelumnya ditolak, sentuhan ujung boleh', function (): void {
        [$k, $rina] = SiapkanJadwalShift($this);
        $kirim = fn (string $tanggal, array $shift) => $this->put('/kelola/karyawan/jadwal', ['UuidOutlet' => $k['Outlet']->Uuid, 'Senin' => '2026-09-21', 'Sel' => [
            ['UuidKaryawan' => $rina->Uuid, 'Tanggal' => $tanggal, 'Shift' => $shift],
        ]]);

        $kirim('2026-09-22', [['JamMulai' => '08:00', 'JamSelesai' => '13:00'], ['JamMulai' => '12:00', 'JamSelesai' => '18:00']])->assertSessionHasErrors('Sel.0.JamMulai');
        $kirim('2026-09-22', [['JamMulai' => '08:00', 'JamSelesai' => '12:00'], ['JamMulai' => '12:00', 'JamSelesai' => '18:00']])->assertSessionHasNoErrors();
        // Shift malam Selasa berakhir Rabu 06.00, jadi shift Rabu 05.00 bertumpuk.
        $kirim('2026-09-22', [['JamMulai' => '22:00', 'JamSelesai' => '06:00']])->assertSessionHasNoErrors();
        $kirim('2026-09-23', [['JamMulai' => '05:00', 'JamSelesai' => '09:00']])->assertSessionHasErrors('Sel.0.JamMulai');
        $kirim('2026-09-23', [['JamMulai' => '06:00', 'JamSelesai' => '09:00']])->assertSessionHasNoErrors();
    });

    it('salin minggu lalu menyalin semua shift satu hari dan tidak menimpa hari yang sudah berjadwal', function (): void {
        [$k, $rina] = SiapkanJadwalShift($this);
        foreach ([['08:00', '12:00'], ['17:00', '21:00']] as [$m, $s]) {
            JadwalKerja::query()->create(['IdKaryawan' => $rina->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-09-14', 'JamMulai' => $m, 'JamSelesai' => $s]);
        }
        JadwalKerja::query()->create(['IdKaryawan' => $rina->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-09-15', 'JamMulai' => '09:00', 'JamSelesai' => '17:00']);
        JadwalKerja::query()->create(['IdKaryawan' => $rina->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-09-22', 'JamMulai' => '10:00', 'JamSelesai' => '14:00']);

        $this->post('/kelola/karyawan/jadwal/salin', ['UuidOutlet' => $k['Outlet']->Uuid, 'Senin' => '2026-09-21'])->assertSessionHasNoErrors();
        expect(JadwalKerja::query()->whereDate('Tanggal', '2026-09-21')->count())->toBe(2)
            ->and(JadwalKerja::query()->whereDate('Tanggal', '2026-09-22')->count())->toBe(1);
    });
});

describe('isi massal', function (): void {
    it('memasang satu shift ke banyak karyawan dan hari, melewati hari yang sudah berjadwal', function (): void {
        [$k, $rina, $dimas] = SiapkanJadwalShift($this);
        $pagi = BuatShiftUji();
        JadwalKerja::query()->create(['IdKaryawan' => $dimas->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-09-22', 'JamMulai' => '13:00', 'JamSelesai' => '21:00']);

        $this->post('/kelola/karyawan/jadwal/isi-massal', [
            'UuidOutlet' => $k['Outlet']->Uuid,
            'Senin' => '2026-09-21',
            'UuidShift' => $pagi->Uuid,
            'UuidKaryawan' => [$rina->Uuid, $dimas->Uuid],
            'Tanggal' => ['2026-09-21', '2026-09-22'],
        ])->assertSessionHasNoErrors()->assertSessionHas('Kilat', fn (string $p): bool => str_contains($p, '3 jadwal terisi') && str_contains($p, '1 hari dilewati'));
        expect(JadwalKerja::query()->count())->toBe(4)
            ->and(JadwalKerja::query()->where('IdShiftKerja', $pagi->Id)->count())->toBe(3);
    });

    it('shift nonaktif atau tidak dikenal ditolak', function (): void {
        [$k, $rina] = SiapkanJadwalShift($this);
        $this->post('/kelola/karyawan/jadwal/isi-massal', [
            'UuidOutlet' => $k['Outlet']->Uuid, 'Senin' => '2026-09-21', 'UuidShift' => '01J9SHF0000000000000000009',
            'UuidKaryawan' => [$rina->Uuid], 'Tanggal' => ['2026-09-21'],
        ])->assertSessionHasErrors('UuidShift');
    });
});

describe('pola jadwal berulang', function (): void {
    it('pola tersimpan, diterapkan ke hari kosong, melewati hari cuti dan hari yang sudah berjadwal', function (): void {
        [$k, $rina] = SiapkanJadwalShift($this);
        $pagi = BuatShiftUji();
        $baris = collect(range(1, 5))->map(fn (int $h): array => ['HariKe' => $h, 'JamMulai' => '08:00', 'JamSelesai' => '16:00', 'UuidShift' => $pagi->Uuid])->all();

        $this->put("/kelola/karyawan/jadwal/pola/{$rina->Uuid}", ['UuidOutlet' => $k['Outlet']->Uuid, 'Baris' => $baris])->assertSessionHasNoErrors();
        expect(PolaJadwalKerja::query()->count())->toBe(5);

        JadwalKerja::query()->create(['IdKaryawan' => $rina->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-09-21', 'JamMulai' => '10:00', 'JamSelesai' => '14:00']);
        $this->post('/kelola/karyawan/cuti', ['Karyawan' => $rina->Uuid, 'Jenis' => 'Sakit', 'TanggalMulai' => '2026-09-23', 'TanggalSelesai' => '2026-09-23', 'Berbayar' => true, 'Alasan' => 'Demam'])->assertRedirect();
        $this->post('/kelola/karyawan/jadwal/terapkan-pola', ['UuidOutlet' => $k['Outlet']->Uuid, 'Senin' => '2026-09-21'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        // Senin sudah berjadwal, Rabu cuti, jadi Selasa, Kamis, Jumat yang terisi.
        $terisi = JadwalKerja::query()->where('IdKaryawan', $rina->Id)->orderBy('Tanggal')->get()->map(fn (JadwalKerja $j): string => $j->Tanggal->toDateString().' '.$j->JamMulai)->all();
        expect($terisi)->toBe(['2026-09-21 10:00', '2026-09-22 08:00', '2026-09-24 08:00', '2026-09-25 08:00']);

        $this->put("/kelola/karyawan/jadwal/pola/{$rina->Uuid}", ['UuidOutlet' => $k['Outlet']->Uuid, 'Baris' => []])->assertSessionHasNoErrors();
        expect(PolaJadwalKerja::query()->count())->toBe(0);
    });

    it('pola yang shiftnya saling bertumpuk ditolak', function (): void {
        [$k, $rina] = SiapkanJadwalShift($this);
        $this->put("/kelola/karyawan/jadwal/pola/{$rina->Uuid}", ['UuidOutlet' => $k['Outlet']->Uuid, 'Baris' => [
            ['HariKe' => 1, 'JamMulai' => '08:00', 'JamSelesai' => '14:00'],
            ['HariKe' => 1, 'JamMulai' => '13:00', 'JamSelesai' => '18:00'],
        ]])->assertSessionHasErrors('Baris.0.JamMulai');
    });

    it('perintah terjadwal mengisi minggu yang belum berjadwal sama sekali, tidak minggu yang sudah punya jadwal', function (): void {
        [$k, $rina, $dimas] = SiapkanJadwalShift($this);
        foreach ([$rina, $dimas] as $orang) {
            PolaJadwalKerja::query()->create(['IdKaryawan' => $orang->Id, 'IdOutlet' => $k['Outlet']->Id, 'HariKe' => 1, 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        }
        // Dimas sengaja dikosongkan hari Senin di minggu ini tetapi punya jadwal Selasa, jadi minggunya tidak disentuh.
        JadwalKerja::query()->create(['IdKaryawan' => $dimas->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-09-22', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);

        Artisan::call('karyawan:terapkan-pola-jadwal', ['--tenant' => [$k['Tenant']->Id]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        expect(JadwalKerja::query()->where('IdKaryawan', $rina->Id)->whereDate('Tanggal', '2026-09-21')->count())->toBe(1)
            ->and(JadwalKerja::query()->where('IdKaryawan', $dimas->Id)->whereDate('Tanggal', '2026-09-21')->count())->toBe(0)
            ->and(JadwalKerja::query()->where('IdKaryawan', $dimas->Id)->whereDate('Tanggal', '2026-09-28')->count())->toBe(1);
    });
});

describe('kekurangan staf', function (): void {
    it('menghitung jumlah staf paling sedikit di sepanjang rentang jam, termasuk shift malam hari sebelumnya', function (): void {
        $kebutuhan = [['HariKe' => 1, 'JamMulai' => '10:00', 'JamSelesai' => '14:00', 'Minimal' => 2]];
        $hari = ['2026-09-21', '2026-09-22'];
        // Senin: Rina 08–12 dan Dimas 12–18 (hanya satu orang di tiap jam), jadi kurang 1.
        $kurang = PenghitungKekuranganStaf::Hitung($kebutuhan, ['2026-09-21' => [
            ['IdKaryawan' => 1, 'JamMulai' => '08:00', 'JamSelesai' => '12:00'],
            ['IdKaryawan' => 2, 'JamMulai' => '12:00', 'JamSelesai' => '18:00'],
        ]], $hari);
        expect($kurang)->toBe([['Tanggal' => '2026-09-21', 'JamMulai' => '10:00', 'JamSelesai' => '14:00', 'Minimal' => 2, 'Terjadwal' => 1, 'Kurang' => 1]]);

        // Shift malam Minggu berakhir Senin 12.00 ikut dihitung, jadi setelah 12.00 hanya satu orang.
        expect(PenghitungKekuranganStaf::Hitung($kebutuhan, [
            '2026-09-20' => [['IdKaryawan' => 3, 'JamMulai' => '20:00', 'JamSelesai' => '12:00']],
            '2026-09-21' => [['IdKaryawan' => 1, 'JamMulai' => '08:00', 'JamSelesai' => '16:00']],
        ], $hari))->toBe([['Tanggal' => '2026-09-21', 'JamMulai' => '10:00', 'JamSelesai' => '14:00', 'Minimal' => 2, 'Terjadwal' => 1, 'Kurang' => 1]]);
        // Tambahan satu orang yang menutup 09.00–15.00 memenuhi seluruh rentang.
        expect(PenghitungKekuranganStaf::Hitung($kebutuhan, [
            '2026-09-20' => [['IdKaryawan' => 3, 'JamMulai' => '20:00', 'JamSelesai' => '12:00']],
            '2026-09-21' => [['IdKaryawan' => 1, 'JamMulai' => '08:00', 'JamSelesai' => '16:00'], ['IdKaryawan' => 2, 'JamMulai' => '09:00', 'JamSelesai' => '15:00']],
        ], $hari))->toBe([]);

        // Orang yang sama dengan dua baris bersinggungan dihitung sekali.
        expect(PenghitungKekuranganStaf::Hitung($kebutuhan, ['2026-09-21' => [
            ['IdKaryawan' => 1, 'JamMulai' => '08:00', 'JamSelesai' => '13:00'],
            ['IdKaryawan' => 1, 'JamMulai' => '12:00', 'JamSelesai' => '18:00'],
        ]], $hari))->toHaveCount(1);
    });

    it('kebutuhan tersimpan per outlet dan kekurangan tampil di prop jadwal, cuti tidak dihitung', function (): void {
        [$k, $rina, $dimas] = SiapkanJadwalShift($this);
        $this->put('/kelola/karyawan/jadwal/kebutuhan', ['UuidOutlet' => $k['Outlet']->Uuid, 'Baris' => [['HariKe' => 1, 'JamMulai' => '10:00', 'JamSelesai' => '14:00', 'Minimal' => 2]]])->assertSessionHasNoErrors();
        $this->put('/kelola/karyawan/jadwal/kebutuhan', ['UuidOutlet' => $k['Outlet']->Uuid, 'Baris' => [['HariKe' => 1, 'JamMulai' => '14:00', 'JamSelesai' => '10:00', 'Minimal' => 2]]])->assertSessionHasErrors('Baris.0.JamMulai');
        foreach ([$rina, $dimas] as $orang) {
            JadwalKerja::query()->create(['IdKaryawan' => $orang->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-09-21', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        }
        $this->get('/kelola/karyawan/jadwal?minggu=2026-09-21')->assertInertia(fn (AssertableInertia $h) => $h->has('Jadwal.Kebutuhan', 1)->has('Jadwal.Kekurangan', 0));

        $this->post('/kelola/karyawan/cuti', ['Karyawan' => $dimas->Uuid, 'Jenis' => 'Izin', 'TanggalMulai' => '2026-09-21', 'TanggalSelesai' => '2026-09-21', 'Berbayar' => true, 'Alasan' => 'Urusan keluarga'])->assertRedirect();
        $this->get('/kelola/karyawan/jadwal?minggu=2026-09-21')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Jadwal.Kekurangan.0.Kurang', 1)->where('Jadwal.Kekurangan.0.Terjadwal', 1));
    });
});

describe('umumkan jadwal lewat WhatsApp', function (): void {
    it('mengirim satu pesan per karyawan berakun bernomor, berisi shift minggu itu', function (): void {
        [$k, $rina, $dimas] = SiapkanJadwalShift($this);
        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        Pengguna::query()->whereKey($kasir->Id)->update(['NoHp' => '081288880002']);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $rina->forceFill(['IdPengguna' => $kasir->Id])->save();
        foreach ([$rina, $dimas] as $orang) {
            JadwalKerja::query()->create(['IdKaryawan' => $orang->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-09-22', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        }

        $this->post('/kelola/karyawan/jadwal/umumkan', ['UuidOutlet' => $k['Outlet']->Uuid, 'Senin' => '2026-09-21'])->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', fn (string $p): bool => str_contains($p, 'ke 1 karyawan'));
        $pesan = collect(Http::recorded())->map(fn (array $pasangan): PermintaanHttp => $pasangan[0])->filter(fn (PermintaanHttp $r): bool => str_contains($r->url(), 'api.fonnte.com/send'))->values();
        expect($pesan)->toHaveCount(1)
            ->and((string) $pesan[0]['target'])->toBe('6281288880002')
            ->and((string) $pesan[0]['message'])->toContain('Rina Jadwal')->toContain('Selasa 22 Sep: 08:00–16:00')
            ->and(LogAudit::query()->where('Peristiwa', 'jadwal-kerja.umumkan')->count())->toBe(1);
    });

    it('ditolak dengan pesan jelas bila belum ada karyawan berakun bernomor', function (): void {
        [$k, $rina] = SiapkanJadwalShift($this);
        JadwalKerja::query()->create(['IdKaryawan' => $rina->Id, 'IdOutlet' => $k['Outlet']->Id, 'Tanggal' => '2026-09-22', 'JamMulai' => '08:00', 'JamSelesai' => '16:00']);
        $this->post('/kelola/karyawan/jadwal/umumkan', ['UuidOutlet' => $k['Outlet']->Uuid, 'Senin' => '2026-09-21'])->assertSessionHasErrors('Umum');
        expect(collect(Http::recorded())->filter(fn (array $p): bool => str_contains($p[0]->url(), 'fonnte'))->count())->toBe(0);
    });
});

describe('tukar shift', function (): void {
    function JadwalTukar(Karyawan $orang, int $idOutlet, string $tanggal, string $mulai = '08:00', string $selesai = '16:00'): JadwalKerja
    {
        return JadwalKerja::query()->create(['IdKaryawan' => $orang->Id, 'IdOutlet' => $idOutlet, 'Tanggal' => $tanggal, 'JamMulai' => $mulai, 'JamSelesai' => $selesai]);
    }

    it('alur penuh: ajukan, rekan menerima, pengelola menyetujui, shift berpindah (tukar balik) dan diaudit', function (): void {
        [$k, $rina, $dimas] = SiapkanJadwalShift($this);
        $milikRina = JadwalTukar($rina, $k['Outlet']->Id, '2026-09-24');
        $milikDimas = JadwalTukar($dimas, $k['Outlet']->Id, '2026-09-25', '16:00', '23:00');
        $tukar = app(KelolaTukarShift::class);

        $p = $tukar->Ajukan($rina, $milikRina->Uuid, $dimas->Uuid, $milikDimas->Uuid, 'Ada acara keluarga');
        expect($p->Status)->toBe(StatusTukarShift::MenungguRekan);
        expect(fn () => $tukar->Tanggapi($rina, $p->Uuid, true))->toThrow(Exception::class);
        $tukar->Tanggapi($dimas, $p->Uuid, true);
        expect($p->refresh()->Status)->toBe(StatusTukarShift::MenungguPengelola);

        $this->get('/kelola/karyawan/jadwal?minggu=2026-09-21')->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Jadwal.TukarShift', 1)->where('Jadwal.TukarShift.0.Pemohon', 'Rina Jadwal')->where('Jadwal.TukarShift.0.Rekan', 'Dimas Jadwal'));
        $this->post("/kelola/karyawan/jadwal/tukar-shift/{$p->Uuid}/putuskan", ['Setuju' => true])->assertSessionHasNoErrors();

        expect($milikRina->refresh()->IdKaryawan)->toBe($dimas->Id)
            ->and($milikDimas->refresh()->IdKaryawan)->toBe($rina->Id)
            ->and($p->refresh()->Status)->toBe(StatusTukarShift::Disetujui)
            ->and(LogAudit::query()->where('Peristiwa', 'tukar-shift.setujui')->count())->toBe(1);
    });

    it('rekan mengambil alih tanpa balasan, penolakan rekan atau pengelola tidak mengubah jadwal, pemohon bisa membatalkan', function (): void {
        [$k, $rina, $dimas] = SiapkanJadwalShift($this);
        $milikRina = JadwalTukar($rina, $k['Outlet']->Id, '2026-09-24');
        $tukar = app(KelolaTukarShift::class);

        $p = $tukar->Ajukan($rina, $milikRina->Uuid, $dimas->Uuid, null, '');
        $tukar->Tanggapi($dimas, $p->Uuid, false);
        expect($p->refresh()->Status)->toBe(StatusTukarShift::Ditolak)
            ->and($milikRina->refresh()->IdKaryawan)->toBe($rina->Id);

        $p2 = $tukar->Ajukan($rina, $milikRina->Uuid, $dimas->Uuid, null, '');
        $tukar->Batalkan($rina, $p2->Uuid);
        expect($p2->refresh()->Status)->toBe(StatusTukarShift::Dibatalkan);

        $p3 = $tukar->Ajukan($rina, $milikRina->Uuid, $dimas->Uuid, null, '');
        $tukar->Tanggapi($dimas, $p3->Uuid, true);
        $this->post("/kelola/karyawan/jadwal/tukar-shift/{$p3->Uuid}/putuskan", ['Setuju' => false, 'Alasan' => 'Staf terlalu sedikit'])->assertSessionHasNoErrors();
        expect($p3->refresh()->Status)->toBe(StatusTukarShift::Ditolak)->and($p3->AlasanKeputusan)->toBe('Staf terlalu sedikit')
            ->and($milikRina->refresh()->IdKaryawan)->toBe($rina->Id);

        $p4 = $tukar->Ajukan($rina, $milikRina->Uuid, $dimas->Uuid, null, '');
        $tukar->Tanggapi($dimas, $p4->Uuid, true);
        $this->post("/kelola/karyawan/jadwal/tukar-shift/{$p4->Uuid}/putuskan", ['Setuju' => true])->assertSessionHasNoErrors();
        expect($milikRina->refresh()->IdKaryawan)->toBe($dimas->Id);
    });

    it('validasi saat mengajukan: shift orang lain, diri sendiri, shift lewat, dan permintaan ganda ditolak', function (): void {
        [$k, $rina, $dimas] = SiapkanJadwalShift($this);
        $lalu = JadwalTukar($rina, $k['Outlet']->Id, '2026-09-18');
        $depan = JadwalTukar($rina, $k['Outlet']->Id, '2026-09-24');
        $milikDimas = JadwalTukar($dimas, $k['Outlet']->Id, '2026-09-26');
        $tukar = app(KelolaTukarShift::class);

        expect(fn () => $tukar->Ajukan($dimas, $depan->Uuid, $rina->Uuid, null, ''))->toThrow(Exception::class);
        expect(fn () => $tukar->Ajukan($rina, $depan->Uuid, $rina->Uuid, null, ''))->toThrow(Exception::class);
        expect(fn () => $tukar->Ajukan($rina, $lalu->Uuid, $dimas->Uuid, null, ''))->toThrow(Exception::class);
        $tukar->Ajukan($rina, $depan->Uuid, $dimas->Uuid, $milikDimas->Uuid, '');
        expect(fn () => $tukar->Ajukan($rina, $depan->Uuid, $dimas->Uuid, null, ''))->toThrow(Exception::class)
            ->and(PermintaanTukarShift::query()->count())->toBe(1);
    });

    it('persetujuan memeriksa ulang: jadwal berubah, penerima sedang cuti, atau bertumpuk ditolak tanpa mengubah apa pun', function (): void {
        [$k, $rina, $dimas] = SiapkanJadwalShift($this);
        $milikRina = JadwalTukar($rina, $k['Outlet']->Id, '2026-09-24');
        $tukar = app(KelolaTukarShift::class);

        // Dimas sudah punya shift bertumpuk di hari itu.
        JadwalTukar($dimas, $k['Outlet']->Id, '2026-09-24', '14:00', '20:00');
        $p = $tukar->Ajukan($rina, $milikRina->Uuid, $dimas->Uuid, null, '');
        $tukar->Tanggapi($dimas, $p->Uuid, true);
        $this->post("/kelola/karyawan/jadwal/tukar-shift/{$p->Uuid}/putuskan", ['Setuju' => true])->assertSessionHasErrors('Umum');
        expect($milikRina->refresh()->IdKaryawan)->toBe($rina->Id)->and($p->refresh()->Status)->toBe(StatusTukarShift::MenungguPengelola);

        // Jadwal pemohon dipindahkan pengelola ke orang lain sebelum diputuskan.
        $milikRina->forceFill(['IdKaryawan' => $dimas->Id, 'JamMulai' => '06:00', 'JamSelesai' => '10:00'])->save();
        $this->post("/kelola/karyawan/jadwal/tukar-shift/{$p->Uuid}/putuskan", ['Setuju' => true])->assertSessionHasErrors('Umum');
    });

    it('penerima yang sedang cuti tidak bisa menerima shift', function (): void {
        [$k, $rina, $dimas] = SiapkanJadwalShift($this);
        $milikRina = JadwalTukar($rina, $k['Outlet']->Id, '2026-09-24');
        $this->post('/kelola/karyawan/cuti', ['Karyawan' => $dimas->Uuid, 'Jenis' => 'Cuti', 'TanggalMulai' => '2026-09-24', 'TanggalSelesai' => '2026-09-24', 'Berbayar' => true, 'Alasan' => 'Menikah'])->assertRedirect();
        $tukar = app(KelolaTukarShift::class);
        $p = $tukar->Ajukan($rina, $milikRina->Uuid, $dimas->Uuid, null, '');
        $tukar->Tanggapi($dimas, $p->Uuid, true);

        $this->post("/kelola/karyawan/jadwal/tukar-shift/{$p->Uuid}/putuskan", ['Setuju' => true])->assertSessionHasErrors('Umum');
        expect($milikRina->refresh()->IdKaryawan)->toBe($rina->Id);
    });

    it('menjadi butir Kotak Tindakan bagi pengelola saat menunggu keputusan', function (): void {
        [$k, $rina, $dimas] = SiapkanJadwalShift($this);
        $milikRina = JadwalTukar($rina, $k['Outlet']->Id, '2026-09-24');
        $tukar = app(KelolaTukarShift::class);
        $p = $tukar->Ajukan($rina, $milikRina->Uuid, $dimas->Uuid, null, '');
        $tukar->Tanggapi($dimas, $p->Uuid, true);

        $this->get('/kelola/tindakan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Butir', fn ($butir): bool => collect($butir)->contains(fn ($b): bool => $b['Kunci'] === 'karyawan.tukar-shift' && $b['Jumlah'] === 1)));
    });
});
