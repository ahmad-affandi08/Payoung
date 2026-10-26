<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Aksi\BalikkanJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\HasilPostingJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

function BalikkanJurnalUji(int $idJurnal, int $idSumber = 1, string $tanggal = '2026-09-30', string $kunci = 'Pembatalan', ?int $idPengguna = null): HasilPostingJurnal
{
    return app(BalikkanJurnal::class)->Jalankan(
        $idJurnal,
        CarbonImmutable::parse($tanggal),
        'Pembatalan stok awal SA/2026/09/0001: salah input jumlah',
        JenisSumberJurnal::StokAwal,
        $idSumber,
        $kunci,
        $idPengguna,
    );
}

/**
 * @return list<array{IdAkun: int, IdOutlet: int|null, Debit: string, Kredit: string, Memo: string|null}>
 */
function AmbilBarisJurnalTanpaUrutan(int $idJurnal): array
{
    return JurnalDetail::query()->where('IdJurnal', $idJurnal)->orderBy('IdAkun')->orderBy('IdOutlet')->orderBy('Debit')->get()
        ->map(fn (JurnalDetail $d): array => ['IdAkun' => $d->IdAkun, 'IdOutlet' => $d->IdOutlet, 'Debit' => $d->Debit, 'Kredit' => $d->Kredit, 'Memo' => $d->Memo])
        ->all();
}

describe('F-05a BalikkanJurnal (aturan #8, DesainF05a C.5)', function (): void {
    it('aturan #8: jurnal pembalik adalah cermin persis (debit ↔ kredit per akun & outlet) dengan IdJurnalDibalik. Asal tidak berubah', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $solo = BantuanJurnal::BuatOutlet();
        $asal = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 1, idPengguna: $t['Pemilik']->Id, baris: [
            DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('10600000.00'), $t['Outlet']->Id, 'Minyak goreng 2 L'),
            DataBarisJurnal::Debit(PeranAkun::PersediaanBahanBaku, Uang::Dari('2500000.25'), $solo->Id),
            DataBarisJurnal::Debit(PeranAkun::SelisihHpp, Uang::Dari('400.00'), $t['Outlet']->Id),
            DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('10600400.00'), $t['Outlet']->Id),
            DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('2500000.25'), $solo->Id),
        ]));
        $sebelum = AmbilBarisJurnalTanpaUrutan($asal->idJurnal);

        $balik = BalikkanJurnalUji($asal->idJurnal, idPengguna: $t['Pemilik']->Id);
        $jurnalBalik = Jurnal::query()->findOrFail($balik->idJurnal);
        $jurnalAsal = Jurnal::query()->findOrFail($asal->idJurnal);

        $cermin = array_map(fn (array $b): array => [...$b, 'Debit' => $b['Kredit'], 'Kredit' => $b['Debit']], $sebelum);
        usort($cermin, fn (array $x, array $y): int => [$x['IdAkun'], $x['IdOutlet'], $x['Debit']] <=> [$y['IdAkun'], $y['IdOutlet'], $y['Debit']]);

        expect($balik->sudahAda)->toBeFalse()
            ->and($balik->nomor)->toBe('JU/2026/09/000002')
            ->and($jurnalBalik->IdJurnalDibalik)->toBe($asal->idJurnal)
            ->and($jurnalBalik->KunciSumber)->toBe('Pembatalan')
            ->and($jurnalBalik->Tanggal->toDateString())->toBe('2026-09-30')
            ->and($jurnalBalik->UuidSumber)->toBe($jurnalAsal->UuidSumber)
            ->and($jurnalBalik->NomorSumber)->toBe($jurnalAsal->NomorSumber)
            ->and($jurnalBalik->TotalDebit)->toBe($jurnalAsal->TotalKredit)
            ->and($jurnalBalik->Otomatis)->toBeTrue()
            ->and(AmbilBarisJurnalTanpaUrutan($balik->idJurnal))->toEqualCanonicalizing($cermin)
            ->and(AmbilBarisJurnalTanpaUrutan($asal->idJurnal))->toBe($sebelum)
            ->and($jurnalAsal->IdJurnalDibalik)->toBeNull()
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($t['Tenant']->Id))->toBe([]);

        // Asal + pembalik = nol per akun & outlet.
        $saldo = DB::table('JurnalDetail')->where('IdTenant', $t['Tenant']->Id)
            ->selectRaw('IdAkun, IdOutlet, SUM(Debit) - SUM(Kredit) AS Saldo')->groupBy('IdAkun', 'IdOutlet')->pluck('Saldo')->unique()->values()->all();
        expect($saldo)->toEqual(['0.00']);
    });

    it('idempotensi: membalik ulang dengan sumber & kunci sama mengembalikan pembalik yang sama', function (): void {
        BantuanPersediaan::SiapkanTenant();
        $asal = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal());
        $pertama = BalikkanJurnalUji($asal->idJurnal);
        $kedua = BalikkanJurnalUji($asal->idJurnal);

        expect($kedua->sudahAda)->toBeTrue()
            ->and($kedua->idJurnal)->toBe($pertama->idJurnal)
            ->and(Jurnal::query()->count())->toBe(2);
    });

    it('satu jurnal hanya bisa dibalik sekali (JurnalSudahDibalik). Jurnal tidak dikenal ditolak', function (): void {
        BantuanPersediaan::SiapkanTenant();
        $asal = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal());
        BalikkanJurnalUji($asal->idJurnal);

        $ganda = null;
        try {
            BalikkanJurnalUji($asal->idJurnal, kunci: 'PembatalanKedua');
        } catch (PelanggaranAturanBisnis $galat) {
            $ganda = $galat;
        }

        $takDikenal = null;
        try {
            BalikkanJurnalUji(999999);
        } catch (PelanggaranAturanBisnis $galat) {
            $takDikenal = $galat;
        }

        expect($ganda?->kode)->toBe('JurnalSudahDibalik')
            ->and($ganda?->getMessage())->toBe('Jurnal JU/2026/09/000001 sudah dibalik oleh jurnal JU/2026/09/000002.')
            ->and($takDikenal?->kode)->toBe('JurnalTidakDikenal')
            ->and(Jurnal::query()->count())->toBe(2);
    });

    it('PeriodeTerkunci: pembalik bertanggal di periode terkunci ditolak', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $asal = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(tanggal: '2026-08-15'));
        BantuanPersediaan::KunciPeriode('2026-08', $t['Pemilik']->Id);

        $galat = null;
        try {
            BalikkanJurnalUji($asal->idJurnal, tanggal: '2026-08-31');
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e;
        }

        expect($galat?->kode)->toBe('PeriodeTerkunci')
            ->and(BalikkanJurnalUji($asal->idJurnal, tanggal: '2026-09-01')->nomor)->toBe('JU/2026/09/000001');
    });

    it('isolasi tenant: jurnal tenant lain tidak bisa dibalik (JurnalTidakDikenal)', function (): void {
        BantuanPersediaan::SiapkanTenant('Toko Elektronik Maju Bersama');
        $asal = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal());
        BantuanPersediaan::SiapkanTenant('Toko Kelontong Bu Endang');

        $galat = null;
        try {
            BalikkanJurnalUji($asal->idJurnal);
        } catch (PelanggaranAturanBisnis $e) {
            $galat = $e;
        }

        expect($galat?->kode)->toBe('JurnalTidakDikenal')
            ->and(Jurnal::query()->count())->toBe(0);
    });
});
