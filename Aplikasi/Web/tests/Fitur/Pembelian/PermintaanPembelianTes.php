<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pembelian\Enum\StatusPermintaanPembelian;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\PermintaanPembelian;
use App\Domain\Pembelian\Model\PermintaanPembelianDetail;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Persediaan\Model\MutasiStok;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-04 PR (v6.06): permintaan pembelian dari outlet → diajukan → disetujui (bukan pembuat/pengaju; D-38 Pemilik atau
 * penyetuju tunggal langsung) → dijadikan draf PO. Tanpa dampak stok/jurnal; terkunci setelah Draf; isolasi tenant.
 */

beforeEach(fn () => BantuanPendaftaran::SiapkanPrasyarat());

/** @return array<string, mixed> */
function SiapkanPermintaanUji(): array
{
    $t = BantuanPembelian::SiapkanTenant();
    $pemasok = BantuanPembelian::BuatPemasok('PT Beras Delanggu Sejahtera');
    $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Delanggu Premium Pulen 1 kg']);
    $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kristal Putih Lokal 1 kg']);
    $dus = BantuanPembelian::TambahSatuan($beras, $t['Dus'], '10');

    return $t + ['Pemasok' => $pemasok, 'Beras' => $beras, 'Gula' => $gula, 'DusBeras' => $dus];
}

/** @return array<string, mixed> */
function KirimanPermintaanUji(array $k, array $timpa = []): array
{
    return [
        'UuidGudang' => $k['Gudang']->Uuid,
        'Tanggal' => BantuanPembelian::Hari()->toDateString(),
        'DibutuhkanPada' => BantuanPembelian::Hari()->addDays(3)->toDateString(),
        'Catatan' => 'Stok menipis menjelang akhir pekan',
        'Baris' => [
            ['UuidProduk' => $k['Beras']->Uuid, 'UuidProdukSatuan' => $k['DusBeras']->Uuid, 'Jumlah' => '4', 'Harga' => '135000'],
            ['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '12', 'Harga' => '16500'],
        ],
        ...$timpa,
    ];
}

function MasukPermintaan(object $tes, array $k, ?object $pengguna = null): object
{
    return BantuanOrganisasi::Masuk($tes, $pengguna ?? $k['Pemilik'], $k['Tenant']->Id);
}

describe('alur permintaan pembelian', function (): void {
    it('Pemilik: simpan draf → ajukan (langsung disetujui, audit) → jadikan PO dengan harga perkiraan', function (): void {
        $k = SiapkanPermintaanUji();
        $kiriman = KirimanPermintaanUji($k);

        MasukPermintaan($this, $k)->post('/kelola/pembelian/permintaan', $kiriman)->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pr = PermintaanPembelian::query()->sole();

        expect($pr->Nomor)->toStartWith('PP/')
            ->and($pr->Status)->toBe(StatusPermintaanPembelian::Draf)
            ->and($pr->Detail)->toHaveCount(2)
            ->and($pr->Detail->first()->only(['Jumlah', 'HargaPerkiraan', 'SimbolSatuan']))->toBe(['Jumlah' => '4.0000', 'HargaPerkiraan' => '135000.00', 'SimbolSatuan' => 'dus']);

        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/ajukan")->assertSessionHasNoErrors();
        expect($pr->refresh()->Status)->toBe(StatusPermintaanPembelian::Disetujui);
        $audit = LogAudit::query()->where('Peristiwa', 'permintaan-pembelian.ajukan')->sole();
        expect($audit->NilaiBaru)->toMatchArray(['Status' => 'Disetujui', 'DisetujuiLangsung' => 'Pemilik']);

        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/jadikan-po", ['UuidPemasok' => $k['Pemasok']->Uuid])->assertSessionHasNoErrors();
        $po = PesananPembelian::query()->with('Detail')->sole();

        expect($pr->refresh()->Status)->toBe(StatusPermintaanPembelian::DijadikanPo)
            ->and($pr->IdPesananPembelian)->toBe($po->Id)
            ->and($po->Status)->toBe(StatusPesananPembelian::Draf)
            ->and($po->IdPemasok)->toBe($k['Pemasok']->Id)
            ->and($po->Catatan)->toContain($pr->Nomor)
            ->and($po->Detail)->toHaveCount(2)
            ->and($po->Detail->first()->only(['Jumlah', 'Harga']))->toBe(['Jumlah' => '4.0000', 'Harga' => '135000.00']);
        // Tanpa dampak stok maupun jurnal.
        expect(MutasiStok::query()->count())->toBe(0)->and(Jurnal::query()->count())->toBe(0);

        // Sudah jadi PO: tidak bisa dijadikan PO lagi maupun dibatalkan.
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/jadikan-po", ['UuidPemasok' => $k['Pemasok']->Uuid])->assertSessionHasErrors('Umum');
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/batalkan", ['Alasan' => 'Tidak jadi dipesan'])->assertSessionHasErrors('Umum');
    });

    it('Simpan & ajukan dalam satu langkah, dan ubah draf mengganti semua baris', function (): void {
        $k = SiapkanPermintaanUji();
        MasukPermintaan($this, $k)->post('/kelola/pembelian/permintaan', [...KirimanPermintaanUji($k), 'Lanjutkan' => false])->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pr = PermintaanPembelian::query()->sole();

        $this->put("/kelola/pembelian/permintaan/{$pr->Uuid}", [
            ...KirimanPermintaanUji($k, ['Baris' => [['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '20', 'Harga' => '0']], 'Lanjutkan' => true]),
        ])->assertSessionHasNoErrors();

        expect($pr->refresh()->Status)->toBe(StatusPermintaanPembelian::Disetujui)
            ->and(PermintaanPembelianDetail::query()->where('IdPermintaanPembelian', $pr->Id)->count())->toBe(1)
            ->and(PermintaanPembelian::query()->count())->toBe(1);
    });

    it('four-eyes: pengaju tidak bisa menyetujui sendiri. Penyetuju lain menyetujui atau menolak dengan alasan', function (): void {
        $k = SiapkanPermintaanUji();
        $staf = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::StafPembelian);
        $admin = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Admin);

        MasukPermintaan($this, $k, $staf)->post('/kelola/pembelian/permintaan', KirimanPermintaanUji($k))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pr = PermintaanPembelian::query()->sole();
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/ajukan")->assertSessionHasNoErrors();
        expect($pr->refresh()->Status)->toBe(StatusPermintaanPembelian::Diajukan);

        // Staf tidak punya izin persetujuan.
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/setujui")->assertForbidden();

        MasukPermintaan($this, $k, $admin)->post("/kelola/pembelian/permintaan/{$pr->Uuid}/tolak", ['Alasan' => 'x'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/tolak", ['Alasan' => 'Stok gudang pusat masih cukup'])->assertSessionHasNoErrors();
        expect($pr->refresh()->Status)->toBe(StatusPermintaanPembelian::Draf)->and($pr->AlasanDitolak)->toBe('Stok gudang pusat masih cukup');

        MasukPermintaan($this, $k, $staf)->post("/kelola/pembelian/permintaan/{$pr->Uuid}/ajukan")->assertSessionHasNoErrors();
        MasukPermintaan($this, $k, $admin)->post("/kelola/pembelian/permintaan/{$pr->Uuid}/setujui")->assertSessionHasNoErrors();
        expect($pr->refresh()->Status)->toBe(StatusPermintaanPembelian::Disetujui)->and($pr->DiputuskanOleh)->toBe($admin->Id);
    });

    it('pembuat Admin tidak bisa menyetujui permintaannya sendiri bila ada penyetuju lain', function (): void {
        $k = SiapkanPermintaanUji();
        $admin = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Admin);
        $admin2 = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Admin);

        MasukPermintaan($this, $k, $admin)->post('/kelola/pembelian/permintaan', KirimanPermintaanUji($k))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pr = PermintaanPembelian::query()->sole();
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/ajukan")->assertSessionHasNoErrors();
        expect($pr->refresh()->Status)->toBe(StatusPermintaanPembelian::Diajukan);

        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/setujui")->assertSessionHasErrors('Umum');
        MasukPermintaan($this, $k, $admin2)->post("/kelola/pembelian/permintaan/{$pr->Uuid}/setujui")->assertSessionHasNoErrors();
        expect($pr->refresh()->Status)->toBe(StatusPermintaanPembelian::Disetujui);
    });

    it('jadikan PO: pemasok wajib kecuali ada usulan. Usulan dipakai bila tidak dipilih. Hanya yang disetujui', function (): void {
        $k = SiapkanPermintaanUji();
        MasukPermintaan($this, $k)->post('/kelola/pembelian/permintaan', KirimanPermintaanUji($k))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pr = PermintaanPembelian::query()->sole();

        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/jadikan-po", ['UuidPemasok' => $k['Pemasok']->Uuid])->assertSessionHasErrors('Umum');
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/ajukan")->assertSessionHasNoErrors();
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/jadikan-po")->assertSessionHasErrors('UuidPemasok');
        expect(PesananPembelian::query()->count())->toBe(0);

        $pr2 = (function () use ($k): PermintaanPembelian {
            $this->post('/kelola/pembelian/permintaan', KirimanPermintaanUji($k, ['UuidPemasok' => $k['Pemasok']->Uuid]))->assertSessionHasNoErrors();
            BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

            return PermintaanPembelian::query()->orderByDesc('Id')->firstOrFail();
        })();
        $this->post("/kelola/pembelian/permintaan/{$pr2->Uuid}/ajukan")->assertSessionHasNoErrors();
        $this->post("/kelola/pembelian/permintaan/{$pr2->Uuid}/jadikan-po")->assertSessionHasNoErrors();
        expect(PesananPembelian::query()->sole()->IdPemasok)->toBe($k['Pemasok']->Id);
    });

    it('batalkan butuh alasan 5 karakter ke atas dan final. Draf yang sudah diajukan terkunci di model', function (): void {
        $k = SiapkanPermintaanUji();
        MasukPermintaan($this, $k)->post('/kelola/pembelian/permintaan', KirimanPermintaanUji($k))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pr = PermintaanPembelian::query()->sole();

        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/batalkan", ['Alasan' => 'abc'])->assertSessionHasErrors('Alasan');
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/ajukan")->assertSessionHasNoErrors();
        expect(fn () => $pr->refresh()->forceFill(['Catatan' => 'diubah diam-diam'])->save())->toThrow(LogicException::class);
        expect(fn () => PermintaanPembelianDetail::query()->firstOrFail()->delete())->toThrow(LogicException::class);
        $this->put("/kelola/pembelian/permintaan/{$pr->Uuid}", KirimanPermintaanUji($k))->assertSessionHasErrors('Umum');

        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/batalkan", ['Alasan' => 'Barang sudah dibeli langsung'])->assertSessionHasNoErrors();
        expect($pr->refresh()->Status)->toBe(StatusPermintaanPembelian::Dibatalkan)->and($pr->AlasanBatal)->toBe('Barang sudah dibeli langsung');
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/ajukan")->assertSessionHasErrors('Umum');
    });

    it('validasi: baris kosong, produk ganda, dan tanggal dibutuhkan sebelum tanggal permintaan ditolak', function (): void {
        $k = SiapkanPermintaanUji();
        MasukPermintaan($this, $k);

        $this->post('/kelola/pembelian/permintaan', KirimanPermintaanUji($k, ['Baris' => []]))->assertSessionHasErrors('Baris');
        $this->post('/kelola/pembelian/permintaan', KirimanPermintaanUji($k, ['Baris' => [
            ['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '1', 'Harga' => '0'],
            ['UuidProduk' => $k['Gula']->Uuid, 'Jumlah' => '2', 'Harga' => '0'],
        ]]))->assertSessionHasErrors();
        $this->post('/kelola/pembelian/permintaan', KirimanPermintaanUji($k, ['DibutuhkanPada' => BantuanPembelian::Hari(5)->toDateString()]))->assertSessionHasErrors('DibutuhkanPada');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(PermintaanPembelian::query()->count())->toBe(0);
    });
});

describe('akses permintaan pembelian', function (): void {
    it('halaman daftar, form, dan detail memuat props. Tindakan mengikuti status dan izin', function (): void {
        $k = SiapkanPermintaanUji();
        MasukPermintaan($this, $k)->get('/kelola/pembelian/permintaan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Permintaan/Daftar')->where('Izin.Kelola', true));
        $this->get('/kelola/pembelian/permintaan/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Permintaan/Form')->where('Mode', 'Buat')->where('Permintaan', null)->has('OpsiGudang'));

        $this->post('/kelola/pembelian/permintaan', KirimanPermintaanUji($k))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pr = PermintaanPembelian::query()->sole();

        $this->get("/kelola/pembelian/permintaan/{$pr->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Permintaan/Detail')
            ->where('Permintaan.Status', 'Draf')->has('Baris', 2)
            ->where('Tindakan.Ubah', true)->where('Tindakan.Ajukan', true)->where('Tindakan.JadikanPo', false)->where('Tindakan.Putuskan', false));
        $this->get("/kelola/pembelian/permintaan/{$pr->Uuid}/ubah")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/Permintaan/Form')->where('Mode', 'Ubah')->has('Permintaan.Baris', 2));
        $this->getJson('/kelola/pembelian/permintaan?cari=PP%2F')->assertOk()->assertJsonPath('Data.0.Nomor', $pr->Nomor)->assertJsonPath('Data.0.JumlahBaris', 2);
    });

    it('Kasir 403. Tenant lain tidak melihat permintaan ini (404)', function (): void {
        $k = SiapkanPermintaanUji();
        MasukPermintaan($this, $k)->post('/kelola/pembelian/permintaan', KirimanPermintaanUji($k))->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $pr = PermintaanPembelian::query()->sole();

        $kasir = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        MasukPermintaan($this, $k, $kasir)->get('/kelola/pembelian/permintaan')->assertForbidden();
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/ajukan")->assertForbidden();

        ['Tenant' => $lain, 'Pemilik' => $pemilikLain] = BantuanPembelian::SiapkanTenant(namaUsaha: 'Toko Lain');
        BantuanOrganisasi::Masuk($this, $pemilikLain, $lain->Id)->get("/kelola/pembelian/permintaan/{$pr->Uuid}")->assertNotFound();
        $this->post("/kelola/pembelian/permintaan/{$pr->Uuid}/ajukan")->assertNotFound();
    });
});
