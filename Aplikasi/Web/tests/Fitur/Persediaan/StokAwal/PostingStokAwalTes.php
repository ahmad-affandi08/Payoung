<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Persediaan\Aksi\BuangStokAwal;
use App\Domain\Persediaan\Aksi\PostingStokAwal;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\LapisanFifo;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Persediaan\Model\StokAwal;
use Carbon\CarbonImmutable;
use Tests\Pendukung\Katalog\BantuanHarga;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\BantuanStokAwal;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

/*
 * F-05a Tim C: posting stok awal (DesainF05a C.6.4, J-05.1). Mutasi StokAwal + jurnal Dr Persediaan / Cr Ekuitas
 * Saldo Awal di satu transaksi, idempoten, dengan pemeriksaan ulang saat posting. Setiap skenario diakhiri
 * pemeriksaan invarian (BR-05.1: SaldoStok = Σ MutasiStok; Σ debit = Σ kredit; akun persediaan = nilai stok).
 */

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Baris jurnal sebagai [Kunci peran, Debit, Kredit, IdOutlet], urut Urutan.
 *
 * @return list<array{0: string, 1: string, 2: string, 3: int|null}>
 */
function AmbilBarisJurnalStokAwal(int $idJurnal): array
{
    $peran = PemetaanAkun::query()->whereNull('IdOutlet')->pluck('Kunci', 'IdAkun')->all();

    return array_values(JurnalDetail::query()->where('IdJurnal', $idJurnal)->orderBy('Urutan')->get()
        ->map(fn (JurnalDetail $d): array => [(string) ($peran[$d->IdAkun] ?? $d->IdAkun), $d->Debit, $d->Kredit, $d->IdOutlet])
        ->all());
}

function AmbilKodeGalatPosting(Closure $kerja): ?string
{
    try {
        $kerja();
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat->kode;
    }

    return null;
}

describe('F-05a posting stok awal J-05.1 (BR-05.1)', function (): void {
    it('memposting: status Diposting, nomor SA, MutasiStok per baris (P/{IdDetail}), saldo, jurnal Dr Persediaan per jenis / Cr Ekuitas Saldo Awal', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $tanggal = CarbonImmutable::now('Asia/Jakarta')->subDay();

        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [
            BantuanStokAwal::Baris($p['Stok'], '10', '1234.5678'),
            BantuanStokAwal::Baris($p['BahanBaku'], '25.5', '14750'),
            BantuanStokAwal::Baris($p['Produksi'], '40', '9500'),
        ], $t['Pemilik']->Id, $tanggal->format('Y-m-d'));

        $dokumen->refresh();
        $detail = $dokumen->Detail()->orderBy('Urutan')->get();
        $mutasi = MutasiStok::query()->orderBy('Id')->get();

        expect($dokumen->Status)->toBe(StatusStokAwal::Diposting)
            ->and($dokumen->Nomor)->toBe('SA/'.$tanggal->format('Y/m').'/0001')
            ->and($dokumen->TotalNilai)->toBe('768470.68')
            ->and($dokumen->DipostingOleh)->toBe($t['Pemilik']->Id)
            ->and($mutasi->pluck('KunciBaris')->all())->toBe($detail->map(fn ($d) => 'P/'.$d->Id)->all())
            ->and($mutasi->pluck('NomorReferensi')->unique()->all())->toBe([$dokumen->Nomor])
            ->and($mutasi->pluck('UuidReferensi')->unique()->all())->toBe([$dokumen->Uuid])
            ->and($mutasi[0]->TanggalBisnis->format('Y-m-d'))->toBe($tanggal->format('Y-m-d'))
            ->and($mutasi[0]->IdReferensiDetail)->toBe($detail[0]->Id)
            ->and(SaldoStok::query()->where('IdProduk', $p['Stok']->Id)->value('JumlahTersedia'))->toBe('10.0000')
            ->and(SaldoStok::query()->where('IdProduk', $p['Stok']->Id)->value('NilaiPersediaan'))->toBe('12345.68');

        $jurnal = Jurnal::query()->whereKey($dokumen->IdJurnal)->firstOrFail();
        expect($jurnal->Tanggal->format('Y-m-d'))->toBe($tanggal->format('Y-m-d'))
            ->and($jurnal->KunciSumber)->toBe('Utama')
            ->and($jurnal->NomorSumber)->toBe($dokumen->Nomor)
            ->and($jurnal->TotalDebit)->toBe('768470.68')
            ->and(AmbilBarisJurnalStokAwal($jurnal->Id))->toEqualCanonicalizing([
                ['PersediaanBahanBaku', '376125.00', '0.00', $t['Outlet']->Id],
                ['PersediaanBarangDagang', '392345.68', '0.00', $t['Outlet']->Id],
                ['EkuitasSaldoAwal', '0.00', '768470.68', $t['Outlet']->Id],
            ])
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.posting')->where('IdObjek', $dokumen->Id)->value('NilaiBaru'))->toMatchArray([
                'Nomor' => $dokumen->Nomor, 'IdJurnal' => $jurnal->Id, 'NomorJurnal' => $jurnal->Nomor, 'TotalNilai' => '768470.68', 'JumlahBaris' => 3,
            ])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('posting berulang idempoten: satu set mutasi dan satu jurnal', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '48', '37500')]);

        $pertama = app(PostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id);
        $kedua = app(PostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id);

        expect($kedua->Nomor)->toBe($pertama->Nomor)
            ->and($kedua->IdJurnal)->toBe($pertama->IdJurnal)
            ->and(MutasiStok::query()->count())->toBe(1)
            ->and(Jurnal::query()->count())->toBe(1)
            ->and(LogAudit::query()->where('Peristiwa', 'stok-awal.posting')->count())->toBe(1)
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('jurnal per outlet: lokasi stok di outlet cabang memakai dimensi outlet cabang, nomor SA berurutan per tenant', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $cabang = BantuanHarga::BuatOutlet('SLO-02', 'Cabang Solo Baru');
        $gudangCabang = BantuanPersediaan::BuatGudang($cabang, 'Gudang Cabang Solo Baru');

        $utama = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '12', '37500')], $t['Pemilik']->Id);
        $solo = BantuanStokAwal::BuatDanPosting($gudangCabang, [BantuanStokAwal::Baris($p['Stok'], '6', '37750')], $t['Pemilik']->Id);

        expect(substr((string) $solo->Nomor, -4))->toBe('0002')
            ->and(substr((string) $utama->Nomor, -4))->toBe('0001')
            ->and($solo->IdOutlet)->toBe($cabang->Id)
            ->and(array_unique(array_map(fn (array $b) => $b[3], AmbilBarisJurnalStokAwal((int) $solo->IdJurnal))))->toBe([$cabang->Id])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('produk batch: BatchStok terisi. Produk seri: satu mutasi per nomor dengan pembagian nilai 333,33/333,33/333,34', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);

        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [
            BantuanStokAwal::Baris($p['Batch'], '24', '18250', 'UHT-2609A', '2027-03-31'),
            BantuanStokAwal::Baris($p['Seri'], '3', '333.333333', nomorSeri: ['RC18-0001', 'RC18-0002', 'RC18-0003']),
        ], $t['Pemilik']->Id);

        $idSeri = $dokumen->Detail()->where('IdProduk', $p['Seri']->Id)->value('Id');
        $seri = MutasiStok::query()->where('IdProduk', $p['Seri']->Id)->orderBy('Id')->get();

        expect(BatchStok::query()->where('IdProduk', $p['Batch']->Id)->value('JumlahSisa'))->toBe('24.0000')
            ->and(BatchStok::query()->where('IdProduk', $p['Batch']->Id)->value('NomorBatch'))->toBe('UHT-2609A')
            ->and($seri->pluck('KunciBaris')->all())->toBe(["P/{$idSeri}/1", "P/{$idSeri}/2", "P/{$idSeri}/3"])
            ->and($seri->pluck('TotalHpp')->all())->toBe(['333.33', '333.33', '333.34'])
            ->and(NomorSeri::query()->where('IdProduk', $p['Seri']->Id)->where('Status', 'Tersedia')->count())->toBe(3)
            ->and(Jurnal::query()->whereKey($dokumen->IdJurnal)->value('TotalDebit'))->toBe('439000.00')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('tenant FIFO: stok awal membuat lapisan FIFO bernilai baris', function (): void {
        $t = BantuanPersediaan::SiapkanTenant(metodeHpp: MetodeHpp::Fifo);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);

        BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '1234.5678')], $t['Pemilik']->Id);

        expect(LapisanFifo::query()->where('IdProduk', $p['Stok']->Id)->value('NilaiSisa'))->toBe('12345.68')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id, fifo: true))->toBe([]);
    });

    it('BR-04.3 stok awal setelah stok minus (H-16): selisih HPP masuk akun Selisih HPP, jurnal tetap seimbang', function (): void {
        $t = BantuanPersediaan::SiapkanTenant(stokBolehMinus: true);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $hariIni = CarbonImmutable::now('Asia/Jakarta');
        BantuanStokAwal::Jual($p['Stok'], $t['Gudang'], '4', $hariIni->subDays(2)->format('Y-m-d'));

        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '1100')], $t['Pemilik']->Id, $hariIni->subDay()->format('Y-m-d'));

        expect(SaldoStok::query()->where('IdProduk', $p['Stok']->Id)->value('JumlahTersedia'))->toBe('6.0000')
            ->and(SaldoStok::query()->where('IdProduk', $p['Stok']->Id)->value('NilaiPersediaan'))->toBe('6600.00')
            ->and(AmbilBarisJurnalStokAwal((int) $dokumen->IdJurnal))->toEqualCanonicalizing([
                ['PersediaanBarangDagang', '6600.00', '0.00', $t['Outlet']->Id],
                ['SelisihHpp', '4400.00', '0.00', $t['Outlet']->Id],
                ['EkuitasSaldoAwal', '0.00', '11000.00', $t['Outlet']->Id],
            ])
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});

describe('F-05a posting stok awal: pemeriksaan ulang saat posting', function (): void {
    it('StokAwalSudahAda: produk yang sudah punya stok awal Diposting di lokasi yang sama ditolak. Lokasi lain boleh', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '5', '38000')], $t['Pemilik']->Id);
        $kedua = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Produksi'], '5', '9000'), BantuanStokAwal::Baris($p['Stok'], '7', '38000')]);

        expect(AmbilKodeGalatPosting(fn () => app(PostingStokAwal::class)->Jalankan($kedua, $t['Pemilik']->Id)))->toBe('StokAwalSudahAda')
            ->and($kedua->fresh()?->Status)->toBe(StatusStokAwal::Draf)
            ->and(MutasiStok::query()->count())->toBe(1);

        $belakang = BantuanPersediaan::BuatGudang($t['Outlet'], 'Gudang Belakang Toko');
        expect(AmbilKodeGalatPosting(fn () => BantuanStokAwal::BuatDanPosting($belakang, [BantuanStokAwal::Baris($p['Stok'], '7', '38000')], $t['Pemilik']->Id)))->toBeNull()
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });

    it('TanggalSebelumMutasiTerakhir: tanggal stok awal sebelum mutasi terakhir pasangan (produk, lokasi) ditolak (H-5)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant(stokBolehMinus: true);
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $hariIni = CarbonImmutable::now('Asia/Jakarta');
        BantuanStokAwal::Jual($p['Stok'], $t['Gudang'], '2', $hariIni->subDay()->format('Y-m-d'));
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')], $hariIni->subDays(3)->format('Y-m-d'));

        expect(AmbilKodeGalatPosting(fn () => app(PostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id)))->toBe('TanggalSebelumMutasiTerakhir')
            ->and(StokAwal::query()->whereKey($draf->Id)->value('Status'))->toBe(StatusStokAwal::Draf);
    });

    it('PemetaanAkunBelumAda: akun Ekuitas Saldo Awal belum dipetakan, posting ditolak tanpa efek stok', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        PemetaanAkun::query()->where('Kunci', 'EkuitasSaldoAwal')->delete();
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')]);

        expect(AmbilKodeGalatPosting(fn () => app(PostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id)))->toBe('PemetaanAkunBelumAda')
            ->and(MutasiStok::query()->count())->toBe(0)
            ->and(SaldoStok::query()->where('JumlahTersedia', '!=', 0)->count())->toBe(0);
    });

    it('periode terkunci: posting stok awal bertanggal di periode yang dikunci ditolak PeriodeTerkunci', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $tanggal = CarbonImmutable::now('Asia/Jakarta')->subMonthNoOverflow()->startOfMonth();
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')], $tanggal->format('Y-m-d'));
        BantuanPersediaan::KunciPeriode($tanggal->format('Y-m'));

        expect(AmbilKodeGalatPosting(fn () => app(PostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id)))->toBe('PeriodeTerkunci')
            ->and(MutasiStok::query()->count())->toBe(0)
            ->and(Jurnal::query()->count())->toBe(0);
    });

    it('produk yang diarsipkan setelah draf dibuat ditolak saat posting (pemeriksaan ulang C.6.1)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')]);
        $p['Stok']->update(['DiarsipkanPada' => now(), 'Aktif' => false]);

        expect(AmbilKodeGalatPosting(fn () => app(PostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id)))->toBe('ProdukDiarsipkan');
    });

    it('dokumen Dibuang tidak bisa diposting (StatusTidakSesuai)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);
        $draf = BantuanStokAwal::BuatDraf($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '10', '38000')]);
        app(BuangStokAwal::class)->Jalankan($draf);

        expect(AmbilKodeGalatPosting(fn () => app(PostingStokAwal::class)->Jalankan($draf, $t['Pemilik']->Id)))->toBe('StatusTidakSesuai');
    });

    it('stok awal bernilai nol diposting tanpa jurnal (tidak ada JurnalKosong)', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $p = BantuanPersediaan::BuatProdukSemuaJenis($t['Pcs'], $t['Kg']);

        $dokumen = BantuanStokAwal::BuatDanPosting($t['Gudang'], [BantuanStokAwal::Baris($p['Stok'], '3', '0')], $t['Pemilik']->Id);

        expect($dokumen->Status)->toBe(StatusStokAwal::Diposting)
            ->and($dokumen->IdJurnal)->toBeNull()
            ->and(Jurnal::query()->count())->toBe(0)
            ->and(SaldoStok::query()->where('IdProduk', $p['Stok']->Id)->value('JumlahTersedia'))->toBe('3.0000')
            ->and(PemeriksaInvarian::PeriksaSemua($t['Tenant']->Id))->toBe([]);
    });
});
