<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Dokumen\Model\RiwayatStatusDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Persediaan\Aksi\BatalkanStokAwal;
use App\Domain\Persediaan\Aksi\PostingStokAwal;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\SaldoStok;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05a Tim C: pembatalan stok awal (DesainF05a C.6.5, CLAUDE.md #8). Dokumen Diposting tidak diedit; koreksinya
 * pembatalan penuh yang membalik stok (MutasiStok `B/…` dengan IdMutasiAsal) dan jurnal (IdJurnalDibalik). Ditolak
 * bila stok awal sudah terpakai (rata-rata bergerak, lapisan FIFO, batch, seri).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function AmbilKodeGalatBatal(Closure $kerja): ?string
{
    try {
        $kerja();
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat->kode;
    }

    return null;
}

describe('F-05a pembatalan stok awal', function (): void {
    it('membalik stok dan jurnal: mutasi B/… bertanda negatif ber-IdMutasiAsal, jurnal Pembatalan ber-IdJurnalDibalik, saldo kembali nol', function (): void {
        // Siang hari WIB: hari bisnis = tanggal kalender Jakarta (sebelum 04.00 WIB masih hari bisnis kemarin).
        // Tanggal hari ini (bukan tanggal tetap): dokumen legal prasyarat pendaftaran berlaku sejak kemarin.
        $this->travelTo(CarbonImmutable::now('UTC')->setTime(5, 0));
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [
            BantuanStokAwal::Baris($p['Stok'], '10', '1234.5678'),
            BantuanStokAwal::Baris($p['BahanBaku'], '12.5', '14750'),
        ], $t['Pemilik']->Id);

        $batal = app(BatalkanStokAwal::class)->Jalankan($dokumen, 'Salah input lokasi stok, seharusnya gudang belakang', $t['Pemilik']->Id);

        $asal = MutasiStok::query()->where('KunciBaris', 'like', 'P/%')->orderBy('Id')->get();
        $balik = MutasiStok::query()->where('KunciBaris', 'like', 'B/%')->orderBy('Id')->get();
        $jurnalBatal = Jurnal::query()->whereKey($batal->IdJurnalPembatalan)->firstOrFail();

        expect($batal->Status)->toBe(StatusStokAwal::Dibatalkan)
            ->and($batal->AlasanBatal)->toBe('Salah input lokasi stok, seharusnya gudang belakang')
            ->and($batal->DibatalkanOleh)->toBe($t['Pemilik']->Id)
            ->and($balik->pluck('KunciBaris')->all())->toBe($asal->map(fn ($m) => 'B/'.$m->KunciBaris)->all())
            ->and($balik->pluck('IdMutasiAsal')->all())->toBe($asal->pluck('Id')->all())
            ->and($balik->pluck('Jumlah')->all())->toBe(['-10.0000', '-12.5000'])
            ->and($balik->pluck('TotalHpp')->all())->toBe(['-12345.68', '-184375.00'])
            ->and($balik[0]->TanggalBisnis->format('Y-m-d'))->toBe(CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d'))
            ->and(SaldoStok::query()->where('JumlahTersedia', '!=', 0)->count())->toBe(0)
            ->and(SaldoStok::query()->where('NilaiPersediaan', '!=', 0)->count())->toBe(0)
            ->and($jurnalBatal->KunciSumber)->toBe('Pembatalan')
            ->and($jurnalBatal->IdJurnalDibalik)->toBe($dokumen->IdJurnal)
            ->and($jurnalBatal->TotalDebit)->toBe('196720.68')
            ->and(JurnalDetail::query()->where('IdJurnal', $jurnalBatal->Id)->sum('Debit'))->toEqual(JurnalDetail::query()->where('IdJurnal', $jurnalBatal->Id)->sum('Kredit'))
            ->and(RiwayatStatusDokumen::query()->where('IdDokumen', $dokumen->Id)->orderBy('Id')->pluck('StatusKe')->all())->toBe(['Draf', 'Diposting', 'Dibatalkan'])
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.batalkan')->where('IdObjek', $dokumen->Id)->exists())->toBeTrue()
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('idempoten: membatalkan dua kali tidak membuat mutasi atau jurnal kedua. Setelah batal produk boleh diberi stok awal lagi bertanggal tidak sebelum mutasi pembatalan (H-5)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')], $t['Pemilik']->Id);

        app(BatalkanStokAwal::class)->Jalankan($dokumen, 'Harga modal salah ketik', $t['Pemilik']->Id);
        app(BatalkanStokAwal::class)->Jalankan($dokumen, 'Harga modal salah ketik', $t['Pemilik']->Id);

        expect(MutasiStok::query()->count())->toBe(2)
            ->and(Jurnal::query()->count())->toBe(2);

        $ulang = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '37800')], $t['Pemilik']->Id, app(TanggalBisnisOutlet::class)->Hitung($t['Outlet']->Id)->format('Y-m-d'));
        expect($ulang->Status)->toBe(StatusStokAwal::Diposting)
            ->and(SaldoStok::query()->where('IdProduk', $p['Stok']->Id)->value('NilaiPersediaan'))->toBe('378000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('hanya dokumen Diposting yang bisa dibatalkan. Alasan wajib 5–255 karakter. Posting ulang dokumen batal ditolak', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')]);

        expect(AmbilKodeGalatBatal(fn () => app(BatalkanStokAwal::class)->Jalankan($draf, 'Salah input', $t['Pemilik']->Id)))->toBe('StatusTidakSesuai');

        $dokumen = app(PostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id);
        expect(AmbilKodeGalatBatal(fn () => app(BatalkanStokAwal::class)->Jalankan($dokumen, 'oke', $t['Pemilik']->Id)))->toBe('AlasanTidakValid');

        app(BatalkanStokAwal::class)->Jalankan($dokumen, 'Salah input jumlah', $t['Pemilik']->Id);
        expect(AmbilKodeGalatBatal(fn () => app(PostingStokAwal::class)->Jalankan($dokumen, $t['Pemilik']->Id)))->toBe('StatusTidakSesuai');
    });
});

describe('F-05a pembatalan stok awal ditolak bila stok sudah terpakai (StokSudahTerpakai)', function (): void {
    it('rata-rata bergerak: saldo sudah berkurang oleh penjualan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')], $t['Pemilik']->Id);
        BantuanStokAwal::Jual($p['Stok'], $t['Gudang'], '3');

        expect(AmbilKodeGalatBatal(fn () => app(BatalkanStokAwal::class)->Jalankan($dokumen, 'Mau input ulang', $t['Pemilik']->Id)))->toBe('StokSudahTerpakai')
            ->and($dokumen->fresh()?->Status)->toBe(StatusStokAwal::Diposting)
            ->and(MutasiStok::query()->where('KunciBaris', 'like', 'B/%')->count())->toBe(0)
            ->and(BantuanStokAwal::PeriksaInvarianTanpaJurnalPenjualan($t['Tenant']->Id))->toBe([]);
    });

    it('FIFO: lapisan stok awal sudah terpakai', function (): void {
        $t = BantuanPersediaan::SiapkanTenant(metodeHpp: MetodeHpp::Fifo);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')], $t['Pemilik']->Id);
        BantuanStokAwal::Jual($p['Stok'], $t['Gudang'], '1');

        expect(AmbilKodeGalatBatal(fn () => app(BatalkanStokAwal::class)->Jalankan($dokumen, 'Mau input ulang', $t['Pemilik']->Id)))->toBe('StokSudahTerpakai')
            ->and(BantuanStokAwal::PeriksaInvarianTanpaJurnalPenjualan($t['Tenant']->Id, fifo: true))->toBe([]);
    });

    it('FIFO tanpa pemakaian: pembatalan berhasil dan lapisan habis', function (): void {
        $t = BantuanPersediaan::SiapkanTenant(metodeHpp: MetodeHpp::Fifo);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')], $t['Pemilik']->Id);

        expect(app(BatalkanStokAwal::class)->Jalankan($dokumen, 'Mau input ulang', $t['Pemilik']->Id)->Status)->toBe(StatusStokAwal::Dibatalkan)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id, fifo: true))->toBe([]);
    });

    it('batch: sisa batch stok awal sudah berkurang', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Batch'], '24', '18250', 'UHT-2609A', '2027-03-31')], $t['Pemilik']->Id);
        BantuanStokAwal::Jual($p['Batch'], $t['Gudang'], '2', idBatchStok: BatchStok::query()->value('Id'));

        expect(AmbilKodeGalatBatal(fn () => app(BatalkanStokAwal::class)->Jalankan($dokumen, 'Mau input ulang', $t['Pemilik']->Id)))->toBe('StokSudahTerpakai')
            ->and(BantuanStokAwal::PeriksaInvarianTanpaJurnalPenjualan($t['Tenant']->Id))->toBe([]);
    });

    it('seri: nomor seri stok awal sudah keluar, tanpa pemakaian pembatalan mengeluarkan semua nomor seri', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $gudangLain = BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Elektronik');
        $terjual = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Seri'], '2', '650000', nomorSeri: ['RC18-0001', 'RC18-0002'])], $t['Pemilik']->Id);
        $utuh = BantuanStokAwal::BuatDanPosting($gudangLain, [BantuanStokAwal::Baris($p['Seri'], '1', '655000', nomorSeri: ['RC18-0101'])], $t['Pemilik']->Id);
        BantuanStokAwal::Jual($p['Seri'], $t['Gudang'], '1', idNomorSeri: NomorSeri::query()->where('Nomor', 'RC18-0002')->value('Id'));

        expect(AmbilKodeGalatBatal(fn () => app(BatalkanStokAwal::class)->Jalankan($terjual, 'Mau input ulang', $t['Pemilik']->Id)))->toBe('StokSudahTerpakai')
            ->and(app(BatalkanStokAwal::class)->Jalankan($utuh, 'Salah lokasi stok', $t['Pemilik']->Id)->Status)->toBe(StatusStokAwal::Dibatalkan)
            ->and(NomorSeri::query()->where('Nomor', 'RC18-0101')->value('Status'))->not->toBe(StatusNomorSeri::Tersedia)
            ->and(BantuanStokAwal::PeriksaInvarianTanpaJurnalPenjualan($t['Tenant']->Id))->toBe([]);
    });
});
