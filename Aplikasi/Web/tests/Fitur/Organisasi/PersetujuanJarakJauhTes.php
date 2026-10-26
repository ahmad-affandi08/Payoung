<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusPermintaanPersetujuan;
use App\Domain\Organisasi\Model\Pengguna;
use App\Domain\Organisasi\Model\PermintaanPersetujuan;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Tenant\Enum\JenisOverride;
use App\Domain\Tenant\Model\OverrideTenant;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\Pendukung\Tenant\BantuanAutentikasi;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * X4 persetujuan jarak jauh (§19.2, OWN-03, §16.3): kasir meminta dari perangkat bila penyetuju tidak di tempat, Aplikasi
 * Owner menampilkan antrean dan memutuskan (setujui / tolak + alasan), perangkat menunggu status dan menerima penyetuju.
 * Fitur paket `persetujuan.jarak-jauh`; izin & outlet penyetuju, four-eyes, kedaluwarsa 10 menit, idempoten per Uuid.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function AktifkanPersetujuanJarakJauh(Tenant $tenant): void
{
    OverrideTenant::query()->create([
        'IdTenant' => $tenant->Id,
        'Jenis' => JenisOverride::Fitur,
        'Kunci' => 'persetujuan.jarak-jauh',
        'BerakhirPada' => now()->addDays(30),
        'Alasan' => 'Uji coba persetujuan jarak jauh untuk kafe',
        'DibuatOleh' => BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin)->Id,
    ]);
    BantuanOrganisasi::AturKonteks($tenant->Id);
}

function TokenPenyetujuUji(TestCase $tes, Pengguna $pengguna): string
{
    $pengguna->forceFill(['EmailDiverifikasiPada' => now(), 'KataSandi' => Hash::make(BantuanAutentikasi::KATA_SANDI)])->save();
    $tes->flushHeaders();
    $token = $tes->postJson('/api/pemilik/v1/masuk', ['Email' => $pengguna->Email, 'KataSandi' => BantuanAutentikasi::KATA_SANDI, 'NamaPerangkat' => 'HP Uji'])->assertOk()->json('Token');

    return is_string($token) ? $token : '';
}

/**
 * @param  array<string, mixed>  $isi
 * @return TestResponse<JsonResponse>
 */
function PemilikPersetujuan(TestCase $tes, string $token, Tenant $tenant, string $metode, string $jalur, array $isi = []): TestResponse
{
    $tes->flushHeaders();
    $tes->withToken($token)->withHeaders(['X-Tenant' => $tenant->Uuid, 'X-Versi-Aplikasi' => '1.0.0']);

    return $metode === 'GET' ? $tes->getJson('/api/pemilik/v1/'.$jalur) : $tes->postJson('/api/pemilik/v1/'.$jalur, $isi);
}

/**
 * @param  array<string, mixed>  $timpa
 * @return TestResponse<JsonResponse>
 */
function AjukanDariKasir(TestCase $tes, string $token, Pengguna $kasir, array $timpa = []): TestResponse
{
    $tes->flushHeaders();

    return $tes->withToken($token)->postJson('/api/pos/v1/persetujuan/jarak-jauh', [
        'Uuid' => (string) Str::ulid(),
        'Izin' => 'kas.keluar.setujui',
        'UuidPengguna' => $kasir->Uuid,
        'Judul' => 'Kas keluar Rp 350.000',
        'Rincian' => [['Label' => 'Kategori', 'Nilai' => 'Beli es batu & galon'], ['Label' => 'Catatan', 'Nilai' => 'Galon 12 + es balok 4']],
        'Nilai' => '350000.00',
        ...$timpa,
    ]);
}

describe('alur setujui & tolak', function (): void {
    it('kasir mengajukan → muncul di antrean pemilik → disetujui → perangkat menerima penyetuju. Riwayat & audit', function (): void {
        $k = BantuanKasir::Siapkan($this);
        AktifkanPersetujuanJarakJauh($k['Tenant']);
        $uuid = (string) Str::ulid();

        $ajukan = AjukanDariKasir($this, $k['Token'], $k['Kasir'], ['Uuid' => $uuid])->assertCreated();
        expect($ajukan->json('Persetujuan.Status'))->toBe('Menunggu')
            ->and($ajukan->json('Persetujuan.Penyetuju'))->toBeNull();
        // Kirim ulang Uuid sama = permintaan yang sama.
        AjukanDariKasir($this, $k['Token'], $k['Kasir'], ['Uuid' => $uuid])->assertCreated();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PermintaanPersetujuan::query()->count())->toBe(1);

        $token = TokenPenyetujuUji($this, $k['Pemilik']);
        $antrean = PemilikPersetujuan($this, $token, $k['Tenant'], 'GET', 'persetujuan')->assertOk()->json('Persetujuan');
        expect($antrean)->toHaveCount(1)
            ->and($antrean[0]['Uuid'])->toBe($uuid)
            ->and($antrean[0]['Judul'])->toBe('Kas keluar Rp 350.000')
            ->and($antrean[0]['Nilai'])->toBe('350000.00')
            ->and($antrean[0]['NamaPemohon'])->toBe($k['Kasir']->Nama)
            ->and($antrean[0]['Rincian'][0])->toBe(['Label' => 'Kategori', 'Nilai' => 'Beli es batu & galon']);

        PemilikPersetujuan($this, $token, $k['Tenant'], 'POST', "persetujuan/{$uuid}/setujui")->assertOk()->assertJsonPath('Persetujuan.Status', 'Disetujui');
        // Klik ganda = idempoten.
        PemilikPersetujuan($this, $token, $k['Tenant'], 'POST', "persetujuan/{$uuid}/setujui")->assertOk();

        $this->flushHeaders();
        $status = $this->withToken($k['Token'])->getJson("/api/pos/v1/persetujuan/jarak-jauh/{$uuid}")->assertOk()->json('Persetujuan');
        expect($status['Status'])->toBe('Disetujui')
            ->and($status['Penyetuju']['Uuid'])->toBe($k['Pemilik']->Uuid)
            ->and($status['Penyetuju']['Pemilik'])->toBeTrue()
            ->and($status['Penyetuju']['Izin'])->toContain('kas.keluar.setujui');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

        $p = PermintaanPersetujuan::query()->sole();
        expect(RiwayatStatusDokumen::query()->where('JenisDokumen', 'PermintaanPersetujuan')->where('IdDokumen', $p->Id)->pluck('StatusKe')->all())->toBe(['Menunggu', 'Disetujui'])
            ->and(LogAudit::query()->where('Peristiwa', 'persetujuan-jarak-jauh.setujui')->count())->toBe(1)
            ->and(PemilikPersetujuan($this, $token, $k['Tenant'], 'GET', 'persetujuan')->json('Persetujuan'))->toBe([]);
    });

    it('tolak wajib alasan 5–255 karakter. Perangkat menerima alasan. Tidak bisa disetujui lagi', function (): void {
        $k = BantuanKasir::Siapkan($this);
        AktifkanPersetujuanJarakJauh($k['Tenant']);
        $uuid = AjukanDariKasir($this, $k['Token'], $k['Kasir'])->json('Persetujuan.Uuid');
        $token = TokenPenyetujuUji($this, $k['Pemilik']);

        PemilikPersetujuan($this, $token, $k['Tenant'], 'POST', "persetujuan/{$uuid}/tolak", ['Alasan' => 'no'])->assertStatus(422);
        PemilikPersetujuan($this, $token, $k['Tenant'], 'POST', "persetujuan/{$uuid}/tolak", ['Alasan' => 'Galon masih ada stok di gudang'])
            ->assertOk()->assertJsonPath('Persetujuan.Status', 'Ditolak');
        PemilikPersetujuan($this, $token, $k['Tenant'], 'POST', "persetujuan/{$uuid}/setujui")->assertStatus(409)->assertJsonPath('Galat.Kode', 'StatusTidakSesuai');

        $this->flushHeaders();
        $this->withToken($k['Token'])->getJson("/api/pos/v1/persetujuan/jarak-jauh/{$uuid}")->assertOk()
            ->assertJsonPath('Persetujuan.Status', 'Ditolak')
            ->assertJsonPath('Persetujuan.AlasanTolak', 'Galon masih ada stok di gudang')
            ->assertJsonPath('Persetujuan.Penyetuju', null);
    });

    it('kasir membatalkan → hilang dari antrean. Lewat 10 menit → Kedaluwarsa dan tidak bisa disetujui', function (): void {
        $k = BantuanKasir::Siapkan($this);
        AktifkanPersetujuanJarakJauh($k['Tenant']);
        $batal = AjukanDariKasir($this, $k['Token'], $k['Kasir'])->json('Persetujuan.Uuid');
        $lewat = AjukanDariKasir($this, $k['Token'], $k['Kasir'])->json('Persetujuan.Uuid');
        $this->flushHeaders();
        $this->withToken($k['Token'])->postJson("/api/pos/v1/persetujuan/jarak-jauh/{$batal}/batal")->assertOk()->assertJsonPath('Persetujuan.Status', 'Dibatalkan');
        $token = TokenPenyetujuUji($this, $k['Pemilik']);
        expect(array_column(PemilikPersetujuan($this, $token, $k['Tenant'], 'GET', 'persetujuan')->json('Persetujuan'), 'Uuid'))->toBe([$lewat]);

        $this->travel(11)->minutes();
        expect(PemilikPersetujuan($this, $token, $k['Tenant'], 'GET', 'persetujuan')->json('Persetujuan'))->toBe([]);
        PemilikPersetujuan($this, $token, $k['Tenant'], 'POST', "persetujuan/{$lewat}/setujui")->assertStatus(409)->assertJsonPath('Galat.Kode', 'SudahKedaluwarsa');
        $this->flushHeaders();
        $this->withToken($k['Token'])->getJson("/api/pos/v1/persetujuan/jarak-jauh/{$lewat}")->assertJsonPath('Persetujuan.Status', 'Kedaluwarsa');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PermintaanPersetujuan::query()->where('Uuid', $lewat)->value('Status'))->toBe(StatusPermintaanPersetujuan::Kedaluwarsa);
    });
});

describe('wewenang, four-eyes, fitur, isolasi', function (): void {
    it('penyetuju wajib punya izin yang diminta. Permintaan khusus pemilik hanya untuk pemilik. Pemohon tidak bisa menyetujui sendiri', function (): void {
        $k = BantuanKasir::Siapkan($this);
        AktifkanPersetujuanJarakJauh($k['Tenant']);
        $kas = AjukanDariKasir($this, $k['Token'], $k['Kasir'])->json('Persetujuan.Uuid');
        $pemilik = AjukanDariKasir($this, $k['Token'], $k['Kasir'], ['Izin' => null, 'Judul' => 'Diskon 60% di atas batas'])->json('Persetujuan.Uuid');
        $olehSupervisor = AjukanDariKasir($this, $k['Token'], $k['Supervisor'])->json('Persetujuan.Uuid');

        $tokenSupervisor = TokenPenyetujuUji($this, $k['Supervisor']);
        // Supervisor melihat permintaan kas kasir, tidak melihat permintaan khusus pemilik & permintaannya sendiri.
        expect(array_column(PemilikPersetujuan($this, $tokenSupervisor, $k['Tenant'], 'GET', 'persetujuan')->json('Persetujuan'), 'Uuid'))->toBe([$kas]);
        PemilikPersetujuan($this, $tokenSupervisor, $k['Tenant'], 'POST', "persetujuan/{$pemilik}/setujui")->assertForbidden()->assertJsonPath('Galat.Kode', 'TanpaIzin');
        PemilikPersetujuan($this, $tokenSupervisor, $k['Tenant'], 'POST', "persetujuan/{$olehSupervisor}/setujui")->assertForbidden()->assertJsonPath('Galat.Kode', 'PenyetujuSamaDenganPemohon');
        PemilikPersetujuan($this, $tokenSupervisor, $k['Tenant'], 'POST', "persetujuan/{$kas}/setujui")->assertOk();

        $tokenKasir = TokenPenyetujuUji($this, $k['Kasir']);
        expect(PemilikPersetujuan($this, $tokenKasir, $k['Tenant'], 'GET', 'persetujuan')->json('Persetujuan'))->toBe([]);

        $tokenPemilik = TokenPenyetujuUji($this, $k['Pemilik']);
        expect(array_column(PemilikPersetujuan($this, $tokenPemilik, $k['Tenant'], 'GET', 'persetujuan')->json('Persetujuan'), 'Uuid'))->toBe([$pemilik, $olehSupervisor]);
    });

    it('izin di luar daftar ditolak. Pemohon bukan anggota outlet ditolak. Fitur paket tidak aktif = FiturTidakTersedia & data-awal false', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $this->flushHeaders();
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->assertJsonPath('Pengaturan.PersetujuanJarakJauh', false);
        AjukanDariKasir($this, $k['Token'], $k['Kasir'])->assertForbidden()->assertJsonPath('Galat.Kode', 'FiturTidakTersedia');

        AktifkanPersetujuanJarakJauh($k['Tenant']);
        $this->flushHeaders();
        $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->assertJsonPath('Pengaturan.PersetujuanJarakJauh', true);
        AjukanDariKasir($this, $k['Token'], $k['Kasir'], ['Izin' => 'produk.kelola'])->assertStatus(422);
        $orangLuar = BantuanOrganisasi::TambahAnggota(BantuanOrganisasi::BuatTenant('Toko Lain Persetujuan')['Tenant']->Id, PeranTenantBawaan::Kasir);
        AjukanDariKasir($this, $k['Token'], $orangLuar)->assertForbidden()->assertJsonPath('Galat.Kode', 'KasirTidakDitemukan');
    });

    it('konfigurasi-aplikasi membawa keadaan fitur paket, jadi perubahan di konsol sampai ke kasir tanpa menekan Perbarui data', function (): void {
        $k = BantuanKasir::Siapkan($this);
        $this->flushHeaders();
        $this->withToken($k['Token'])->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('FiturPaket.PersetujuanJarakJauh', false);

        AktifkanPersetujuanJarakJauh($k['Tenant']);
        $this->flushHeaders();
        $this->withToken($k['Token'])->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('FiturPaket.PersetujuanJarakJauh', true);

        // Fitur dicabut lagi (override berakhir): perangkat ikut tahu pada pemeriksaan berikutnya.
        OverrideTenant::query()->where('IdTenant', $k['Tenant']->Id)->update(['BerakhirPada' => now()->subMinute()]);
        $this->flushHeaders();
        $this->withToken($k['Token'])->getJson('/api/pos/v1/konfigurasi-aplikasi')->assertOk()->assertJsonPath('FiturPaket.PersetujuanJarakJauh', false);
    });

    it('pemilik tenant lain tidak melihat & tidak bisa memutuskan. Perangkat lain tidak bisa membaca permintaan', function (): void {
        $k = BantuanKasir::Siapkan($this);
        AktifkanPersetujuanJarakJauh($k['Tenant']);
        $uuid = AjukanDariKasir($this, $k['Token'], $k['Kasir'])->json('Persetujuan.Uuid');

        $lain = BantuanKasir::Siapkan($this, 'Kopi Lain Persetujuan');
        AktifkanPersetujuanJarakJauh($lain['Tenant']);
        $tokenLain = TokenPenyetujuUji($this, $lain['Pemilik']);
        expect(PemilikPersetujuan($this, $tokenLain, $lain['Tenant'], 'GET', 'persetujuan')->json('Persetujuan'))->toBe([]);
        PemilikPersetujuan($this, $tokenLain, $lain['Tenant'], 'POST', "persetujuan/{$uuid}/setujui")->assertNotFound();

        $this->flushHeaders();
        $this->withToken($lain['Token'])->getJson("/api/pos/v1/persetujuan/jarak-jauh/{$uuid}")->assertNotFound();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PermintaanPersetujuan::query()->sole()->Status)->toBe(StatusPermintaanPersetujuan::Menunggu);
    });
});
