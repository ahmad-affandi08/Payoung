<?php

declare(strict_types=1);

use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Persediaan\Aksi\AjukanPermintaanStok;
use App\Domain\Persediaan\Aksi\BatalkanPermintaanStok;
use App\Domain\Persediaan\Aksi\KirimTransferStok;
use App\Domain\Persediaan\Aksi\TerimaTransferStok;
use App\Domain\Persediaan\Aksi\TolakPermintaanStok;
use App\Domain\Persediaan\Aksi\TutupPermintaanStok;
use App\Domain\Persediaan\Data\DataBarisPermintaanStok;
use App\Domain\Persediaan\Data\DataPermintaanStok;
use App\Domain\Persediaan\Data\DataTerimaTransfer;
use App\Domain\Persediaan\Enum\StatusPermintaanStok;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Model\PermintaanStok;
use App\Domain\Persediaan\Model\TransferStok;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanFitur;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05b, D-105: permintaan stok. Outlet yang butuh barang (lokasi tujuan) mengajukan ke lokasi pengirim (asal); pengirim
 * menjawab dengan transfer biasa yang ditautkan ke permintaan. Permintaan bukan peristiwa stok/akuntansi: stok baru
 * bergerak saat transfer dikirim, dan permintaan terpenuhi menurut jumlah yang dikirim.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Gudang utama (outlet pertama) menyimpan stok; cabang meminta. */
function SiapkanPermintaan(): array
{
    $t = BantuanPersediaan::SiapkanTenant();
    BantuanFitur::Beri($t['Tenant']->Id, 'stok.transfer');
    $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
    $cabang = B::BuatOutlet();
    $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Toko Cabang Solo Baru', JenisGudang::Toko);
    BantuanStokAwal::BuatDanPosting($t['Gudang'], [
        BantuanStokAwal::Baris($p['Stok'], '100', '38500'),
        BantuanStokAwal::Baris($p['BahanBaku'], '50', '14750'),
    ], $t['Pemilik']->Id, CarbonImmutable::now('Asia/Jakarta')->subDays(3)->format('Y-m-d'));

    return [...$t, 'Produk' => $p, 'Cabang' => $cabang, 'GudangCabang' => $gudangCabang];
}

/**
 * @param  list<DataBarisPermintaanStok>  $baris
 */
function AjukanPermintaanUji(array $t, array $baris, ?string $uuid = null): PermintaanStok
{
    return app(AjukanPermintaanStok::class)->Jalankan(new DataPermintaanStok(
        $uuid ?? (string) Str::ulid(),
        $t['Gudang']->Id,
        $t['GudangCabang']->Id,
        CarbonImmutable::parse(B::Kemarin()),
        'Stok rak cabang menipis',
        $baris,
    ));
}

function BarisPermintaanUji(object $produk, string $jumlah): DataBarisPermintaanStok
{
    return new DataBarisPermintaanStok($produk->Id, Kuantitas::Dari($jumlah));
}

describe('D-105 mengajukan permintaan stok', function (): void {
    it('membuat permintaan Diajukan bernomor MS/{TUJUAN}-{ASAL}, tanpa mengubah stok atau jurnal', function (): void {
        $t = SiapkanPermintaan();
        $stokSebelum = B::Saldo($t['Produk']['Stok'], $t['Gudang']);
        $p = AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '30'), BarisPermintaanUji($t['Produk']['BahanBaku'], '10.5')]);

        expect($p->Status)->toBe(StatusPermintaanStok::Diajukan)
            ->and($p->Nomor)->toBe('MS/'.mb_substr($t['GudangCabang']->Kode, 0, 13).'-'.mb_substr($t['Gudang']->Kode, 0, 13).'/'.CarbonImmutable::parse(B::Kemarin())->format('ym').'/0001')
            ->and($p->JumlahBaris)->toBe(2)
            ->and($p->IdOutletTujuan)->toBe($t['Cabang']->Id)->and($p->IdOutletAsal)->toBe($t['Outlet']->Id)
            ->and($p->Detail()->orderBy('Urutan')->pluck('JumlahDiminta')->all())->toBe(['30.0000', '10.5000'])
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang']))->toBe($stokSebelum)
            ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'PermintaanStok')->where('IdDokumen', $p->Id)->pluck('StatusKe')->all())->toBe(['Diajukan'])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('idempoten per Uuid klien dan menolak data tidak valid', function (): void {
        $t = SiapkanPermintaan();
        $uuid = (string) Str::ulid();
        $a = AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '5')], $uuid);
        $b = AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '5')], $uuid);

        expect($b->Id)->toBe($a->Id)->and(PermintaanStok::query()->count())->toBe(1)
            ->and(B::KodeGalat(fn () => AjukanPermintaanUji($t, [])))->toBe('BarisKosong')
            ->and(B::KodeGalat(fn () => AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '5'), BarisPermintaanUji($t['Produk']['Stok'], '2')])))->toBe('BarisTidakValid')
            ->and(B::KodeGalat(fn () => AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '0')])))->toBe('JumlahTidakValid')
            ->and(B::KodeGalat(fn () => AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '2.5')])))->toBe('JumlahTidakValid')
            ->and(B::KodeGalat(fn () => app(AjukanPermintaanStok::class)->Jalankan(new DataPermintaanStok(null, $t['Gudang']->Id, $t['Gudang']->Id, CarbonImmutable::parse(B::Kemarin()), null, [BarisPermintaanUji($t['Produk']['Stok'], '1')]))))->toBe('LokasiSama')
            ->and(PermintaanStok::query()->count())->toBe(1);
    });
});

describe('D-105 transfer menjawab permintaan', function (): void {
    it('terpenuhi bertahap: kirim sebagian → DiprosesSebagian, kirim sisanya → Selesai', function (): void {
        $t = SiapkanPermintaan();
        $p = AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '30')]);

        $transfer1 = B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '20')], uuidPermintaanStok: $p->Uuid);
        expect($transfer1->IdPermintaanStok)->toBe($p->Id)
            // Draf belum terhitung terpenuhi.
            ->and($p->refresh()->Status)->toBe(StatusPermintaanStok::Diajukan);

        app(KirimTransferStok::class)->Jalankan($transfer1, $t['Pemilik']->Id);
        expect($p->refresh()->Status)->toBe(StatusPermintaanStok::DiprosesSebagian)
            ->and($p->Detail()->sole()->JumlahDikirim)->toBe('20.0000')
            ->and($p->Detail()->sole()->AmbilSisa()->KeString())->toBe('10.0000');

        $transfer2 = B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '10')], uuidPermintaanStok: $p->Uuid);
        app(KirimTransferStok::class)->Jalankan($transfer2, $t['Pemilik']->Id);

        expect($p->refresh()->Status)->toBe(StatusPermintaanStok::Selesai)
            ->and($p->Detail()->sole()->JumlahDikirim)->toBe('30.0000')
            ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'PermintaanStok')->where('IdDokumen', $p->Id)->pluck('StatusKe')->all())->toBe(['Diajukan', 'DiprosesSebagian', 'Selesai'])
            // Terima di cabang tetap alur transfer biasa; permintaan tidak ikut berubah.
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang'])[0])->toBe('70.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);

        app(TerimaTransferStok::class)->Jalankan($transfer1->refresh(), [new DataTerimaTransfer(1, Kuantitas::Dari('20'))], CarbonImmutable::now('Asia/Jakarta')->subHours(4)->startOfDay(), $t['Pemilik']->Id);
        expect($p->refresh()->Status)->toBe(StatusPermintaanStok::Selesai);
    });

    it('produk di luar permintaan boleh ikut dikirim tetapi tidak dihitung. Permintaan lain outlet ditolak', function (): void {
        $t = SiapkanPermintaan();
        $p = AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '30')]);
        $lain = B::BuatOutlet('KLATEN', 'Cabang Klaten');
        $gudangLain = BantuanPersediaan::BuatGudang($lain, 'Toko Klaten', JenisGudang::Toko);

        expect(B::KodeGalat(fn () => B::DrafTransfer($t['Gudang'], $gudangLain, [B::Baris($t['Produk']['Stok'], '5')], uuidPermintaanStok: $p->Uuid)))->toBe('PermintaanTidakCocok')
            ->and(B::KodeGalat(fn () => B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '5')], uuidPermintaanStok: (string) Str::ulid())))->toBe('PermintaanTidakDikenal');

        $transfer = B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '30'), B::Baris($t['Produk']['BahanBaku'], '4')], uuidPermintaanStok: $p->Uuid);
        app(KirimTransferStok::class)->Jalankan($transfer, $t['Pemilik']->Id);

        expect($p->refresh()->Status)->toBe(StatusPermintaanStok::Selesai)
            ->and($p->Detail()->count())->toBe(1);
    });
});

describe('D-105 mengakhiri permintaan', function (): void {
    it('peminta membatalkan, pengirim menolak, keduanya wajib beralasan. Hanya saat Diajukan', function (): void {
        $t = SiapkanPermintaan();
        $a = AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '5')]);
        $b = AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '6')]);

        expect(B::KodeGalat(fn () => app(BatalkanPermintaanStok::class)->Jalankan($a, 'ok', $t['Pemilik']->Id)))->toBe('AlasanWajib');

        $a = app(BatalkanPermintaanStok::class)->Jalankan($a, 'Salah pilih lokasi pengirim', $t['Pemilik']->Id);
        $b = app(TolakPermintaanStok::class)->Jalankan($b, 'Stok gudang sedang opname', $t['Pemilik']->Id);

        expect($a->Status)->toBe(StatusPermintaanStok::Dibatalkan)->and($a->Alasan)->toBe('Salah pilih lokasi pengirim')
            ->and($b->Status)->toBe(StatusPermintaanStok::Ditolak)
            ->and(B::KodeGalat(fn () => app(TolakPermintaanStok::class)->Jalankan($a, 'Sudah dibatalkan peminta', $t['Pemilik']->Id)))->toBe('StatusTidakSesuai')
            ->and(B::KodeGalat(fn () => B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '5')], uuidPermintaanStok: $b->Uuid)))->toBe('PermintaanSudahBerakhir');
    });

    it('menutup sisa hanya setelah sebagian terkirim. Permintaan yang ditutup tidak menerima pengiriman draf lama', function (): void {
        $t = SiapkanPermintaan();
        $p = AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '30')]);
        $drafLama = B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '5')], uuidPermintaanStok: $p->Uuid);
        $draf = B::DrafTransfer($t['Gudang'], $t['GudangCabang'], [B::Baris($t['Produk']['Stok'], '12')], uuidPermintaanStok: $p->Uuid);

        expect(B::KodeGalat(fn () => app(TutupPermintaanStok::class)->Jalankan($p, 'Belum ada pengiriman', $t['Pemilik']->Id)))->toBe('StatusTidakSesuai');

        app(KirimTransferStok::class)->Jalankan($draf, $t['Pemilik']->Id);
        $p = app(TutupPermintaanStok::class)->Jalankan($p->refresh(), 'Sisa dibatalkan, stok gudang habis', $t['Pemilik']->Id);

        expect($p->Status)->toBe(StatusPermintaanStok::Ditutup)
            ->and(B::KodeGalat(fn () => app(KirimTransferStok::class)->Jalankan($drafLama, $t['Pemilik']->Id)))->toBe('PermintaanSudahBerakhir')
            ->and($drafLama->refresh()->Status->value)->toBe('Draf')
            ->and(B::Saldo($t['Produk']['Stok'], $t['Gudang'])[0])->toBe('88.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});

/** Anggota ber-peran yang hanya boleh mengakses outlet `idOutlet`; `Masuk()` dipanggil tiap kali bertukar pelaku. */
function BuatAnggotaOutletPermintaan(int $idTenant, int $idOutlet, PeranTenantBawaan $peran = PeranTenantBawaan::ManajerOutlet): object
{
    $pengguna = BantuanOrganisasi::TambahAnggota($idTenant, $peran, semuaOutlet: false);
    OutletPengguna::query()->create(['IdOutlet' => $idOutlet, 'IdPengguna' => $pengguna->Id, 'IdPeran' => BantuanOrganisasi::Peran($idTenant, $peran)->Id]);
    BantuanOrganisasi::AturKonteks($idTenant);

    return $pengguna;
}

describe('D-105 HTTP permintaan stok', function (): void {
    it('alur lewat form: cabang mengajukan, gudang melihat "perlu dikirim", memenuhi lewat form transfer, lalu selesai', function (): void {
        $t = SiapkanPermintaan();
        $uuid = (string) Str::ulid();

        $penggunaCabang = BuatAnggotaOutletPermintaan($t['Tenant']->Id, $t['Cabang']->Id);
        $penggunaGudang = BuatAnggotaOutletPermintaan($t['Tenant']->Id, $t['Outlet']->Id);
        $sebagai = fn (object $pengguna): object => BantuanOrganisasi::Masuk($this, $pengguna, $t['Tenant']->Id);

        // Admin cabang mengajukan dari lokasi cabangnya ke gudang utama.
        $cabang = $sebagai($penggunaCabang);
        $cabang->get('/kelola/persediaan/permintaan/buat')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Permintaan/Form')
            ->where('UuidGudangTujuanAwal', $t['GudangCabang']->Uuid)
            ->has('OpsiGudangAsal', fn ($o) => $o->etc()));
        $cabang->post('/kelola/persediaan/permintaan', [
            'Uuid' => $uuid, 'UuidGudangAsal' => $t['Gudang']->Uuid, 'UuidGudangTujuan' => $t['GudangCabang']->Uuid, 'Tanggal' => B::Kemarin(), 'Catatan' => 'Rak depan kosong',
            'Baris' => [['UuidProduk' => $t['Produk']['Stok']->Uuid, 'Jumlah' => '30']],
        ])->assertSessionHasNoErrors()->assertRedirect("/kelola/persediaan/permintaan/{$uuid}");

        // Cabang tidak boleh meminta atas nama lokasi di outlet lain.
        $cabang->post('/kelola/persediaan/permintaan', [
            'UuidGudangAsal' => $t['GudangCabang']->Uuid, 'UuidGudangTujuan' => $t['Gudang']->Uuid, 'Tanggal' => B::Kemarin(),
            'Baris' => [['UuidProduk' => $t['Produk']['Stok']->Uuid, 'Jumlah' => '1']],
        ])->assertNotFound();
        $cabang->post("/kelola/persediaan/permintaan/{$uuid}/tolak", ['Alasan' => 'Peminta tidak boleh menolak'])->assertForbidden();

        // Gudang utama melihatnya sebagai permintaan masuk yang perlu dikirim.
        $gudang = $sebagai($penggunaGudang);
        $baris = $gudang->getJson('/kelola/persediaan/permintaan?saring[Arah]=Masuk')->assertOk()->json('Data');
        expect($baris)->toHaveCount(1)->and($baris[0]['PerluDikirim'])->toBeTrue()->and($baris[0]['LabelStatus'])->toBe('Diajukan');
        $gudang->get("/kelola/persediaan/permintaan/{$uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Permintaan/Detail')
            ->where('Tindakan.Penuhi', true)->where('Tindakan.Tolak', true)->where('Tindakan.Batalkan', false)
            ->where('Baris.0.JumlahSisa', '30.0000'));

        // "Penuhi" membuka form transfer yang sudah terisi dari permintaan.
        $gudang->get("/kelola/persediaan/transfer/buat?permintaan={$uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/Transfer/Form')
            ->where('Awal.UuidPermintaanStok', $uuid)
            ->where('Awal.UuidGudangAsal', $t['Gudang']->Uuid)
            ->where('Awal.UuidGudangTujuan', $t['GudangCabang']->Uuid)
            ->where('Awal.Baris.0.Jumlah', '30'));
        // Pihak peminta tidak bisa memenuhi permintaannya sendiri.
        $sebagai($penggunaCabang)->get("/kelola/persediaan/transfer/buat?permintaan={$uuid}")->assertForbidden();
        $gudang = $sebagai($penggunaGudang);

        $uuidTransfer = (string) Str::ulid();
        $gudang->post('/kelola/persediaan/transfer', [
            'Uuid' => $uuidTransfer, 'UuidPermintaanStok' => $uuid, 'UuidGudangAsal' => $t['Gudang']->Uuid, 'UuidGudangTujuan' => $t['GudangCabang']->Uuid, 'Tanggal' => B::Kemarin(),
            'Baris' => [['UuidProduk' => $t['Produk']['Stok']->Uuid, 'Jumlah' => '30']], 'Lanjutkan' => true,
        ])->assertSessionHasNoErrors();

        $gudang->get("/kelola/persediaan/permintaan/{$uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Permintaan.Status', 'Selesai')
            ->where('Transfer.0.Uuid', $uuidTransfer)
            ->where('Tindakan.Penuhi', false));
        expect(TransferStok::query()->where('Uuid', $uuidTransfer)->value('Status'))->toBe(StatusTransferStok::Dikirim)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('permintaan tidak terlihat oleh outlet lain (404) dan butuh izin persediaan', function (): void {
        $t = SiapkanPermintaan();
        $p = AjukanPermintaanUji($t, [BarisPermintaanUji($t['Produk']['Stok'], '5')]);
        $lain = B::BuatOutlet('KLATEN', 'Cabang Klaten');
        $orangLain = BantuanOrganisasi::Masuk($this, BuatAnggotaOutletPermintaan($t['Tenant']->Id, $lain->Id), $t['Tenant']->Id);

        $orangLain->get("/kelola/persediaan/permintaan/{$p->Uuid}")->assertNotFound();
        expect($orangLain->getJson('/kelola/persediaan/permintaan')->assertOk()->json('Data'))->toBe([]);

        $kasir = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::Masuk($this, $kasir, $t['Tenant']->Id)->get('/kelola/persediaan/permintaan')->assertForbidden();
    });
});
