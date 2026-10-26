<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Enum\PeranTenantBawaan;
use App\Domain\Pembelian\Aksi\BatalkanBiayaTambahanPembelian;
use App\Domain\Pembelian\Aksi\BatalkanPenerimaanBarang;
use App\Domain\Pembelian\Aksi\CatatBiayaTambahanPembelian;
use App\Domain\Pembelian\Data\DataBiayaTambahanPembelian;
use App\Domain\Pembelian\Enum\DasarAlokasiBiaya;
use App\Domain\Pembelian\Enum\JenisBiayaTambahan;
use App\Domain\Pembelian\Model\BiayaTambahanPembelian;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\SaldoStok;
use Inertia\Testing\AssertableInertia;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Organisasi\BantuanOrganisasi;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanDokumenPersediaan as B;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * v3.41 biaya tambahan pembelian (INV-14 landed cost, BR-04.2/04.4): biaya pihak ketiga setelah GRN dialokasikan ke
 * baris; porsi stok yang masih ada menaikkan nilai persediaan (penilaian ulang, jumlah bersih nol), porsi barang yang
 * sudah terjual ke HPP; Dr persediaan + Dr HPP / Cr kas-bank. Pembatalan membalik selama stok belum bergerak; GRN tidak
 * bisa dibatalkan selama ada biaya tambahan aktif. Invarian stok, akun persediaan, dan GRNI di tiap langkah.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function CatatBiaya(PenerimaanBarang $grn, string $jumlah, int $idPengguna, DasarAlokasiBiaya $dasar = DasarAlokasiBiaya::Nilai): BiayaTambahanPembelian
{
    return app(CatatBiayaTambahanPembelian::class)->Jalankan(new DataBiayaTambahanPembelian(
        $grn->Id,
        JenisBiayaTambahan::Ongkir,
        $dasar,
        BantuanPembelian::Hari(),
        Uang::Dari($jumlah),
        BantuanPembelian::AkunKas()->Uuid,
        null,
        'Ekspedisi JNE Trucking',
        $idPengguna,
    ));
}

describe('v3.41 biaya tambahan pembelian', function (): void {
    it('porsi stok tersisa ke persediaan, porsi terjual ke HPP; nilai & HPP rata-rata naik; pembatalan membalik', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Premium Rojolele 5 kg'], '85000.00', $t['Pcs']);
        $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Pouch 2 Liter'], '38000.00', $t['Pcs']);
        $grn = BantuanPembelian::TerimaTanpaPo(null, $t['Gudang'], [[$beras, '10', '2000'], [$minyak, '5', '4000']], $t['Pemilik']->Id);
        BantuanPembelian::Jual($beras, $t['Gudang'], '6', $t['Pemilik']->Id);
        $persediaanAwal = BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::PersediaanBarangDagang);
        $hppAwal = BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::Hpp);

        $biaya = CatatBiaya($grn, '1000', $t['Pemilik']->Id);

        // Nilai: beras 20.000 & minyak 20.000 → masing-masing 500. Beras tersisa 4 dari 10 → 200 stok, 300 HPP.
        expect($biaya->Nomor)->toStartWith('BY/')
            ->and((string) $biaya->KePersediaan)->toBe('700.00')
            ->and((string) $biaya->KeHpp)->toBe('300.00')
            ->and(SaldoStok::query()->where('IdProduk', $beras->Id)->sole()->only(['JumlahTersedia', 'NilaiPersediaan', 'HppRataRata']))
            ->toBe(['JumlahTersedia' => '4.0000', 'NilaiPersediaan' => '8200.00', 'HppRataRata' => '2050.000000'])
            ->and(SaldoStok::query()->where('IdProduk', $minyak->Id)->sole()->NilaiPersediaan)->toBe('20500.00')
            ->and(MutasiStok::query()->where('IdProduk', $beras->Id)->pluck('JenisMutasi')->all())
            ->toBe([JenisMutasi::PenerimaanPembelian, JenisMutasi::Penjualan, JenisMutasi::RevaluasiKeluar, JenisMutasi::RevaluasiMasuk])
            ->and(Uang::Dari(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::PersediaanBarangDagang))->Kurangi(Uang::Dari($persediaanAwal))->KeString())->toBe('700.00')
            ->and(Uang::Dari(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::Hpp))->Kurangi(Uang::Dari($hppAwal))->KeString())->toBe('300.00')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        // GRN tidak bisa dibatalkan selama biaya tambahan aktif.
        expect(B::KodeGalat(fn () => app(BatalkanPenerimaanBarang::class)->Jalankan($grn, 'Salah input penerimaan', $t['Pemilik']->Id)))->toBe('AdaBiayaTambahan');

        $batal = app(BatalkanBiayaTambahanPembelian::class)->Jalankan($biaya, 'Tagihan ekspedisi salah', $t['Pemilik']->Id);

        expect($batal->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(SaldoStok::query()->where('IdProduk', $beras->Id)->sole()->NilaiPersediaan)->toBe('8000.00')
            ->and(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::PersediaanBarangDagang))->toBe($persediaanAwal)
            ->and(BantuanPembelian::SaldoPeran($t['Tenant']->Id, PeranAkun::Hpp))->toBe($hppAwal)
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('dasar jumlah, semua terjual = seluruhnya HPP; batal ditolak setelah stok bergerak; validasi', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Premium Rojolele 5 kg'], '85000.00', $t['Pcs']);
        $gula = BantuanKatalog::BuatProduk(['Nama' => 'Gula Pasir Kristal 1 kg'], '17000.00', $t['Pcs']);
        $grn = BantuanPembelian::TerimaTanpaPo(null, $t['Gudang'], [[$beras, '3', '2000'], [$gula, '1', '15000']], $t['Pemilik']->Id);
        BantuanPembelian::Jual($gula, $t['Gudang'], '1', $t['Pemilik']->Id);

        expect(B::KodeGalat(fn () => CatatBiaya($grn, '0', $t['Pemilik']->Id)))->toBe('JumlahTidakValid');

        // Dasar jumlah: 3 : 1 → beras 750 (masih ada semua), gula 250 (sudah terjual semua → HPP).
        $biaya = CatatBiaya($grn, '1000', $t['Pemilik']->Id, DasarAlokasiBiaya::Jumlah);

        expect((string) $biaya->KePersediaan)->toBe('750.00')
            ->and((string) $biaya->KeHpp)->toBe('250.00')
            ->and(SaldoStok::query()->where('IdProduk', $gula->Id)->sole()->NilaiPersediaan)->toBe('0.00')
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

        BantuanPembelian::Jual($beras, $t['Gudang'], '1', $t['Pemilik']->Id);

        expect(B::KodeGalat(fn () => app(BatalkanBiayaTambahanPembelian::class)->Jalankan($biaya, 'Terlambat dikoreksi', $t['Pemilik']->Id)))->toBe('StokSudahBergerak')
            ->and($biaya->fresh()?->Status)->toBe(StatusDokumenTerposting::Diposting)
            ->and(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);
    });

    it('halaman: daftar, formulir dari penerimaan, simpan, rincian, batalkan; kasir tanpa izin; tenant lain 404', function (): void {
        $t = BantuanPembelian::SiapkanTenant();
        $beras = BantuanKatalog::BuatProduk(['Nama' => 'Beras Premium Rojolele 5 kg'], '85000.00', $t['Pcs']);
        $grn = BantuanPembelian::TerimaTanpaPo(null, $t['Gudang'], [[$beras, '10', '2000']], $t['Pemilik']->Id);
        BantuanOrganisasi::Masuk($this, $t['Pemilik'], $t['Tenant']->Id);

        $this->get("/kelola/pembelian/biaya-tambahan/buat?penerimaan={$grn->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h
            ->component('Kelola/Pembelian/BiayaTambahan/Buat')->where('Penerimaan.Nomor', $grn->Nomor)->where('Penerimaan.UuidPemasok', null)->where('Penerimaan.Baris.0.Jumlah', '10.0000'));
        $this->post('/kelola/pembelian/biaya-tambahan', [
            'UuidPenerimaan' => $grn->Uuid,
            'Jenis' => 'BeaMasuk',
            'DasarAlokasi' => 'Nilai',
            'Tanggal' => BantuanPembelian::Hari()->format('Y-m-d'),
            'Jumlah' => '2500',
            'UuidAkun' => BantuanPembelian::AkunKas()->Uuid,
        ])->assertRedirect()->assertSessionHasNoErrors();
        BantuanOrganisasi::AturKonteks($t['Tenant']->Id);
        $biaya = BiayaTambahanPembelian::query()->sole();

        $this->get('/kelola/pembelian/biaya-tambahan')->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pembelian/BiayaTambahan/Daftar')->where('Biaya.Data.0.Nomor', $biaya->Nomor)->where('Biaya.Data.0.NomorPenerimaan', $grn->Nomor));
        $this->get("/kelola/pembelian/biaya-tambahan/{$biaya->Uuid}")->assertInertia(fn (AssertableInertia $h) => $h->component('Kelola/Pembelian/BiayaTambahan/Detail')
            ->where('Biaya.KePersediaan', '2500.00')->where('Baris.0.Alokasi', '2500.00')->where('Tindakan.Batalkan', true)->has('Jurnal', 1));
        $this->post("/kelola/pembelian/biaya-tambahan/{$biaya->Uuid}/batalkan", ['Alasan' => 'Salah nominal'])->assertRedirect();
        expect($biaya->fresh()?->Status)->toBe(StatusDokumenTerposting::Dibatalkan)
            ->and(SaldoStok::query()->where('IdProduk', $beras->Id)->sole()->NilaiPersediaan)->toBe('20000.00');

        BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id, PeranTenantBawaan::Kasir);
        $this->get('/kelola/pembelian/biaya-tambahan')->assertForbidden();

        $lain = BantuanOrganisasi::BuatTenant('Warung Sebelah');
        BantuanPersediaan::MasukSebagai($this, $lain['Tenant']->Id);
        $this->get("/kelola/pembelian/biaya-tambahan/{$biaya->Uuid}")->assertNotFound();
        $this->get("/kelola/pembelian/biaya-tambahan/buat?penerimaan={$grn->Uuid}")->assertNotFound();
    });
});
