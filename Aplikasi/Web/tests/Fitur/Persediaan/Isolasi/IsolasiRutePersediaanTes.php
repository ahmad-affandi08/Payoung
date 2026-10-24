<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Model\Gudang;
use App\Domain\Organisasi\Model\Merek;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\OutletPengguna;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\StatusImporStokAwal;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\ImporStokAwal;
use App\Domain\Persediaan\Model\StokAwal;
use App\Domain\Persediaan\Model\StokAwalDetail;
use App\Domain\Tenant\Model\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
    Storage::fake('local');
});

/**
 * Dokumen persediaan & jurnal ditulis langsung (tanpa Aksi tim lain) di lokasi stok `gudang` tenant konteks:
 * stok awal Draf dan Diposting (berbaris), impor stok awal di Pratinjau, dan satu jurnal seimbang.
 *
 * @return array{Draf: StokAwal, Diposting: StokAwal, Impor: ImporStokAwal, Jurnal: Jurnal}
 */
function BuatDokumenIsolasiLangsung(Gudang $gudang, Produk $produk, int $idPengguna): array
{
    $buatStokAwal = function (StatusStokAwal $status, ?string $nomor) use ($gudang, $produk): StokAwal {
        $dokumen = StokAwal::query()->create([
            'Nomor' => $nomor, 'IdGudang' => $gudang->Id, 'IdOutlet' => $gudang->IdOutlet, 'Tanggal' => '2026-09-01', 'Status' => $status,
            'Catatan' => 'Stok awal rahasia '.$gudang->Nama, 'JumlahBaris' => 1, 'TotalNilai' => '385000.00',
        ]);
        StokAwalDetail::query()->create([
            'IdStokAwal' => $dokumen->Id, 'Urutan' => 1, 'IdProduk' => $produk->Id, 'NamaProduk' => $produk->Nama, 'Jumlah' => '10.0000',
            'HppSatuan' => '38500.000000', 'Nilai' => '385000.00',
        ]);

        return $dokumen->refresh();
    };

    $akun = PemetaanAkun::query()->whereIn('Kunci', ['PersediaanBarangDagang', 'EkuitasSaldoAwal'])->pluck('IdAkun', 'Kunci');
    $jurnal = Jurnal::query()->create([
        'Nomor' => 'JU/2026/09/000001', 'Tanggal' => '2026-09-01', 'Periode' => '2026-09', 'JenisSumber' => JenisSumberJurnal::StokAwal,
        'IdSumber' => 1, 'Keterangan' => 'Stok awal rahasia '.$gudang->Nama, 'TotalDebit' => '385000.00', 'TotalKredit' => '385000.00',
    ]);
    JurnalDetail::query()->create(['IdJurnal' => $jurnal->Id, 'Urutan' => 1, 'IdAkun' => $akun['PersediaanBarangDagang'], 'IdOutlet' => $gudang->IdOutlet, 'Debit' => '385000.00', 'Kredit' => '0.00', 'Tanggal' => '2026-09-01']);
    JurnalDetail::query()->create(['IdJurnal' => $jurnal->Id, 'Urutan' => 2, 'IdAkun' => $akun['EkuitasSaldoAwal'], 'IdOutlet' => $gudang->IdOutlet, 'Debit' => '0.00', 'Kredit' => '385000.00', 'Tanggal' => '2026-09-01']);

    $impor = ImporStokAwal::query()->create([
        'IdPengguna' => $idPengguna, 'IdGudangBawaan' => $gudang->Id, 'Tanggal' => '2026-09-01', 'NamaBerkas' => 'stok-rahasia.csv',
        'PathBerkas' => 'impor-stok-awal/stok-rahasia.csv', 'HashBerkas' => str_repeat('a', 64), 'UkuranBerkas' => 120, 'Format' => 'csv',
        'Status' => StatusImporStokAwal::Pratinjau, 'KolomSumber' => [], 'Pemetaan' => ['Sku' => 0, 'Jumlah' => 1, 'HargaModal' => 2],
    ]);

    return [
        'Draf' => $buatStokAwal(StatusStokAwal::Draf, null),
        'Diposting' => $buatStokAwal(StatusStokAwal::Diposting, 'SA/2026/09/0001'),
        'Impor' => $impor->refresh(),
        'Jurnal' => $jurnal->refresh(),
    ];
}

/**
 * Semua rute F-05a yang membawa ULID dokumen, dengan badan permintaan yang valid supaya validasi form lolos dan
 * yang diuji benar-benar pencarian dokumen (MilikTenant + outlet).
 *
 * @return list<array{string, string, array<string, mixed>}>
 */
function AmbilRuteBerdokumenIsolasi(StokAwal $draf, StokAwal $diposting, ?ImporStokAwal $impor, ?Jurnal $jurnal, Gudang $gudangSendiri, Produk $produkSendiri): array
{
    $badanStokAwal = fn (StokAwal $d): array => [
        'UuidGudang' => $gudangSendiri->Uuid, 'Tanggal' => '2026-09-01', 'Catatan' => 'Coba ubah dokumen orang lain',
        'VersiDiubahPada' => $d->DiubahPada?->toISOString(),
        'Baris' => [['UuidProduk' => $produkSendiri->Uuid, 'Jumlah' => '1', 'HppSatuan' => '1000', 'NomorBatch' => null, 'TanggalKedaluwarsa' => null, 'NomorSeri' => []]],
    ];
    $sa = '/kelola/persediaan/stok-awal';
    $rute = [
        ['GET', "{$sa}/{$draf->Uuid}", []],
        ['GET', "{$sa}/{$draf->Uuid}/ubah", []],
        ['PUT', "{$sa}/{$draf->Uuid}", $badanStokAwal($draf)],
        ['POST', "{$sa}/{$draf->Uuid}/buang", []],
        ['POST', "{$sa}/{$draf->Uuid}/posting", []],
        ['GET', "{$sa}/{$draf->Uuid}/status", []],
        ['GET', "{$sa}/{$diposting->Uuid}", []],
        ['POST', "{$sa}/{$diposting->Uuid}/batalkan", ['Alasan' => 'Coba batalkan dokumen orang lain']],
        ['GET', "{$sa}/{$diposting->Uuid}/status", []],
    ];

    if ($impor !== null) {
        $i = "{$sa}/impor/{$impor->Uuid}";
        array_push($rute,
            ['GET', $i, []],
            ['GET', "{$i}/status", []],
            ['PUT', "{$i}/pemetaan", ['Pemetaan' => ['Sku' => 0, 'Jumlah' => 1, 'HargaModal' => 2], 'UuidGudangBawaan' => $gudangSendiri->Uuid, 'Tanggal' => '2026-09-01']],
            ['POST', "{$i}/terapkan", []],
            ['POST', "{$i}/lanjutkan", []],
            ['POST', "{$i}/batalkan", []],
            ['GET', "{$i}/laporan", []],
        );
    }

    if ($jurnal !== null) {
        $rute[] = ['GET', "/kelola/akuntansi/jurnal/{$jurnal->Uuid}", []];
    }

    return $rute;
}

/** Keadaan dokumen yang tidak boleh berubah oleh permintaan dari luar tenant/outlet. */
function AmbilJejakDokumenIsolasi(int $idTenant): array
{
    return [
        DB::table('StokAwal')->where('IdTenant', $idTenant)->orderBy('Id')->get(['Id', 'Status', 'Nomor', 'IdJurnal', 'DiubahPada'])->map(fn ($b) => (array) $b)->all(),
        DB::table('StokAwalDetail')->where('IdTenant', $idTenant)->count(),
        DB::table('ImporStokAwal')->where('IdTenant', $idTenant)->orderBy('Id')->get(['Id', 'Status', 'DiubahPada'])->map(fn ($b) => (array) $b)->all(),
        DB::table('MutasiStok')->where('IdTenant', $idTenant)->count(),
        DB::table('Jurnal')->where('IdTenant', $idTenant)->count(),
    ];
}

/**
 * Respons daftar/saringan: boleh 404 (UUID asing ditolak) atau berhasil tanpa membocorkan data rahasia. Alamat
 * permintaan yang digemakan Inertia (`"url"`) dibuang dulu: UUID yang dikirim penguji sendiri bukan kebocoran.
 *
 * @param  TestResponse<Response>  $respons
 */
function PastikanTanpaBocor(TestResponse $respons, string ...$rahasia): void
{
    $status = $respons->getStatusCode();
    expect($status)->toBeIn([200, 302, 404, 422], "status {$status}: ".($respons->exception?->getMessage() ?? ''));

    $isi = (string) preg_replace('/"url":"[^"]*"/', '', (string) $respons->getContent());

    foreach ($rahasia as $teks) {
        expect(str_contains($isi, $teks) || str_contains($isi, str_replace('/', '\\/', $teks)))->toBeFalse("bocor: {$teks}");
    }
}

/**
 * Permintaan tulis dengan UUID asing ditolak: 404, atau galat validasi (redirect ber-`errors` / 422).
 *
 * @param  TestResponse<Response>  $respons
 */
function PastikanDitolakIsolasi(TestResponse $respons): void
{
    $status = $respons->getStatusCode();
    expect($status)->toBeIn([302, 404, 422], "status {$status}: ".($respons->exception?->getMessage() ?? ''));

    if ($status === 302) {
        $respons->assertSessionHasErrors();
    }
}

/**
 * GET `alamat` lalu `PastikanTanpaBocor`. Rahasia yang dikirim penguji sendiri di kueri (misal `?gudang=` asing yang
 * digemakan kembali di prop `Saring`) tidak dihitung bocor; nama dan dokumennya tetap diperiksa.
 */
function PastikanTanpaBocorDi(TestCase $klien, string $alamat, bool $json, string ...$rahasia): void
{
    $respons = $json ? $klien->getJson($alamat) : $klien->get($alamat);
    $kueri = urldecode($alamat);

    PastikanTanpaBocor($respons, ...array_values(array_filter($rahasia, fn (string $r): bool => ! str_contains($kueri, $r))));
}

describe('F-05a isolasi tenant: setiap rute persediaan & jurnal × dokumen tenant B (aturan #11)', function (): void {
    it('dokumen, impor, dan jurnal tenant B = 404 di semua rute berdokumen; tidak ada yang berubah', function (): void {
        $b = BantuanPersediaan::SiapkanTenant('Warung Bu Tini Jaya');
        $produkB = BantuanPersediaan::BuatProdukSemuaJenis($b['Pcs'], $b['Kg'])['Stok'];
        $dokB = BuatDokumenIsolasiLangsung($b['Gudang'], $produkB, $b['Pemilik']->Id);
        $jejakB = AmbilJejakDokumenIsolasi($b['Tenant']->Id);

        $a = BantuanPersediaan::SiapkanTenant('Toko Kelontong Makmur Sentosa');
        $produkA = BantuanPersediaan::BuatProdukSemuaJenis($a['Pcs'], $a['Kg'])['Stok'];
        $pemilikA = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $pemilikA, $a['Tenant']->Id);

        foreach (AmbilRuteBerdokumenIsolasi($dokB['Draf'], $dokB['Diposting'], $dokB['Impor'], $dokB['Jurnal'], $a['Gudang'], $produkA) as [$metode, $alamat, $badan]) {
            $masuk()->call($metode, $alamat, $badan)->assertNotFound();
        }

        expect(AmbilJejakDokumenIsolasi($b['Tenant']->Id))->toBe($jejakB)
            ->and(AmbilJejakDokumenIsolasi($a['Tenant']->Id)[0])->toBe([]);
    });

    it('UUID lokasi stok, produk, dan filter tenant B tidak bisa dipakai dan tidak membocorkan data', function (): void {
        $b = BantuanPersediaan::SiapkanTenant('Warung Bu Tini Jaya');
        $rahasiaB = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Luwak Premium Rahasia Tenant B 250 gram'], '350000.00', $b['Pcs']);
        BantuanPersediaan::TulisMutasiLangsung($rahasiaB->Id, $b['Gudang']->Id, '15', '4500000.00');
        $dokB = BuatDokumenIsolasiLangsung($b['Gudang'], $rahasiaB, $b['Pemilik']->Id);
        $pengaturanB = Tenant::query()->findOrFail($b['Tenant']->Id)->Pengaturan;

        $a = BantuanPersediaan::SiapkanTenant('Toko Kelontong Makmur Sentosa');
        $produkA = BantuanPersediaan::BuatProdukSemuaJenis($a['Pcs'], $a['Kg'])['Stok'];
        $pemilikA = BantuanOrganisasi::TambahAnggota($a['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $pemilikA, $a['Tenant']->Id);
        $baris = fn (Produk $p): array => [['UuidProduk' => $p->Uuid, 'Jumlah' => '5', 'HppSatuan' => '38500', 'NomorBatch' => null, 'TanggalKedaluwarsa' => null, 'NomorSeri' => []]];
        $sa = '/kelola/persediaan/stok-awal';

        // Simpan draf di lokasi stok tenant B = 404; produk tenant B di lokasi sendiri = ditolak.
        $masuk()->post($sa, ['Uuid' => (string) Str::ulid(), 'UuidGudang' => $b['Gudang']->Uuid, 'Tanggal' => '2026-09-01', 'Catatan' => null, 'Baris' => $baris($produkA)])->assertNotFound();
        PastikanDitolakIsolasi($masuk()->post($sa, ['Uuid' => (string) Str::ulid(), 'UuidGudang' => $a['Gudang']->Uuid, 'Tanggal' => '2026-09-01', 'Catatan' => null, 'Baris' => $baris($rahasiaB)]));

        // Unggah impor dengan lokasi bawaan tenant B.
        $csv = UploadedFile::fake()->createWithContent('stok-awal.csv', "SKU,Stok,Harga Modal\nMGR-2L,10,38500\n");
        PastikanDitolakIsolasi($masuk()->post("{$sa}/impor", ['Berkas' => $csv, 'UuidGudangBawaan' => $b['Gudang']->Uuid]));

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(StokAwal::query()->count())->toBe(0)
            ->and(ImporStokAwal::query()->count())->toBe(0);

        // Daftar, saringan, pencarian, templat, dan kartu stok tidak menampilkan data tenant B.
        $rahasia = [$rahasiaB->Uuid, $rahasiaB->Nama, $b['Gudang']->Uuid, $dokB['Draf']->Uuid, $dokB['Diposting']->Uuid, $dokB['Impor']->Uuid, $dokB['Jurnal']->Uuid];
        PastikanTanpaBocorDi($masuk(), $sa, false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "{$sa}?gudang={$b['Gudang']->Uuid}", false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "{$sa}/impor", false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "{$sa}/impor/templat?format=csv&isi=produk&gudang={$b['Gudang']->Uuid}", false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), '/kelola/persediaan/produk/cari?kata=Kopi+Luwak&batas=20', true, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "/kelola/persediaan/produk/cari?kata=Kopi&gudang={$b['Gudang']->Uuid}", true, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), '/kelola/persediaan/saldo?keadaan=Semua', false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "/kelola/persediaan/saldo?gudang={$b['Gudang']->Uuid}", false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "/kelola/persediaan/kartu-stok?produk={$rahasiaB->Uuid}&gudang={$b['Gudang']->Uuid}", false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "/kelola/persediaan/kartu-stok?produk={$produkA->Uuid}&gudang={$b['Gudang']->Uuid}", false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), '/kelola/akuntansi/jurnal', false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), '/kelola/akuntansi/jurnal?cari=JU%2F2026', false, ...$rahasia);

        // Pengaturan persediaan hanya milik tenant aktif.
        $masuk()->put('/kelola/persediaan/pengaturan', ['MetodeHpp' => MetodeHpp::Fifo->value, 'StokBolehMinus' => true])->assertSessionHasNoErrors();
        expect(Tenant::query()->findOrFail($b['Tenant']->Id)->Pengaturan)->toBe($pengaturanB)
            ->and(Tenant::query()->findOrFail($a['Tenant']->Id)->Pengaturan['MetodeHpp'] ?? null)->toBe(MetodeHpp::Fifo->value);
    });
});

describe('F-05a isolasi outlet: pengguna per outlet × lokasi stok outlet lain (DesainF05a D)', function (): void {
    it('dokumen di lokasi stok outlet lain = 404 di semua rute berdokumen; simpan & filter lokasi outlet lain ditolak', function (): void {
        $t = BantuanPersediaan::SiapkanTenant('Toko Bangunan Sinar Abadi');
        $produk = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg'])['Stok'];
        $cabang = Outlet::query()->create(['IdMerek' => Merek::query()->value('Id'), 'Kode' => 'SOLO', 'Nama' => 'Cabang Solo Baru']);
        $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Gudang Cabang Solo Baru');
        $dokCabang = BuatDokumenIsolasiLangsung($gudangCabang, $produk, $t['Pemilik']->Id);
        $jejak = AmbilJejakDokumenIsolasi($t['Tenant']->Id);

        $manajer = BantuanOrganisasi::TambahAnggota($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet, semuaOutlet: false);
        OutletPengguna::query()->create(['IdOutlet' => $t['Outlet']->Id, 'IdPengguna' => $manajer->Id, 'IdPeran' => BantuanOrganisasi::Peran($t['Tenant']->Id, PeranTenantBawaan::ManajerOutlet)->Id]);
        $masuk = fn () => BantuanOrganisasi::Masuk($this, $manajer, $t['Tenant']->Id);

        // Stok awal di lokasi outlet lain, dan impor milik pengguna lain (pengguna per outlet hanya melihat impornya
        // sendiri, Tim E) = 404. Jurnal tidak terikat outlet (DesainF05a D).
        foreach (AmbilRuteBerdokumenIsolasi($dokCabang['Draf'], $dokCabang['Diposting'], $dokCabang['Impor'], null, $t['Gudang'], $produk) as [$metode, $alamat, $badan]) {
            $masuk()->call($metode, $alamat, $badan)->assertNotFound();
        }

        $sa = '/kelola/persediaan/stok-awal';
        $baris = [['UuidProduk' => $produk->Uuid, 'Jumlah' => '5', 'HppSatuan' => '38500', 'NomorBatch' => null, 'TanggalKedaluwarsa' => null, 'NomorSeri' => []]];
        $masuk()->post($sa, ['Uuid' => (string) Str::ulid(), 'UuidGudang' => $gudangCabang->Uuid, 'Tanggal' => '2026-09-01', 'Catatan' => null, 'Baris' => $baris])->assertNotFound();

        expect(AmbilJejakDokumenIsolasi($t['Tenant']->Id))->toBe($jejak);

        $rahasia = [$gudangCabang->Uuid, $dokCabang['Draf']->Uuid, $dokCabang['Diposting']->Uuid, 'Gudang Cabang Solo Baru'];
        PastikanTanpaBocorDi($masuk(), "{$sa}/impor", false, $dokCabang['Impor']->Uuid, 'stok-rahasia.csv');
        PastikanTanpaBocorDi($masuk(), $sa, false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "{$sa}/buat", false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "{$sa}?gudang={$gudangCabang->Uuid}", false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "/kelola/persediaan/saldo?gudang={$gudangCabang->Uuid}", false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), '/kelola/persediaan/saldo', false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "/kelola/persediaan/kartu-stok?produk={$produk->Uuid}&gudang={$gudangCabang->Uuid}", false, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "/kelola/persediaan/produk/cari?kata=Minyak&gudang={$gudangCabang->Uuid}", true, ...$rahasia);
        PastikanTanpaBocorDi($masuk(), "{$sa}/impor/templat?format=csv&isi=produk&gudang={$gudangCabang->Uuid}", false, ...$rahasia);
    });
});
