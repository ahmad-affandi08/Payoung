<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Karyawan\Enum\CakupanKomisi;
use App\Domain\Karyawan\Enum\JenisKomisi;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Model\AturanKomisi;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\Kasbon;
use App\Domain\Karyawan\Model\Komisi;
use App\Domain\Karyawan\Model\RekapGaji;
use App\Domain\Karyawan\Model\RekapGajiBaris;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-18 bagian 3 rekap gaji bulanan: draf dari gaji pokok + komisi bersih periode, potongan kasbon awal = sisa kasbon
 * (maks. gaji kotor), ubah baris, bayar = jurnal seimbang (Dr beban, Cr Piutang Karyawan, Cr Pendapatan Lain, Cr kas)
 * + pelunasan kasbon terlama dulu, append-only setelah dibayar, izin `karyawan.kelola`, isolasi tenant.
 */

beforeEach(function (): void {
    // 20 Oktober 2026 pukul 10.00 WIB.
    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
    BantuanPendaftaran::SiapkanPrasyarat();
});

afterEach(fn () => Carbon::setTestNow());

function SaldoAkunGaji(int $idAkun): string
{
    return (string) JurnalDetail::query()->where('IdAkun', $idAkun)->selectRaw('CAST(COALESCE(SUM(`Debit` - `Kredit`), 0) AS DECIMAL(20,2)) AS s')->value('s');
}

describe('F-18 bagian 3 rekap gaji', function (): void {
    it('buat draf → ubah baris → bayar: jurnal seimbang, kasbon terlama dipotong dulu, lalu append-only', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Cantik Gaji');
        $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $maya = Karyawan::query()->create(['Nama' => 'Maya Senior', 'GajiPokok' => '3000000', 'IdOutlet' => $k['Outlet']->Id]);
        $dewi = Karyawan::query()->create(['Nama' => 'Dewi Junior', 'GajiPokok' => '1000000', 'IdOutlet' => $k['Outlet']->Id]);
        Karyawan::query()->create(['Nama' => 'Tanpa Gaji']);
        Karyawan::query()->create(['Nama' => 'Sudah Keluar', 'GajiPokok' => '2000000', 'Status' => StatusKaryawan::Nonaktif]);
        AturanKomisi::query()->create(['Nama' => 'Umum 10%', 'Cakupan' => CakupanKomisi::Semua, 'Jenis' => JenisKomisi::Persen, 'Nilai' => '10']);

        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '2', 'Harga' => '38500.00', 'Staf' => [$maya->Uuid]]]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $komisiMaya = (string) Komisi::query()->where('IdKaryawan', $maya->Id)->sole()->Jumlah;
        $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
        $beban = Akun::query()->where('Kode', '6-1000')->firstOrFail();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        // Dua kasbon Dewi: 600.000 (1 Okt) lalu 700.000 (5 Okt) = 1.300.000 > gaji kotor 1.000.000.
        foreach ([['2026-10-01', '600000'], ['2026-10-05', '700000']] as [$tanggal, $jumlah]) {
            $this->post('/kelola/karyawan/kasbon', ['Karyawan' => $dewi->Uuid, 'Tanggal' => $tanggal, 'Jumlah' => $jumlah, 'AkunKasBank' => $kas->Uuid])->assertRedirect();
        }

        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-11'])->assertSessionHasErrors(['Periode' => 'Rekap gaji hanya untuk bulan berjalan atau sebelumnya.']);
        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-13'])->assertSessionHasErrors('Periode');
        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-10'])->assertRedirect();
        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-10'])->assertSessionHasErrors(['Periode' => 'Rekap gaji 2026-10 sudah dibuat.']);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $rekap = RekapGaji::query()->sole();
        $baris = RekapGajiBaris::query()->get()->keyBy('IdKaryawan');
        $kotorMaya = Uang::Dari('3000000')->Tambah(Uang::Dari($komisiMaya));
        // Hanya karyawan aktif yang punya gaji pokok atau komisi.
        expect($baris)->toHaveCount(2)
            ->and($baris[$maya->Id]->Komisi)->toBe($komisiMaya)
            ->and($baris[$maya->Id]->PotonganKasbon)->toBe('0.00')
            ->and($baris[$maya->Id]->Bersih)->toBe($kotorMaya->KeString())
            ->and($baris[$dewi->Id]->PotonganKasbon)->toBe('1000000.00')
            ->and($baris[$dewi->Id]->Bersih)->toBe('0.00')
            ->and($rekap->TotalKotor)->toBe($kotorMaya->Tambah(Uang::Dari('1000000'))->KeString());

        $this->get("/kelola/karyawan/gaji/{$rekap->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Karyawan/DetailGaji')
            ->where('Rekap.LabelPeriode', 'Oktober 2026')
            ->where('Baris.0.Nama', 'Dewi Junior')
            ->where('Baris.0.SisaKasbon', '1300000.00'));

        $alamatDewi = "/kelola/karyawan/gaji/{$rekap->Uuid}/baris/{$dewi->Uuid}";
        $this->put($alamatDewi, ['Tambahan' => '500000', 'PotonganKasbon' => '1400000', 'PotonganLain' => '0'])
            ->assertSessionHasErrors(['PotonganKasbon' => 'Potongan kasbon melebihi sisa kasbon Rp 1.300.000.']);
        $this->put($alamatDewi, ['Tambahan' => '0', 'PotonganKasbon' => '900000', 'PotonganLain' => '200000'])
            ->assertSessionHasErrors(['PotonganLain' => 'Potongan melebihi gaji kotor. Kurangi potongan.']);
        // Dewi: kotor 1.500.000, potong kasbon 800.000 + denda 50.000 → bersih 650.000.
        $this->put($alamatDewi, ['Tambahan' => '500000', 'PotonganKasbon' => '800000', 'PotonganLain' => '50000', 'Catatan' => 'Lembur 5 hari. Denda terlambat'])->assertRedirect();

        $this->post("/kelola/karyawan/gaji/{$rekap->Uuid}/bayar", ['Tanggal' => '2026-10-21', 'AkunKasBank' => $kas->Uuid, 'AkunBeban' => $beban->Uuid])
            ->assertSessionHasErrors(['Tanggal' => 'Tanggal bayar tidak boleh setelah hari ini.']);
        $this->post("/kelola/karyawan/gaji/{$rekap->Uuid}/bayar", ['Tanggal' => '2026-10-20', 'AkunKasBank' => $kas->Uuid, 'AkunBeban' => $kas->Uuid])
            ->assertSessionHasErrors(['AkunBeban' => 'Pilih akun beban gaji.']);
        $saldoKasSebelum = Uang::Dari(SaldoAkunGaji($kas->Id));
        $this->post("/kelola/karyawan/gaji/{$rekap->Uuid}/bayar", ['Tanggal' => '2026-10-20', 'AkunKasBank' => $kas->Uuid, 'AkunBeban' => $beban->Uuid])->assertRedirect();

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $rekap->refresh();
        $bersih = $kotorMaya->Tambah(Uang::Dari('650000'));
        $jurnal = Jurnal::query()->where('JenisSumber', JenisSumberJurnal::RekapGaji->value)->sole();
        $kasbon = Kasbon::query()->orderBy('Tanggal')->get();
        expect($rekap->Status->value)->toBe('Dibayar')
            ->and($rekap->TotalBersih)->toBe($bersih->KeString())
            ->and($rekap->IdJurnal)->toBe($jurnal->Id)
            ->and($jurnal->TotalDebit)->toBe($kotorMaya->Tambah(Uang::Dari('1500000'))->KeString())
            ->and(SaldoAkunGaji($beban->Id))->toBe($jurnal->TotalDebit)
            ->and(Uang::Dari(SaldoAkunGaji($kas->Id))->KeString())->toBe($saldoKasSebelum->Kurangi($bersih)->KeString())
            // Kasbon 1 Okt lunas dulu (600.000), sisanya 200.000 dari kasbon 5 Okt.
            ->and($kasbon[0]->Status->value)->toBe('Lunas')
            ->and($kasbon[1]->Sisa)->toBe('500000.00')
            ->and(SaldoAkunGaji(BantuanJurnal::IdAkunPeran(PeranAkun::PiutangKaryawan)))->toBe('500000.00')
            ->and(SaldoAkunGaji(BantuanJurnal::IdAkunPeran(PeranAkun::PendapatanLain)))->toBe('-50000.00')
            ->and((string) JurnalDetail::query()->selectRaw('CAST(SUM(`Debit`) - SUM(`Kredit`) AS DECIMAL(20,2)) AS s')->value('s'))->toBe('0.00')
            ->and(LogAudit::query()->where('Peristiwa', 'like', 'rekap-gaji.%')->orderBy('Peristiwa')->pluck('Peristiwa')->all())->toBe(['rekap-gaji.bayar', 'rekap-gaji.buat', 'rekap-gaji.ubah']);

        // Setelah dibayar: tidak bisa diubah, dibayar ulang, atau dihapus.
        $this->put($alamatDewi, ['Tambahan' => '0', 'PotonganKasbon' => '0', 'PotonganLain' => '0'])
            ->assertSessionHasErrors(['Umum' => 'Rekap gaji yang sudah dibayar tidak bisa diubah.']);
        $this->post("/kelola/karyawan/gaji/{$rekap->Uuid}/bayar", ['Tanggal' => '2026-10-20', 'AkunKasBank' => $kas->Uuid, 'AkunBeban' => $beban->Uuid])->assertSessionHasErrors('Umum');
        $this->delete("/kelola/karyawan/gaji/{$rekap->Uuid}")->assertSessionHasErrors('Umum');

        $this->getJson('/kelola/karyawan/gaji?saring[Status]=Dibayar')->assertOk()
            ->assertJsonPath('Meta.Total', 1)
            ->assertJsonPath('Data.0.TotalBersih', $bersih->KeString());
        $this->get("/kelola/karyawan/gaji/{$rekap->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Rekap.Jurnal.Nomor', $jurnal->Nomor)
            ->where('Rekap.AkunBeban', '6-1000 Beban Gaji & Komisi'));
        $csv = $this->get("/kelola/karyawan/gaji/{$rekap->Uuid}/ekspor")->assertOk()->streamedContent();
        expect($csv)->toContain('Nama,Jabatan,"Gaji pokok"')->toContain('Dewi Junior')->toContain('650000.00');
    });

    it('draf bisa dihapus. Periode yang dipakai kembali tersedia. Butuh karyawan.kelola. Tenant lain tidak bisa mengakses', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Kopi Senja Solo');
        $b = BantuanPersediaan::SiapkanTenant('Warung Bakso Pak Kumis');
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        Karyawan::query()->create(['Nama' => 'Rina Wulandari', 'GajiPokok' => '2500000']);

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/karyawan/gaji')->assertForbidden();
        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-10'])->assertForbidden();

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->get('/kelola/karyawan/gaji')->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Karyawan/Gaji')
            ->where('OpsiPeriode.0', ['Nilai' => '2026-10', 'Label' => 'Oktober 2026'])
            ->count('OpsiPeriode', 12));
        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-10'])->assertRedirect();
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        $rekap = RekapGaji::query()->sole();
        expect($rekap->TotalBersih)->toBe('2500000.00');
        $this->get('/kelola/karyawan/gaji')->assertInertia(fn (AssertableInertia $h) => $h->where('OpsiPeriode.0.Nilai', '2026-09'));

        BantuanPersediaan::MasukSebagai($this, $b['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->getJson('/kelola/karyawan/gaji')->assertOk()->assertJsonPath('Meta.Total', 0);
        $this->get("/kelola/karyawan/gaji/{$rekap->Uuid}")->assertNotFound();
        $this->delete("/kelola/karyawan/gaji/{$rekap->Uuid}")->assertNotFound();

        BantuanPersediaan::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->delete("/kelola/karyawan/gaji/{$rekap->Uuid}")->assertRedirect('/kelola/karyawan/gaji');
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(RekapGaji::query()->count())->toBe(0)
            ->and(RekapGajiBaris::query()->count())->toBe(0)
            ->and(LogAudit::query()->where('Peristiwa', 'rekap-gaji.hapus')->count())->toBe(1);
    });
});

describe('F-18 rekap gaji aksi massal', function (): void {
    it('tambahan massal (THR/bonus): semua karyawan terpilih bertambah, audit per baris, semua-atau-tidak, ditolak setelah dibayar', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Salon Cantik THR');
        $maya = Karyawan::query()->create(['Nama' => 'Maya Senior', 'GajiPokok' => '3000000', 'IdOutlet' => $k['Outlet']->Id]);
        $dewi = Karyawan::query()->create(['Nama' => 'Dewi Junior', 'GajiPokok' => '1000000', 'IdOutlet' => $k['Outlet']->Id]);
        $luar = Karyawan::query()->create(['Nama' => 'Baru Masuk Setelah Rekap', 'GajiPokok' => '2000000', 'IdOutlet' => $k['Outlet']->Id]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
        $beban = Akun::query()->where('Kode', '6-1000')->firstOrFail();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        // Karyawan ketiga dibuat setelah draf sehingga tidak ada di rekap.
        Karyawan::query()->whereKey($luar->Id)->update(['Status' => StatusKaryawan::Nonaktif]);
        $this->post('/kelola/karyawan/gaji', ['Periode' => '2026-10'])->assertRedirect();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $rekap = RekapGaji::query()->sole();
        $alamat = "/kelola/karyawan/gaji/{$rekap->Uuid}/tambahan-massal";

        $this->post($alamat, ['Tambahan' => '250000', 'Catatan' => 'THR 2026', 'Uuid' => [$maya->Uuid, $dewi->Uuid]])
            ->assertSessionHasNoErrors()->assertSessionHas('Kilat', 'Tambahan ditambahkan ke 2 karyawan.');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $baris = RekapGajiBaris::query()->get()->keyBy('IdKaryawan');
        expect($baris[$maya->Id]->Tambahan)->toBe('250000.00')->and($baris[$maya->Id]->Bersih)->toBe('3250000.00')
            ->and($baris[$dewi->Id]->Tambahan)->toBe('250000.00')->and($baris[$dewi->Id]->Catatan)->toBe('THR 2026')
            ->and(LogAudit::query()->where('Peristiwa', 'rekap-gaji.ubah')->count())->toBe(2);

        // Diulang menambah lagi (akumulatif); jumlah 0, minus, dan karyawan di luar rekap menolak semuanya.
        $this->post($alamat, ['Tambahan' => '50000', 'Uuid' => [$maya->Uuid]])->assertSessionHasNoErrors();
        $this->post($alamat, ['Tambahan' => '0', 'Uuid' => [$maya->Uuid]])->assertSessionHasErrors('Tambahan');
        $this->post($alamat, ['Tambahan' => '-5', 'Uuid' => [$maya->Uuid]])->assertSessionHasErrors('Tambahan');
        $this->post($alamat, ['Tambahan' => '10000', 'Uuid' => [$maya->Uuid, $luar->Uuid]])->assertSessionHasErrors('Uuid');
        $this->post($alamat, ['Tambahan' => '10000', 'Uuid' => []])->assertSessionHasErrors('Uuid');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(RekapGajiBaris::query()->where('IdKaryawan', $maya->Id)->sole()->Tambahan)->toBe('300000.00');

        $this->post("/kelola/karyawan/gaji/{$rekap->Uuid}/bayar", ['Tanggal' => '2026-10-20', 'AkunKasBank' => $kas->Uuid, 'AkunBeban' => $beban->Uuid])->assertRedirect();
        $this->post($alamat, ['Tambahan' => '10000', 'Uuid' => [$maya->Uuid]])->assertSessionHasErrors('Umum');
    });
});
