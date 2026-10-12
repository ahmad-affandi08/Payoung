<?php

declare(strict_types=1);

use App\Domain\Bersama\Unduhan\Aksi\BersihkanBerkasUnduhan;
use App\Domain\Bersama\Unduhan\Aksi\ProsesBerkasUnduhan;
use App\Domain\Bersama\Unduhan\Enum\StatusBerkasUnduhan;
use App\Domain\Bersama\Unduhan\Layanan\BatasEksporLaporan;
use App\Domain\Bersama\Unduhan\Model\BerkasUnduhan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Pendukung\Laporan\BantuanLaporan;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 05:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 05:00:00', 'UTC'));
    Storage::fake('local');
});

const ALAMAT_EKSPOR = '/kelola/laporan/penjualan/ekspor?tab=detail&dari=2026-10-01&sampai=2026-10-07';

describe('D-124 Pusat Unduhan: ekspor ditunda ke latar belakang', function (): void {
    it('CSV dengan latarbelakang=1 dialihkan ke Pusat Unduhan, disiapkan setelah respons, isinya sama dengan unduhan langsung', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);
        $langsung = $this->get(ALAMAT_EKSPOR.'&format=csv')->assertOk()->streamedContent();

        $this->get(ALAMAT_EKSPOR.'&format=csv&latarbelakang=1')
            ->assertRedirect('/kelola/unduhan')
            ->assertSessionHas('Kilat');

        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $berkas = BerkasUnduhan::query()->sole();
        expect($berkas->Status)->toBe(StatusBerkasUnduhan::Siap)
            ->and($berkas->Format)->toBe('csv')
            ->and($berkas->NamaBerkas)->toBe('laporan-penjualan-detail-2026-10-01-2026-10-07.csv')
            ->and($berkas->Judul)->toBe('Laporan penjualan detail 2026 10 01 2026 10 07')
            ->and($berkas->UkuranByte)->toBe(strlen($langsung))
            ->and($berkas->KedaluwarsaPada?->toDateString())->toBe('2026-10-14');
        Storage::disk('local')->assertExists((string) $berkas->PathBerkas);

        $unduh = $this->get("/kelola/unduhan/{$berkas->Uuid}/unduh")->assertOk();
        expect($unduh->streamedContent())->toBe($langsung)
            ->and($unduh->headers->get('Content-Disposition'))->toContain('laporan-penjualan-detail-2026-10-01-2026-10-07.csv');
        expect(DB::table('LogAudit')->whereIn('Peristiwa', ['unduhan.minta', 'unduhan.unduh'])->count())->toBe(2);
    });

    it('Excel dengan latarbelakang=1 menghasilkan berkas xlsx utuh di Pusat Unduhan', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);

        $this->get(ALAMAT_EKSPOR.'&format=xlsx&latarbelakang=1')->assertRedirect('/kelola/unduhan');

        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $berkas = BerkasUnduhan::query()->sole();
        expect($berkas->Status)->toBe(StatusBerkasUnduhan::Siap)->and($berkas->Format)->toBe('xlsx');
        $isi = Storage::disk('local')->get((string) $berkas->PathBerkas);
        expect(substr((string) $isi, 0, 2))->toBe('PK')
            ->and(strlen((string) $isi))->toBe($berkas->UkuranByte);

        $zip = new ZipArchive;
        $sementara = tempnam(sys_get_temp_dir(), 'xlsx-uji-');
        file_put_contents((string) $sementara, $isi);
        expect($zip->open((string) $sementara))->toBeTrue()
            ->and((string) $zip->getFromName('xl/worksheets/sheet1.xml'))->toContain($d['Minyak']->Nama);
        $zip->close();
        @unlink((string) $sementara);
    });

    it('cetak tidak ditunda (halaman dirender peramban) dan tanpa flag ekspor tetap langsung', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);

        $this->get(ALAMAT_EKSPOR.'&format=cetak&latarbelakang=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Laporan/Cetak'));
        $this->get(ALAMAT_EKSPOR.'&format=csv')->assertOk();

        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        expect(BerkasUnduhan::query()->count())->toBe(0);
    });

    it('otorisasi diperiksa sebelum apa pun ditunda: Kasir tanpa izin laporan 403 dan tidak ada antrean', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id, PeranTenantBawaan::Kasir);

        $this->get(ALAMAT_EKSPOR.'&format=csv&latarbelakang=1')->assertForbidden();

        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        expect(BerkasUnduhan::query()->count())->toBe(0);
    });

    it('paling banyak tiga berkas aktif per pengguna; yang keempat ditolak dengan pesan dan tidak membuat baris', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        $pemilik = BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);
        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $idPengguna = (int) auth()->id();

        for ($i = 0; $i < 3; $i++) {
            BerkasUnduhan::query()->create(['IdTenant' => $d['Tenant']->Id, 'IdPengguna' => $idPengguna, 'Judul' => "Laporan {$i}", 'Format' => 'csv', 'Status' => StatusBerkasUnduhan::Diproses]);
        }

        $pemilik->get(ALAMAT_EKSPOR.'&format=csv&latarbelakang=1')
            ->assertRedirect('/kelola/unduhan')
            ->assertSessionHasErrors(['Umum']);

        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        expect(BerkasUnduhan::query()->count())->toBe(3);
    });
});

describe('D-124 Pusat Unduhan: daftar, unduh, hapus, isolasi', function (): void {
    it('daftar hanya berisi berkas milik sendiri; pengguna lain dan tenant lain tidak bisa melihat atau mengunduh', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);
        $this->get(ALAMAT_EKSPOR.'&format=csv&latarbelakang=1');
        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $milikku = BerkasUnduhan::query()->sole();

        $this->getJson('/kelola/unduhan')->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.Uuid', $milikku->Uuid)->assertJsonPath('Data.0.BisaDiunduh', true);

        $rekan = BantuanOrganisasi::TambahAnggota($d['Tenant']->Id, PeranTenantBawaan::Admin);
        BantuanOrganisasi::Masuk($this, $rekan, $d['Tenant']->Id);
        $this->getJson('/kelola/unduhan')->assertOk()->assertJsonPath('Meta.Total', 0);
        $this->get("/kelola/unduhan/{$milikku->Uuid}/unduh")->assertNotFound();
        $this->delete("/kelola/unduhan/{$milikku->Uuid}")->assertNotFound();

        $b = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id);
        $this->get("/kelola/unduhan/{$milikku->Uuid}/unduh")->assertNotFound();
    });

    it('halaman daftar dirender Inertia dengan opsi status dan masa berlaku', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);

        $this->get('/kelola/unduhan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Unduhan/Daftar')
            ->where('HariBerlaku', 7)
            ->has('OpsiStatus', 5)
            ->where('Unduhan.Meta.Total', 0));
    });

    it('berkas kedaluwarsa tidak bisa diunduh; hapus membuang berkas fisik; yang masih disiapkan tidak bisa dihapus', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);
        $this->get(ALAMAT_EKSPOR.'&format=csv&latarbelakang=1');
        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $berkas = BerkasUnduhan::query()->sole();
        $path = (string) $berkas->PathBerkas;

        $berkas->update(['KedaluwarsaPada' => now()->subMinute()]);
        $this->get("/kelola/unduhan/{$berkas->Uuid}/unduh")->assertNotFound();

        $berkas->update(['KedaluwarsaPada' => now()->addDay(), 'Status' => StatusBerkasUnduhan::Diproses]);
        $this->delete("/kelola/unduhan/{$berkas->Uuid}")->assertStatus(409);

        $berkas->update(['Status' => StatusBerkasUnduhan::Siap]);
        $this->delete("/kelola/unduhan/{$berkas->Uuid}")->assertRedirect()->assertSessionHas('Kilat');
        Storage::disk('local')->assertMissing($path);
        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        expect(BerkasUnduhan::query()->count())->toBe(0);
    });
});

describe('D-124 Pusat Unduhan: penyiapan dan perawatan', function (): void {
    it('penangan yang gagal atau bukan unduhan menandai Gagal dengan pesan, tidak melempar', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        $pemilik = BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);
        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $buat = fn (): BerkasUnduhan => BerkasUnduhan::query()->create(['IdTenant' => $d['Tenant']->Id, 'IdPengguna' => (int) auth()->id(), 'Judul' => 'Uji', 'Format' => 'csv', 'Status' => StatusBerkasUnduhan::Menunggu]);
        $proses = app(ProsesBerkasUnduhan::class);

        $meledak = $buat();
        $proses->Jalankan($meledak, function (): never {
            throw new RuntimeException('rahasia internal');
        });
        $biasa = $buat();
        $proses->Jalankan($biasa, fn () => response('bukan unduhan'));
        $kosong = $buat();
        $proses->Jalankan($kosong, fn () => new StreamedResponse(static function (): void {}));

        foreach ([$meledak, $biasa, $kosong] as $berkas) {
            expect($berkas->refresh()->Status)->toBe(StatusBerkasUnduhan::Gagal)
                ->and($berkas->Galat)->toContain('gagal disiapkan')
                ->and($berkas->Galat)->not->toContain('rahasia')
                ->and($berkas->PathBerkas)->toBeNull();
        }

        expect(app(BatasEksporLaporan::class)->CekLatarBelakang())->toBeFalse();
    });

    it('penangan yang menulis keluaran besar tertangkap utuh tanpa bocor ke respons', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);
        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $berkas = BerkasUnduhan::query()->create(['IdTenant' => $d['Tenant']->Id, 'IdPengguna' => (int) auth()->id(), 'Judul' => 'Uji', 'Format' => 'csv', 'Status' => StatusBerkasUnduhan::Menunggu]);
        $baris = str_repeat("1234567890,abcdefghij\n", 20000);

        app(ProsesBerkasUnduhan::class)->Jalankan($berkas, function () use ($baris): StreamedResponse {
            $balasan = new StreamedResponse(static function () use ($baris): void {
                foreach (str_split($baris, 8192) as $potongan) {
                    echo $potongan;
                }
            });
            $balasan->headers->set('Content-Disposition', 'attachment; filename="besar-sekali.csv"');

            return $balasan;
        });

        $berkas->refresh();
        expect($berkas->Status)->toBe(StatusBerkasUnduhan::Siap)
            ->and($berkas->UkuranByte)->toBe(strlen($baris))
            ->and($berkas->NamaBerkas)->toBe('besar-sekali.csv')
            ->and($berkas->Judul)->toBe('Besar sekali')
            ->and(Storage::disk('local')->get((string) $berkas->PathBerkas))->toBe($baris);
    });

    it('batas baris ekspor tabel: 5.000 langsung, jauh lebih besar hanya saat latar belakang', function (): void {
        $batas = app(BatasEksporLaporan::class);
        expect($batas->Maks())->toBe(5000);

        $batas->AktifkanLatarBelakang();
        expect($batas->Maks())->toBe(100000);

        $batas->NonaktifkanLatarBelakang();
        expect($batas->Maks())->toBe(5000);
    });

    it('unduhan:bersihkan menghapus berkas kedaluwarsa, menandai proses macet Gagal, dan membuang jejak lama', function (): void {
        $d = BantuanLaporan::SiapkanDataPenjualan($this);
        BantuanPersediaan::MasukSebagai($this, $d['Tenant']->Id);
        $this->get(ALAMAT_EKSPOR.'&format=csv&latarbelakang=1');
        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        $siap = BerkasUnduhan::query()->sole();
        $path = (string) $siap->PathBerkas;
        $macet = BerkasUnduhan::query()->create(['IdTenant' => $d['Tenant']->Id, 'IdPengguna' => $siap->IdPengguna, 'Judul' => 'Macet', 'Format' => 'xlsx', 'Status' => StatusBerkasUnduhan::Diproses]);
        $lama = BerkasUnduhan::query()->create(['IdTenant' => $d['Tenant']->Id, 'IdPengguna' => $siap->IdPengguna, 'Judul' => 'Lama', 'Format' => 'xlsx', 'Status' => StatusBerkasUnduhan::Gagal]);
        $lama->forceFill(['DibuatPada' => now()->subDays(40)])->save();

        $this->artisan('unduhan:bersihkan')->assertSuccessful();
        expect($siap->refresh()->Status)->toBe(StatusBerkasUnduhan::Siap)->and($macet->refresh()->Status)->toBe(StatusBerkasUnduhan::Diproses);
        Storage::disk('local')->assertExists($path);
        expect(BerkasUnduhan::query()->whereKey($lama->Id)->exists())->toBeFalse();

        $this->travelTo(now()->addDays(8));
        $this->artisan('unduhan:bersihkan')->assertSuccessful();

        BantuanOrganisasi::AturKonteks($d['Tenant']->Id);
        Storage::disk('local')->assertMissing($path);
        expect($siap->refresh()->Status)->toBe(StatusBerkasUnduhan::Kedaluwarsa)
            ->and($siap->PathBerkas)->toBeNull()
            ->and($macet->refresh()->Status)->toBe(StatusBerkasUnduhan::Gagal)
            ->and($macet->Galat)->toContain('terhenti');
        expect(BersihkanBerkasUnduhan::HARI_SIMPAN_JEJAK)->toBe(30);
    });
});
