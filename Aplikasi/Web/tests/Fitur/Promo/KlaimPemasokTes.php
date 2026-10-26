<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Model\PembayaranHutang;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Promo\Enum\CaraPenerimaanKlaim;
use App\Domain\Promo\Enum\StatusKlaimPromo;
use App\Domain\Promo\Model\KlaimPromoPemasok;
use App\Domain\Promo\Model\PenerimaanKlaimPemasok;
use App\Domain\Promo\Model\Promo;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;
use Tests\TestCase;

/*
 * F-16c bagian 4b/4d pendanaan promo (PSAK 72, basis akrual): potongan ke pelanggan tetap Diskon Penjualan; bagian yang
 * ditanggung pemasok menjadi klaim per transaksi, diakui saat penjualan (Dr Piutang Klaim Promosi Pemasok, Cr HPP) dan
 * dibalik saat void; penerimaan pembayaran klaim dijurnal Dr kas/bank, Cr Piutang Klaim Promosi Pemasok (J-16.5).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Promo diskon 10% produk Rp 38.500, 60% ditanggung pemasok.
 *
 * @return array<string, mixed>
 */
function SiapkanPromoPemasok(TestCase $tes): array
{
    $k = BantuanPenjualan::Siapkan($tes, 'Minimarket Berkah Promo');
    $produk = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
    $pemasok = BantuanPembelian::BuatPemasok('PT Kopi Nusantara Distribusi');
    $promo = Promo::query()->create([
        'Kode' => 'KOPI10',
        'Nama' => 'Diskon 10% kopi, didukung distributor',
        'Definisi' => [
            'Hari' => [], 'JamMulai' => null, 'JamSelesai' => null, 'Outlet' => [], 'Kanal' => [], 'Tier' => [],
            'MinimalSubtotal' => '0.00',
            'Kondisi' => ['Jenis' => 'Produk', 'Uuid' => [$produk->Uuid], 'JumlahMinimal' => '0.0000'],
            'Aksi' => ['Jenis' => 'DiskonPersenItem', 'Persen' => '10'],
            'BatasPerTransaksi' => null,
        ],
        'IdPemasok' => $pemasok->Id,
        'PersenDanaPemasok' => '60.00',
    ]);

    return $k + ['Produk' => $produk, 'Promo' => $promo, 'Pemasok' => $pemasok];
}

/**
 * @param  array<string, mixed>  $k
 * @return array{Jenis: string, Uuid: string, Data: array<string, mixed>}
 */
function ItemPromoPemasok(array $k): array
{
    return BantuanPenjualan::Item($k, [
        'Baris' => [['Produk' => $k['Produk'], 'Jumlah' => '2', 'Harga' => '38500.00']],
        'Promo' => [['Promo' => $k['Promo'], 'Baris' => [0 => '7700.00']]],
    ]);
}

describe('F-16c bagian 4b klaim promo pemasok', function (): void {
    it('klaim = potongan × bagian pemasok per transaksi. Void membatalkan klaim. Diskon tetap di jurnal penjualan', function (): void {
        $k = SiapkanPromoPemasok($this);
        $satu = ItemPromoPemasok($k);
        $dua = ItemPromoPemasok($k);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$satu, $dua]))->toBe([['Diterima', null], ['Diterima', null]]);
        // Kirim ulang tidak menggandakan klaim.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$satu]))->toBe([['Duplikat', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // 7.700 × 60% = 4.620 per transaksi.
        expect(KlaimPromoPemasok::query()->count())->toBe(2)
            ->and(KlaimPromoPemasok::query()->pluck('Jumlah')->map(fn ($j): string => (string) $j)->all())->toBe(['4620.00', '4620.00']);

        $jualDua = Penjualan::query()->where('Uuid', $dua['Uuid'])->sole();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $jualDua)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $klaimDua = KlaimPromoPemasok::query()->where('IdPenjualan', $jualDua->Id)->sole();
        expect($klaimDua->Status)->toBe(StatusKlaimPromo::Dibatalkan)
            ->and($klaimDua->IdJurnalBatal)->not->toBeNull();

        // Akrual (v1.93): jurnal klaim saat penjualan Dr Piutang Klaim Pemasok / Cr HPP per outlet; void membaliknya.
        $idPiutang = BantuanJurnal::IdAkunPeran(PeranAkun::PiutangKlaimPemasok);
        $idHpp = BantuanJurnal::IdAkunPeran(PeranAkun::Hpp);
        $barisKlaim = fn (?int $idJurnal): array => JurnalDetail::query()->where('IdJurnal', $idJurnal)->orderBy('Urutan')->get()
            ->map(fn (JurnalDetail $d): array => [$d->IdAkun, $d->IdOutlet, (string) $d->Debit, (string) $d->Kredit])->all();
        $klaimSatu = KlaimPromoPemasok::query()->where('IdPenjualan', '!=', $jualDua->Id)->sole();
        expect($barisKlaim($klaimSatu->IdJurnal))->toBe([
            [$idPiutang, $k['Outlet']->Id, '4620.00', '0.00'],
            [$idHpp, $k['Outlet']->Id, '0.00', '4620.00'],
        ])
            ->and($barisKlaim($klaimDua->IdJurnalBatal))->toBe([
                [$idHpp, $k['Outlet']->Id, '4620.00', '0.00'],
                [$idPiutang, $k['Outlet']->Id, '0.00', '4620.00'],
            ])
            ->and((string) JurnalDetail::query()->where('IdAkun', $idPiutang)->sum('Debit'))->toBe('9240.00')
            ->and((string) JurnalDetail::query()->where('IdAkun', $idPiutang)->sum('Kredit'))->toBe('4620.00')
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);

        // Diskon penjualan tetap penuh 7.700 di jurnal penjualan (kontra pendapatan).
        $jualSatu = Penjualan::query()->where('Uuid', $satu['Uuid'])->sole();
        $jurnal = Jurnal::query()->where('JenisSumber', JenisSumberJurnal::Penjualan->value)->where('IdSumber', $jualSatu->Id)->where('KunciSumber', 'Utama')->firstOrFail();
        expect((string) JurnalDetail::query()->where('IdJurnal', $jurnal->Id)->where('IdAkun', BantuanJurnal::IdAkunPeran(PeranAkun::DiskonPenjualan))->sum('Debit'))->toBe('7700.00');
    });

    it('penerimaan klaim: semua klaim terbuka ≤ tanggal diterima, jurnal Dr kas/bank Cr piutang klaim seimbang. Halaman & izin', function (): void {
        $k = SiapkanPromoPemasok($this);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemPromoPemasok($k), ItemPromoPemasok($k)]))->toBe([['Diterima', null], ['Diterima', null]]);

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->get('/kelola/promo/klaim-pemasok')->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Promo/KlaimPemasok')
            ->where('Terbuka.0.NamaPemasok', 'PT Kopi Nusantara Distribusi')
            ->where('Terbuka.0.JumlahTransaksi', 2)
            ->where('Terbuka.0.Total', '9240.00')
            ->where('Izin.Terima', true));

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
        $hariIni = BantuanPembelian::Hari()->toDateString();
        $isian = ['UuidPemasok' => $k['Pemasok']->Uuid, 'Tanggal' => $hariIni, 'Cara' => 'KasBank', 'UuidAkunKasBank' => $kas->Uuid, 'Keterangan' => 'Transfer klaim Oktober'];

        $this->post('/kelola/promo/klaim-pemasok/penerimaan', [...$isian, 'UuidAkunKasBank' => ''])->assertSessionHasErrors('UuidAkunKasBank');
        $this->post('/kelola/promo/klaim-pemasok/penerimaan', $isian)->assertRedirect('/kelola/promo/klaim-pemasok');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $penerimaan = PenerimaanKlaimPemasok::query()->sole();
        $jurnal = Jurnal::query()->whereKey($penerimaan->IdJurnal)->firstOrFail();
        $baris = JurnalDetail::query()->where('IdJurnal', $jurnal->Id)->orderBy('Urutan')->get()
            ->map(fn (JurnalDetail $d): array => [$d->IdAkun, (string) $d->Debit, (string) $d->Kredit])->all();
        expect((string) $penerimaan->Jumlah)->toBe('9240.00')
            ->and($jurnal->JenisSumber)->toBe(JenisSumberJurnal::PenerimaanKlaimPemasok)
            ->and($baris)->toBe([
                [$kas->Id, '9240.00', '0.00'],
                [BantuanJurnal::IdAkunPeran(PeranAkun::PiutangKlaimPemasok), '0.00', '9240.00'],
            ])
            ->and((string) JurnalDetail::query()->where('IdAkun', BantuanJurnal::IdAkunPeran(PeranAkun::PiutangKlaimPemasok))->selectRaw('SUM(Debit) - SUM(Kredit) AS Saldo')->value('Saldo'))->toBe('0.00')
            ->and(KlaimPromoPemasok::query()->where('Status', StatusKlaimPromo::Diterima->value)->count())->toBe(2)
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);

        // Tidak ada lagi klaim terbuka.
        $this->post('/kelola/promo/klaim-pemasok/penerimaan', $isian)->assertSessionHasErrors('Tanggal');

        // Kasir tanpa izin pelanggan.lihat tidak bisa membuka halaman klaim.
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/promo/klaim-pemasok')->assertForbidden();
    });

    it('tenant lama tanpa peran Piutang Klaim Pemasok: akun 1-1460 & pemetaan dibuat otomatis. Klaim lama tanpa jurnal dikredit ke HPP saat diterima', function (): void {
        $k = SiapkanPromoPemasok($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        // Simulasi tenant yang menerapkan template sebelum v1.93.
        PemetaanAkun::query()->where('Kunci', PeranAkun::PiutangKlaimPemasok->value)->delete();
        Akun::query()->where('Kode', '1-1460')->delete();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemPromoPemasok($k)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $akun = Akun::query()->where('Kode', '1-1460')->sole();
        expect($akun->Nama)->toBe('Piutang Klaim Promosi Pemasok')
            ->and(BantuanJurnal::IdAkunPeran(PeranAkun::PiutangKlaimPemasok))->toBe($akun->Id)
            ->and(KlaimPromoPemasok::query()->sole()->IdJurnal)->not->toBeNull();

        // Klaim sebelum v1.93 (belum berjurnal akrual).
        $lama = KlaimPromoPemasok::query()->sole()->replicate(['Uuid']);
        $lama->IdPenjualan = 999999;
        $lama->IdOutlet = null;
        $lama->IdJurnal = null;
        $lama->save();

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kas = Akun::query()->where('KasBank', true)->orderBy('Kode')->firstOrFail();
        $this->post('/kelola/promo/klaim-pemasok/penerimaan', [
            'UuidPemasok' => $k['Pemasok']->Uuid, 'Tanggal' => BantuanPembelian::Hari()->toDateString(), 'Cara' => 'KasBank', 'UuidAkunKasBank' => $kas->Uuid,
        ])->assertRedirect('/kelola/promo/klaim-pemasok');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $baris = JurnalDetail::query()->where('IdJurnal', PenerimaanKlaimPemasok::query()->sole()->IdJurnal)->orderBy('Urutan')->get()
            ->map(fn (JurnalDetail $d): array => [$d->IdAkun, $d->IdOutlet, (string) $d->Debit, (string) $d->Kredit])->all();
        expect($baris)->toBe([
            [$kas->Id, null, '9240.00', '0.00'],
            [$akun->Id, $k['Outlet']->Id, '0.00', '4620.00'],
            [BantuanJurnal::IdAkunPeran(PeranAkun::Hpp), null, '0.00', '4620.00'],
        ])->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);
    });

    it('bagian 4e potong hutang: klaim mengurangi faktur terbuka paling lama (Dr Hutang Usaha, Cr piutang klaim). Hutang kurang ditolak. Tidak bisa dibatalkan', function (): void {
        $k = SiapkanPromoPemasok($this);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [ItemPromoPemasok($k), ItemPromoPemasok($k)]))->toBe([['Diterima', null], ['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $idPemilik = $k['Pemilik']->Id;
        $hariIni = BantuanPembelian::Hari()->toDateString();
        $isian = ['UuidPemasok' => $k['Pemasok']->Uuid, 'Tanggal' => $hariIni, 'Cara' => 'PotongHutang', 'Keterangan' => 'Nota debit klaim Oktober'];

        // Faktur 1: Rp 6.000 (lama), faktur 2: Rp 30.000.
        $faktur1 = BantuanPembelian::Fakturkan($k['Pemasok'], [BantuanPembelian::TerimaTanpaPo($k['Pemasok'], $k['Gudang'], [[$k['Produk'], '1', '6000']], $idPemilik, tanggal: BantuanPembelian::Hari(3))], $idPemilik, tanggal: BantuanPembelian::Hari(3));

        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        // Total klaim 9.240 > sisa hutang 6.000: ditolak, klaim tetap terbuka.
        $this->post('/kelola/promo/klaim-pemasok/penerimaan', $isian)->assertSessionHasErrors('Cara');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(KlaimPromoPemasok::query()->where('Status', StatusKlaimPromo::Terbuka->value)->count())->toBe(2);

        $faktur2 = BantuanPembelian::Fakturkan($k['Pemasok'], [BantuanPembelian::TerimaTanpaPo($k['Pemasok'], $k['Gudang'], [[$k['Produk'], '1', '30000']], $idPemilik, tanggal: BantuanPembelian::Hari(1))], $idPemilik, tanggal: BantuanPembelian::Hari(1));
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->get('/kelola/promo/klaim-pemasok')->assertInertia(fn (AssertableInertia $h) => $h->where('Terbuka.0.SisaHutang', '36000.00'));
        $this->post('/kelola/promo/klaim-pemasok/penerimaan', $isian)->assertRedirect('/kelola/promo/klaim-pemasok');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $penerimaan = PenerimaanKlaimPemasok::query()->sole();
        $pembayaran = PembayaranHutang::query()->whereKey($penerimaan->IdPembayaranHutang)->sole();
        expect($penerimaan->Cara)->toBe(CaraPenerimaanKlaim::PotongHutang)
            ->and($penerimaan->IdAkunKasBank)->toBeNull()
            ->and($pembayaran->Kompensasi)->toBeTrue()
            ->and((string) $pembayaran->Jumlah)->toBe('9240.00')
            ->and($penerimaan->IdJurnal)->toBe($pembayaran->IdJurnal)
            // Faktur terlama lunas dulu (6.000), sisanya 3.240 ke faktur berikutnya.
            ->and((string) $faktur1->refresh()->JumlahDibayar)->toBe('6000.00')
            ->and($faktur1->Status)->toBe(StatusFakturPembelian::Lunas)
            ->and((string) $faktur2->refresh()->JumlahDibayar)->toBe('3240.00')
            ->and($faktur2->Status)->toBe(StatusFakturPembelian::DibayarSebagian);

        $baris = JurnalDetail::query()->where('IdJurnal', $pembayaran->IdJurnal)->orderBy('Urutan')->get()
            ->map(fn (JurnalDetail $d): array => [$d->IdAkun, (string) $d->Debit, (string) $d->Kredit])->all();
        $idHutang = BantuanJurnal::IdAkunPeran(PeranAkun::HutangUsaha);
        $idPiutang = BantuanJurnal::IdAkunPeran(PeranAkun::PiutangKlaimPemasok);
        // Baris hutang satu outlet digabung oleh PostingJurnal.
        expect($baris)->toBe([
            [$idHutang, '9240.00', '0.00'],
            [$idPiutang, '0.00', '9240.00'],
        ])
            ->and(BantuanPembelian::SaldoPeran($k['Tenant']->Id, PeranAkun::PiutangKlaimPemasok))->toBe('0.00')
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($k['Tenant']->Id))->toBe([]);

        // Pembayaran hasil kompensasi tidak bisa dibatalkan dari halaman hutang.
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $this->post("/kelola/pembelian/pembayaran/{$pembayaran->Uuid}/batalkan", ['Alasan' => 'Salah input pembayaran'])
            ->assertSessionHasErrors();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect($pembayaran->refresh()->Status)->toBe(StatusDokumenTerposting::Diposting);
    });

    it('formulir promo: bagian pemasok 0–100%, bagian > 0 wajib pemasok. Tersimpan di promo', function (): void {
        $k = SiapkanPromoPemasok($this);
        BantuanPersediaan::MasukSebagai($this, $k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $isian = [
            'Kode' => 'SUSU20', 'Nama' => 'Susu UHT 20% didanai pabrik', 'Prioritas' => 0, 'Eksklusif' => false,
            'TanggalMulai' => null, 'TanggalSelesai' => null, 'Kuota' => null, 'Hari' => [], 'JamMulai' => null, 'JamSelesai' => null,
            'Outlet' => [], 'Kanal' => [], 'Tier' => [], 'MinimalSubtotal' => '0', 'JenisKondisi' => 'Semua', 'UuidKondisi' => [],
            'JumlahMinimal' => '0', 'JenisAksi' => 'DiskonPersenPesanan', 'Persen' => '20',
            'UuidPemasok' => $k['Pemasok']->Uuid, 'PersenDanaPemasok' => '100',
        ];

        $this->post('/kelola/promo', [...$isian, 'UuidPemasok' => null, 'PersenDanaPemasok' => '50'])->assertSessionHasErrors('UuidPemasok');
        $this->post('/kelola/promo', [...$isian, 'PersenDanaPemasok' => '150'])->assertSessionHasErrors('PersenDanaPemasok');
        $this->post('/kelola/promo', [...$isian, 'UuidPemasok' => '01K5ZZZZZZZZZZZZZZZZZZZZZZ'])->assertSessionHasErrors('UuidPemasok');
        $this->post('/kelola/promo', $isian)->assertRedirect('/kelola/promo');

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $promo = Promo::query()->where('Kode', 'SUSU20')->sole();
        expect($promo->IdPemasok)->toBe($k['Pemasok']->Id)
            ->and((string) $promo->PersenDanaPemasok)->toBe('100.00');
        $this->get("/kelola/promo/{$promo->Uuid}/ubah")->assertOk()->assertInertia(fn (AssertableInertia $h) => $h
            ->where('Promo.UuidPemasok', $k['Pemasok']->Uuid)
            ->where('Promo.PersenDanaPemasok', '100.00')
            ->where('OpsiPemasok.0.Label', 'PT Kopi Nusantara Distribusi'));
    });
});
