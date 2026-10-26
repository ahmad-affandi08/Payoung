<?php

declare(strict_types=1);

use App\Domain\Bersama\Status\StatusDataMaster;
use App\Domain\Pengelola\Katalog\Aksi\SiapkanKatalogBawaan;
use App\Domain\Pengelola\Referensi\Model\PersetujuanDataMaster;
use App\Domain\Pengelola\TimInternal\Enum\PeranPengelolaBawaan;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Kueri\HargaPaketBerlaku;
use App\Domain\Tenant\Model\HargaPaket;
use App\Domain\Tenant\Model\Paket;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Pengelola\BantuanPengelola;
use Tests\TestCase;

function MasukKatalog(TestCase $tes, PenggunaPengelola $pengguna): TestCase
{
    return $tes->actingAs($pengguna, 'pengelola')->withSession(BantuanPengelola::SesiTerverifikasi());
}

/** Usulkan & ajukan harga PRO sebagai $pengaju, kembalikan versinya. */
function UsulkanHargaPro(TestCase $tes, PenggunaPengelola $pengaju, string $bulanan, string $mulai, bool $terapkanLama = false): HargaPaket
{
    $pro = Paket::query()->where('Kode', 'PRO')->sole();
    MasukKatalog($tes, $pengaju)->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga"), [
        'HargaBulanan' => $bulanan,
        'HargaTahunan' => $bulanan.'0',
        'BerlakuMulai' => $mulai,
        'TerapkanKePelangganLama' => $terapkanLama,
    ])->assertSessionHasNoErrors();
    $harga = HargaPaket::query()->latest('Id')->firstOrFail();
    $tes->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga/{$harga->Uuid}/ajukan"))->assertSessionHasNoErrors();

    return $harga->refresh();
}

function TinjauHarga(TestCase $tes, PenggunaPengelola $peninjau, HargaPaket $harga, string $keputusan = 'Setuju', ?string $catatan = null): TestResponse
{
    $paket = Paket::query()->findOrFail($harga->IdPaket);

    return MasukKatalog($tes, $peninjau)->post(
        BantuanPengelola::Url("/katalog/paket/{$paket->Uuid}/harga/{$harga->Uuid}/tinjau"),
        ['Keputusan' => $keputusan, 'Catatan' => $catatan],
    );
}

beforeEach(function (): void {
    // Waktu dibekukan agar tanggal di test tidak kedaluwarsa seiring waktu (BR tanggal berlaku tidak boleh lewat).
    $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'Asia/Jakarta'));
    app(SiapkanKatalogBawaan::class)->Jalankan();
    HargaPaket::query()->delete();
});

describe('Harga paket berversi (P-04, BR-P04.1, BR-P04.5)', function (): void {
    it('Keuangan mengusulkan, Super Admin menerbitkan; Keuangan tidak bisa menyetujui. Ajuan Super Admin langsung terbit (D-34)', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $superAdminPenyusun = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $harga = UsulkanHargaPro($this, $keuangan, '199000', '2026-10-01');

        expect($harga->Status)->toBe(StatusDataMaster::MenungguTinjauan)
            ->and($harga->HargaBulanan)->toBe('199000.00');

        TinjauHarga($this, $keuangan, $harga)->assertForbidden();

        TinjauHarga($this, $superAdmin, $harga)->assertSessionHasNoErrors();
        expect($harga->refresh()->Status)->toBe(StatusDataMaster::Terbit);
        $this->assertDatabaseHas('LogAuditPengelola', ['Aksi' => 'katalog.harga.terbit', 'IdObjek' => $harga->Id]);

        // D-34: Super Admin tidak terikat tinjau-meninjau; draf yang ia ubah lalu ajukan sendiri langsung terbit.
        $draf = UsulkanHargaPro($this, $keuangan, '209000', '2027-01-01');
        TinjauHarga($this, $superAdmin, $draf, 'Tolak', 'Perbaiki harga tahunan');
        $pro = Paket::query()->where('Kode', 'PRO')->sole();
        MasukKatalog($this, $superAdminPenyusun)->put(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga/{$draf->Uuid}"), [
            'HargaBulanan' => '209000', 'HargaTahunan' => '2006400', 'BerlakuMulai' => '2027-01-01', 'TerapkanKePelangganLama' => false,
        ])->assertSessionHasNoErrors();
        $this->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga/{$draf->Uuid}/ajukan"))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('Kilat', 'Harga terbit.');
        expect($draf->refresh()->Status)->toBe(StatusDataMaster::Terbit)
            ->and($harga->refresh()->BerlakuSampai?->toDateString())->toBe('2026-12-31');
    });

    it('harga baru mengakhiri harga lama. Harga terbit tidak bisa diubah', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $lama = UsulkanHargaPro($this, $keuangan, '199000', '2026-10-01');
        TinjauHarga($this, $superAdmin, $lama);
        $baru = UsulkanHargaPro($this, $keuangan, '219000', '2027-01-01');
        TinjauHarga($this, $superAdmin, $baru);

        expect($lama->refresh()->BerlakuSampai?->toDateString())->toBe('2026-12-31')
            ->and(fn () => $lama->refresh()->update(['HargaBulanan' => '1']))->toThrow(LogicException::class)
            ->and($lama->refresh()->HargaBulanan)->toBe('199000.00');

        $pro = Paket::query()->where('Kode', 'PRO')->sole();
        MasukKatalog($this, $keuangan)->put(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga/{$lama->Uuid}"), [
            'HargaBulanan' => '1', 'HargaTahunan' => '1', 'BerlakuMulai' => '2027-01-01', 'TerapkanKePelangganLama' => false,
        ])->assertSessionHasErrors('Umum');
    });

    it('BR-P04.1: grandfathering: langganan lama tetap harga lama kecuali versi baru diterapkan ke pelanggan lama', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $idPro = Paket::query()->where('Kode', 'PRO')->sole()->Id;
        TinjauHarga($this, $superAdmin, UsulkanHargaPro($this, $keuangan, '199000', '2026-10-01'));
        TinjauHarga($this, $superAdmin, UsulkanHargaPro($this, $keuangan, '219000', '2027-01-01'));
        $kueri = app(HargaPaketBerlaku::class);

        expect($kueri->Cari($idPro, Carbon::parse('2027-02-01'), Carbon::parse('2026-11-15'))?->HargaBulanan)->toBe('199000.00')
            ->and($kueri->Cari($idPro, Carbon::parse('2027-02-01'), Carbon::parse('2027-01-20'))?->HargaBulanan)->toBe('219000.00')
            ->and($kueri->Cari($idPro, Carbon::parse('2027-02-01'))?->HargaBulanan)->toBe('219000.00')
            ->and($kueri->Cari($idPro, Carbon::parse('2026-09-30')))->toBeNull();

        TinjauHarga($this, $superAdmin, UsulkanHargaPro($this, $keuangan, '229000', '2027-06-01', terapkanLama: true));
        expect($kueri->Cari($idPro, Carbon::parse('2027-07-01'), Carbon::parse('2026-11-15'))?->HargaBulanan)->toBe('229000.00');
    });

    it('BR-P04.5: harga dengan tanggal lewat tidak bisa diajukan maupun terbit', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $pro = Paket::query()->where('Kode', 'PRO')->sole();
        MasukKatalog($this, $keuangan)->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga"), [
            'HargaBulanan' => '199000', 'HargaTahunan' => '1910400', 'BerlakuMulai' => '2026-01-01', 'TerapkanKePelangganLama' => false,
        ]);
        $lewat = HargaPaket::query()->sole();
        $this->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga/{$lewat->Uuid}/ajukan"))->assertSessionHasErrors('BerlakuMulai');

        $besok = UsulkanHargaPro($this, $keuangan, '199000', now('Asia/Jakarta')->addDay()->toDateString());
        $this->travel(3)->days();
        TinjauHarga($this, $superAdmin, $besok)->assertSessionHasErrors('BerlakuMulai');
        expect(PersetujuanDataMaster::query()->count())->toBe(0);
    });

    it('menolak harga negatif/format salah dan paket harga negosiasi', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $pro = Paket::query()->where('Kode', 'PRO')->sole();
        $enterprise = Paket::query()->where('Kode', 'ENTERPRISE')->sole();
        MasukKatalog($this, $keuangan);

        $this->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga"), ['HargaBulanan' => '199.000', 'HargaTahunan' => '1', 'BerlakuMulai' => '2027-01-01', 'TerapkanKePelangganLama' => false])
            ->assertSessionHasErrors('HargaBulanan');
        $this->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga"), ['HargaBulanan' => '-1', 'HargaTahunan' => '1', 'BerlakuMulai' => '2027-01-01', 'TerapkanKePelangganLama' => false])
            ->assertSessionHasErrors('HargaBulanan');
        $this->post(BantuanPengelola::Url("/katalog/paket/{$enterprise->Uuid}/harga"), ['HargaBulanan' => '1', 'HargaTahunan' => '1', 'BerlakuMulai' => '2027-01-01', 'TerapkanKePelangganLama' => false])
            ->assertSessionHasErrors('Umum');
    });

    it('menampilkan riwayat harga di halaman paket', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        UsulkanHargaPro($this, $keuangan, '199000', '2026-10-01');
        $pro = Paket::query()->where('Kode', 'PRO')->sole();

        $this->get(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga"))
            ->assertInertia(fn (AssertableInertia $halaman) => $halaman->component('Pengelola/Katalog/HargaPaket')
                ->has('Harga', 1)
                ->where('Harga.0.Status', 'MenungguTinjauan')
                ->where('Harga.0.HargaBulanan', '199000.00'));
    });
    it('BR-P04.6: paket tidak bisa diaktifkan bila harga terbitnya baru berlaku nanti', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        TinjauHarga($this, $superAdmin, UsulkanHargaPro($this, $keuangan, '199000', '2026-10-01'));
        $pro = Paket::query()->where('Kode', 'PRO')->sole();

        MasukKatalog($this, $superAdmin)->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/aktifkan"))->assertSessionHasErrors('Umum');

        $this->travelTo(Carbon::parse('2026-10-01 08:00', 'Asia/Jakarta'));
        // Masuk ulang: lompatan waktu melewati batas sesi 30 menit tidak aktif (BR-P01.2).
        MasukKatalog($this, $superAdmin)->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/aktifkan"))->assertSessionHasNoErrors();
        expect($pro->refresh()->Status->value)->toBe('Aktif');
    });

    it('BR-P04.1: langganan yang dimulai sebelum versi harga pertama memakai versi paling awal (tidak pernah kosong)', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        TinjauHarga($this, $superAdmin, UsulkanHargaPro($this, $keuangan, '199000', '2026-10-01'));
        $idPro = Paket::query()->where('Kode', 'PRO')->sole()->Id;

        expect(app(HargaPaketBerlaku::class)->Cari($idPro, Carbon::parse('2026-11-01'), Carbon::parse('2026-01-01'))?->HargaBulanan)
            ->toBe('199000.00');
    });

    it('harga kedua dengan tanggal berlaku sama ditolak. Harga terbit tidak bisa dihapus', function (): void {
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $superAdmin = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $pertama = UsulkanHargaPro($this, $keuangan, '199000', '2026-10-01');
        $kembar = UsulkanHargaPro($this, $keuangan, '189000', '2026-10-01');
        TinjauHarga($this, $superAdmin, $pertama)->assertSessionHasNoErrors();
        TinjauHarga($this, $superAdmin, $kembar)->assertSessionHasErrors('Umum');

        expect(fn () => $pertama->refresh()->delete())->toThrow(LogicException::class);
    });

    it('§19.3: Dukungan tidak bisa mengusulkan harga. Pengaju yang bukan penyusun juga tidak bisa meninjau', function (): void {
        $dukungan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Dukungan);
        $keuangan = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::Keuangan);
        $superAdminPengaju = BantuanPengelola::BuatAnggota(PeranPengelolaBawaan::SuperAdmin);
        $pro = Paket::query()->where('Kode', 'PRO')->sole();

        MasukKatalog($this, $dukungan)->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga"), [
            'HargaBulanan' => '1', 'HargaTahunan' => '1', 'BerlakuMulai' => '2026-10-01', 'TerapkanKePelangganLama' => false,
        ])->assertForbidden();

        MasukKatalog($this, $keuangan)->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga"), [
            'HargaBulanan' => '199000', 'HargaTahunan' => '1910400', 'BerlakuMulai' => '2026-10-01', 'TerapkanKePelangganLama' => false,
        ]);
        $harga = HargaPaket::query()->sole();
        MasukKatalog($this, $superAdminPengaju)->post(BantuanPengelola::Url("/katalog/paket/{$pro->Uuid}/harga/{$harga->Uuid}/ajukan"))->assertSessionHasNoErrors();
        TinjauHarga($this, $superAdminPengaju, $harga)->assertSessionHasErrors('Umum');
    });
});
