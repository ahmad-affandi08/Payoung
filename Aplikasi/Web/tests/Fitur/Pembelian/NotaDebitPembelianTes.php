<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pembelian\Aksi\BatalkanFakturPembelian;
use App\Domain\Pembelian\Aksi\BatalkanNotaDebitPembelian;
use App\Domain\Pembelian\Aksi\BatalkanPembayaranHutang;
use App\Domain\Pembelian\Aksi\SimpanNotaDebitPembelian;
use App\Domain\Pembelian\Aksi\SimpanPembayaranHutang;
use App\Domain\Pembelian\Data\DataNotaDebitPembelian;
use App\Domain\Pembelian\Data\DataPembayaranHutang;
use App\Domain\Pembelian\Enum\JenisNotaDebit;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelianDetail;
use App\Domain\Pembelian\Model\NotaDebitPembelian;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * v6.07 nota debit pembelian (F-04 BR-04.10, J-04.7): pengurang hutang atas faktur karena selisih harga / klaim pemasok
 * tanpa barang kembali. Bagian stok yang masih ada menurunkan nilai persediaan (penilaian ulang, jumlah bersih nol),
 * bagian barang yang sudah terjual mengurangi HPP (BR-04.4); bagian yang melebihi sisa hutang diterima sebagai uang
 * (BR-04.9); pembatalan membalik selama stok belum bergerak. Invarian stok, akun persediaan, dan hutang tiap langkah.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * @return array<string, array{0: string, 1: string}>
 */
function JurnalNotaDebit(int $idSumber, string $kunci = 'Utama'): array
{
    $jurnal = Jurnal::query()->where('JenisSumber', 'NotaDebitPembelian')->where('IdSumber', $idSumber)->where('KunciSumber', $kunci)->sole();
    $hasil = [];

    foreach (DB::table('JurnalDetail')->join('Akun', 'Akun.Id', '=', 'JurnalDetail.IdAkun')->where('IdJurnal', $jurnal->Id)->orderBy('Urutan')->get(['Akun.Kode', 'Debit', 'Kredit']) as $d) {
        $hasil[(string) $d->Kode] = [(string) $d->Debit, (string) $d->Kredit];
    }

    return $hasil;
}

/**
 * @param  list<int>  $baris
 */
function CatatNotaDebit(FakturPembelian $faktur, string $nilai, int $idPengguna, array $baris = [], ?string $uuidAkunRefund = null, bool $bolehMelebihiBatas = true, ?JenisNotaDebit $jenis = null): NotaDebitPembelian
{
    return app(SimpanNotaDebitPembelian::class)->Jalankan(new DataNotaDebitPembelian(
        $faktur->Id,
        $jenis ?? JenisNotaDebit::SelisihHarga,
        BantuanPembelian::Hari(),
        Uang::Dari($nilai),
        $baris,
        'Harga faktur lebih tinggi dari kesepakatan',
        'ND-PEMASOK-0042',
        $uuidAkunRefund,
        $bolehMelebihiBatas,
        $idPengguna,
    ));
}

describe('v6.07 nota debit pembelian (BR-04.10)', function (): void {
    it('stok tersisa menurunkan nilai persediaan, bagian terjual mengurangi HPP; hutang faktur berkurang; pembatalan membalik', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Premium Rojolele 5 kg'], '85000.00', $t['Pcs']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$beras, '10', '2000']], $id);
        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $id);
        BantuanPembelian::Jual($beras, $t['Gudang'], '6', $id);
        $persediaanAwal = BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::PersediaanBarangDagang);
        $hppAwal = BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::Hpp);

        $nota = CatatNotaDebit($faktur, '1000', $id);

        // Terjual 6 dari 10: 4 tersisa → 400 menurunkan nilai stok, 600 mengurangi HPP.
        expect($nota->Nomor)->toStartWith('ND/')
            ->and($nota->Total)->toBe('1000.00')->and($nota->PotongHutang)->toBe('1000.00')->and($nota->NilaiRefund)->toBe('0.00')
            ->and($nota->KePersediaan)->toBe('400.00')->and($nota->KeHpp)->toBe('600.00')
            ->and(JurnalNotaDebit($nota->Id))->toBe(['2-1100' => ['1000.00', '0.00'], '1-1500' => ['0.00', '400.00'], '5-1000' => ['0.00', '600.00']])
            ->and(SaldoStok::query()->where('IdProduk', $beras->Id)->sole()->only(['JumlahTersedia', 'NilaiPersediaan', 'HppRataRata']))
            ->toBe(['JumlahTersedia' => '4.0000', 'NilaiPersediaan' => '7600.00', 'HppRataRata' => '1900.000000'])
            ->and(MutasiStok::query()->where('IdProduk', $beras->Id)->orderBy('Id')->pluck('JenisMutasi')->all())
            ->toBe([JenisMutasi::PenerimaanPembelian, JenisMutasi::Penjualan, JenisMutasi::RevaluasiKeluar, JenisMutasi::RevaluasiMasuk])
            ->and($faktur->refresh()->JumlahRetur)->toBe('1000.00')->and($faktur->AmbilSisa()->KeString())->toBe('19000.00')->and($faktur->Status)->toBe(StatusFakturPembelian::BelumDibayar)
            ->and(Uang::Dari(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::PersediaanBarangDagang))->Kurangi(Uang::Dari($persediaanAwal))->KeString())->toBe('-400.00')
            ->and(Uang::Dari(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::Hpp))->Kurangi(Uang::Dari($hppAwal))->KeString())->toBe('-600.00')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        // Faktur yang punya nota debit aktif tidak bisa dibatalkan.
        expect(B::KodeGalat(fn () => app(BatalkanFakturPembelian::class)->Jalankan($faktur, 'Faktur salah pemasok', $id)))->toBe('AdaNotaDebit');

        $batal = app(BatalkanNotaDebitPembelian::class)->Jalankan($nota, 'Pemasok menarik klaimnya', $id);

        expect($batal->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(SaldoStok::query()->where('IdProduk', $beras->Id)->sole()->NilaiPersediaan)->toBe('8000.00')
            ->and($faktur->refresh()->JumlahRetur)->toBe('0.00')
            ->and(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::PersediaanBarangDagang))->toBe($persediaanAwal)
            ->and(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::Hpp))->toBe($hppAwal)
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        // Idempoten: membatalkan lagi tidak mengubah apa pun.
        expect(app(BatalkanNotaDebitPembelian::class)->Jalankan($nota, 'Pemasok menarik klaimnya', $id)->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('baris dipilih, klaim kumulatif dibatasi sisa nilai faktur, validasi masukan, dan batal ditolak setelah stok bergerak', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Premium Rojolele 5 kg'], '85000.00', $t['Pcs']);
        $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kristal 1 kg'], '17000.00', $t['Pcs']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$beras, '3', '2000'], [$gula, '1', '15000']], $id);
        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $id);
        $idGula = FakturPembelianDetail::query()->where('IdFakturPembelian', $faktur->Id)->where('IdProduk', $gula->Id)->value('Id');
        BantuanPembelian::Jual($gula, $t['Gudang'], '1', $id);

        expect(B::KodeGalat(fn () => CatatNotaDebit($faktur, '0', $id)))->toBe('NilaiTidakValid')
            ->and(B::KodeGalat(fn () => app(SimpanNotaDebitPembelian::class)->Jalankan(new DataNotaDebitPembelian($faktur->Id, JenisNotaDebit::KlaimKerusakan, BantuanPembelian::Hari(), Uang::Dari('100'), [], 'ok', null, null, true, $id))))->toBe('AlasanTidakValid')
            ->and(B::KodeGalat(fn () => app(SimpanNotaDebitPembelian::class)->Jalankan(new DataNotaDebitPembelian($faktur->Id, JenisNotaDebit::KlaimKerusakan, BantuanPembelian::Hari(1)->subDays(30), Uang::Dari('100'), [], 'Tanggal sebelum faktur', null, null, true, $id))))->toBe('TanggalSebelumFaktur')
            ->and(B::KodeGalat(fn () => CatatNotaDebit($faktur, '100', $id, [999999])))->toBe('BarisTidakDikenal')
            // Gula: 15.000. Klaim 15.001 melebihi.
            ->and(B::KodeGalat(fn () => CatatNotaDebit($faktur, '15000.01', $id, [$idGula])))->toBe('MelebihiNilaiFaktur');

        // Gula sudah terjual semua → seluruhnya HPP; saldo gula tetap 0.
        $nota = CatatNotaDebit($faktur, '10000', $id, [$idGula], jenis: JenisNotaDebit::KlaimKerusakan);
        expect($nota->KePersediaan)->toBe('0.00')->and($nota->KeHpp)->toBe('10000.00')
            ->and(SaldoStok::query()->where('IdProduk', $gula->Id)->sole()->NilaiPersediaan)->toBe('0.00')
            ->and(SaldoStok::query()->where('IdProduk', $beras->Id)->sole()->NilaiPersediaan)->toBe('6000.00');

        // Sisa kapasitas gula tinggal 5.000: klaim kumulatif tidak boleh melewati nilai fakturnya.
        expect(B::KodeGalat(fn () => CatatNotaDebit($faktur, '5000.01', $id, [$idGula])))->toBe('MelebihiNilaiFaktur');
        $kedua = CatatNotaDebit($faktur, '5000', $id, [$idGula]);
        expect($kedua->KeHpp)->toBe('5000.00')->and($faktur->refresh()->JumlahRetur)->toBe('15000.00')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        // Semua baris (default): beras 6.000 masih bisa diklaim; setelah beras terjual sebagian, batal ditolak.
        $beras3 = CatatNotaDebit($faktur, '3000', $id);
        expect($beras3->KePersediaan)->toBe('3000.00');
        BantuanPembelian::Jual($beras, $t['Gudang'], '1', $id);
        expect(B::KodeGalat(fn () => app(BatalkanNotaDebitPembelian::class)->Jalankan($beras3, 'Terlambat dikoreksi', $id)))->toBe('StokSudahBergerak')
            ->and($beras3->fresh()?->Status)->toBe(StatusDokumenTerposting::Diposting)
            ->and(B::KodeGalat(fn () => app(BatalkanNotaDebitPembelian::class)->Jalankan($kedua, 'x', $id)))->toBe('AlasanTidakValid')
            // Nota yang hanya menyentuh HPP tetap bisa dibatalkan.
            ->and(app(BatalkanNotaDebitPembelian::class)->Jalankan($kedua, 'Klaim ditarik pemasok', $id)->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('faktur sudah dibayar: bagian melebihi sisa hutang diterima sebagai uang (BR-04.9); pembayaran terkunci selama nota berlaku', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $mi = BantuanKatalog::BuatProduk(['Nama' => 'Mi Instan Goreng Rasa Rendang isi 40']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$mi, '10', '110000']], $id);
        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $id);
        $kas = BantuanPembelian::AkunKas('1-1200');
        $bayar = app(SimpanPembayaranHutang::class)->Jalankan(new DataPembayaranHutang($pemasok->Uuid, $kas->Uuid, BantuanPembelian::Hari(), [$faktur->Uuid => Uang::Dari('1000000')], null, null, $id));

        // Faktur 1.100.000, dibayar 1.000.000 → sisa 100.000. Nota debit 150.000: 100.000 memotong hutang, 50.000 uang kembali.
        expect(B::KodeGalat(fn () => CatatNotaDebit($faktur, '150000', $id)))->toBe('AkunRefundWajib')
            ->and(B::KodeGalat(fn () => CatatNotaDebit($faktur, '150000', $id, uuidAkunRefund: BantuanPembelian::AkunKas('1-1500')->Uuid)))->toBe('AkunRefundWajib');

        $nota = CatatNotaDebit($faktur, '150000', $id, uuidAkunRefund: $kas->Uuid);

        expect($nota->PotongHutang)->toBe('100000.00')->and($nota->NilaiRefund)->toBe('50000.00')->and($nota->IdAkunRefund)->toBe($kas->Id)
            ->and(JurnalNotaDebit($nota->Id))->toBe(['2-1100' => ['100000.00', '0.00'], '1-1200' => ['50000.00', '0.00'], '1-1500' => ['0.00', '150000.00']])
            ->and($faktur->refresh()->JumlahRetur)->toBe('100000.00')->and($faktur->Status)->toBe(StatusFakturPembelian::Lunas)
            ->and(B::KodeGalat(fn () => app(BatalkanPembayaranHutang::class)->Jalankan($bayar, 'Salah akun bayar', $id)))->toBe('AdaNotaDebitBerefund')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        app(BatalkanNotaDebitPembelian::class)->Jalankan($nota, 'Klaim ditolak pemasok', $id);

        expect($faktur->refresh()->JumlahRetur)->toBe('0.00')->and($faktur->Status)->toBe(StatusFakturPembelian::DibayarSebagian)
            ->and(JurnalNotaDebit($nota->Id, 'Pembatalan'))->toBe(['1-1500' => ['150000.00', '0.00'], '2-1100' => ['0.00', '100000.00'], '1-1200' => ['0.00', '50000.00']])
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('pemasok PKP: PPN masukan dikontra sebanding tarif efektif faktur, tanpa angka tarif di kode', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $t['Outlet']->refresh()->forceFill(['ProfilPajak' => ['Pkp' => true]])->save();
        $pemasok = BantuanPembelian::BuatPemasok('PT Indofood Distribusi Solo', true);
        $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kristal Putih 1 kg']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$gula, '100', '16500']], $id);
        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $id);
        $faktur->refresh();

        // Subtotal 1.650.000 → PPN 11% efektif (12% × DPP 11/12) = 181.500.
        expect($faktur->PpnDikreditkan)->toBeTrue()->and($faktur->Pajak)->toBe('181500.00');

        $nota = CatatNotaDebit($faktur, '165000', $id);
        $jurnal = JurnalNotaDebit($nota->Id);
        $debit = BigDecimal::zero();
        $kredit = BigDecimal::zero();

        foreach ($jurnal as [$d, $k]) {
            $debit = $debit->plus($d);
            $kredit = $kredit->plus($k);
        }

        // Klaim 10% dari nilai faktur → PPN kontra 10% dari PPN faktur; hutang berkurang sebesar nilai + PPN.
        expect($nota->Pajak)->toBe('18150.00')->and($nota->Total)->toBe('183150.00')->and($nota->PotongHutang)->toBe('183150.00')
            ->and($jurnal['2-1100'])->toBe(['183150.00', '0.00'])->and($jurnal['1-1500'])->toBe(['0.00', '165000.00'])
            ->and($debit->isEqualTo($kredit))->toBeTrue()->and($debit->isEqualTo('183150.00'))->toBeTrue()
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        app(BatalkanNotaDebitPembelian::class)->Jalankan($nota, 'Salah hitung klaim', $id);
        expect($faktur->refresh()->JumlahRetur)->toBe('0.00')->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('nota di atas batas persetujuan butuh izin penyetuju; faktur dibatalkan ditolak', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $id = $t['Pemilik']->Id;
        $pemasok = BantuanPembelian::BuatPemasok();
        $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Pouch 2 Liter'], '38000.00', $t['Pcs']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$minyak, '1000', '30000']], $id);
        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $id);

        // Batas bawaan Rp 5.000.000.
        expect(B::KodeGalat(fn () => CatatNotaDebit($faktur, '5000000.01', $id, bolehMelebihiBatas: false)))->toBe('MelebihiBatasPersetujuan');
        $boleh = CatatNotaDebit($faktur, '5000000', $id, bolehMelebihiBatas: false);
        expect($boleh->Total)->toBe('5000000.00')
            ->and(CatatNotaDebit($faktur, '5000001', $id, bolehMelebihiBatas: true)->Total)->toBe('5000001.00')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        $lain = BantuanPembelian::BuatPemasok('CV Tak Terpakai');
        $grn2 = BantuanPembelian::TerimaTanpaPo($lain, $t['Gudang'], [[$minyak, '1', '30000']], $id);
        $faktur2 = BantuanPembelian::Fakturkan($lain, [$grn2], $id);
        app(BatalkanFakturPembelian::class)->Jalankan($faktur2, 'Faktur dobel input', $id);

        expect(B::KodeGalat(fn () => CatatNotaDebit($faktur2, '1000', $id)))->toBe('FakturTidakValid');
    });

    it('halaman: formulir dari faktur, simpan, daftar, rincian, tautan di faktur, batalkan; kasir tanpa izin; tenant lain 404', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $pemasok = BantuanPembelian::BuatPemasok();
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Premium Rojolele 5 kg'], '85000.00', $t['Pcs']);
        $grn = BantuanPembelian::TerimaTanpaPo($pemasok, $t['Gudang'], [[$beras, '10', '2000']], $t['Pemilik']->Id);
        $faktur = BantuanPembelian::Fakturkan($pemasok, [$grn], $t['Pemilik']->Id);
        BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $this->get("/kelola/pembelian/nota-debit/buat?faktur={$faktur->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/NotaDebit/Buat')->where('Faktur.Nomor', $faktur->Nomor)->where('Faktur.SisaHutang', '20000.00')
            ->where('Faktur.Baris.0.SisaKlaim', '20000.00')->where('BatasPersetujuan', '5000000.00')->where('BolehMelebihiBatas', true));
        $this->get('/kelola/pembelian/nota-debit/buat')->assertRedirect('/kelola/pembelian/faktur');
        $this->post('/kelola/pembelian/nota-debit', [
            'UuidFaktur' => $faktur->Uuid,
            'Jenis' => 'DiskonSusulan',
            'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d'),
            'NilaiKlaim' => '2500',
            'Alasan' => 'Rabat akhir bulan dari pemasok',
            'NomorNotaPemasok' => 'NK-0007',
        ])->assertRedirect()->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $nota = NotaDebitPembelian::query()->sole();

        $this->get('/kelola/pembelian/nota-debit')->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pembelian/NotaDebit/Daftar')
            ->where('Nota.Data.0.Nomor', $nota->Nomor)->where('Nota.Data.0.NomorFaktur', $faktur->Nomor)->where('Nota.Data.0.Total', '2500.00'));
        $this->get("/kelola/pembelian/nota-debit/{$nota->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pembelian/NotaDebit/Detail')
            ->where('Nota.KePersediaan', '2500.00')->where('Nota.NomorNotaPemasok', 'NK-0007')->where('Baris.0.NilaiKlaim', '2500.00')->where('Tindakan.Batalkan', true)->has('Jurnal', 1));
        $this->get("/kelola/pembelian/faktur/{$faktur->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pembelian/Faktur/Detail')
            ->where('NotaDebit.0.Nomor', $nota->Nomor)->where('Tindakan.NotaDebit', true));
        $this->post('/kelola/pembelian/nota-debit', [
            'UuidFaktur' => $faktur->Uuid, 'Jenis' => 'DiskonSusulan', 'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d'), 'NilaiKlaim' => '99999999', 'Alasan' => 'Terlalu besar',
        ])->assertSessionHasErrors();
        $this->post("/kelola/pembelian/nota-debit/{$nota->Uuid}/batalkan", ['Alasan' => 'Salah nominal'])->assertRedirect();
        expect($nota->fresh()?->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(SaldoStok::query()->where('IdProduk', $beras->Id)->sole()->NilaiPersediaan)->toBe('20000.00');

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/pembelian/nota-debit')->assertForbidden();

        $lain = BantuanOrganisasi::BuatTenant('Warung Sebelah');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->get("/kelola/pembelian/nota-debit/{$nota->Uuid}")->assertNotFound();
        $this->get("/kelola/pembelian/nota-debit/buat?faktur={$faktur->Uuid}")->assertNotFound();
    });
});
