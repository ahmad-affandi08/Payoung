<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Sinkron\Enum\StatusItemSinkron;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Model\Produk;
use App\Domain\Katalog\PaketProduk\Aksi\SimpanKomponenPaket;
use App\Domain\Katalog\PaketProduk\Data\DataKomponenPaket;
use App\Domain\Katalog\Pilihan\Model\Pilihan;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Organisasi\Enum\StatusKeanggotaan;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\TenantPengguna;
use App\Domain\Pajak\Enum\CakupanPajak;
use App\Domain\Pajak\Enum\KategoriJenisPajak;
use App\Domain\Pajak\Model\JenisPajak;
use App\Domain\Penjualan\Aksi\TerimaPenjualanPos;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Layanan\PenyusunJurnalPenjualan;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\PenjualanPajak;
use App\Domain\Penjualan\Model\PenjualanPembayaran;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Kasir\BantuanKasir;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Katalog\BantuanKomposisi;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Organisasi\BantuanPerangkat;
use Tests\Pendukung\PanduanAwal\BantuanPanduanAwal;
use Tests\Pendukung\Penjualan\BantuanPenjualan;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Saldo (debit − kredit) jurnal pada akun peran tertentu (dimensi outlet).
 */
function SaldoPeranJurnalPenjualan(int $idJurnal, PeranAkun $peran, int $idOutlet): string
{
    $idAkun = app(PenentuAkun::class)->AmbilIdAkun($peran, $idOutlet);
    $baris = JurnalDetail::query()->where('IdJurnal', $idJurnal)->where('IdAkun', $idAkun)->get();
    $saldo = Kuantitas::Nol();

    foreach ($baris as $b) {
        $saldo = $saldo->Tambah(Kuantitas::Dari($b->Debit))->Kurangi(Kuantitas::Dari($b->Kredit));
    }

    return (string) $saldo->KeDesimal()->toScale(2);
}

function SaldoStokPenjualan(int $idProduk, int $idGudang): ?string
{
    return SaldoStok::query()->where('IdProduk', $idProduk)->where('IdGudang', $idGudang)->value('JumlahTersedia');
}

describe('F-07b penjualan lewat sinkron (Penjualan.Buat): diterima & tersimpan', function (): void {
    it('penjualan PPN 12% (DPP 11/12) split QRIS + tunai: dokumen, baris, pajak, pembayaran, stok, jurnal J-07.1/J-07.2, riwayat & audit tercatat; invariant terjaga', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa Antar Belanja Dalam Kota', 'Jenis' => JenisProduk::Jasa], '10000.00');
        // PRD v1.46 (a): snapshot pajak sesuai profil pajak outlet (PKP) & kelompok pajak produk → tidak perlu ditinjau.
        BantuanPenjualan::AturProfilPajak($k, pkp: true);
        BantuanPenjualan::PasangKelompokPajak('Uji kasir: barang kena PPN', ['Ppn' => 'Subtotal'], $minyak, $jasa);
        $dibuat = now()->subMinutes(7)->toImmutable();

        $item = BantuanPenjualan::Item($k, [
            'DibuatPada' => $dibuat,
            'Pajak' => [['Ppn', '12.000000', 11, 12]],
            'Baris' => [
                ['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '38500.00'],
                ['Produk' => $jasa, 'Jumlah' => '1', 'Harga' => '10000.00'],
            ],
            'Pembayaran' => [
                ['Metode' => $k['Qris'], 'Jumlah' => '50000.00', 'Referensi' => 'QR-88123'],
                ['Metode' => $k['Tunai'], 'Jumlah' => '50000.00'],
            ],
        ]);

        expect($item['Data']['Ringkasan'])->toBe(['Subtotal' => '87000.00', 'TotalPajak' => '9570.00', 'Pembulatan' => '0.00', 'TotalAkhir' => '96570.00', 'Kembalian' => '3430.00']);

        $respons = BantuanKasir::Kirim($this, $k['Token'], [$item])->assertOk();
        expect($respons->json('Hasil'))->toBe([['Uuid' => $item['Uuid'], 'Jenis' => 'Penjualan.Buat', 'Status' => 'Diterima', 'Galat' => null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        expect($p->Status)->toBe(StatusPenjualan::Lunas)
            ->and($p->Nomor)->toBe($item['Data']['Nomor'])
            ->and($p->IdPengguna)->toBe($k['Kasir']->Id)
            ->and($p->IdOutlet)->toBe($k['Outlet']->Id)
            ->and($p->IdPerangkat)->toBe($k['Perangkat']->Id)
            ->and($p->Subtotal)->toBe('87000.00')
            ->and($p->TotalPajak)->toBe('9570.00')
            ->and($p->TotalAkhir)->toBe('96570.00')
            ->and($p->TotalDibayar)->toBe('100000.00')
            ->and($p->Kembalian)->toBe('3430.00')
            ->and($p->TotalHpp)->toBe('60000.00')
            ->and($p->PerluTinjauan)->toBeFalse()
            ->and($p->AlasanTinjauan)->toBeNull()
            ->and($p->DibuatOfflinePada->toIso8601String())->toBe(Carbon::parse($dibuat)->utc()->startOfSecond()->toIso8601String())
            ->and($p->DiterimaPada)->not->toBeNull()
            ->and($p->IdJurnal)->not->toBeNull();

        $detail = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->orderBy('Urutan')->get();
        expect($detail)->toHaveCount(2)
            ->and($detail[0]->Bruto)->toBe('77000.00')
            ->and($detail[0]->JumlahPajak)->toBe('8470.00')
            ->and($detail[0]->PajakEksklusif)->toBe('8470.00')
            ->and($detail[0]->TotalBaris)->toBe('85470.00')
            ->and($detail[0]->HppSatuan)->toBe('30000.000000')
            ->and($detail[0]->TotalHpp)->toBe('60000.00')
            ->and($detail[0]->SnapshotPajak)->toEqual([['Kode' => 'Ppn', 'Tarif' => '12.000000', 'PengaliDppPembilang' => 11, 'PengaliDppPenyebut' => 12, 'DasarPengenaan' => 'Subtotal', 'KenaBiayaKirim' => false]])
            ->and($detail[1]->JumlahPajak)->toBe('1100.00')
            ->and($detail[1]->TotalHpp)->toBe('0.00');

        $pajak = PenjualanPajak::query()->where('IdPenjualan', $p->Id)->sole();
        expect($pajak->KodeJenisPajak)->toBe('Ppn')
            ->and($pajak->Dpp)->toBe('79750.00')
            ->and($pajak->Jumlah)->toBe('9570.00');

        $bayar = PenjualanPembayaran::query()->where('IdPenjualan', $p->Id)->orderBy('Urutan')->get();
        expect($bayar->pluck('Jumlah')->all())->toBe(['50000.00', '50000.00'])
            ->and($bayar[0]->Referensi)->toBe('QR-88123');

        $mutasi = MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::Penjualan->value)->where('IdReferensi', $p->Id)->sole();
        expect($mutasi->Jumlah)->toBe('-2.0000')
            ->and($mutasi->IdGudang)->toBe($k['Gudang']->Id)
            ->and($mutasi->IdReferensiDetail)->toBe($detail[0]->Id)
            ->and(SaldoStokPenjualan($minyak->Id, $k['Gudang']->Id))->toBe('8.0000');

        $j = (int) $p->IdJurnal;
        $o = $k['Outlet']->Id;
        expect(Jurnal::query()->findOrFail($j)->JenisSumber)->toBe(JenisSumberJurnal::Penjualan)
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::PiutangPencairan, $o))->toBe('50000.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::KasOutlet, $o))->toBe('46570.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::Penjualan, $o))->toBe('-77000.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::PendapatanJasa, $o))->toBe('-10000.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::PpnKeluaran, $o))->toBe('-9570.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::Hpp, $o))->toBe('60000.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::PersediaanBarangDagang, $o))->toBe('-60000.00')
            ->and(RiwayatStatusDokumen::query()->where('JenisDokumen', 'Penjualan')->where('IdDokumen', $p->Id)->value('StatusKe'))->toBe('Lunas')
            ->and(LogAudit::query()->where('Peristiwa', 'penjualan.terima')->where('IdPengguna', $k['Kasir']->Id)->count())->toBe(1)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('F&B harga termasuk PBJT 10% + biaya layanan 5% (masuk DPP) dan pembulatan tunai ke bawah Rp 100: jurnal seimbang, pembulatan negatif di debit Pendapatan Lain', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $k['Outlet']->forceFill(['KodeKota' => '33.72'])->save();
        BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '33.72', '10.000000', true);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Es Kopi Susu Gula Aren Ukuran Besar', 'Jenis' => JenisProduk::NonStok], '27500.00');
        BantuanPenjualan::AturProfilPajak($k, pungutPbjt: true, hargaTermasukPajak: true, persenBiayaLayanan: '5.00');
        BantuanPenjualan::AturPembulatanTunai($k, [100, 'Bawah']);
        BantuanPenjualan::PasangKelompokPajak('Uji kasir: makan & minum PBJT', ['PbjtMakananMinuman' => 'SubtotalPlusLayanan'], $kopi);

        $item = BantuanPenjualan::Item($k, [
            'HargaTermasukPajak' => true,
            'PersenBiayaLayanan' => '5',
            'PembulatanTunai' => [100, 'Bawah'],
            'Pajak' => [['PbjtMakananMinuman', '10.000000', 1, 1, 'SubtotalPlusLayanan']],
            'Baris' => [['Produk' => $kopi, 'Jumlah' => '3', 'Harga' => '27500.00']],
            'Pembayaran' => [['Metode' => $k['Tunai'], 'Jumlah' => '100000.00']],
        ]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        $j = (int) $p->IdJurnal;
        $o = $k['Outlet']->Id;
        expect($p->Pembulatan)->toBe($item['Data']['Ringkasan']['Pembulatan'])
            ->and($p->PerluTinjauan)->toBeFalse()
            ->and(str_starts_with($p->Pembulatan, '-'))->toBeTrue()
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::KasOutlet, $o))->toBe($p->TotalAkhir)
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::PendapatanLain, $o))->toBe(ltrim($p->Pembulatan, '-'))
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::PendapatanBiayaLayanan, $o))->toBe('-'.$p->BiayaLayanan)
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::HutangPbjt, $o))->toBe('-'.$p->TotalPajak)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('F-17 bagian 3 ongkir Rp 25.000 ikut DPP PPN (KenaBiayaKirim): kolom BiayaKirim/DiskonKirim & bagian ongkir per baris tersimpan, Cr Pendapatan Pengiriman kotor, jurnal seimbang', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $beras = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Beras Premium Kemasan Karung 5 Kilogram', '10', '60000', '75000.00');
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Minyak Goreng Kelapa Sawit Kemasan 2 Liter', '10', '40000', '50000.00');
        BantuanPenjualan::AturProfilPajak($k, pkp: true);
        BantuanPenjualan::PasangKelompokPajak('Uji kasir: ongkir kena PPN', ['Ppn' => ['Subtotal', true]], $beras, $minyak);

        // Sama dengan vektor bersama RTL-ONGKIR-PPN-001: DPP (200.000 + 25.000) x 11/12 = 206.250, PPN 24.750.
        $item = BantuanPenjualan::Item($k, [
            'Pajak' => [['Ppn', '12.000000', 11, 12, 'Subtotal', true]],
            'BiayaKirim' => '25000.00',
            'DiskonKirim' => '0.00',
            'Baris' => [
                ['Produk' => $beras, 'Jumlah' => '2', 'Harga' => '75000.00'],
                ['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '50000.00'],
            ],
            'Pembayaran' => [['Metode' => $k['Tunai'], 'Jumlah' => '250000.00']],
        ]);

        expect($item['Data']['Ringkasan'])->toBe(['Subtotal' => '200000.00', 'TotalPajak' => '24750.00', 'Pembulatan' => '0.00', 'TotalAkhir' => '249750.00', 'Kembalian' => '250.00']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        $detail = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->orderBy('Urutan')->get();
        $j = (int) $p->IdJurnal;
        $o = $k['Outlet']->Id;

        expect($p->BiayaKirim)->toBe('25000.00')
            ->and($p->DiskonKirim)->toBe('0.00')
            ->and($p->TotalAkhir)->toBe('249750.00')
            ->and($p->PerluTinjauan)->toBeFalse()
            // Ongkir dibagi sebanding netto 3:1, dan ikut TotalBaris.
            ->and($detail->pluck('BiayaKirim')->all())->toBe(['18750.00', '6250.00'])
            ->and($detail->pluck('TotalBaris')->all())->toBe(['187312.50', '62437.50'])
            ->and(PenjualanPajak::query()->where('IdPenjualan', $p->Id)->value('Dpp'))->toBe('206250.00')
            ->and(PenjualanPajak::query()->where('IdPenjualan', $p->Id)->value('KenaBiayaKirim'))->toBeTrue()
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::KasOutlet, $o))->toBe('249750.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::PendapatanPengiriman, $o))->toBe('-25000.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::Penjualan, $o))->toBe('-200000.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::PpnKeluaran, $o))->toBe('-24750.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::DiskonPenjualan, $o))->toBe('0.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('F-17 bagian 3 gratis ongkir: DiskonKirim = BiayaKirim, ongkir netto nol tidak menambah DPP, ongkir tetap kotor di Pendapatan Pengiriman dan diskonnya di Diskon Penjualan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $beras = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Beras Premium Kemasan Karung 5 Kilogram', '10', '60000', '75000.00');
        BantuanPenjualan::AturProfilPajak($k, pkp: true);
        BantuanPenjualan::PasangKelompokPajak('Uji kasir: ongkir kena PPN', ['Ppn' => ['Subtotal', true]], $beras);

        $item = BantuanPenjualan::Item($k, [
            'Pajak' => [['Ppn', '12.000000', 11, 12, 'Subtotal', true]],
            'BiayaKirim' => '25000.00',
            'DiskonKirim' => '25000.00',
            'Baris' => [['Produk' => $beras, 'Jumlah' => '2', 'Harga' => '75000.00']],
            'Pembayaran' => [['Metode' => $k['Tunai'], 'Jumlah' => '166500.00']],
        ]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        $j = (int) $p->IdJurnal;
        $o = $k['Outlet']->Id;

        expect($p->BiayaKirim)->toBe('25000.00')
            ->and($p->DiskonKirim)->toBe('25000.00')
            // 150.000 x 11/12 x 12% = 16.500: ongkir netto nol tidak masuk DPP.
            ->and($p->TotalPajak)->toBe('16500.00')
            ->and($p->TotalAkhir)->toBe('166500.00')
            ->and(PenjualanDetail::query()->where('IdPenjualan', $p->Id)->value('BiayaKirim'))->toBe('0.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::PendapatanPengiriman, $o))->toBe('-25000.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::DiskonPenjualan, $o))->toBe('25000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('F-17 bagian 3 ongkir tanpa KenaBiayaKirim: ongkir masuk TotalAkhir tetapi tidak menambah DPP pajak', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $beras = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Beras Premium Kemasan Karung 5 Kilogram', '10', '60000', '75000.00');
        BantuanPenjualan::AturProfilPajak($k, pkp: true);
        BantuanPenjualan::PasangKelompokPajak('Uji kasir: ongkir bebas PPN', ['Ppn' => 'Subtotal'], $beras);

        $item = BantuanPenjualan::Item($k, [
            'Pajak' => [['Ppn', '12.000000', 11, 12]],
            'BiayaKirim' => '25000.00',
            'Baris' => [['Produk' => $beras, 'Jumlah' => '2', 'Harga' => '75000.00']],
            'Pembayaran' => [['Metode' => $k['Tunai'], 'Jumlah' => '191500.00']],
        ]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->where('Uuid', $item['Uuid'])->sole();

        expect($p->TotalPajak)->toBe('16500.00')
            ->and($p->TotalAkhir)->toBe('191500.00')
            ->and($p->BiayaKirim)->toBe('25000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('F-17 bagian 3: perangkat versi lama tanpa BiayaKirim tetap diterima dengan ongkir nol (CLAUDE.md #16); DiskonKirim melebihi BiayaKirim = DataTidakValid', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $beras = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);

        $lama = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $beras, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        expect(array_key_exists('BiayaKirim', $lama['Data']))->toBeFalse();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lama]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $lama['Uuid'])->value('BiayaKirim'))->toBe('0.00');

        $tidakSah = BantuanPenjualan::Item($k, [
            'BiayaKirim' => '10000.00',
            'Baris' => [['Produk' => $beras, 'Jumlah' => '1', 'Harga' => '38500.00']],
        ], ['DiskonKirim' => '15000.00']);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$tidakSah]))->toBe([['Ditolak', 'DataTidakValid']]);
    });
});

describe('F-07b idempotensi & isolasi', function (): void {
    it('BR-07.6 Uuid sama dikirim ulang = Duplikat tanpa dokumen/stok/jurnal ganda; batch dikirim ulang seluruhnya Duplikat; Uuid sama dengan nomor lain = UuidSudahDipakai', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $satu = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        $dua = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '3', 'Harga' => '38500.00']]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$satu, $dua]))->toBe([['Diterima', null], ['Diterima', null]])
            ->and(BantuanKasir::KirimRingkas($this, $k['Token'], [$satu, $dua]))->toBe([['Duplikat', null], ['Duplikat', null]]);

        $lain = $satu;
        $lain['Data']['Nomor'] = BantuanPenjualan::Nomor($k, 9001);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$lain]))->toBe([['Ditolak', 'UuidSudahDipakai']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->count())->toBe(2)
            ->and(MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::Penjualan->value)->count())->toBe(2)
            ->and(Jurnal::query()->where('JenisSumber', JenisSumberJurnal::Penjualan->value)->count())->toBe(2)
            ->and(SaldoStokPenjualan($minyak->Id, $k['Gudang']->Id))->toBe('6.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('isolasi tenant: produk, shift, dan metode bayar tenant lain tidak bisa dirujuk; Uuid penjualan tenant lain = UuidSudahDipakai', function (): void {
        $a = BantuanPenjualan::Siapkan($this, 'Toko Kelontong Berkah Solo');
        $minyakA = BantuanPenjualan::BuatProdukBerstok($a['Gudang'], $a['Pemilik']->Id);
        $itemA = BantuanPenjualan::Item($a, ['Baris' => [['Produk' => $minyakA, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        expect(BantuanKasir::KirimRingkas($this, $a['Token'], [$itemA]))->toBe([['Diterima', null]]);

        $b = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        $minyakB = BantuanPenjualan::BuatProdukBerstok($b['Gudang'], $b['Pemilik']->Id);

        expect(BantuanKasir::KirimRingkas($this, $b['Token'], [
            BantuanPenjualan::Item($b, ['Baris' => [['Produk' => $minyakA, 'Jumlah' => '1', 'Harga' => '38500.00']]]),
            BantuanPenjualan::Item($b, ['Baris' => [['Produk' => $minyakB, 'Jumlah' => '1', 'Harga' => '38500.00']]], ['UuidShift' => $a['UuidShift']]),
            BantuanPenjualan::Item($b, ['Baris' => [['Produk' => $minyakB, 'Jumlah' => '1', 'Harga' => '38500.00']], 'Pembayaran' => [['Metode' => $a['Tunai'], 'Jumlah' => '38500.00']]]),
            BantuanPenjualan::Item($b, ['Baris' => [['Produk' => $minyakB, 'Jumlah' => '1', 'Harga' => '38500.00']]], uuid: $itemA['Uuid']),
        ]))->toBe([
            ['Ditolak', 'ProdukTidakDikenal'],
            ['Ditolak', 'ShiftTidakDitemukan'],
            ['Ditolak', 'MetodeBayarTidakDikenal'],
            ['Ditolak', 'UuidSudahDipakai'],
        ]);

        BantuanOrganisasi::AturKonteks($b['Tenant']->Id);
        expect(Penjualan::query()->count())->toBe(0);
        BantuanOrganisasi::AturKonteks($a['Tenant']->Id);
        expect(Penjualan::query()->count())->toBe(1)
            ->and(SaldoStokPenjualan($minyakA->Id, $a['Gudang']->Id))->toBe('9.0000');
    });
});

describe('F-07b aturan penolakan', function (): void {
    it('shift, kasir, nomor (BR-07.1), waktu: ShiftTidakDitemukan, KasirTidakDitemukan (bukan anggota tenant), NomorTidakValid, NomorSudahDipakai, WaktuTidakValid; kasir yang izin/outletnya berubah setelah transaksi offline diterima + PerluTinjauan IzinBerubah (PRD v1.46)', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $gudang = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::StafGudang);
        $luarOutlet = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir, semuaOutlet: false);
        $dinonaktifkan = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        TenantPengguna::query()->where('IdTenant', $k['Tenant']->Id)->where('IdPengguna', $dinonaktifkan->Id)->update(['Status' => StatusKeanggotaan::Nonaktif->value]);
        $lain = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]];
        $pertama = BantuanPenjualan::Item($k, $baris);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            $pertama,
            BantuanPenjualan::Item($k, $baris, ['UuidShift' => BantuanKasir::Uuid()]),
            $diLuarOutlet = BantuanPenjualan::Item($k, $baris + ['Kasir' => $luarOutlet]),
            $tanpaIzin = BantuanPenjualan::Item($k, $baris + ['Kasir' => $gudang]),
            $nonaktif = BantuanPenjualan::Item($k, $baris + ['Kasir' => $dinonaktifkan]),
            BantuanPenjualan::Item($k, $baris + ['Kasir' => $lain['Kasir']]),
            BantuanPenjualan::Item($k, $baris, ['UuidPengguna' => BantuanKasir::Uuid()]),
            BantuanPenjualan::Item($k, $baris, ['Nomor' => 'INV/UTAMA/260924/K01-0001']),
            BantuanPenjualan::Item($k, $baris, ['Nomor' => $pertama['Data']['Nomor']]),
            BantuanPenjualan::Item($k, $baris + ['DibuatPada' => now()->addHour()->toImmutable()]),
            BantuanPenjualan::Item($k, $baris + ['DibuatPada' => now()->subDays(2)->toImmutable()]),
        ]))->toBe([
            ['Diterima', null],
            ['Ditolak', 'ShiftTidakDitemukan'],
            ['Diterima', null],
            ['Diterima', null],
            ['Diterima', null],
            ['Ditolak', 'KasirTidakDitemukan'],
            ['Ditolak', 'KasirTidakDitemukan'],
            ['Ditolak', 'NomorTidakValid'],
            ['Ditolak', 'NomorSudahDipakai'],
            ['Ditolak', 'WaktuTidakValid'],
            ['Ditolak', 'WaktuTidakValid'],
        ]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $alasan = fn (array $item): ?string => Penjualan::query()->where('Uuid', $item['Uuid'])->sole()->AlasanTinjauan;
        expect(Penjualan::query()->where('Uuid', $pertama['Uuid'])->sole()->PerluTinjauan)->toBeFalse()
            ->and(Penjualan::query()->where('Uuid', $diLuarOutlet['Uuid'])->sole()->PerluTinjauan)->toBeTrue()
            ->and($alasan($diLuarOutlet))->toBe("IzinBerubah: {$luarOutlet->Nama} tidak lagi terdaftar di outlet ini")
            ->and($alasan($tanpaIzin))->toBe("IzinBerubah: {$gudang->Nama} tidak lagi punya izin berjualan")
            ->and($alasan($nonaktif))->toContain('IzinBerubah')
            ->and(Penjualan::query()->where('Uuid', $tanpaIzin['Uuid'])->sole()->IdPengguna)->toBe($gudang->Id)
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('produk: bahan baku, induk varian = ProdukTidakBisaDijual (konsinyasi diterima sejak F-05i: dijual sebagai titipan, J-05.7); seri langsung tanpa nomor seri = NomorSeriTidakSesuai (F-05h) dan seri sebagai bahan resep = PelacakanBelumDidukung, batch diterima (F-05g, tanpa stok batch = ditinjau BatchTidakCukup); produk diarsipkan/dihapus setelah dijual offline tetap diterima (dihapus: stok tidak dikurangi, ditinjau)', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $semua = BantuanPersediaan::BuatProdukSemuaJenis(BantuanKomposisi::Satuan('Pieces', 'pcs', false), BantuanKomposisi::Satuan('Kilogram', 'kg'));
        $induk = BantuanKatalog::BuatProduk(['Nama' => 'Kaos Polos Katun Combed 30s', 'Jenis' => JenisProduk::IndukVarian]);
        $resepBatch = BantuanKomposisi::BuatProdukResep('Susu Segar Karamel Dingin');
        $resepSeri = BantuanKomposisi::BuatProdukResep('Paket Servis Rice Cooker');
        BantuanKomposisi::SimpanResep($resepSeri, [[$semua['Seri'], '1']]);
        BantuanKomposisi::SimpanResep($resepBatch, [[$semua['Batch'], '1']]);
        $diarsipkan = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, 'Sabun Cuci Piring Jeruk Nipis 800 ml', '5', '12000', '15500.00');
        $diarsipkan->forceFill(['DiarsipkanPada' => now(), 'Aktif' => false])->save();
        // Produk hanya bisa dihapus bila belum pernah dipakai (BR-03.2), jadi belum punya stok.
        $dihapus = BantuanKatalog::BuatProduk(['Nama' => 'Pewangi Pakaian Sachet Lavender'], '1500.00');
        $dihapus->delete();

        $item = fn (Produk $p) => BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $p, 'Jumlah' => '1', 'Harga' => '15500.00']]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            $item($semua['BahanBaku']),
            $item($induk),
            $item($semua['Konsinyasi']),
            $item($semua['Batch']),
            $item($semua['Seri']),
            $item($resepBatch),
            $item($resepSeri),
            $item($diarsipkan),
            $itemDihapus = $item($dihapus),
        ]))->toBe([
            ['Ditolak', 'ProdukTidakBisaDijual'],
            ['Ditolak', 'ProdukTidakBisaDijual'],
            ['Diterima', null],
            ['Diterima', null],
            ['Ditolak', 'NomorSeriTidakSesuai'],
            ['Diterima', null],
            ['Ditolak', 'PelacakanBelumDidukung'],
            ['Diterima', null],
            ['Diterima', null],
        ]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(SaldoStokPenjualan($diarsipkan->Id, $k['Gudang']->Id))->toBe('4.0000')
            ->and(SaldoStokPenjualan($dihapus->Id, $k['Gudang']->Id))->toBeNull()
            ->and(Penjualan::query()->where('Uuid', $itemDihapus['Uuid'])->sole()->AlasanTinjauan)->toContain('ProdukDihapus')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('CLAUDE.md #12 tarif pajak snapshot wajib sama dengan TarifPajak terbit yang berlaku: tarif berbeda, pengali berbeda, PBJT tanpa kota outlet = TarifPajakTidakSah', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '33.72', '10.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Tubruk Robusta Temanggung', 'Jenis' => JenisProduk::NonStok], '15000.00');
        $item = fn (array $pajak) => BantuanPenjualan::Item($k, ['Pajak' => [$pajak], 'Baris' => [['Produk' => $kopi, 'Jumlah' => '1', 'Harga' => '15000.00']]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            $item(['Ppn', '11.000000', 1, 1]),
            $item(['Ppn', '12.000000', 1, 1]),
            $item(['PbjtMakananMinuman', '10.000000', 1, 1]),
            $item(['PajakKarangan', '5.000000', 1, 1]),
            $item(['Ppn', '12.000000', 11, 12]),
        ]))->toBe([
            ['Ditolak', 'TarifPajakTidakSah'],
            ['Ditolak', 'TarifPajakTidakSah'],
            ['Ditolak', 'TarifPajakTidakSah'],
            ['Ditolak', 'TarifPajakTidakSah'],
            ['Diterima', null],
        ]);
    });

    it('E7: tarif PPN yang baru berakhir (perangkat belum memperbarui tarif) diterima + PerluTinjauan TarifPajakLama; tarif yang sudah lama berakhir atau karangan tetap TarifPajakTidakSah', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $hari = fn (int $mundur): string => now('Asia/Jakarta')->subDays($mundur)->toDateString();
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '11.000000', berlakuMulai: $hari(730), berlakuSampai: $hari(11));
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '10.000000', berlakuMulai: $hari(1095), berlakuSampai: $hari(200));
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Tubruk Robusta Temanggung', 'Jenis' => JenisProduk::NonStok], '15000.00');
        $item = fn (array $pajak) => BantuanPenjualan::Item($k, ['Pajak' => [$pajak], 'Baris' => [['Produk' => $kopi, 'Jumlah' => '1', 'Harga' => '15000.00']]]);
        $itemLama = $item(['Ppn', '11.000000', 11, 12]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$itemLama, $item(['Ppn', '10.000000', 11, 12]), $item(['Ppn', '9.000000', 11, 12])]))
            ->toBe([['Diterima', null], ['Ditolak', 'TarifPajakTidakSah'], ['Ditolak', 'TarifPajakTidakSah']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $penjualan = Penjualan::query()->where('Uuid', $itemLama['Uuid'])->sole();
        expect($penjualan->PerluTinjauan)->toBeTrue()
            ->and($penjualan->AlasanTinjauan)->toContain('TarifPajakLama')->toContain('berlaku sekarang: 12.000000%')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('BR-07.3 diskon manual (PRD v1.46): kasir tanpa izin atau di atas batas tanpa penyetuju, dan di atas 30% tanpa Pemilik = diterima + PerluTinjauan DiskonMelebihiBatas; supervisor menyetujui sampai 30%; penyetuju tanpa izin setujui = PenyetujuTidakBerwenang', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $pemilik = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        $kasirLain = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Kasir);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Bubuk Robusta Temanggung 250 gram', 'Jenis' => JenisProduk::NonStok], '100000.00');
        $diskon = fn (array $d, array $opsi = []) => BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $kopi, 'Jumlah' => '1', 'Harga' => '100000.00', 'DiskonManual' => $d]]] + $opsi);

        $hasil = BantuanKasir::KirimRingkas($this, $k['Token'], [
            $d1 = $diskon(['Persen' => '5']),
            $d2 = $diskon(['Jumlah' => '15000.00']),
            $d3 = $diskon(['Persen' => '5'], ['Penyetuju' => $k['Supervisor']]),
            $d4 = $diskon(['Persen' => '30'], ['Penyetuju' => $k['Supervisor']]),
            $d5 = $diskon(['Persen' => '30.01'], ['Penyetuju' => $k['Supervisor']]),
            $d6 = $diskon(['Persen' => '80'], ['Penyetuju' => $pemilik]),
            $d7 = $diskon(['Persen' => '20'], ['Kasir' => $k['Supervisor']]),
            $d8 = $diskon(['Persen' => '100'], ['Kasir' => $pemilik]),
            $d9 = $diskon(['Persen' => '5'], ['Penyetuju' => $kasirLain]),
            $d10 = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $kopi, 'Jumlah' => '1', 'Harga' => '100000.00']], 'DiskonManualPesanan' => ['Persen' => '25'], 'Penyetuju' => $k['Supervisor']]),
        ]);

        expect($hasil)->toBe([
            ['Diterima', null],
            ['Diterima', null],
            ['Diterima', null],
            ['Diterima', null],
            ['Diterima', null],
            ['Diterima', null],
            ['Diterima', null],
            ['Diterima', null],
            ['Ditolak', 'PenyetujuTidakBerwenang'],
            ['Diterima', null],
        ]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tinjau = fn (array $item): ?string => Penjualan::query()->where('Uuid', $item['Uuid'])->sole()->AlasanTinjauan;
        expect($tinjau($d1))->toBe("DiskonMelebihiBatas: diskon 5% diberikan tanpa persetujuan, padahal {$k['Kasir']->Nama} tidak punya izin diskon manual")
            ->and($tinjau($d2))->toBe('DiskonMelebihiBatas: diskon 15% melebihi batas kasir 10% tanpa persetujuan supervisor')
            ->and($tinjau($d5))->toBe("DiskonMelebihiBatas: diskon 30,01% melebihi batas persetujuan 30% ({$k['Supervisor']->Nama}); hanya Pemilik yang boleh menyetujui")
            ->and(array_map($tinjau, [$d3, $d4, $d6, $d7, $d8, $d10]))->toBe([null, null, null, null, null, null])
            ->and(Penjualan::query()->where('Uuid', $d5['Uuid'])->sole()->IdPenyetujuDiskon)->toBe($k['Supervisor']->Id);

        $p3 = Penjualan::query()->where('Uuid', $d3['Uuid'])->sole();
        $p10 = Penjualan::query()->where('Uuid', $d10['Uuid'])->sole();
        expect($p3->IdPenyetujuDiskon)->toBe($k['Supervisor']->Id)
            ->and($p3->TotalDiskon)->toBe('5000.00')
            ->and($p10->DiskonPesanan)->toBe('25000.00')
            ->and(SaldoPeranJurnalPenjualan((int) $p3->IdJurnal, PeranAkun::DiskonPenjualan, $k['Outlet']->Id))->toBe('5000.00')
            ->and(Penjualan::query()->where('Uuid', $d8['Uuid'])->sole()->IdJurnal)->not->toBeNull()
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
        unset($d9);
    });

    it('BR-07.3 penyetuju = kasir sendiri (perangkat tanpa dialog PIN): diterima bila kasir ber-izin penjualan.diskon.setujui sampai BatasDiskonPenyetuju (di atasnya diterima + DiskonMelebihiBatas); Pemilik tanpa batas; kasir tanpa izin setujui = PenyetujuTidakBerwenang', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $pemilik = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Pemilik);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Gula Aren Literan', 'Jenis' => JenisProduk::NonStok], '80000.00');
        $diskon = fn (string $persen, $kasir) => BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $kopi, 'Jumlah' => '1', 'Harga' => '80000.00', 'DiskonManual' => ['Persen' => $persen]]], 'Kasir' => $kasir, 'Penyetuju' => $kasir]);

        $hasil = BantuanKasir::KirimRingkas($this, $k['Token'], [
            $sendiri = $diskon('30', $k['Supervisor']),
            $lebih = $diskon('31', $k['Supervisor']),
            $diskon('90', $pemilik),
            $diskon('5', $k['Kasir']),
        ]);

        expect($hasil)->toBe([
            ['Diterima', null],
            ['Diterima', null],
            ['Diterima', null],
            ['Ditolak', 'PenyetujuTidakBerwenang'],
        ]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->where('Uuid', $sendiri['Uuid'])->sole();
        expect($p->IdPenyetujuDiskon)->toBe($k['Supervisor']->Id)
            ->and($p->IdPengguna)->toBe($k['Supervisor']->Id)
            ->and($p->PerluTinjauan)->toBeFalse()
            ->and(Penjualan::query()->where('Uuid', $lebih['Uuid'])->sole()->AlasanTinjauan)->toStartWith('DiskonMelebihiBatas: diskon 31% melebihi batas persetujuan 30%');
    });

    it('batas diskon mengikuti pengaturan kasir tenant (BatasDiskonManual/BatasDiskonPenyetuju)', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $tenant = $k['Tenant']->refresh();
        $tenant->Pengaturan = [...($tenant->Pengaturan ?? []), 'BatasDiskonManual' => '0', 'BatasDiskonPenyetuju' => '15'];
        $tenant->save();
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Teh Melati Tubruk Premium', 'Jenis' => JenisProduk::NonStok], '20000.00');
        $diskon = fn (string $persen) => BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $kopi, 'Jumlah' => '1', 'Harga' => '20000.00', 'DiskonManual' => ['Persen' => $persen]]], 'Penyetuju' => $k['Supervisor']]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$pas = $diskon('15'), $lebih = $diskon('16')]))->toBe([['Diterima', null], ['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->where('Uuid', $pas['Uuid'])->sole()->PerluTinjauan)->toBeFalse()
            ->and(Penjualan::query()->where('Uuid', $lebih['Uuid'])->sole()->AlasanTinjauan)->toStartWith('DiskonMelebihiBatas: diskon 16% melebihi batas persetujuan 15%');
    });

    it('hitung ulang MesinKalkulasi: Ringkasan berbeda = HitunganTidakCocok; harga pilihan tidak sama dengan Σ pilihan = HitunganTidakCocok; harga jual snapshot berbeda dari harga katalog tetap diterima (§18.3)', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '2', 'Harga' => '35000.00']]];
        $salah = BantuanPenjualan::Item($k, $baris);
        $salah['Data']['Ringkasan']['TotalAkhir'] = '70100.00';
        $pilihan = BantuanPenjualan::Item($k, $baris);
        $pilihan['Data']['Baris'][0]['Pilihan'] = [['UuidPilihan' => BantuanKasir::Uuid(), 'Nama' => 'Bungkus kado', 'Harga' => '2000.00']];

        $respons = BantuanKasir::Kirim($this, $k['Token'], [$salah, $pilihan, BantuanPenjualan::Item($k, $baris)])->assertOk();

        expect($respons->json('Hasil.0.Galat.Kode'))->toBe('HitunganTidakCocok')
            ->and($respons->json('Hasil.0.Galat.Detail'))->toBe(['Perangkat' => '70100.00', 'Server' => '70000.00'])
            ->and($respons->json('Hasil.1.Galat.Kode'))->toBe('HitunganTidakCocok')
            ->and($respons->json('Hasil.2.Status'))->toBe('Diterima');
    });

    it('pembayaran fase 1: metode lain = MetodeBayarBelumDidukung; dua tunai atau non-tunai melebihi total = PembayaranTidakValid; kurang = PembayaranKurang; metode nonaktif setelah transaksi offline tetap diterima', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $nonaktif = BantuanPenjualan::BuatMetode(JenisMetodePembayaran::Ewallet, 'GoPay Toko', false);
        // F-12: Tempo sudah didukung; F-16d: Deposit juga; X8: Marketplace (platform ojol). Contoh yang belum didukung kasir = Voucher.
        $deposit = BantuanPenjualan::BuatMetode(JenisMetodePembayaran::Voucher, 'Voucher kertas');
        $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]];

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::Item($k, $baris + ['Pembayaran' => [['Metode' => $deposit, 'Jumlah' => '38500.00']]]),
            BantuanPenjualan::Item($k, $baris + ['Pembayaran' => [['Metode' => $k['Tunai'], 'Jumlah' => '20000.00'], ['Metode' => $k['Tunai'], 'Jumlah' => '20000.00']]]),
            BantuanPenjualan::Item($k, $baris + ['Pembayaran' => [['Metode' => $k['Edc'], 'Jumlah' => '40000.00']]]),
            BantuanPenjualan::Item($k, $baris + ['Pembayaran' => [['Metode' => $k['Transfer'], 'Jumlah' => '30000.00']]]),
            BantuanPenjualan::Item($k, $baris + ['Pembayaran' => [['Metode' => $k['Tunai'], 'Jumlah' => '30000.00']]], ['Ringkasan' => ['Subtotal' => '38500.00', 'TotalPajak' => '0.00', 'Pembulatan' => '0.00', 'TotalAkhir' => '38500.00', 'Kembalian' => '0.00']]),
            BantuanPenjualan::Item($k, $baris + ['Pembayaran' => [['Metode' => $nonaktif, 'Jumlah' => '38500.00']]]),
        ]))->toBe([
            ['Ditolak', 'MetodeBayarBelumDidukung'],
            ['Ditolak', 'PembayaranTidakValid'],
            ['Ditolak', 'PembayaranTidakValid'],
            ['Ditolak', 'PembayaranKurang'],
            ['Ditolak', 'PembayaranKurang'],
            ['Diterima', null],
        ]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->sole();
        expect(SaldoPeranJurnalPenjualan((int) $p->IdJurnal, PeranAkun::PiutangPencairan, $k['Outlet']->Id))->toBe('38500.00');
    });

    it('§18/F-15 periode terkunci: penjualan offline diterima dengan tanggal bisnis asli + tinjauan PeriodeTerkunci; jurnal & stok dibukukan hari pertama periode terbuka berikutnya; void juga', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $tanggal = app(TanggalBisnisOutlet::class)->Hitung($k['Outlet']->Id, now()->subMinutes(5));
        BantuanPersediaan::KunciPeriode($tanggal->format('Y-m'));
        $awalBerikutnya = $tanggal->startOfMonth()->addMonthNoOverflow()->toDateString();

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->sole();
        $jurnal = Jurnal::query()->findOrFail($p->IdJurnal);
        expect($p->TanggalBisnis->toDateString())->toBe($tanggal->toDateString())
            ->and($p->PerluTinjauan)->toBeTrue()
            ->and($p->AlasanTinjauan)->toContain('PeriodeTerkunci: periode')
            ->and($p->AlasanTinjauan)->toContain("dibukukan {$awalBerikutnya}")
            ->and($jurnal->Tanggal->toDateString())->toBe($awalBerikutnya)
            ->and($jurnal->Keterangan)->toContain('periodenya terkunci')
            ->and($jurnal->TotalDebit)->toBe($jurnal->TotalKredit)
            ->and(MutasiStok::query()->where('JenisReferensi', JenisReferensiMutasi::Penjualan->value)->sole()->TanggalBisnis->toDateString())->toBe($awalBerikutnya)
            ->and(SaldoStokPenjualan($minyak->Id, $k['Gudang']->Id))->toBe('9.0000');

        // Kirim ulang tetap Duplikat walau periodenya kini terkunci.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Duplikat', null]]);

        // Void offline di periode terkunci: diterima, jurnal pembalik & stok balik di periode terbuka, penjualan ditinjau.
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::ItemVoid($k, $p)]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p->refresh();
        $pembalik = Jurnal::query()->where('IdJurnalDibalik', $jurnal->Id)->sole();
        expect($p->Status)->toBe(StatusPenjualan::Void)
            ->and($p->AlasanTinjauan)->toContain('Void PeriodeTerkunci')
            ->and($pembalik->Tanggal->toDateString())->toBe($awalBerikutnya)
            ->and(SaldoStokPenjualan($minyak->Id, $k['Gudang']->Id))->toBe('10.0000');

        // Transaksi back-office di periode terkunci tetap ditolak.
        expect(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(tanggal: $tanggal->toDateString())))
            ->toThrow(PelanggaranAturanBisnis::class);
    });
});

describe('F-07b stok penjualan (aturan #9–#10)', function (): void {
    it('§18.3 stok tidak cukup tidak menolak: diterima, stok minus, PerluTinjauan StokTidakCukup; invariant SaldoStok = Σ MutasiStok & Σ debit = Σ kredit terjaga', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '2');
        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '5', 'Harga' => '38500.00']]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->sole();
        expect($p->PerluTinjauan)->toBeTrue()
            ->and($p->AlasanTinjauan)->toContain('StokTidakCukup')
            ->and($p->AlasanTinjauan)->toContain('Minyak Goreng Sawit')
            ->and(SaldoStokPenjualan($minyak->Id, $k['Gudang']->Id))->toBe('-3.0000')
            ->and($p->TotalHpp)->toBe('150000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('produk Stok satuan dus (konversi 12), Resep (bahan versi terbaru + susut), Paket (komponen stok + komponen resep), dan pilihan berbahan mengurangi stok satuan dasar; HPP per baris & jurnal per jenis persediaan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id, jumlah: '30');
        $dus = BantuanKomposisi::TambahSatuanProduk($minyak, BantuanKomposisi::Satuan('Dus', 'dus', false), '12');
        $gula = BantuanKomposisi::BuatBahan('Gula Aren Cair Asli Banyumas');
        BantuanPenjualan::IsiStok($k['Gudang'], $gula, '1000', '50', $k['Pemilik']->Id);
        $esKopi = BantuanKomposisi::BuatProdukResep();
        BantuanKomposisi::SimpanResep($esKopi, [[$gula, '15']]);
        BantuanKomposisi::SimpanResep($esKopi, [[$gula, '20', null, '20']]);
        $kelompok = BantuanKomposisi::BuatKelompokPilihan('Tambahan', [['Extra gula aren', '3000', $gula, '10']], 0, 1);
        $extra = Pilihan::query()->where('IdKelompokPilihan', $kelompok->Id)->sole();
        $paket = BantuanKomposisi::BuatPaket('Paket Dapur Hemat Minyak + Es Kopi');
        app(SimpanKomponenPaket::class)->Jalankan($paket, [
            new DataKomponenPaket($minyak->Id, Kuantitas::Dari('1'), null),
            new DataKomponenPaket($esKopi->Id, Kuantitas::Dari('1'), null),
        ]);

        $item = BantuanPenjualan::Item($k, ['Baris' => [
            ['Produk' => $minyak, 'Satuan' => $dus, 'Jumlah' => '1', 'Harga' => '450000.00'],
            ['Produk' => $esKopi, 'Jumlah' => '2', 'Harga' => '25000.00', 'Pilihan' => [$extra]],
            ['Produk' => $paket, 'Jumlah' => '1', 'Harga' => '60000.00'],
        ]]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->sole();
        $detail = PenjualanDetail::query()->where('IdPenjualan', $p->Id)->orderBy('Urutan')->get();
        $j = (int) $p->IdJurnal;
        $o = $k['Outlet']->Id;

        expect($detail[0]->JumlahDasar)->toBe('12.0000')
            ->and($detail[0]->KonversiKeDasar)->toBe('12.0000')
            ->and($detail[0]->TotalHpp)->toBe('360000.00')
            ->and($detail[0]->HppSatuan)->toBe('360000.000000')
            ->and($detail[1]->TotalHpp)->toBe('3500.00')
            ->and($detail[1]->HargaPilihan)->toBe('3000.00')
            ->and($detail[1]->Bruto)->toBe('56000.00')
            ->and($detail[2]->TotalHpp)->toBe('31250.00')
            ->and($p->TotalHpp)->toBe('394750.00')
            ->and(SaldoStokPenjualan($minyak->Id, $k['Gudang']->Id))->toBe('17.0000')
            ->and(SaldoStokPenjualan($gula->Id, $k['Gudang']->Id))->toBe('905.0000')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::Hpp, $o))->toBe('394750.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::PersediaanBarangDagang, $o))->toBe('-390000.00')
            ->and(SaldoPeranJurnalPenjualan($j, PeranAkun::PersediaanBahanBaku, $o))->toBe('-4750.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('pilihan yang dihapus setelah transaksi offline: bahan tidak dikurangi, penjualan diterima dan ditandai PilihanTidakDikenal', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Susu Aren', 'Jenis' => JenisProduk::NonStok], '20000.00');
        $item = BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $kopi, 'Jumlah' => '1', 'Harga' => '20000.00']]]);
        $item['Data']['Baris'][0]['Pilihan'] = [['UuidPilihan' => BantuanKasir::Uuid(), 'Nama' => 'Oat milk', 'Harga' => '0.00']];

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        expect(Penjualan::query()->sole()->AlasanTinjauan)->toContain('PilihanTidakDikenal');
    });

    it('dokumen lunas tidak bisa diubah atau dihapus (CLAUDE.md #8)', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        BantuanKasir::KirimRingkas($this, $k['Token'], [BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]])]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->sole();

        expect(fn () => $p->forceFill(['TotalAkhir' => '1.00'])->save())->toThrow(LogicException::class)
            ->and(fn () => Penjualan::query()->sole()->delete())->toThrow(LogicException::class)
            ->and(fn () => PenjualanDetail::query()->firstOrFail()->forceFill(['Jumlah' => '9'])->save())->toThrow(LogicException::class)
            ->and(fn () => PenjualanPembayaran::query()->firstOrFail()->forceFill(['Jumlah' => '1.00'])->save())->toThrow(LogicException::class)
            ->and(fn () => $p->refresh()->forceFill(['Status' => StatusPenjualan::Void])->save())->not->toThrow(LogicException::class);
        unset($k);
    });
});

describe('F-07b data-awal', function (): void {
    it('data-awal memuat pengaturan diskon & pembulatan, outlet, perangkat, profil pajak, tarif pajak nasional + kota outlet, metode bayar aktif fase 1', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        Outlet::query()->whereKey($k['Outlet']->Id)->update(['KodeKota' => '33.72', 'Alamat' => 'Jl. Slamet Riyadi 12, Solo']);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '33.72', '10.000000');
        BantuanPanduanAwal::TerbitkanTarif('PbjtMakananMinuman', '31.71', '10.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BantuanPenjualan::BuatMetode(JenisMetodePembayaran::Ewallet, 'OVO lama', false);

        $respons = $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk();

        expect($respons->json('Pengaturan'))->toBe([
            'BatasKasKeluar' => '200000.00',
            'ShiftBersama' => false,
            'BatasDiskonManual' => '10.00',
            'BatasDiskonPenyetuju' => '30.00',
            'PembulatanTunai' => null,
            'TutupShiftButa' => true,
            'ToleransiSelisihKas' => '10000.00',
            'BatasHariRetur' => 7,
            'BatasHariLewatJatuhTempo' => 0,
            'BukaLaciPerluPin' => false,
            'BatasReturTanpaStrukHarian' => '1000000.00',
            'PersetujuanJarakJauh' => false,
            // v3.55: barcode timbangan (bawaan mati).
            'BarcodeTimbangan' => ['Aktif' => false, 'Awalan' => ['27'], 'Nilai' => 'Berat'],
        ])
            // v3.51 jenis pesanan & v3.58 mode kasir (aditif; outlet retail tanpa template = kosong).
            ->and($respons->json('Outlet'))->toBe(['Uuid' => $k['Outlet']->Uuid, 'Kode' => $k['Outlet']->Kode, 'Nama' => $k['Outlet']->Nama, 'Alamat' => 'Jl. Slamet Riyadi 12, Solo', 'Telepon' => null, 'ZonaWaktu' => 'Asia/Jakarta', 'JamTutupBuku' => '04:00', 'JenisPesanan' => [], 'JenisPesananBawaan' => null, 'ModeKasir' => [], 'ModeKasirBawaan' => null])
            ->and($respons->json('Perangkat'))->toBe(['Uuid' => $k['Perangkat']->Uuid, 'Kode' => $k['Perangkat']->Kode, 'NomorUrutPenjualan' => [], 'NomorUrutRetur' => [], 'NomorUrutIsiDeposit' => []])
            // Belum ada penjualan: objek JSON kosong, bukan larik.
            ->and($respons->getContent())->toContain('"NomorUrutPenjualan":{}')
            ->and($respons->getContent())->toContain('"NomorUrutRetur":{}')
            ->and($respons->getContent())->toContain('"NomorUrutIsiDeposit":{}')
            ->and(array_column($respons->json('TarifPajak'), 'Kategori'))->toBe(['Pbjt', 'Ppn'])
            ->and(array_keys($respons->json('ProfilPajak')))->toBe(['Pkp', 'PungutPbjt', 'HargaTermasukPajak', 'BiayaLayanan'])
            ->and(array_column($respons->json('TarifPajak'), 'KodeJenisPajak'))->toBe(['PbjtMakananMinuman', 'Ppn'])
            ->and($respons->json('TarifPajak.1.PengaliDppPembilang'))->toBe(11)
            // F-12: Tempo (piutang) ikut dikirim ke POS. K-11: metode sistem Tukar barang dibuat saat data awal diminta.
            ->and(array_column($respons->json('MetodePembayaran'), 'Jenis'))->toBe(['Tunai', 'QrisStatis', 'Edc', 'Transfer', 'Tempo', 'Tukar'])
            ->and($respons->json('MetodePembayaran.1'))->toHaveKeys(['Uuid', 'Jenis', 'Nama', 'NomorRekening', 'NamaPemilikRekening', 'AdaGambarQris', 'Urutan']);
    });

    it('PRD v1.46 (e): Perangkat.NomorUrutPenjualan & NomorUrutRetur = {YYMMDD: urut terakhir} dari nomor penjualan/retur perangkat ini (14 hari terakhir), agar pemasangan ulang aplikasi tidak memakai nomor yang sama', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $baris = ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]];
        $waktu = now()->subMinutes(5)->toImmutable();
        $item = fn (int $urut) => BantuanPenjualan::Item($k, $baris + ['DibuatPada' => $waktu], ['Nomor' => BantuanPenjualan::Nomor($k, $urut, $waktu)]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$item(7), $item(12), $sembilan = $item(9), $lama = $item(15)]))
            ->toBe([['Diterima', null], ['Diterima', null], ['Diterima', null], ['Diterima', null]]);

        // Penjualan dengan tanggal bisnis lebih dari 14 hari lalu tidak ikut (sekuens hari lama tidak dipakai lagi).
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        DB::table('Penjualan')->where('Uuid', $lama['Uuid'])->update(['TanggalBisnis' => now()->subDays(20)->toDateString()]);
        $yymmdd = $waktu->setTimezone($k['Outlet']->ZonaWaktu)->format('ymd');

        $p = Penjualan::query()->where('Uuid', $sembilan['Uuid'])->sole();
        $waktuRetur = now()->subMinute()->toImmutable();
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::ItemRetur($k, $p, [['Detail' => $p->Detail()->firstOrFail()]], ['DibuatPada' => $waktuRetur], ['Nomor' => BantuanPenjualan::NomorRetur($k, 4, $waktuRetur)]),
        ]))->toBe([['Diterima', null]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $yymmddRetur = $waktuRetur->setTimezone($k['Outlet']->ZonaWaktu)->format('ymd');

        $respons = $this->withToken($k['Token'])->getJson('/api/pos/v1/data-awal')->assertOk();

        expect($respons->json('Perangkat.NomorUrutPenjualan'))->toBe([$yymmdd => 12])
            ->and($respons->getContent())->toContain("\"NomorUrutPenjualan\":{\"{$yymmdd}\":12}")
            ->and($respons->json('Perangkat.NomorUrutRetur'))->toBe([$yymmddRetur => 4]);

        // Perangkat lain di outlet yang sama tidak melihat sekuens perangkat ini.
        $lain = BantuanPerangkat::BuatDanAktifkan($this, $k['Tenant']->Id, $k['Outlet'], 'Kasir Teras');
        expect($this->withToken($lain['Token'])->getJson('/api/pos/v1/data-awal')->assertOk()->getContent())->toContain('"NomorUrutPenjualan":{}');
    });
});

describe('F-07b tindak lanjut tinjauan (PRD v1.46)', function (): void {
    it('(a) snapshot HargaTermasukPajak (dokumen & per baris efektif), PersenBiayaLayanan, dan PembulatanTunai berbeda dari pengaturan server: diterima + PerluTinjauan PengaturanBerbeda dengan alasan manusiawi; Kelipatan di luar 1–1.000 = DataTidakValid', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPenjualan::AturProfilPajak($k);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Tubruk Robusta Temanggung', 'Jenis' => JenisProduk::NonStok], '20050.00');
        $teh = BantuanKatalog::BuatProduk(['Nama' => 'Teh Poci Gula Batu Jumbo', 'Jenis' => JenisProduk::NonStok, 'HargaTermasukPajak' => true], '8000.00');
        $baris = fn (Produk $p, ?bool $termasuk = null): array => ['Baris' => [['Produk' => $p, 'Jumlah' => '1', 'Harga' => $p->Is($teh) ? '8000.00' : '20050.00', 'HargaTermasukPajak' => $termasuk]]];

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            $termasukPajak = BantuanPenjualan::Item($k, $baris($kopi) + ['HargaTermasukPajak' => true]),
            $layanan = BantuanPenjualan::Item($k, $baris($kopi) + ['PersenBiayaLayanan' => '5']),
            $bulat = BantuanPenjualan::Item($k, $baris($kopi) + ['PembulatanTunai' => [1000, 'Bawah']]),
            $perBaris = BantuanPenjualan::Item($k, $baris($teh)),
            $sesuai = BantuanPenjualan::Item($k, $baris($teh, true)),
            BantuanPenjualan::Item($k, $baris($kopi) + ['PembulatanTunai' => [1001, 'Bawah']]),
        ]))->toBe([
            ['Diterima', null],
            ['Diterima', null],
            ['Diterima', null],
            ['Diterima', null],
            ['Diterima', null],
            ['Ditolak', 'DataTidakValid'],
        ]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $alasan = fn (array $item): ?string => Penjualan::query()->where('Uuid', $item['Uuid'])->sole()->AlasanTinjauan;
        expect($alasan($termasukPajak))->toBe('PengaturanBerbeda: harga di perangkat termasuk pajak, pengaturan outlet belum termasuk pajak')
            ->and($alasan($layanan))->toBe('PengaturanBerbeda: biaya layanan di perangkat 5%, pengaturan outlet 0%')
            ->and($alasan($bulat))->toBe('PengaturanBerbeda: pembulatan tunai di perangkat kelipatan Rp 1.000 ke bawah, pengaturan kasir tanpa pembulatan')
            ->and($alasan($perBaris))->toBe('PengaturanBerbeda: harga per produk Teh Poci Gula Batu Jumbo (belum termasuk pajak, seharusnya termasuk pajak)')
            ->and($alasan($sesuai))->toBeNull()
            ->and(Penjualan::query()->where('Uuid', $bulat['Uuid'])->sole()->Pembulatan)->toBe('-50.00')
            ->and(PemeriksaInvarian::PeriksaSemua($k['Tenant']->Id))->toBe([]);
    });

    it('(a) himpunan pajak per baris dicocokkan dengan kelompok pajak produk & profil pajak outlet menurut kategori JenisPajak pada tanggal bisnis: beda = diterima + PajakBerbeda; outlet non-PKP tidak memungut PPN', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        BantuanPanduanAwal::TerbitkanTarif('Ppn', null, '12.000000');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        BantuanPenjualan::AturProfilPajak($k, pkp: true);
        $sabun = BantuanKatalog::BuatProduk(['Nama' => 'Sabun Batang Antiseptik 110 gram', 'Jenis' => JenisProduk::NonStok], '10000.00');
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Pandan Wangi Premium 5 kg', 'Jenis' => JenisProduk::NonStok], '75000.00');
        BantuanPenjualan::PasangKelompokPajak('Uji kasir: barang kena PPN', ['Ppn' => 'Subtotal'], $sabun);
        $ppn = ['Ppn', '12.000000', 11, 12];
        $jual = fn (array $pajak, array $baris) => BantuanPenjualan::Item($k, ['Pajak' => $pajak, 'Baris' => $baris]);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            $sesuai = $jual([$ppn], [
                ['Produk' => $sabun, 'Jumlah' => '2', 'Harga' => '10000.00'],
                ['Produk' => $beras, 'Jumlah' => '1', 'Harga' => '75000.00', 'KodePajak' => []],
            ]),
            $kurang = $jual([], [['Produk' => $sabun, 'Jumlah' => '1', 'Harga' => '10000.00']]),
            $lebih = $jual([$ppn], [['Produk' => $beras, 'Jumlah' => '1', 'Harga' => '75000.00']]),
        ]))->toBe([['Diterima', null], ['Diterima', null], ['Diterima', null]]);

        BantuanPenjualan::AturProfilPajak($k);
        $nonPkp = $jual([], [['Produk' => $sabun, 'Jumlah' => '1', 'Harga' => '10000.00']]);
        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [$nonPkp]))->toBe([['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $alasan = fn (array $item): ?string => Penjualan::query()->where('Uuid', $item['Uuid'])->sole()->AlasanTinjauan;
        expect($alasan($sesuai))->toBeNull()
            ->and($alasan($kurang))->toBe('PajakBerbeda: pajak per produk Sabun Batang Antiseptik 110 gram (perangkat tanpa pajak, seharusnya PPN)')
            ->and($alasan($lebih))->toBe('PajakBerbeda: pajak per produk Beras Pandan Wangi Premium 5 kg (perangkat PPN, seharusnya tanpa pajak)')
            ->and($alasan($nonPkp))->toBeNull()
            ->and(Penjualan::query()->where('Uuid', $lebih['Uuid'])->sole()->TotalPajak)->toBe('8250.00');
    });

    it('(c) persen diskon efektif = diskon hasil mesin (dibulatkan ke sen) ÷ bruto baris atau ÷ subtotal, dibandingkan eksak: 30% dari Rp 4.995,45 = Rp 1.498,64 (30,0001%) melebihi batas 30%; 30% dari Rp 4.995,41 = Rp 1.498,62 tidak', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $roti = BantuanKatalog::BuatProduk(['Nama' => 'Roti Sobek Cokelat Keju Isi 10', 'Jenis' => JenisProduk::NonStok], '4995.45');
        $spv = ['Kasir' => $k['Supervisor'], 'Penyetuju' => $k['Supervisor']];
        $baris = fn (string $harga, ?array $diskon = null): array => [['Produk' => $roti, 'Jumlah' => '1', 'Harga' => $harga, 'DiskonManual' => $diskon]];

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            $naik = BantuanPenjualan::Item($k, ['Baris' => $baris('4995.45', ['Persen' => '30'])] + $spv),
            $turun = BantuanPenjualan::Item($k, ['Baris' => $baris('4995.41', ['Persen' => '30'])] + $spv),
            $pesanan = BantuanPenjualan::Item($k, ['Baris' => $baris('4995.45'), 'DiskonManualPesanan' => ['Persen' => '30']] + $spv),
        ]))->toBe([['Diterima', null], ['Diterima', null], ['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = fn (array $item): Penjualan => Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        $harap = "DiskonMelebihiBatas: diskon 30,0001% melebihi batas persetujuan 30% ({$k['Supervisor']->Nama}); hanya Pemilik yang boleh menyetujui";
        expect($p($naik)->TotalDiskon)->toBe('1498.64')
            ->and($p($naik)->AlasanTinjauan)->toBe($harap)
            ->and($p($turun)->TotalDiskon)->toBe('1498.62')
            ->and($p($turun)->PerluTinjauan)->toBeFalse()
            ->and($p($pesanan)->DiskonPesanan)->toBe('1498.64')
            ->and($p($pesanan)->AlasanTinjauan)->toBe($harap);
    });

    it('(b) penyetuju anggota tenant ber-izin setujui yang tidak lagi di outlet = diterima + IzinBerubah; UuidPenyetuju bukan anggota tenant = PenyetujuTidakBerwenang', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $spvLuar = BantuanOrganisasi::TambahAnggota($k['Tenant']->Id, PeranTenantBawaan::Supervisor, semuaOutlet: false);
        $lain = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Bubuk Robusta Temanggung 250 gram', 'Jenis' => JenisProduk::NonStok], '100000.00');
        $diskon = fn (array $opsi = [], array $timpa = []) => BantuanPenjualan::Item($k, ['Baris' => [['Produk' => $kopi, 'Jumlah' => '1', 'Harga' => '100000.00', 'DiskonManual' => ['Persen' => '5']]]] + $opsi, $timpa);

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            $luar = $diskon(['Penyetuju' => $spvLuar]),
            $diskon([], ['UuidPenyetujuDiskon' => BantuanKasir::Uuid()]),
            $diskon(['Penyetuju' => $lain['Supervisor']]),
        ]))->toBe([['Diterima', null], ['Ditolak', 'PenyetujuTidakBerwenang'], ['Ditolak', 'PenyetujuTidakBerwenang']]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = Penjualan::query()->where('Uuid', $luar['Uuid'])->sole();
        expect($p->AlasanTinjauan)->toBe("IzinBerubah: penyetuju diskon {$spvLuar->Nama} tidak lagi terdaftar di outlet ini")
            ->and($p->IdPenyetujuDiskon)->toBe($spvLuar->Id);
    });

    it('harga manual kasir: supervisor ber-izin pos.harga.timpa = diterima bersih + HargaAsli & alasan tersimpan; kasir tanpa izin = tinjauan HargaManualTanpaIzin; dengan PIN penyetuju ber-izin = bersih; alasan kosong = HargaManualTanpaAlasan; harga sama dengan asli bukan perubahan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Bubuk Robusta Temanggung 250 gram', 'Jenis' => JenisProduk::NonStok], '40000.00');
        $manual = fn (string $harga = '35000.00', string $alasan = 'Kemasan penyok'): array => ['Produk' => $kopi, 'Jumlah' => '1', 'Harga' => $harga, 'HargaManual' => ['HargaAsli' => '40000.00', 'Alasan' => $alasan]];

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            $supervisor = BantuanPenjualan::Item($k, ['Baris' => [$manual()], 'Kasir' => $k['Supervisor']]),
            $tanpaIzin = BantuanPenjualan::Item($k, ['Baris' => [$manual()]]),
            $disetujui = BantuanPenjualan::Item($k, ['Baris' => [$manual()], 'PenyetujuHarga' => $k['Supervisor']]),
            $tanpaAlasan = BantuanPenjualan::Item($k, ['Baris' => [$manual('35000.00', '  ')], 'Kasir' => $k['Supervisor']]),
            $sama = BantuanPenjualan::Item($k, ['Baris' => [$manual('40000.00')]]),
        ]))->toBe([['Diterima', null], ['Diterima', null], ['Diterima', null], ['Diterima', null], ['Diterima', null]]);

        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $p = fn (array $item): Penjualan => Penjualan::query()->where('Uuid', $item['Uuid'])->sole();
        $detail = fn (array $item): PenjualanDetail => PenjualanDetail::query()->where('IdPenjualan', $p($item)->Id)->sole();

        expect($p($supervisor)->PerluTinjauan)->toBeFalse()
            ->and($detail($supervisor)->HargaSatuan)->toBe('35000.00')
            ->and($detail($supervisor)->HargaAsli)->toBe('40000.00')
            ->and($detail($supervisor)->AlasanHargaManual)->toBe('Kemasan penyok')
            ->and($p($tanpaIzin)->AlasanTinjauan)->toStartWith('HargaManualTanpaIzin: ')
            ->and($p($disetujui)->PerluTinjauan)->toBeFalse()
            ->and($p($disetujui)->IdPenyetujuHarga)->toBe($k['Supervisor']->Id)
            ->and($p($tanpaAlasan)->AlasanTinjauan)->toStartWith('HargaManualTanpaAlasan: ')
            ->and($p($sama)->PerluTinjauan)->toBeFalse()
            ->and($detail($sama)->HargaAsli)->toBeNull()
            ->and($detail($sama)->AlasanHargaManual)->toBeNull();
    });

    it('harga manual kasir: UuidPenyetujuHarga bukan anggota tenant, anggota tenant lain, atau tanpa izin pos.harga.timpa = Ditolak PenyetujuTidakBerwenang', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $lain = BantuanPenjualan::Siapkan($this, 'Warung Bakso Pak Kumis');
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $kopi = BantuanKatalog::BuatProduk(['Nama' => 'Kopi Bubuk Robusta Temanggung 250 gram', 'Jenis' => JenisProduk::NonStok], '40000.00');
        $baris = [['Produk' => $kopi, 'Jumlah' => '1', 'Harga' => '35000.00', 'HargaManual' => ['HargaAsli' => '40000.00', 'Alasan' => 'Kemasan penyok']]];

        expect(BantuanKasir::KirimRingkas($this, $k['Token'], [
            BantuanPenjualan::Item($k, ['Baris' => $baris, 'PenyetujuHarga' => $lain['Supervisor']]),
            BantuanPenjualan::Item($k, ['Baris' => $baris, 'PenyetujuHarga' => $k['Kasir']]),
            BantuanPenjualan::Item($k, ['Baris' => $baris], ['UuidPenyetujuHarga' => BantuanKasir::Uuid()]),
        ]))->toBe([['Ditolak', 'PenyetujuTidakBerwenang'], ['Ditolak', 'PenyetujuTidakBerwenang'], ['Ditolak', 'PenyetujuTidakBerwenang']]);
    });

    it('(saran 1) bentrok indeks unik (1062) saat dua kiriman bersamaan: penjualan dibaca ulang; Nomor & TotalAkhir sama = Duplikat, selain itu UuidSudahDipakai/NomorSudahDipakai; galat database lain diteruskan', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $minyak = BantuanPenjualan::BuatProdukBerstok($k['Gudang'], $k['Pemilik']->Id);
        $p = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $minyak, 'Jumlah' => '1', 'Harga' => '38500.00']]]);
        BantuanOrganisasi::AturKonteks($k['Tenant']->Id);
        $aksi = app(TerimaPenjualanPos::class);
        $selesaikan = new ReflectionMethod($aksi, 'SelesaikanBentrokUnik');
        $galat = function (int $kode, string $indeks): QueryException {
            $pdo = new PDOException("SQLSTATE[23000]: Integrity constraint violation: {$kode} Duplicate entry for key 'Penjualan.{$indeks}'");
            $pdo->errorInfo = ['23000', $kode, "Duplicate entry for key 'Penjualan.{$indeks}'"];

            return new QueryException('mysql', 'insert into `Penjualan`', [], $pdo);
        };
        $kode = function (callable $aksi): string {
            try {
                $aksi();
            } catch (PelanggaranAturanBisnis $g) {
                return $g->kode;
            }

            return 'TanpaGalat';
        };

        expect($selesaikan->invoke($aksi, $galat(1062, 'UniqPenjualanUuid'), $p->Uuid, $p->Nomor, Uang::Dari($p->TotalAkhir)))->toBe(StatusItemSinkron::Duplikat)
            ->and($selesaikan->invoke($aksi, $galat(1062, 'UniqPenjualanIdTenantNomor'), $p->Uuid, $p->Nomor, Uang::Dari($p->TotalAkhir)))->toBe(StatusItemSinkron::Duplikat)
            ->and($kode(fn () => $selesaikan->invoke($aksi, $galat(1062, 'UniqPenjualanUuid'), $p->Uuid, BantuanPenjualan::Nomor($k, 9001), Uang::Dari($p->TotalAkhir))))->toBe('UuidSudahDipakai')
            ->and($kode(fn () => $selesaikan->invoke($aksi, $galat(1062, 'UniqPenjualanUuid'), $p->Uuid, $p->Nomor, Uang::Dari('1.00'))))->toBe('UuidSudahDipakai')
            ->and($kode(fn () => $selesaikan->invoke($aksi, $galat(1062, 'UniqPenjualanIdTenantNomor'), BantuanKasir::Uuid(), $p->Nomor, Uang::Dari($p->TotalAkhir))))->toBe('NomorSudahDipakai')
            ->and(fn () => $selesaikan->invoke($aksi, $galat(1213, 'PRIMARY'), $p->Uuid, $p->Nomor, Uang::Dari($p->TotalAkhir)))->toThrow(QueryException::class);
    });

    it('(saran 2) CLAUDE.md #8: HPP & jurnal penjualan hanya boleh diisi di dalam penerimaan; di luar penerimaan penjualan Rp 0 tanpa jurnal (IdJurnal null) dan baris ber-HPP 0 tetap tidak bisa diubah; penanda pulih setelah galat', function (): void {
        $k = BantuanPenjualan::Siapkan($this);
        $jasa = BantuanKatalog::BuatProduk(['Nama' => 'Jasa Bungkus Kado Gratis', 'Jenis' => JenisProduk::Jasa], '0.00');
        $gratis = BantuanPenjualan::Jual($this, $k, ['Baris' => [['Produk' => $jasa, 'Jumlah' => '1', 'Harga' => '0.00']]]);
        $detail = PenjualanDetail::query()->where('IdPenjualan', $gratis->Id)->sole();

        expect($gratis->IdJurnal)->toBeNull()
            ->and($gratis->TotalHpp)->toBe('0.00')
            ->and(Penjualan::CekSedangMenerima())->toBeFalse()
            ->and(fn () => $gratis->forceFill(['TotalHpp' => '5000.00'])->save())->toThrow(LogicException::class)
            ->and(fn () => $gratis->refresh()->forceFill(['IdJurnal' => 1])->save())->toThrow(LogicException::class)
            ->and(fn () => $detail->forceFill(['HppSatuan' => '100', 'TotalHpp' => '100.00'])->save())->toThrow(LogicException::class)
            ->and(fn () => Penjualan::JalankanPenerimaan(fn () => throw new RuntimeException('gagal di tengah penerimaan')))->toThrow(RuntimeException::class)
            ->and(Penjualan::CekSedangMenerima())->toBeFalse()
            ->and(Penjualan::query()->whereKey($gratis->Id)->value('TotalHpp'))->toBe('0.00');
    });

    it('(g) akun pajak jurnal dari kategori JenisPajak, bukan kode: kategori Ppn → PPN Keluaran, Pbjt & Lainnya (juga kode tidak dikenal) → Hutang PBJT', function (): void {
        BantuanHarga::SiapkanJenisPajak();
        JenisPajak::query()->create(['Kode' => 'PpnDtp', 'Nama' => 'PPN ditanggung pemerintah', 'Cakupan' => CakupanPajak::Nasional, 'Kategori' => KategoriJenisPajak::Ppn]);
        $lainnya = JenisPajak::query()->create(['Kode' => 'PajakRokokDaerah', 'Nama' => 'Pajak rokok daerah', 'Cakupan' => CakupanPajak::Daerah]);

        $akun = app(PenyusunJurnalPenjualan::class)->TentukanAkunPajak(['Ppn', 'PpnDtp', 'PbjtMakananMinuman', 'PbjtJasaHiburan', 'PajakRokokDaerah', 'TidakDikenal']);

        expect(array_map(fn (PeranAkun $p): string => $p->value, $akun))->toBe([
            'Ppn' => 'PpnKeluaran',
            'PpnDtp' => 'PpnKeluaran',
            'PbjtMakananMinuman' => 'HutangPbjt',
            'PbjtJasaHiburan' => 'HutangPbjt',
            'PajakRokokDaerah' => 'HutangPbjt',
            'TidakDikenal' => 'HutangPbjt',
        ])
            ->and($lainnya->refresh()->Kategori)->toBe(KategoriJenisPajak::Lainnya)
            ->and(JenisPajak::query()->where('Kode', 'PbjtMakananMinuman')->sole()->Kategori)->toBe(KategoriJenisPajak::Pbjt);
    });
});
