<?php

declare(strict_types=1);

use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Penjualan\Layanan\KodeStrukDigital;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\SaldoStok;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Produk bernomor seri dengan stok awal terposting, HPP Rp5.000.000 per unit.
 *
 * @param  list<string>  $nomor
 */
function BuatProdukSerialJual(array $k, array $nomor, string $nama = 'Ponsel Android 8/256 GB Hitam'): Produk
{
    $produk = BantuanKatalog::BuatProduk(['Nama' => $nama, 'Pelacakan' => PelacakanProduk::Seri], '6500000.00');
    BantuanStokAwal::BuatDanPosting($k['Gudang'], [BantuanStokAwal::Baris($produk, (string) count($nomor), '5000000', null, null, $nomor)], $k['Pemilik']->Id);

    return $produk;
}

/** @return array<string, string> Nomor → Status */
function StatusSerialJual(Produk $produk): array
{
    return NomorSeri::query()->where('IdProduk', $produk->Id)->orderBy('Nomor')->get()->mapWithKeys(fn (NomorSeri $s): array => [$s->Nomor => $s->Status->value])->all();
}

describe('F-05h penjualan produk bernomor seri', function (): void {
    it('nomor seri yang dicatat kasir dikeluarkan satu per unit (Terjual, tertaut ke baris), HPP baris = Σ unit, saldo turun; invariant terjaga', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hp = BuatProdukSerialJual($k, ['IMEI-0001', 'IMEI-0002', 'IMEI-0003']);

        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '2', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001', 'imei-0003']]]]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();

        expect(StatusSerialJual($hp))->toBe(['IMEI-0001' => 'Terjual', 'IMEI-0002' => 'Tersedia', 'IMEI-0003' => 'Terjual'])
            ->and(NomorSeri::query()->where('IdProduk', $hp->Id)->where('Status', StatusNomorSeri::Terjual->value)->pluck('IdPenjualanDetail')->unique()->values()->all())->toBe([$d->Id])
            ->and(MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::Penjualan->value)->where('IdReferensi', $p->Id)->count())->toBe(2)
            ->and($d->TotalHpp)->toBe('10000000.00')
            ->and(SaldoStok::query()->where('IdProduk', $hp->Id)->value('JumlahTersedia'))->toBe('1.0000')
            ->and($p->PerluTinjauan)->toBeFalse()
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('jumlah nomor seri tidak sama dengan jumlah unit, atau nomor ganda, ditolak tanpa data tersimpan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hp = BuatProdukSerialJual($k, ['IMEI-0001', 'IMEI-0002']);
        $item = fn (array $nomor, string $jumlah = '2') => BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $hp, 'Jumlah' => $jumlah, 'Harga' => '6500000.00', 'NomorSeri' => $nomor]]]);
        $kurang = $item(['IMEI-0001']);
        $tanpa = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '1', 'Harga' => '6500000.00']]]);
        $ganda = BantuanPenjualan::Item($k, ['Baris' => [
            ['Produk' => $hp, 'Jumlah' => '1', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001']],
            ['Produk' => $hp, 'Jumlah' => '1', 'Harga' => '6500000.00', 'NomorSeri' => ['imei-0001']],
        ]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$kurang, $tanpa, $ganda]))->toBe([
            ['Ditolak', 'NomorSeriTidakSesuai'],
            ['Ditolak', 'NomorSeriTidakSesuai'],
            ['Ditolak', 'NomorSeriGanda'],
        ]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->whereIn('Uuid', [$kurang['Uuid'], $tanpa['Uuid'], $ganda['Uuid']])->count())->toBe(0)
            ->and(StatusSerialJual($hp))->toBe(['IMEI-0001' => 'Tersedia', 'IMEI-0002' => 'Tersedia']);
    });

    it('nomor yang tidak tersedia (belum diterima atau sudah terjual): diterima, unit itu tidak mengurangi stok, ditinjau SerialBermasalah', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hp = BuatProdukSerialJual($k, ['IMEI-0001', 'IMEI-0002']);
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '1', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001']]]]);

        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '2', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001', 'IMEI-0002']]]]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();

        expect($p->PerluTinjauan)->toBeTrue()
            ->and($p->AlasanTinjauan)->toContain('SerialBermasalah')->toContain('IMEI-0001')
            ->and(StatusSerialJual($hp))->toBe(['IMEI-0001' => 'Terjual', 'IMEI-0002' => 'Terjual'])
            ->and($d->TotalHpp)->toBe('5000000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);

        $tak = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '1', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-9999']]]]);
        expect($tak->AlasanTinjauan)->toContain('IMEI-9999')
            ->and(NomorSeri::query()->where('Nomor', 'IMEI-9999')->exists())->toBeFalse();
    });
});

describe('F-05h void & retur mengembalikan nomor seri', function (): void {
    it('void: semua nomor seri penjualan kembali Tersedia di lokasi asal dan tidak tertaut lagi', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hp = BuatProdukSerialJual($k, ['IMEI-0001', 'IMEI-0002', 'IMEI-0003']);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '2', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001', 'IMEI-0002']]]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $p)]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(StatusSerialJual($hp))->toBe(['IMEI-0001' => 'Tersedia', 'IMEI-0002' => 'Tersedia', 'IMEI-0003' => 'Tersedia'])
            ->and(NomorSeri::query()->where('IdProduk', $hp->Id)->whereNotNull('IdPenjualanDetail')->count())->toBe(0)
            ->and(SaldoStok::query()->where('IdProduk', $hp->Id)->value('JumlahTersedia'))->toBe('3.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('retur: nomor yang disebut kasir kembali; tanpa nomor, unit belum-diretur berurutan; nomor asing atau yang sudah diretur ditolak', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hp = BuatProdukSerialJual($k, ['IMEI-0001', 'IMEI-0002', 'IMEI-0003']);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '3', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001', 'IMEI-0002', 'IMEI-0003']]]]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();
        $kirim = function (array $baris) use ($k, $p): array {
            $hasil = BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemRetur($k, $p, [$baris])]);
            BantuanOrganisasi::AturKonteks($k['Tenant']->Id);

            return $hasil;
        };

        // Nomor asing ditolak; nomor yang dipilih (bukan yang pertama) kembali.
        expect($kirim(['Detail' => $d, 'Jumlah' => '1', 'NomorSeri' => ['IMEI-7777']]))->toBe([['Ditolak', 'NomorSeriTidakSesuai']]);
        expect($kirim(['Detail' => $d, 'Jumlah' => '1', 'NomorSeri' => ['imei-0002']]))->toBe([['Diterima', null]]);
        expect(StatusSerialJual($hp))->toBe(['IMEI-0001' => 'Terjual', 'IMEI-0002' => 'Tersedia', 'IMEI-0003' => 'Terjual']);

        // Nomor yang sudah diretur tidak bisa diretur lagi.
        expect($kirim(['Detail' => $d, 'Jumlah' => '1', 'NomorSeri' => ['IMEI-0002']]))->toBe([['Ditolak', 'NomorSeriTidakSesuai']]);

        // Tanpa nomor: berurutan dari yang belum diretur (IMEI-0001, lalu IMEI-0003).
        expect($kirim(['Detail' => $d, 'Jumlah' => '1']))->toBe([['Diterima', null]]);
        expect(StatusSerialJual($hp))->toBe(['IMEI-0001' => 'Tersedia', 'IMEI-0002' => 'Tersedia', 'IMEI-0003' => 'Terjual']);
        expect($kirim(['Detail' => $d, 'Jumlah' => '1']))->toBe([['Diterima', null]]);

        expect(StatusSerialJual($hp))->toBe(['IMEI-0001' => 'Tersedia', 'IMEI-0002' => 'Tersedia', 'IMEI-0003' => 'Tersedia'])
            ->and(SaldoStok::query()->where('IdProduk', $hp->Id)->value('JumlahTersedia'))->toBe('3.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });
});

describe('F-05h riwayat nomor seri /kelola/persediaan/kartu-stok/nomor-seri', function (): void {
    it('cari potongan nomor (status, lokasi, penjualan) dan riwayat satu unit dari masuk sampai terjual; tenant lain tidak terlihat; izin persediaan.lihat', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hp = BuatProdukSerialJual($k, ['IMEI-0001', 'IMEI-0002']);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '1', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001']]]]);
        $terjual = NomorSeri::query()->where('Nomor', 'IMEI-0001')->sole();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Persediaan/NomorSeri')
            ->where('Saring', ['Cari' => 'IMEI', 'Status' => '', 'Produk' => '', 'Unit' => ''])
            ->where('TotalHasil', 2)
            ->has('Hasil', 2)
            ->where('Hasil.0.Nomor', 'IMEI-0001')
            ->where('Hasil.0.Status', 'Terjual')
            ->where('Hasil.0.NomorPenjualan', $p->Nomor)
            ->where('Hasil.0.NamaProduk', $hp->Nama)
            ->where('Hasil.1.Nomor', 'IMEI-0002')
            ->where('Hasil.1.Status', 'Tersedia')
            ->where('Hasil.1.NamaGudang', $k['Gudang']->Nama)
            ->where('Detail', null));

        $this->get("/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI&unit={$terjual->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Detail.Unit.Nomor', 'IMEI-0001')
            ->has('Detail.Riwayat', 2)
            ->where('Detail.Riwayat.0.Jenis', 'Stok awal')
            ->where('Detail.Riwayat.0.Arah', 'Masuk')
            ->where('Detail.Riwayat.1.Jenis', 'Penjualan')
            ->where('Detail.Riwayat.1.Arah', 'Keluar')
            ->where('Detail.Riwayat.1.NomorDokumen', $p->Nomor));

        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=%25')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Hasil', 0));
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Hasil', 0)->where('Detail', null));

        // Tenant lain tidak melihat nomor seri ini, dan Kasir tanpa izin persediaan.lihat ditolak.
        $lain = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->get("/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI&unit={$terjual->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Hasil', 0)->where('Detail', null));

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI')->assertForbidden();
    });
});

describe('F-05h garansi: snapshot penjualan & struk digital', function (): void {
    it('nomor seri dan masa garansi produk di-snapshot ke baris penjualan (tidak ikut berubah bila produk diubah) dan tampil di struk digital sebagai garansi sampai', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hp = BuatProdukSerialJual($k, ['IMEI-0001', 'IMEI-0002']);
        $hp->forceFill(['MasaGaransiBulan' => 12])->save();
        $tanpaGaransi = BuatProdukSerialJual($k, ['SN-A1'], 'Charger Cepat 65 W');

        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [
            ['Produk' => $hp, 'Jumlah' => '2', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001', 'IMEI-0002']],
            ['Produk' => $tanpaGaransi, 'Jumlah' => '1', 'Harga' => '250000.00', 'NomorSeri' => ['SN-A1']],
        ]]);
        $hp->forceFill(['MasaGaransiBulan' => 24])->save();
        $detail = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->orderBy('Urutan')->get();

        expect($detail[0]->NomorSeri)->toBe(['IMEI-0001', 'IMEI-0002'])
            ->and($detail[0]->MasaGaransiBulan)->toBe(12)
            ->and($detail[1]->NomorSeri)->toBe(['SN-A1'])
            ->and($detail[1]->MasaGaransiBulan)->toBeNull();

        $garansi = $p->TanggalBisnis->copy()->addMonthsNoOverflow(12)->toDateString();
        $this->get('/s/'.KodeStrukDigital::Buat($k['Tenant']->Id, $p->Uuid))->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Struk.Baris.0.NomorSeri', ['IMEI-0001', 'IMEI-0002'])
            ->where('Struk.Baris.0.GaransiSampai', $garansi)
            ->where('Struk.Baris.1.NomorSeri', ['SN-A1'])
            ->where('Struk.Baris.1.GaransiSampai', null));
    });

    it('produk biasa tidak menyimpan nomor seri atau garansi di baris penjualan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        $d = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->sole();

        expect($d->NomorSeri)->toBeNull()->and($d->MasaGaransiBulan)->toBeNull();
    });
});

describe('F-05h riwayat nomor seri (v3.13): buka otomatis, filter, pembeli & garansi, izin, ekspor', function (): void {
    it('alamat lama dialihkan ke alamat baru (tautan tersimpan tetap bekerja) dengan query utuh', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::StafGudang);

        $this->get('/kelola/persediaan/nomor-seri?cari=IMEI&status=Tersedia')
            ->assertStatus(301)
            ->assertRedirect('/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI&status=Tersedia');
    });

    it('satu hasil atau nomor yang persis sama langsung membuka riwayatnya; beberapa hasil tidak', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BuatProdukSerialJual($k, ['IMEI-0001', 'IMEI-00011']);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::StafGudang);

        // Satu-satunya hasil: dibuka.
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI-00011')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Hasil', 1)->where('Detail.Unit.Nomor', 'IMEI-00011'));
        // Dua hasil, tetapi satu nomornya persis sama (huruf besar/kecil diabaikan): yang persis dibuka.
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=imei-0001')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Hasil', 2)->where('Detail.Unit.Nomor', 'IMEI-0001'));
        // Dua hasil tanpa yang persis sama: pengguna memilih.
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI-000')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Hasil', 2)->where('Detail', null));
    });

    it('mencari lewat nama produk, memfilter status dan produk, dan membatasi hasil dengan total yang jujur', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hp = BuatProdukSerialJual($k, ['IMEI-0001', 'IMEI-0002']);
        $charger = BuatProdukSerialJual($k, ['SN-A1'], 'Charger Cepat 65 W');
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '1', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001']]]]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::StafGudang);

        // Nama produk menemukan semua unitnya; produk lain tidak ikut.
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=Ponsel')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Hasil', 2)->where('TotalHasil', 2)->where('Hasil.0.NamaProduk', $hp->Nama));
        // Status menyempitkan; status asing diabaikan (bukan galat).
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=Ponsel&status=Tersedia')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Hasil', 1)->where('Hasil.0.Nomor', 'IMEI-0002')->where('Saring.Status', 'Tersedia'));
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=Ponsel&status=Bukan')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Hasil', 2)->where('Saring.Status', ''));
        // `produk` saja (tanpa kata cari) mendaftar unit produk itu; produk tersaring muncul sebagai penanda.
        $this->get("/kelola/persediaan/kartu-stok/nomor-seri?produk={$charger->Uuid}")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->has('Hasil', 1)->where('Hasil.0.Nomor', 'SN-A1')->where('Produk.Uuid', $charger->Uuid)->where('Produk.Nama', $charger->Nama));
        // Uuid produk yang tidak dikenal menghasilkan daftar kosong, bukan semua unit.
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?produk=01K5TIDAKADA00000000000000')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h->has('Hasil', 0));
    });

    it('unit terjual membawa penjualan, pembeli, dan garansi; pembeli & tautan hanya bagi yang berizin', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hp = BuatProdukSerialJual($k, ['IMEI-0001', 'IMEI-0002']);
        $hp->forceFill(['MasaGaransiBulan' => 12])->save();
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '1', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001']]]]);
        $pelanggan = Pelanggan::query()->create(['Nama' => 'Budi Santoso', 'NoHp' => '6281355550077']);
        Penjualan::query()->toBase()->where('Id', $p->Id)->update(['IdPelanggan' => $pelanggan->Id]);
        $garansi = $p->TanggalBisnis->copy()->addMonthsNoOverflow(12)->toDateString();

        // Pemilik: melihat semuanya.
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI-0001')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Detail.Unit.NomorPenjualan', $p->Nomor)
            ->where('Detail.Unit.UuidPenjualan', $p->Uuid)
            ->where('Detail.Unit.NamaPelanggan', 'Budi Santoso')
            ->where('Detail.Unit.UuidPelanggan', $pelanggan->Uuid)
            ->where('Detail.Unit.MasaGaransiBulan', 12)
            ->where('Detail.Unit.GaransiSampai', $garansi)
            ->where('Detail.Unit.StatusGaransi', 'Aktif')
            ->where('Izin.Pelanggan', true));

        // Unit yang belum terjual tidak membawa data penjualan sama sekali.
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI-0002')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Detail.Unit.Status', 'Tersedia')
            ->where('Detail.Unit.NomorPenjualan', null)
            ->where('Detail.Unit.NamaPelanggan', null)
            ->where('Detail.Unit.GaransiSampai', null)
            ->where('Detail.Unit.StatusGaransi', null));

        // Petugas gudang: nomor penjualan (sudah ada di buku stok) tetap terlihat, pembeli & uuid penjualan tidak.
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI-0001')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Detail.Unit.NomorPenjualan', $p->Nomor)
            ->where('Detail.Unit.NamaPelanggan', null)
            ->where('Detail.Unit.UuidPelanggan', null)
            ->where('Detail.Unit.UuidPenjualan', null)
            ->where('Izin.Pelanggan', false));
    });

    it('garansi yang sudah lewat ditandai Berakhir', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hp = BuatProdukSerialJual($k, ['IMEI-0001']);
        $hp->forceFill(['MasaGaransiBulan' => 1])->save();
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '1', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001']]]]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id);

        $this->travelTo(Carbon::now()->addMonths(3));
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI-0001')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Detail.Unit.StatusGaransi', 'Berakhir'));
        $this->travelBack();
    });

    it('pencarian cepat (JSON) hanya mencocokkan nomor dan memakai bentuk {Data}', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BuatProdukSerialJual($k, ['IMEI-0001']);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::StafGudang);

        $this->getJson('/kelola/persediaan/kartu-stok/nomor-seri?cari=IMEI-0001')->assertOk()
            ->assertJsonCount(1, 'Data')->assertJsonPath('Data.0.Nomor', 'IMEI-0001');
        // Nama produk tidak mencocokkan di mode pencarian cepat (supaya tidak membanjiri hasil).
        $this->getJson('/kelola/persediaan/kartu-stok/nomor-seri?cari=Ponsel')->assertOk()->assertJsonCount(0, 'Data');
    });

    it('ekspor CSV memuat hasil sesuai filter, tanpa pembeli bagi yang tidak berizin, dan diawali BOM', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $hp = BuatProdukSerialJual($k, ['IMEI-0001', 'IMEI-0002']);
        BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $hp, 'Jumlah' => '1', 'Harga' => '6500000.00', 'NomorSeri' => ['IMEI-0001']]]]);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::StafGudang);

        $respons = $this->get('/kelola/persediaan/kartu-stok/nomor-seri/ekspor?cari=Ponsel&status=Tersedia')->assertOk();
        $isi = $respons->streamedContent();

        expect($respons->headers->get('Content-Type'))->toContain('text/csv')
            ->and($isi)->toStartWith("\xEF\xBB\xBF")
            ->and($isi)->toContain('Garansi sampai')
            ->and($isi)->toContain('IMEI-0002')
            ->and($isi)->not->toContain('IMEI-0001');

        // Izin persediaan.lihat tetap wajib.
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/persediaan/kartu-stok/nomor-seri/ekspor?cari=IMEI')->assertForbidden();
    });
});
