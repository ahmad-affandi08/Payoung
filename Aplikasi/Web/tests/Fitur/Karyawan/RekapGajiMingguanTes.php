<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Karyawan\Enum\DasarGaji;
use App\Domain\Karyawan\Enum\SiklusGaji;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\RekapGaji;
use App\Domain\Karyawan\Model\RekapGajiBaris;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-18 gaji mingguan (D-219): siklus gaji per karyawan (Bulanan/Mingguan), dasar gaji (Tetap per siklus atau upah per
 * hari masuk), rekap per rentang tujuh hari yang tidak boleh beririsan, hari masuk dari absensi yang bisa disesuaikan
 * selama draf, jurnal J-18.2 seimbang berlabel rentang, dan pengingat Kotak Tindakan.
 */

beforeEach(function (): void {
    // Selasa 20 Oktober 2026 pukul 10.00 WIB.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

function CatatHadirMingguan(Karyawan $karyawan, int $idOutlet, string $tanggal): void
{
    Absensi::query()->create([
        'IdKaryawan' => $karyawan->Id,
        'IdOutlet' => $idOutlet,
        'TanggalBisnis' => $tanggal,
        'MasukPada' => CarbonImmutable::parse("{$tanggal} 08:00:00", 'Asia/Jakarta')->utc(),
        'KeluarPada' => CarbonImmutable::parse("{$tanggal} 16:00:00", 'Asia/Jakarta')->utc(),
    ]);
}

describe('F-18 gaji mingguan', function (): void {
    it('rekap mingguan hanya memuat karyawan bersiklus mingguan; upah harian = upah × hari masuk; bulanan tetap terpisah', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Toko Sayur Mingguan');
        $budi = Karyawan::query()->create(['Nama' => 'Budi Tetap Mingguan', 'GajiPokok' => '600000', 'SiklusGaji' => SiklusGaji::Mingguan, 'IdOutlet' => $k['Outlet']->Id]);
        $citra = Karyawan::query()->create([
            'Nama' => 'Citra Harian', 'GajiPokok' => '75000', 'SiklusGaji' => SiklusGaji::Mingguan, 'DasarGaji' => DasarGaji::PerHariMasuk,
            'PotonganTidakMasukPerHari' => '50000', 'IdOutlet' => $k['Outlet']->Id,
        ]);
        $dewi = Karyawan::query()->create(['Nama' => 'Dewi Bulanan', 'GajiPokok' => '2000000', 'IdOutlet' => $k['Outlet']->Id]);
        foreach (['2026-10-12', '2026-10-13', '2026-10-15'] as $tanggal) {
            CatatHadirMingguan($citra, $k['Outlet']->Id, $tanggal);
        }
        // Absensi di luar rentang tidak terhitung.
        CatatHadirMingguan($citra, $k['Outlet']->Id, '2026-10-19');
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);

        $this->post('/kelola/karyawan/gaji', ['Siklus' => 'Mingguan', 'Periode' => '2026-10-12'])->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $rekap = RekapGaji::query()->sole();
        $baris = RekapGajiBaris::query()->get()->keyBy('IdKaryawan');
        expect($rekap->Siklus)->toBe(SiklusGaji::Mingguan)
            ->and($rekap->Periode)->toBe('2026-10-12')
            ->and($rekap->TanggalMulai->toDateString())->toBe('2026-10-12')
            ->and($rekap->TanggalSelesai->toDateString())->toBe('2026-10-18')
            ->and($rekap->AmbilLabel())->toBe('12–18 Oktober 2026')
            ->and($baris)->toHaveCount(2)
            ->and($baris->has($dewi->Id))->toBeFalse()
            ->and($baris[$budi->Id]->GajiPokok)->toBe('600000.00')
            ->and($baris[$budi->Id]->HariMasuk)->toBeNull()
            // Upah 75.000 × 3 hari masuk = 225.000; hari tidak masuk tidak memotong karena upahnya memang per hari.
            ->and($baris[$citra->Id]->UpahHarian)->toBe('75000.00')
            ->and($baris[$citra->Id]->HariMasuk)->toBe(3)
            ->and($baris[$citra->Id]->GajiPokok)->toBe('225000.00')
            ->and($baris[$citra->Id]->PotonganTidakMasuk)->toBe('0.00')
            ->and($baris[$citra->Id]->Bersih)->toBe('225000.00')
            ->and($rekap->TotalBersih)->toBe('825000.00');

        // Rekap bulanan memuat Dewi saja, bukan karyawan mingguan.
        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-10'])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $bulanan = RekapGaji::query()->where('Siklus', SiklusGaji::Bulanan->value)->sole();
        expect(RekapGajiBaris::query()->where('IdRekapGaji', $bulanan->Id)->pluck('IdKaryawan')->all())->toBe([$dewi->Id]);
    });

    it('hari masuk bisa disesuaikan selama draf (0 sampai jumlah hari rentang); karyawan bergaji tetap tidak punya hari masuk', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Toko Sayur Hari Masuk');
        $budi = Karyawan::query()->create(['Nama' => 'Budi Tetap Mingguan', 'GajiPokok' => '600000', 'SiklusGaji' => SiklusGaji::Mingguan, 'IdOutlet' => $k['Outlet']->Id]);
        $citra = Karyawan::query()->create([
            'Nama' => 'Citra Harian', 'GajiPokok' => '75000', 'SiklusGaji' => SiklusGaji::Mingguan, 'DasarGaji' => DasarGaji::PerHariMasuk, 'IdOutlet' => $k['Outlet']->Id,
        ]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/karyawan/gaji', ['Siklus' => 'Mingguan', 'Periode' => '2026-10-12'])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $rekap = RekapGaji::query()->sole();
        // Belum ada absensi: baris upah harian tetap ada dengan hari masuk 0 supaya bisa diisi pengelola.
        expect(RekapGajiBaris::query()->where('IdKaryawan', $citra->Id)->sole()->HariMasuk)->toBe(0);

        $sama = ['Tambahan' => '0', 'PotonganKasbon' => '0', 'PotonganLain' => '0'];
        $this->put("/kelola/karyawan/gaji/{$rekap->Uuid}/baris/{$citra->Uuid}", $sama + ['HariMasuk' => '6'])->assertRedirect();
        $this->put("/kelola/karyawan/gaji/{$rekap->Uuid}/baris/{$citra->Uuid}", $sama + ['HariMasuk' => '8'])
            ->assertSessionHasErrors(['HariMasuk' => 'Hari masuk harus antara 0 dan 7.']);
        $this->put("/kelola/karyawan/gaji/{$rekap->Uuid}/baris/{$budi->Uuid}", $sama + ['HariMasuk' => '5'])
            ->assertSessionHasErrors(['HariMasuk' => 'Karyawan ini digaji tetap, jadi tidak punya hari masuk.']);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $baris = RekapGajiBaris::query()->where('IdKaryawan', $citra->Id)->sole();
        expect($baris->HariMasuk)->toBe(6)
            ->and($baris->GajiPokok)->toBe('450000.00')
            ->and($baris->Bersih)->toBe('450000.00')
            ->and(RekapGaji::query()->sole()->TotalBersih)->toBe('1050000.00');
        $this->get("/kelola/karyawan/gaji/{$rekap->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Rekap.LabelPeriode', '12–18 Oktober 2026')
            ->where('Rekap.JumlahHari', 7)
            ->where('Baris.1.HariMasuk', 6));
    });

    it('rentang mingguan tidak boleh beririsan, tidak setelah hari ini, dan harus tanggal yang valid; tanggal mulai tidak harus Senin', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Toko Sayur Rentang');
        Karyawan::query()->create(['Nama' => 'Budi Tetap Mingguan', 'GajiPokok' => '600000', 'SiklusGaji' => SiklusGaji::Mingguan, 'IdOutlet' => $k['Outlet']->Id]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $alamat = '/kelola/karyawan/gaji';

        $this->post($alamat, ['Siklus' => 'Mingguan', 'Periode' => '2026-10-11'])->assertRedirect();
        $this->post($alamat, ['Siklus' => 'Mingguan', 'Periode' => '2026-10-11'])->assertSessionHasErrors(['Periode' => 'Rekap gaji 2026-10-11 sudah dibuat.']);
        $this->post($alamat, ['Siklus' => 'Mingguan', 'Periode' => '2026-10-17'])
            ->assertSessionHasErrors(['Periode' => 'Rentang ini beririsan dengan rekap gaji mingguan 11–17 Oktober 2026. Pilih tanggal mulai setelah rekap itu.']);
        $this->post($alamat, ['Siklus' => 'Mingguan', 'Periode' => '2026-10-21'])
            ->assertSessionHasErrors(['Periode' => 'Rekap gaji mingguan tidak bisa dimulai setelah hari ini.']);
        $this->post($alamat, ['Siklus' => 'Mingguan', 'Periode' => '2026-02-31'])->assertSessionHasErrors('Periode');
        $this->post($alamat, ['Siklus' => 'Mingguan', 'Periode' => '2025-01-05'])
            ->assertSessionHasErrors(['Periode' => 'Tanggal mulai terlalu lama. Rekap mingguan hanya sampai satu tahun ke belakang.']);
        // Rentang berikutnya langsung menyambung, boleh melewati batas bulan.
        $this->post($alamat, ['Siklus' => 'Mingguan', 'Periode' => '2026-10-18'])->assertRedirect();
        // Bulan tidak boleh dipakai sebagai tanggal mulai, dan sebaliknya.
        $this->post($alamat, ['Siklus' => 'Mingguan', 'Periode' => '2026-10'])->assertSessionHasErrors('Periode');
        $this->post($alamat, ['Siklus' => 'Bulanan', 'Periode' => '2026-10-12'])->assertSessionHasErrors('Periode');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(RekapGaji::query()->orderBy('TanggalMulai')->get()->map(fn (RekapGaji $r): string => $r->TanggalMulai->toDateString().'..'.$r->TanggalSelesai->toDateString())->all())
            ->toBe(['2026-10-11..2026-10-17', '2026-10-18..2026-10-24']);
    });

    it('bayar rekap mingguan: jurnal seimbang berlabel rentang, tidak bisa diubah lagi', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Toko Sayur Bayar Mingguan');
        Karyawan::query()->create(['Nama' => 'Budi Tetap Mingguan', 'GajiPokok' => '600000', 'SiklusGaji' => SiklusGaji::Mingguan, 'IdOutlet' => $k['Outlet']->Id]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
        $beban = Akun::query()->where('Kode', '6-1000')->firstOrFail();
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post('/kelola/karyawan/gaji', ['Siklus' => 'Mingguan', 'Periode' => '2026-10-12'])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $rekap = RekapGaji::query()->sole();

        $this->post("/kelola/karyawan/gaji/{$rekap->Uuid}/bayar", ['Tanggal' => '2026-10-20', 'AkunKasBank' => $kas->Uuid, 'AkunBeban' => $beban->Uuid])->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $jurnal = Jurnal::query()->where('JenisSumber', JenisSumberJurnal::RekapGaji->value)->sole();
        expect($jurnal->Keterangan)->toBe('Rekap gaji 12–18 Oktober 2026')
            ->and($jurnal->TotalDebit)->toBe('600000.00')
            ->and((string) JurnalDetail::query()->selectRaw('CAST(SUM(`Debit`) - SUM(`Kredit`) AS DECIMAL(20,2)) AS s')->value('s'))->toBe('0.00')
            ->and(RekapGaji::query()->sole()->Status->value)->toBe('Dibayar')
            ->and(LogAudit::query()->where('Peristiwa', 'rekap-gaji.buat')->sole()->NilaiBaru)->toMatchArray(['Periode' => '2026-10-12', 'Siklus' => 'Mingguan']);
        $this->post('/kelola/karyawan/gaji', ['Siklus' => 'Mingguan', 'Periode' => '2026-10-19'])->assertRedirect();
        $this->delete("/kelola/karyawan/gaji/{$rekap->Uuid}")->assertSessionHasErrors('Umum');
    });

    it('usulan rekap berikutnya dan pengingat Kotak Tindakan bila rentang sudah lewat; tanpa karyawan mingguan tidak ada pengingat', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Toko Sayur Pengingat');
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $kunci = function () {
            $butir = [];
            $this->get('/kelola/tindakan')->assertOk()->assertInertia(function (AssertableInertia $h) use (&$butir) {
                $butir = collect($h->toArray()['props']['Butir'])->pluck('Kunci')->all();
            });

            return $butir;
        };
        expect($kunci())->not->toContain('karyawan.rekap-gaji-mingguan');

        Karyawan::query()->create(['Nama' => 'Budi Tetap Mingguan', 'GajiPokok' => '600000', 'SiklusGaji' => SiklusGaji::Mingguan, 'IdOutlet' => $k['Outlet']->Id]);
        // Tanpa rekap sebelumnya: usulan = Senin pekan ini (19 Okt) yang belum selesai, jadi belum diingatkan.
        $this->get('/kelola/karyawan/gaji')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('UsulanMingguan.Usulan', '2026-10-19')
            ->where('UsulanMingguan.SudahLewat', false)
            ->where('UsulanMingguan.JumlahKaryawan', 1));
        expect($kunci())->not->toContain('karyawan.rekap-gaji-mingguan');

        // Rekap terakhir 5–11 Okt: usulan 12–18 Okt sudah lewat → diingatkan.
        $this->post('/kelola/karyawan/gaji', ['Siklus' => 'Mingguan', 'Periode' => '2026-10-05'])->assertRedirect();
        $this->get('/kelola/karyawan/gaji')->assertInertia(fn (AssertableInertia $h) => $h
            ->where('UsulanMingguan.Usulan', '2026-10-12')
            ->where('UsulanMingguan.LabelUsulan', '12–18 Oktober 2026')
            ->where('UsulanMingguan.SudahLewat', true));
        expect($kunci())->toContain('karyawan.rekap-gaji-mingguan');

        // Setelah rekap 12–18 dibuat, pengingat hilang (19–25 belum selesai).
        $this->post('/kelola/karyawan/gaji', ['Siklus' => 'Mingguan', 'Periode' => '2026-10-12'])->assertRedirect();
        expect($kunci())->not->toContain('karyawan.rekap-gaji-mingguan');
    });

    it('formulir karyawan menyimpan siklus & dasar gaji; upah harian wajib diisi; peran tanpa karyawan.kelola ditolak', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Toko Sayur Formulir Gaji');
        $citra = Karyawan::query()->create(['Nama' => 'Citra Harian', 'IdOutlet' => $k['Outlet']->Id]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);

        $this->put("/kelola/karyawan/{$citra->Uuid}", ['Nama' => 'Citra Harian', 'SiklusGaji' => 'Mingguan', 'DasarGaji' => 'PerHariMasuk'])
            ->assertSessionHasErrors(['GajiPokok' => 'Isi upah per hari untuk karyawan berupah harian.']);
        $this->put("/kelola/karyawan/{$citra->Uuid}", ['Nama' => 'Citra Harian', 'SiklusGaji' => 'Harian'])->assertSessionHasErrors('SiklusGaji');
        $this->put("/kelola/karyawan/{$citra->Uuid}", ['Nama' => 'Citra Harian', 'SiklusGaji' => 'Mingguan', 'DasarGaji' => 'PerHariMasuk', 'GajiPokok' => '75000'])->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $citra->refresh();
        expect($citra->SiklusGaji)->toBe(SiklusGaji::Mingguan)
            ->and($citra->DasarGaji)->toBe(DasarGaji::PerHariMasuk)
            ->and($citra->GajiPokok)->toBe('75000.00');
        // Mengubah tanpa menyebut siklus tidak mengembalikannya ke bawaan.
        $this->put("/kelola/karyawan/{$citra->Uuid}", ['Nama' => 'Citra Harian', 'GajiPokok' => '80000'])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($citra->refresh()->SiklusGaji)->toBe(SiklusGaji::Mingguan)->and($citra->DasarGaji)->toBe(DasarGaji::PerHariMasuk);
    });
});
