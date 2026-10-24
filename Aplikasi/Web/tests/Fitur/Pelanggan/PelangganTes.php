<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Layanan\NomorHp;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PelangganAlias;
use App\Domain\Penjualan\Model\Penjualan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-16a CRM-01 pelanggan (PRD "Rincian F-16a"): nomor HP ternormalisasi & unik per tenant, tambah/ubah/arsip dengan
 * audit tersamar, daftar TabelData (cari nama/HP, saring tag), detail + riwayat belanja, izin & isolasi tenant, API POS
 * cari (tersamar), outbox `Pelanggan.Buat` idempoten + alias nomor HP ganda, dan `Penjualan.Buat` + `UuidPelanggan`.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @param  array<string, mixed>  $timpa
 * @return array<string, mixed>
 */
function IsianPelanggan(array $timpa = []): array
{
    return [
        'Nama' => 'Ani Rahmawati Kusumaningtyas',
        'NoHp' => '0812-3456-7890',
        'Email' => 'ani@contoh.id',
        'TanggalLahir' => '1990-05-17',
        'Alamat' => 'Jl. Slamet Riyadi No. 10, Solo',
        'Tag' => ['Langganan', 'langganan', ' Reseller '],
        'Catatan' => null,
        'SetujuPemasaran' => true,
        ...$timpa,
    ];
}

/**
 * @param  array<string, mixed>  $k
 * @param  array<string, mixed>  $timpa
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemPelanggan(array $k, array $timpa = [], ?string $uuid = null): array
{
    return [
        'Jenis' => 'Pelanggan.Buat',
        'Uuid' => $uuid ?? BantuanKasir::Uuid(),
        'Data' => [
            'Nama' => 'Budi Santoso',
            'NoHp' => '0813 1111 2222',
            'Email' => null,
            'UuidPengguna' => $k['Kasir']->Uuid,
            'DibuatPada' => now()->subMinutes(10)->utc()->toIso8601ZuluString(),
            ...$timpa,
        ],
    ];
}

describe('nomor HP', function (): void {
    it('dinormalisasi ke awalan 62, diformat, dan disamarkan', function (): void {
        expect(NomorHp::Normalisasi('0812-3456-7890'))->toBe('6281234567890')
            ->and(NomorHp::Normalisasi('+62 812 3456 7890'))->toBe('6281234567890')
            ->and(NomorHp::Normalisasi('812.3456.7890'))->toBe('6281234567890')
            ->and(NomorHp::Normalisasi('0812'))->toBeNull()
            ->and(NomorHp::Normalisasi('0812abc4567'))->toBeNull()
            ->and(NomorHp::Format('6281234567890'))->toBe('0812-3456-7890')
            ->and(NomorHp::Samarkan('6281234567890'))->toBe('0812****7890');
    });
});

describe('F-16a back-office pelanggan', function (): void {
    it('tambah, nomor HP unik per tenant, ubah, daftar & cari, filter tag, arsip & pulihkan, audit tersamar', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Toko Roti Manis Sejahtera');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $simpan = $this->post('/kelola/pelanggan', IsianPelanggan())->assertSessionHasNoErrors();
        $ani = Pelanggan::query()->sole();
        // Tambah dari halaman penuh /buat: kembali ke detail pelanggan baru, bukan ke halaman buat.
        $simpan->assertRedirect("/kelola/pelanggan/{$ani->Uuid}");
        expect($ani->NoHp)->toBe('6281234567890')
            ->and($ani->Tag)->toBe(['Langganan', 'Reseller'])
            ->and($ani->TanggalLahir?->toDateString())->toBe('1990-05-17')
            ->and($ani->Status)->toBe(StatusPelanggan::Aktif);

        $this->post('/kelola/pelanggan', IsianPelanggan(['Nama' => 'Ani Lain', 'NoHp' => '+62 812 3456 7890']))->assertSessionHasErrors('NoHp');
        $this->post('/kelola/pelanggan', IsianPelanggan(['NoHp' => '0812']))->assertSessionHasErrors('NoHp');
        $this->post('/kelola/pelanggan', IsianPelanggan(['Nama' => 'Budi Santoso', 'NoHp' => '081311112222', 'Tag' => []]))->assertSessionHasNoErrors();
        $budi = Pelanggan::query()->where('Nama', 'Budi Santoso')->sole();

        $this->put("/kelola/pelanggan/{$budi->Uuid}", IsianPelanggan(['Nama' => 'Budi Santoso', 'NoHp' => '0812-3456-7890']))->assertSessionHasErrors('NoHp');
        $this->put("/kelola/pelanggan/{$budi->Uuid}", IsianPelanggan(['Nama' => 'Budi Santoso Wibowo', 'NoHp' => '081311112222', 'Tag' => ['Grosir']]))->assertSessionHasNoErrors();

        $this->getJson('/kelola/pelanggan?cari=wibowo')->assertOk()->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.NoHp', '0813-1111-2222');
        $this->getJson('/kelola/pelanggan?cari=0812-3456')->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.Nama', 'Ani Rahmawati Kusumaningtyas');
        $this->getJson('/kelola/pelanggan?saring[Tag]=Grosir')->assertJsonPath('Meta.Total', 1)->assertJsonPath('Data.0.Tag', ['Grosir']);
        $this->get('/kelola/pelanggan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pelanggan/Daftar')
            ->where('OpsiTag', ['Grosir', 'Langganan', 'Reseller'])
            ->where('Izin.Kelola', true)
            ->where('Pelanggan.Data.0.JumlahTransaksi', 0));

        $this->post("/kelola/pelanggan/{$ani->Uuid}/arsipkan")->assertSessionHasNoErrors();
        $this->post("/kelola/pelanggan/{$ani->Uuid}/arsipkan")->assertSessionHasErrors('Umum');
        $this->getJson('/kelola/pelanggan?saring[Status]=Aktif')->assertJsonPath('Meta.Total', 1);
        $this->post("/kelola/pelanggan/{$ani->Uuid}/pulihkan")->assertSessionHasNoErrors();

        $tambah = LogAudit::query()->where('Peristiwa', 'pelanggan.tambah')->orderBy('Id')->firstOrFail();
        expect($tambah->NilaiBaru['NoHp'] ?? null)->toBe('0812****7890');
        $ubah = LogAudit::query()->where('Peristiwa', 'pelanggan.ubah')->sole();
        expect($ubah->NilaiLama)->toEqual(['Nama' => 'Budi Santoso', 'Tag' => null])
            ->and($ubah->NilaiBaru)->toEqual(['Nama' => 'Budi Santoso Wibowo', 'Tag' => ['Grosir']]);
        expect(LogAudit::query()->whereIn('Peristiwa', ['pelanggan.arsipkan', 'pelanggan.pulihkan'])->count())->toBe(2);
    });

    it('halaman buat (halaman penuh): pemilik 200; supervisor & kasir 403', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Toko Roti Manis Sejahtera');

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $this->get('/kelola/pelanggan/buat')->assertOk()
            ->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pelanggan/Buat'));

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get('/kelola/pelanggan/buat')->assertForbidden();

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/pelanggan/buat')->assertForbidden();
    });

    it('izin: kasir tanpa akses, supervisor hanya melihat; tenant lain 404', function (): void {
        $a = BantuanKatalog::SiapkanTenantProduk('Toko A');
        $pelanggan = Pelanggan::query()->create(['Nama' => 'Ani', 'NoHp' => '6281234567890']);

        BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/pelanggan')->assertForbidden();

        BantuanKatalog::MasukSebagai($this, $a['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get('/kelola/pelanggan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Izin.Kelola', false));
        $this->get("/kelola/pelanggan/{$pelanggan->Uuid}")->assertOk();
        $this->post('/kelola/pelanggan', IsianPelanggan())->assertForbidden();
        $this->post("/kelola/pelanggan/{$pelanggan->Uuid}/arsipkan")->assertForbidden();

        $b = BantuanKatalog::SiapkanTenantProduk('Toko B');
        BantuanKatalog::MasukSebagai($this, $b['Tenant']->Id);
        $this->getJson('/kelola/pelanggan')->assertJsonPath('Meta.Total', 0);
        $this->get("/kelola/pelanggan/{$pelanggan->Uuid}")->assertNotFound();
        $this->put("/kelola/pelanggan/{$pelanggan->Uuid}", IsianPelanggan())->assertNotFound();
        // Nomor HP yang sama boleh dipakai tenant lain.
        $this->post('/kelola/pelanggan', IsianPelanggan(['NoHp' => '081234567890']))->assertSessionHasNoErrors();

        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect($pelanggan->refresh()->Nama)->toBe('Ani');
    });
});

describe('F-16a pelanggan di POS', function (): void {
    it('Pelanggan.Buat idempoten; nomor HP ganda jadi alias; penjualan tertaut; riwayat & ringkasan di back-office', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Kedai Kopi Senja Rasa Nusantara');
        $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $item = ItemPelanggan($k);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $budi = Pelanggan::query()->where('Uuid', $item['Uuid'])->sole();
        expect($budi->NoHp)->toBe('6281311112222')->and($budi->IdPerangkatPembuat)->toBe($k['Perangkat']->Id);

        // Perangkat lain offline membuat pelanggan dengan nomor yang sama → alias, bukan ditolak.
        $ganda = ItemPelanggan($k, ['Nama' => 'Pak Budi', 'NoHp' => '+62 813-1111-2222']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$ganda]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Pelanggan::query()->count())->toBe(1)
            ->and(PelangganAlias::query()->where('Uuid', $ganda['Uuid'])->value('IdPelanggan'))->toBe($budi->Id);

        $jual = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '2', 'Harga' => '38500.00']]], ['UuidPelanggan' => $ganda['Uuid']]);
        $jual2 = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $produk, 'Jumlah' => '1', 'Harga' => '38500.00']]], ['UuidPelanggan' => strtolower($item['Uuid'])]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$jual, $jual2]))->toBe([['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('IdPelanggan', $budi->Id)->count())->toBe(2)
            ->and(Penjualan::query()->where('Uuid', $jual['Uuid'])->value('PerluTinjauan'))->toBeFalse();

        BantuanKatalog::MasukSebagai($this, $k['Tenant']->Id);
        $this->getJson('/kelola/pelanggan')->assertJsonPath('Data.0.JumlahTransaksi', 2)->assertJsonPath('Data.0.TotalBelanja', '115500.00');
        $this->get("/kelola/pelanggan/{$budi->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pelanggan/Detail')
            ->where('Pelanggan.NoHp', '0813-1111-2222')
            ->has('Riwayat', 2));
        $this->get("/kelola/penjualan/{$jual['Uuid']}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Penjualan.Pelanggan', ['Uuid' => $budi->Uuid, 'Nama' => 'Budi Santoso']));

        $audit = LogAudit::query()->where('Peristiwa', 'pelanggan.tambah')->sole();
        expect($audit->NilaiBaru['NoHp'] ?? null)->toBe('0813****2222')->and($audit->NilaiBaru['Sumber'] ?? null)->toBe('POS');
    });

    it('penjualan dengan pelanggan belum dikenal tetap diterima + PerluTinjauan; nomor HP tidak valid ditolak', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);

        $jual = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $produk, 'Harga' => '38500.00']]], ['UuidPelanggan' => BantuanKasir::Uuid()]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$jual]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $penjualan = Penjualan::query()->where('Uuid', $jual['Uuid'])->sole();
        expect($penjualan->IdPelanggan)->toBeNull()
            ->and($penjualan->PerluTinjauan)->toBeTrue()
            ->and($penjualan->AlasanTinjauan)->toContain('PelangganTidakDikenal');

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemPelanggan($k, ['NoHp' => '12'])]))->toBe([['Ditolak', 'NoHpTidakValid']]);
    });

    it('API cari: minimal 3 karakter, nama atau nomor HP, pelanggan diarsipkan & tenant lain tidak muncul, nomor tersamar', function (): void {
        $k = BantuanPenjualan::Siapkan($this, 'Toko Kelontong Berkah Solo');
        Pelanggan::query()->create(['Nama' => 'Ani Rahmawati', 'NoHp' => '6281234567890']);
        Pelanggan::query()->create(['Nama' => 'Anita Arsip', 'NoHp' => '6281299990000', 'Status' => StatusPelanggan::Diarsipkan]);

        $lain = BantuanPenjualan::Siapkan($this, 'Toko Lain Jaya');
        Pelanggan::query()->create(['Nama' => 'Ani Tetangga', 'NoHp' => '6281234567890']);

        $cari = fn (string $kata) => $this->withToken($k['Token'])->getJson('/api/pos/v1/pelanggan?kata='.urlencode($kata))->assertOk()->json('Pelanggan');

        expect($cari('an'))->toBe([])
            ->and($cari('ani'))->toBe([['Uuid' => Pelanggan::withoutGlobalScopes()->where('Nama', 'Ani Rahmawati')->value('Uuid'), 'Nama' => 'Ani Rahmawati', 'NoHp' => '0812****7890', 'KodeTier' => null, 'NamaTier' => null, 'SaldoPoin' => 0, 'LimitKredit' => null, 'SisaPiutang' => '0.00', 'HariLewatJatuhTempo' => 0, 'HariLahir' => null, 'JumlahTransaksi' => 0, 'PemakaianPromo' => []]])
            ->and($cari('0812-3456'))->toHaveCount(1)
            ->and($cari('7890'))->toHaveCount(1)
            ->and($cari('arsip'))->toBe([]);
        unset($lain);
    });
});

describe('Identitas pajak pelanggan (Faktur Pajak Coretax, v3.11)', function (): void {
    it('NPWP & NIK disimpan sebagai digit terenkripsi, audit tersamar, nama/alamat sesuai NPWP; panjang salah ditolak; ubah tanpa bidang pajak tidak menghapusnya', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Toko Bangunan Makmur Jaya');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);

        $this->post('/kelola/pelanggan', IsianPelanggan(['Npwp' => '0123456789', 'Nik' => '']))->assertSessionHasErrors('Npwp');
        $this->post('/kelola/pelanggan', IsianPelanggan(['NoHp' => '081300000001', 'Nik' => '320101']))->assertSessionHasErrors('Nik');
        $this->post('/kelola/pelanggan', IsianPelanggan(['NoHp' => '081300000002', 'Npwp' => 'ABC']))->assertSessionHasErrors('Npwp');

        $this->post('/kelola/pelanggan', IsianPelanggan([
            'Nama' => 'Budi (proyek Jl. Melati)',
            'NoHp' => '081300000003',
            'Npwp' => '01.234.567.8-901.000',
            'Nik' => '',
            'NamaNpwp' => 'CV Bangun Sejahtera',
            'AlamatNpwp' => 'Jl. Melati No. 10, Solo',
        ]))->assertSessionHasNoErrors();
        $budi = Pelanggan::query()->where('NoHp', '6281300000003')->sole();

        // 15 digit (NPWP lama) diterima; tersimpan hanya angka dan terenkripsi di database.
        expect($budi->Npwp)->toBe('012345678901000')
            ->and($budi->Nik)->toBeNull()
            ->and($budi->NamaNpwp)->toBe('CV Bangun Sejahtera')
            ->and(DB::table('Pelanggan')->where('Id', $budi->Id)->value('Npwp'))->not->toContain('012345678901000');

        $audit = LogAudit::query()->where('Peristiwa', 'pelanggan.tambah')->orderByDesc('Id')->firstOrFail();
        expect(json_encode($audit->NilaiBaru))->not->toContain('012345678901000')->toContain('***********1000');

        // Ubah dari formulir lain (tanpa bidang pajak, mis. sinkron POS) tidak menghapus identitas pajak.
        $this->put("/kelola/pelanggan/{$budi->Uuid}", IsianPelanggan(['Nama' => 'Budi Santoso', 'NoHp' => '081300000003']))->assertSessionHasNoErrors();
        expect($budi->refresh()->Npwp)->toBe('012345678901000');
    });

    it('detail: utuh untuk pengelola pelanggan, tersamar empat digit terakhir untuk yang hanya melihat', function (): void {
        $t = BantuanKatalog::SiapkanTenantProduk('Toko Bangunan Makmur Jaya');
        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id);
        $this->post('/kelola/pelanggan', IsianPelanggan(['NoHp' => '081300000004', 'Npwp' => '0123456789012345']))->assertSessionHasNoErrors();
        $p = Pelanggan::query()->where('NoHp', '6281300000004')->sole();

        $this->get("/kelola/pelanggan/{$p->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Pelanggan.Npwp', '0123456789012345'));

        BantuanKatalog::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Supervisor);
        $this->get("/kelola/pelanggan/{$p->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->where('Pelanggan.Npwp', '************2345'));
    });
});
