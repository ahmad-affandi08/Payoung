<?php

declare(strict_types=1);

use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use Tests\Pendukung\Katalog\BantuanKatalog;
use Tests\Pendukung\Pembelian\BantuanPembelian;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * Audit kemudahan pakai #20 (F-04): belanja stok "Bayar nanti (tempo)" = penerimaan + faktur belum dibayar dalam
 * satu simpan; hutang tercatat (invarian HutangUsaha = Σ sisa faktur, GRNI nol, jurnal seimbang); pemasok wajib;
 * nomor nota kosong memakai nomor penerimaan; jatuh tempo = tanggal + tempo.
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

it('bayar nanti: GRN + faktur belum dibayar, jatuh tempo dari tempo, hutang & jurnal seimbang, tanpa pemasok ditolak', function (): void {
    $t = BantuanPembelian::SiapkanTenant();
    $minyak = BantuanKatalog::BuatProduk(['Nama' => 'Minyak Goreng Sawit Bening Kemasan Pouch 2 Liter']);
    $pemasok = BantuanPembelian::BuatPemasok('PT Sumber Pangan Nusantara');
    BantuanPersediaan::MasukSebagai($this, $t['Tenant']->Id);
    $tanggal = BantuanPembelian::Hari(1);
    $baris = [['UuidProduk' => $minyak->Uuid, 'Jumlah' => '24', 'Harga' => '31500']];

    $this->post('/kelola/pembelian/belanja-stok', [
        'UuidGudang' => $t['Gudang']->Uuid, 'Tanggal' => $tanggal->format('Y-m-d'), 'BayarNanti' => true, 'TerminHari' => 14, 'Baris' => $baris,
    ])->assertSessionHasErrors('UuidPemasok');
    expect(PenerimaanBarang::query()->count())->toBe(0);

    $this->post('/kelola/pembelian/belanja-stok', [
        'UuidPemasok' => $pemasok->Uuid, 'UuidGudang' => $t['Gudang']->Uuid, 'Tanggal' => $tanggal->format('Y-m-d'),
        'BayarNanti' => true, 'TerminHari' => 14, 'Baris' => $baris,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $grn = PenerimaanBarang::query()->sole();
    $faktur = FakturPembelian::query()->sole();
    expect($grn->BelanjaStok)->toBeFalse()
        ->and($grn->IdFakturPembelian)->toBe($faktur->Id)
        ->and($faktur->Status)->toBe(StatusFakturPembelian::BelumDibayar)
        ->and($faktur->BelanjaStok)->toBeFalse()
        ->and($faktur->NomorFakturPemasok)->toBe($grn->Nomor)
        ->and($faktur->Total)->toBe('756000.00')
        ->and($faktur->JatuhTempo->format('Y-m-d'))->toBe($tanggal->addDays(14)->format('Y-m-d'));
    expect(BantuanPembelian::PeriksaInvarian($t['Tenant']->Id))->toBe([]);

    $this->get('/kelola/pembelian/hutang')->assertOk()->assertInertia(fn ($h) => $h
        ->where('Hutang.Ringkasan.Total', '756000.00'));
});
