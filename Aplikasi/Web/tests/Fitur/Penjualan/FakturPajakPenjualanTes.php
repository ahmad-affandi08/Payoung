<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Aksi\BatalkanFakturPajakPenjualan;
use App\Domain\Penjualan\Aksi\TerbitkanFakturPajakPenjualan;
use App\Domain\Penjualan\Aksi\UbahNomorFakturPajakPenjualan;
use App\Domain\Penjualan\Layanan\PenulisXmlCoretax;
use App\Domain\Penjualan\Layanan\PenyusunFakturPajakCoretax;
use App\Domain\Penjualan\Model\FakturPajakPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Tenant\Model\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * TAX-07 (PRD v6.27, D-147): Faktur Pajak per transaksi kasir atas permintaan pembeli ber-NPWP/NIK. Dibuktikan: angka baris
 * Coretax dihitung dari snapshot penjualan (DPP, DPP nilai lain 11/12, PPN) dan cocok dengan pembukuan; penjualan yang tidak
 * bisa dijadikan Faktur Pajak ditolak dengan alasan; satu penjualan satu faktur aktif (idempoten); nomor Coretax diaudit;
 * isolasi tenant dan izin akuntansi.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-27 03:00:00');
    BantuanPendaftaran::SiapkanPrasyarat();
    BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
    $this->k = BantuanPenjualan::Siapkan($this);
    Tenant::query()->whereKey($this->k['Tenant']->Id)->update(['Npwp' => '0123456789012345', 'Pkp' => true]);
    BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
    BantuanPenjualan::AturProfilPajak($this->k, pkp: true);
    $this->minyak = BantuanPenjualan::BuatProdukBerstok($this->k['Gudang'], $this->k['Pemilik']->Id, jumlah: '50');
    BantuanPenjualan::PasangKelompokPajak('Uji kasir: barang kena PPN', ['Ppn' => 'Subtotal'], $this->minyak);
    $this->pembeli = Pelanggan::query()->create([
        'Nama' => 'CV Sinar Bengawan',
        'NoHp' => '6281355550021',
        'Npwp' => '012345678901234',
        'NamaNpwp' => 'CV Sinar Bengawan Abadi',
        'AlamatNpwp' => 'Jl. Slamet Riyadi No. 10, Solo',
        'Email' => 'pajak@sinar.test',
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function JualKenaPpn($tes, bool $inklusif = false, string $jumlah = '2'): Penjualan
{
    return BantuanPenjualan::Jual($tes, $tes->k, [
        'DibuatPada' => CarbonImmutable::now()->subMinutes(10),
        'HargaTermasukPajak' => $inklusif,
        'Pajak' => [['Ppn', '12.000000', 11, 12]],
        'Baris' => [['Produk' => $tes->minyak, 'Jumlah' => $jumlah, 'Harga' => '38500.00', 'HargaTermasukPajak' => $inklusif ? true : null]],
        'Pembayaran' => [['Metode' => $tes->k['Tunai'], 'Jumlah' => '200000.00']],
    ]);
}

function TerbitkanFakturKasir($tes, Penjualan $penjualan, ?Pelanggan $pembeli = null): FakturPajakPenjualan
{
    return app(TerbitkanFakturPajakPenjualan::class)->Jalankan($penjualan->Uuid, ($pembeli ?? $tes->pembeli)->Uuid, null, $tes->k['Pemilik']->Id, null);
}

function RingkasanCoretax(): array
{
    $hasil = app(PenyusunFakturPajakCoretax::class)->Susun(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), null);

    return [$hasil, $hasil->KeRingkasan()];
}

it('menerbitkan faktur dan angka baris Coretax cocok dengan pembukuan (harga belum termasuk PPN)', function (): void {
    $p = JualKenaPpn($this);
    $faktur = TerbitkanFakturKasir($this, $p);

    expect($faktur->Status->value)->toBe('Diposting')
        ->and($faktur->IdPenjualan)->toBe($p->Id)
        ->and($faktur->Tanggal->toDateString())->toBe($p->TanggalBisnis->toDateString())
        ->and($faktur->NomorFakturPajak)->toBeNull();

    [$hasil, $ringkasan] = RingkasanCoretax();
    expect($ringkasan['JumlahDiperiksa'])->toBe(1)->and($ringkasan['JumlahSiap'])->toBe(1)->and($ringkasan['MasalahFaktur'])->toBe([]);

    $f = $hasil->faktur[0];
    expect($f->nomorFaktur)->toBe($p->Nomor)
        ->and($f->namaPembeli)->toBe('CV Sinar Bengawan Abadi')
        ->and($f->jenisDokumenPembeli)->toBe('TIN')
        ->and($f->tinPembeli)->toBe('0012345678901234')
        ->and($f->totalDpp)->toBe('77000.00')
        ->and($f->baris[0]->dppNilaiLain)->toBe('70583.33')
        ->and($f->totalPpn)->toBe('8470.00')
        ->and($f->selisihDpp)->toBe('0.00')
        ->and($f->selisihPpn)->toBe('0.00');

    $xml = app(PenulisXmlCoretax::class)->Tulis($hasil);
    expect($xml)->toContain('<TIN>0123456789012345</TIN>')->toContain('<BuyerName>CV Sinar Bengawan Abadi</BuyerName>')->toContain('<RefDesc>'.$p->Nomor.'</RefDesc>');
});

it('harga sudah termasuk PPN: DPP = bruto − PPN di dalam harga. Selisih pembulatan dilaporkan, bukan disembunyikan', function (): void {
    $p = JualKenaPpn($this, inklusif: true);
    TerbitkanFakturKasir($this, $p);

    [$hasil] = RingkasanCoretax();
    $f = $hasil->faktur[0];

    expect($f->totalDpp)->toBe('69369.37')
        ->and(abs((float) $f->selisihPpn))->toBeLessThanOrEqual(0.02)
        ->and(abs((float) $f->selisihDpp))->toBeLessThanOrEqual(0.02);
});

it('pembeli tanpa NPWP/NIK sah, usaha bukan PKP, dan penjualan tanpa PPN ditolak dengan alasan tertulis', function (): void {
    $p = JualKenaPpn($this);
    $biasa = Pelanggan::query()->create(['Nama' => 'Bu Wati', 'NoHp' => '6281355550022']);

    expect(fn () => TerbitkanFakturKasir($this, $p, $biasa))->toThrow(PelanggaranAturanBisnis::class, 'NPWP atau NIK');
    expect(FakturPajakPenjualan::query()->count())->toBe(0);

    $tanpaPpn = BantuanPenjualan::Jual($this, $this->k, [
        'DibuatPada' => CarbonImmutable::now()->subMinutes(9),
        'Baris' => [['Produk' => BantuanPenjualan::BuatProdukBerstok($this->k['Gudang'], $this->k['Pemilik']->Id, 'Beras Pandan Wangi 5 Kg', '10', '60000', '70000.00'), 'Jumlah' => '1', 'Harga' => '70000.00']],
        'Pembayaran' => [['Metode' => $this->k['Tunai'], 'Jumlah' => '70000.00']],
    ]);
    expect(fn () => TerbitkanFakturKasir($this, $tanpaPpn))->toThrow(PelanggaranAturanBisnis::class, 'tidak memuat PPN');

    Tenant::query()->whereKey($this->k['Tenant']->Id)->update(['Pkp' => false]);
    expect(fn () => TerbitkanFakturKasir($this, $p))->toThrow(PelanggaranAturanBisnis::class, 'PKP');
});

it('idempoten per pelanggan, tolak pelanggan lain sampai dibatalkan, lalu bisa diterbitkan ulang', function (): void {
    $p = JualKenaPpn($this);
    $pertama = TerbitkanFakturKasir($this, $p);
    $lagi = TerbitkanFakturKasir($this, $p);
    expect($lagi->Id)->toBe($pertama->Id)->and(FakturPajakPenjualan::query()->count())->toBe(1);

    $lain = Pelanggan::query()->create(['Nama' => 'Toko Lain', 'NoHp' => '6281355550023', 'Nik' => '3372011234567890']);
    expect(fn () => TerbitkanFakturKasir($this, $p, $lain))->toThrow(PelanggaranAturanBisnis::class, 'pelanggan lain');

    expect(fn () => app(BatalkanFakturPajakPenjualan::class)->Jalankan($pertama->Uuid, 'xx', $this->k['Pemilik']->Id, null))->toThrow(PelanggaranAturanBisnis::class);
    app(BatalkanFakturPajakPenjualan::class)->Jalankan($pertama->Uuid, 'Salah pilih pelanggan', $this->k['Pemilik']->Id, null);
    expect($pertama->refresh()->Status->value)->toBe('Dibatalkan')->and($pertama->AlasanBatal)->toBe('Salah pilih pelanggan');

    // Pembeli ber-NIK: TIN 16 nol + dokumen National ID.
    $baru = TerbitkanFakturKasir($this, $p, $lain);
    expect($baru->Id)->not->toBe($pertama->Id);
    [$hasil] = RingkasanCoretax();
    expect($hasil->faktur)->toHaveCount(1)
        ->and($hasil->faktur[0]->jenisDokumenPembeli)->toBe('National ID')
        ->and($hasil->faktur[0]->nomorDokumenPembeli)->toBe('3372011234567890');
});

it('faktur yang dibatalkan tidak diekspor. Penjualan yang di-void sesudahnya ditandai bermasalah', function (): void {
    $p = JualKenaPpn($this);
    $faktur = TerbitkanFakturKasir($this, $p);

    $p->forceFill(['Status' => 'Void'])->saveQuietly();
    [$hasil, $ringkasan] = RingkasanCoretax();
    expect($hasil->faktur)->toBe([])->and($ringkasan['MasalahFaktur'][0]['Nomor'])->toBe($p->Nomor)
        ->and(implode(' ', $ringkasan['MasalahFaktur'][0]['Alasan']))->toContain('dibatalkan atau diretur');

    app(BatalkanFakturPajakPenjualan::class)->Jalankan($faktur->Uuid, 'Penjualan di-void', $this->k['Pemilik']->Id, null);
    [, $ringkasan] = RingkasanCoretax();
    expect($ringkasan['JumlahDiperiksa'])->toBe(0);
});

it('nomor Faktur Pajak diisi kemudian dan diaudit. Faktur terbatalkan tidak bisa diubah', function (): void {
    $p = JualKenaPpn($this);
    $faktur = TerbitkanFakturKasir($this, $p);

    app(UbahNomorFakturPajakPenjualan::class)->Jalankan($faktur->Uuid, ' 0400002600000123 ', $this->k['Pemilik']->Id, null);
    expect($faktur->refresh()->NomorFakturPajak)->toBe('0400002600000123');
    $log = LogAudit::query()->where('Peristiwa', 'penjualan.faktur-pajak.nomor')->sole();
    expect($log->NilaiBaru)->toBe(['NomorFakturPajak' => '0400002600000123']);

    app(BatalkanFakturPajakPenjualan::class)->Jalankan($faktur->Uuid, 'Pembeli batal minta faktur', $this->k['Pemilik']->Id, null);
    expect(fn () => app(UbahNomorFakturPajakPenjualan::class)->Jalankan($faktur->Uuid, '1', $this->k['Pemilik']->Id, null))->toThrow(PelanggaranAturanBisnis::class);
    expect(fn () => $faktur->refresh()->forceFill(['Catatan' => 'ubah'])->save())->toThrow(LogicException::class);
});

it('outlet di luar akses dan tenant lain diperlakukan sebagai tidak ditemukan', function (): void {
    $p = JualKenaPpn($this);

    expect(fn () => app(TerbitkanFakturPajakPenjualan::class)->Jalankan($p->Uuid, $this->pembeli->Uuid, null, $this->k['Pemilik']->Id, [999999]))
        ->toThrow(PelanggaranAturanBisnis::class, 'Penjualan tidak ditemukan');
});

it('lewat web: terbitkan, isi nomor, batalkan; Kasir tanpa izin akuntansi ditolak. Detail memuat FakturPajak dan Izin', function (): void {
    $p = JualKenaPpn($this);
    BantuanOrganisasi::Masuk($this, $this->k['Pemilik'], $this->k['Tenant']->Id);

    $this->get('/kelola/penjualan/faktur-pajak/pelanggan?kata=Sinar')->assertOk()
        ->assertJsonPath('Data.0.Nama', 'CV Sinar Bengawan')
        ->assertJsonPath('Data.0.JenisIdentitas', 'NPWP')
        ->assertJsonPath('Data.0.IdentitasTersamar', '••••1234');

    $this->post("/kelola/penjualan/{$p->Uuid}/faktur-pajak", ['UuidPelanggan' => $this->pembeli->Uuid, 'Catatan' => 'Minta faktur via WA'])
        ->assertRedirect("/kelola/penjualan/{$p->Uuid}")->assertSessionHasNoErrors();
    BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
    $faktur = FakturPajakPenjualan::query()->sole();

    $this->get("/kelola/penjualan/{$p->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
        ->where('FakturPajak.Status', 'Diposting')
        ->where('FakturPajak.NamaPembeli', 'CV Sinar Bengawan')
        ->where('FakturPajak.Catatan', 'Minta faktur via WA')
        ->where('Izin.KelolaFakturPajak', true));

    $this->put("/kelola/penjualan/{$p->Uuid}/faktur-pajak/{$faktur->Uuid}/nomor", ['NomorFakturPajak' => '04000026000009'])->assertSessionHasNoErrors();
    $this->post("/kelola/penjualan/{$p->Uuid}/faktur-pajak/{$faktur->Uuid}/batalkan", ['Alasan' => 'Xx'])->assertSessionHasErrors('Alasan');
    $this->post("/kelola/penjualan/{$p->Uuid}/faktur-pajak/{$faktur->Uuid}/batalkan", ['Alasan' => 'Pembeli membatalkan permintaan'])
        ->assertSessionHasNoErrors()->assertSessionHas('Kilat');

    BantuanOrganisasi::Masuk($this, $this->k['Kasir'], $this->k['Tenant']->Id);
    $this->post("/kelola/penjualan/{$p->Uuid}/faktur-pajak", ['UuidPelanggan' => $this->pembeli->Uuid])->assertForbidden();
});

it('masalah bisnis lewat web kembali ke halaman dengan pesan, bukan 500', function (): void {
    $p = JualKenaPpn($this);
    $biasa = Pelanggan::query()->create(['Nama' => 'Bu Wati', 'NoHp' => '6281355550022']);
    BantuanOrganisasi::Masuk($this, $this->k['Pemilik'], $this->k['Tenant']->Id);

    $this->post("/kelola/penjualan/{$p->Uuid}/faktur-pajak", ['UuidPelanggan' => $biasa->Uuid])->assertSessionHasErrors();
    BantuanOrganisasi::AturKonteks($this->k['Tenant']->Id);
    expect(FakturPajakPenjualan::query()->count())->toBe(0);
});

it('isolasi tenant: tenant lain tidak melihat Faktur Pajak dan tidak bisa menerbitkannya', function (): void {
    $p = JualKenaPpn($this);
    TerbitkanFakturKasir($this, $p);

    $lain = BantuanKatalog::BuatTenant('Toko Lain Klaten');
    BantuanOrganisasi::AturKonteks($lain['Tenant']->Id);
    expect(FakturPajakPenjualan::query()->count())->toBe(0);
});
