<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Audit\Model\LogAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use Illuminate\Support\Facades\DB;
use Tests\Pendukung\Akuntansi\BantuanJurnal;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Persediaan\PemeriksaInvarian;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/** Menjalankan `fn` dan mengembalikan pelanggaran aturan bisnis yang dilemparnya. */
function TangkapPelanggaranJurnal(callable $fn): PelanggaranAturanBisnis
{
    try {
        $fn();
    } catch (PelanggaranAturanBisnis $galat) {
        return $galat;
    }

    throw new RuntimeException('Diharapkan PelanggaranAturanBisnis, tidak ada yang dilempar.');
}

/**
 * @return list<array{IdAkun: int, IdOutlet: int|null, Debit: string, Kredit: string, Urutan: int}>
 */
function BuatBarisJurnalUji(int $idJurnal): array
{
    return JurnalDetail::query()->where('IdJurnal', $idJurnal)->orderBy('Urutan')->get()
        ->map(fn (JurnalDetail $d): array => ['IdAkun' => $d->IdAkun, 'IdOutlet' => $d->IdOutlet, 'Debit' => $d->Debit, 'Kredit' => $d->Kredit, 'Urutan' => $d->Urutan])
        ->all();
}

describe('F-05a J-05.1 PostingJurnal (DesainF05a C.5)', function (): void {
    it('J-05.1: jurnal stok awal seimbang tersimpan dengan nomor JU, periode, sumber, dan baris debit dulu', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $data = BantuanJurnal::DataStokAwal(idSumber: 7, nilai: '12345678.90', idOutlet: $t['Outlet']->Id, idPengguna: $t['Pemilik']->Id);

        $hasil = BantuanJurnal::Posting($data);
        $jurnal = Jurnal::query()->findOrFail($hasil->idJurnal);

        expect($hasil->sudahAda)->toBeFalse()
            ->and($hasil->nomor)->toBe('JU/2026/09/000001')
            ->and($hasil->uuid)->toBe($jurnal->Uuid)
            ->and($jurnal->IdTenant)->toBe($t['Tenant']->Id)
            ->and($jurnal->Tanggal->toDateString())->toBe('2026-09-24')
            ->and($jurnal->Periode)->toBe('2026-09')
            ->and($jurnal->IdSumber)->toBe(7)
            ->and($jurnal->UuidSumber)->toBe($data->uuidSumber)
            ->and($jurnal->NomorSumber)->toBe('SA/2026/09/0007')
            ->and($jurnal->KunciSumber)->toBe('Utama')
            ->and($jurnal->Otomatis)->toBeTrue()
            ->and($jurnal->TotalDebit)->toBe('12345678.90')
            ->and($jurnal->TotalKredit)->toBe('12345678.90')
            ->and($jurnal->DibuatOleh)->toBe($t['Pemilik']->Id)
            ->and(BuatBarisJurnalUji($jurnal->Id))->toBe([
                ['IdAkun' => BantuanJurnal::IdAkunPeran(PeranAkun::PersediaanBarangDagang), 'IdOutlet' => $t['Outlet']->Id, 'Debit' => '12345678.90', 'Kredit' => '0.00', 'Urutan' => 1],
                ['IdAkun' => BantuanJurnal::IdAkunPeran(PeranAkun::EkuitasSaldoAwal), 'IdOutlet' => $t['Outlet']->Id, 'Debit' => '0.00', 'Kredit' => '12345678.90', 'Urutan' => 2],
            ])
            ->and(JurnalDetail::query()->where('IdJurnal', $jurnal->Id)->pluck('Tanggal')->map->toDateString()->unique()->all())->toBe(['2026-09-24'])
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($t['Tenant']->Id))->toBe([]);
    });

    it('J-05.1: Σ debit ≠ Σ kredit ditolak JurnalTidakSeimbang dengan total, tanpa jurnal & tanpa nomor terpakai', function (): void {
        BantuanPersediaan::SiapkanTenant();
        $galat = TangkapPelanggaranJurnal(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(baris: [
            DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('1500000.00')),
            DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('1499999.99')),
        ])));

        expect($galat->kode)->toBe('JurnalTidakSeimbang')
            ->and($galat->detail)->toBe(['TotalDebit' => '1500000.00', 'TotalKredit' => '1499999.99'])
            ->and(Jurnal::query()->count())->toBe(0)
            ->and(JurnalDetail::query()->count())->toBe(0);

        // Nomor tidak terpakai: jurnal berikutnya tetap 000001.
        expect(BantuanJurnal::Posting(BantuanJurnal::DataStokAwal())->nomor)->toBe('JU/2026/09/000001');
    });

    it('J-05.1: jurnal bernilai nol ditolak JurnalKosong. Baris nol di kedua sisi dibuang', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();

        expect(TangkapPelanggaranJurnal(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(nilai: '0.00')))->kode)->toBe('JurnalKosong')
            ->and(TangkapPelanggaranJurnal(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(baris: [])))->kode)->toBe('JurnalKosong');

        $hasil = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(baris: [
            DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('250000.00')),
            DataBarisJurnal::Debit(PeranAkun::SelisihHpp, Uang::Nol()),
            DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('250000.00')),
        ]));

        expect(BuatBarisJurnalUji($hasil->idJurnal))->toHaveCount(2)
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($t['Tenant']->Id))->toBe([]);
    });

    it('J-05.1: baris negatif, dua sisi terisi, atau tanpa/ganda akun ditolak BarisJurnalTidakValid', function (array $baris, string $pesan): void {
        // Pest mengevaluasi closure dataset lebih dulu, jadi $baris sudah berupa daftar baris.
        BantuanPersediaan::SiapkanTenant();
        $galat = TangkapPelanggaranJurnal(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(baris: $baris)));

        expect($galat->kode)->toBe('BarisJurnalTidakValid')
            ->and($galat->getMessage())->toContain($pesan)
            ->and(Jurnal::query()->count())->toBe(0);
    })->with([
        'debit negatif' => [fn () => [
            new DataBarisJurnal(PeranAkun::PersediaanBarangDagang, null, null, Uang::Dari('-1000.00'), Uang::Nol()),
            DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('-1000.00')),
        ], 'ke-1 tidak boleh bernilai negatif'],
        'dua sisi' => [fn () => [
            new DataBarisJurnal(PeranAkun::PersediaanBarangDagang, null, null, Uang::Dari('1000.00'), Uang::Dari('1000.00')),
        ], 'ke-1 hanya boleh berisi debit atau kredit'],
        'tanpa akun' => [fn () => [
            DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('1000.00')),
            new DataBarisJurnal(null, null, null, Uang::Nol(), Uang::Dari('1000.00')),
        ], 'ke-2 harus menyebut tepat satu'],
        'peran dan akun sekaligus' => [fn () => [
            new DataBarisJurnal(PeranAkun::PersediaanBarangDagang, 1, null, Uang::Dari('1000.00'), Uang::Nol()),
        ], 'ke-1 harus menyebut tepat satu'],
    ]);

    it('J-05.1: baris dengan akun, outlet, dan sisi sama digabung. Debit & kredit akun sama tidak dinetralkan', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $hasil = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(baris: [
            DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('1000000.00'), $t['Outlet']->Id),
            DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('600000.00'), $t['Outlet']->Id, 'Minyak goreng'),
            DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('400000.50'), $t['Outlet']->Id, 'Beras'),
            DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('0.50'), $t['Outlet']->Id),
            DataBarisJurnal::Debit(PeranAkun::SelisihHpp, Uang::Dari('75.25'), $t['Outlet']->Id),
            DataBarisJurnal::Kredit(PeranAkun::SelisihHpp, Uang::Dari('75.25'), $t['Outlet']->Id),
            DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('1.00')),
            DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('1.00')),
        ]));

        $persediaan = BantuanJurnal::IdAkunPeran(PeranAkun::PersediaanBarangDagang);
        $ekuitas = BantuanJurnal::IdAkunPeran(PeranAkun::EkuitasSaldoAwal);
        $selisih = BantuanJurnal::IdAkunPeran(PeranAkun::SelisihHpp);
        $o = $t['Outlet']->Id;

        expect(BuatBarisJurnalUji($hasil->idJurnal))->toBe([
            ['IdAkun' => $persediaan, 'IdOutlet' => $o, 'Debit' => '1000000.50', 'Kredit' => '0.00', 'Urutan' => 1],
            ['IdAkun' => $selisih, 'IdOutlet' => $o, 'Debit' => '75.25', 'Kredit' => '0.00', 'Urutan' => 2],
            ['IdAkun' => $persediaan, 'IdOutlet' => null, 'Debit' => '1.00', 'Kredit' => '0.00', 'Urutan' => 3],
            ['IdAkun' => $ekuitas, 'IdOutlet' => $o, 'Debit' => '0.00', 'Kredit' => '1000000.50', 'Urutan' => 4],
            ['IdAkun' => $selisih, 'IdOutlet' => $o, 'Debit' => '0.00', 'Kredit' => '75.25', 'Urutan' => 5],
            ['IdAkun' => $ekuitas, 'IdOutlet' => null, 'Debit' => '0.00', 'Kredit' => '1.00', 'Urutan' => 6],
        ])
            ->and(JurnalDetail::query()->where('IdJurnal', $hasil->idJurnal)->where('Urutan', 1)->value('Memo'))->toBe('Minyak goreng')
            ->and(Jurnal::query()->findOrFail($hasil->idJurnal)->TotalDebit)->toBe('1000076.75')
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($t['Tenant']->Id))->toBe([]);
    });

    it('J-05.1: akun yang disebut langsung (idAkun) dipakai apa adanya', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $kas = BantuanJurnal::IdAkunPeran(PeranAkun::KasOutlet);
        $hasil = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(baris: [
            new DataBarisJurnal(null, $kas, null, Uang::Dari('50000.00'), Uang::Nol(), 'Setoran modal'),
            DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('50000.00')),
        ]));

        expect(BuatBarisJurnalUji($hasil->idJurnal)[0]['IdAkun'])->toBe($kas)
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($t['Tenant']->Id))->toBe([]);
    });

    it('idempotensi: sumber & KunciSumber sama diposting ulang = jurnal sama (sudahAda), tanpa baris ganda atau nomor baru', function (): void {
        BantuanPersediaan::SiapkanTenant();
        $data = BantuanJurnal::DataStokAwal(idSumber: 11);
        $pertama = BantuanJurnal::Posting($data);
        $kedua = BantuanJurnal::Posting($data);

        expect($kedua->sudahAda)->toBeTrue()
            ->and($kedua->idJurnal)->toBe($pertama->idJurnal)
            ->and($kedua->uuid)->toBe($pertama->uuid)
            ->and($kedua->nomor)->toBe($pertama->nomor)
            ->and(Jurnal::query()->count())->toBe(1)
            ->and(JurnalDetail::query()->count())->toBe(2);

        // KunciSumber lain pada dokumen yang sama = jurnal baru (misal Pembatalan).
        $lain = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 11, kunciSumber: 'Koreksi'));
        expect($lain->sudahAda)->toBeFalse()->and($lain->nomor)->toBe('JU/2026/09/000002');
    });

    it('idempotensi: sumber sama dengan total berbeda ditolak JurnalSumberGanda. Jurnal lama tidak berubah', function (): void {
        BantuanPersediaan::SiapkanTenant();
        BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 12, nilai: '1000000.00'));
        $galat = TangkapPelanggaranJurnal(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 12, nilai: '1000000.01')));

        expect($galat->kode)->toBe('JurnalSumberGanda')
            ->and(Jurnal::query()->sole()->TotalDebit)->toBe('1000000.00');
    });

    it('PemetaanAkunBelumAda: peran tanpa pemetaan ditolak dengan pesan panduan awal', function (): void {
        BantuanPersediaan::SiapkanTenant();
        PemetaanAkun::query()->where('Kunci', PeranAkun::EkuitasSaldoAwal->value)->delete();

        $galat = TangkapPelanggaranJurnal(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal()));

        expect($galat->kode)->toBe('PemetaanAkunBelumAda')
            ->and($galat->getMessage())->toBe('Akun untuk Ekuitas saldo awal belum dipetakan. Tekan "Perbaiki otomatis" di Akuntansi › Pemetaan akun atau minta Akuntan memetakan akun.')
            ->and($galat->detail)->toBe(['Kunci' => 'EkuitasSaldoAwal', 'Label' => 'Ekuitas saldo awal'])
            ->and(Jurnal::query()->count())->toBe(0);
    });

    it('PemetaanAkunBelumAda: akun terpetakan dengan tipe salah ditolak', function (): void {
        BantuanPersediaan::SiapkanTenant();
        $beban = BantuanJurnal::AkunLain(TipeAkun::Beban, 0);
        PemetaanAkun::query()->where('Kunci', PeranAkun::PersediaanBarangDagang->value)->update(['IdAkun' => $beban->Id]);

        $galat = TangkapPelanggaranJurnal(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal()));

        expect($galat->kode)->toBe('PemetaanAkunBelumAda')
            ->and($galat->getMessage())->toContain("Akun {$beban->Kode} {$beban->Nama}")
            ->and($galat->getMessage())->toContain('bertipe Beban, seharusnya Aset');
    });

    it('BR-02.4: pemetaan akun outlet menimpa pemetaan tenant hanya untuk outlet itu', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $solo = BantuanJurnal::BuatOutlet();
        $persediaanTenant = BantuanJurnal::IdAkunPeran(PeranAkun::PersediaanBarangDagang);
        $persediaanSolo = BantuanJurnal::AkunLain(TipeAkun::Aset, $persediaanTenant);
        PemetaanAkun::query()->create(['Kunci' => PeranAkun::PersediaanBarangDagang->value, 'IdAkun' => $persediaanSolo->Id, 'IdOutlet' => $solo->Id]);

        $diSolo = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 21, idOutlet: $solo->Id));
        $diUtama = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 22, idOutlet: $t['Outlet']->Id));

        expect(BuatBarisJurnalUji($diSolo->idJurnal)[0]['IdAkun'])->toBe($persediaanSolo->Id)
            ->and(BuatBarisJurnalUji($diSolo->idJurnal)[1]['IdAkun'])->toBe(BantuanJurnal::IdAkunPeran(PeranAkun::EkuitasSaldoAwal))
            ->and(BuatBarisJurnalUji($diUtama->idJurnal)[0]['IdAkun'])->toBe($persediaanTenant);
    });

    it('PeriodeTerkunci: jurnal bertanggal di periode terkunci ditolak. Periode lain tetap bisa', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanPersediaan::KunciPeriode('2026-08', $t['Pemilik']->Id);

        $galat = TangkapPelanggaranJurnal(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(tanggal: '2026-08-31')));

        expect($galat->kode)->toBe('PeriodeTerkunci')
            ->and($galat->bidang)->toBe('Tanggal')
            ->and($galat->getMessage())->toStartWith('Periode Agustus 2026 sudah dikunci.')
            ->and(Jurnal::query()->count())->toBe(0)
            ->and(BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(tanggal: '2026-09-01'))->nomor)->toBe('JU/2026/09/000001');
    });

    it('penomoran JU/YYYY/MM/NNNNNN tanpa celah per tenant & bulan, termasuk saat transaksi pemanggil batal', function (): void {
        BantuanPersediaan::SiapkanTenant();

        $nomor = [];
        foreach ([1, 2, 3] as $id) {
            $nomor[] = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: $id))->nomor;
        }

        // Dokumen sumber gagal setelah jurnal diposting: jurnal & nomornya ikut batal (savepoint).
        try {
            DB::transaction(function (): void {
                BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 4));
                throw new RuntimeException('Dokumen sumber gagal');
            });
        } catch (RuntimeException) {
        }

        $nomor[] = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 5))->nomor;
        $nomor[] = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 6, tanggal: '2026-10-01'))->nomor;

        expect($nomor)->toBe(['JU/2026/09/000001', 'JU/2026/09/000002', 'JU/2026/09/000003', 'JU/2026/09/000004', 'JU/2026/10/000001'])
            ->and(Jurnal::query()->where('IdSumber', 4)->exists())->toBeFalse();

        // Tenant lain mulai dari 000001 sendiri.
        BantuanPersediaan::SiapkanTenant('Warung Kopi Kedua Sejahtera');
        expect(BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 1))->nomor)->toBe('JU/2026/09/000001');
    });

    it('H-12: LogAudit jurnal.posting hanya untuk jurnal manual, bukan jurnal otomatis', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 1));
        expect(LogAudit::query()->where('Peristiwa', 'jurnal.posting')->count())->toBe(0);

        $manual = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 2, otomatis: false, idPengguna: $t['Pemilik']->Id));
        $log = LogAudit::query()->where('Peristiwa', 'jurnal.posting')->sole();

        expect($log->IdObjek)->toBe($manual->idJurnal)
            ->and($log->IdPengguna)->toBe($t['Pemilik']->Id)
            ->and($log->NilaiBaru)->toMatchArray(['Nomor' => $manual->nomor, 'TotalDebit' => '12345678.90'])
            ->and(Jurnal::query()->findOrFail($manual->idJurnal)->Otomatis)->toBeFalse();
    });

    it('aturan #8: jurnal hasil posting tidak bisa diubah atau dihapus lewat model', function (): void {
        BantuanPersediaan::SiapkanTenant();
        $hasil = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal());
        $jurnal = Jurnal::query()->findOrFail($hasil->idJurnal);
        $baris = JurnalDetail::query()->where('IdJurnal', $jurnal->Id)->firstOrFail();

        expect(fn () => $jurnal->update(['TotalDebit' => '1.00']))->toThrow(LogicException::class, Jurnal::PESAN_TIDAK_BISA_DIUBAH)
            ->and(fn () => $jurnal->delete())->toThrow(LogicException::class, Jurnal::PESAN_TIDAK_BISA_DIUBAH)
            ->and(fn () => $baris->update(['Debit' => '1.00']))->toThrow(LogicException::class, Jurnal::PESAN_TIDAK_BISA_DIUBAH)
            ->and(fn () => $baris->delete())->toThrow(LogicException::class, Jurnal::PESAN_TIDAK_BISA_DIUBAH)
            ->and($jurnal->refresh()->TotalDebit)->toBe('12345678.90');
    });

    it('isolasi tenant: outlet atau akun milik tenant lain ditolak BarisJurnalTidakValid', function (): void {
        $a = BantuanPersediaan::SiapkanTenant('Toko Bangunan Sumber Rejeki');
        $akunA = BantuanJurnal::IdAkunPeran(PeranAkun::KasOutlet);
        $b = BantuanPersediaan::SiapkanTenant('Apotek Sehat Sentosa');

        $galatOutlet = TangkapPelanggaranJurnal(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idOutlet: $a['Outlet']->Id)));
        $galatAkun = TangkapPelanggaranJurnal(fn () => BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(baris: [
            new DataBarisJurnal(null, $akunA, null, Uang::Dari('1000.00'), Uang::Nol()),
            DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('1000.00')),
        ])));

        expect($galatOutlet->kode)->toBe('BarisJurnalTidakValid')
            ->and($galatAkun->kode)->toBe('BarisJurnalTidakValid')
            ->and(Jurnal::query()->count())->toBe(0);

        // Jurnal tenant B memakai akun tenant B; tenant A tidak melihatnya.
        $hasilB = BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idOutlet: $b['Outlet']->Id));
        BantuanPersediaan::SiapkanTenant('Kedai Ketiga');
        expect(Jurnal::query()->whereKey($hasilB->idJurnal)->exists())->toBeFalse()
            ->and(PemeriksaInvarian::PeriksaJurnalSeimbang($b['Tenant']->Id))->toBe([]);
    });

    it('invarian: banyak jurnal campuran tetap Σ debit = Σ kredit per jurnal dan per tenant', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $solo = BantuanJurnal::BuatOutlet();

        foreach (['1234567.89', '0.01', '98765432.10', '500000.00'] as $i => $nilai) {
            BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: $i + 1, nilai: $nilai, idOutlet: $i % 2 === 0 ? $t['Outlet']->Id : $solo->Id));
        }

        BantuanJurnal::Posting(BantuanJurnal::DataStokAwal(idSumber: 9, baris: [
            DataBarisJurnal::Debit(PeranAkun::PersediaanBarangDagang, Uang::Dari('10600.00')),
            DataBarisJurnal::Debit(PeranAkun::SelisihHpp, Uang::Dari('400.00')),
            DataBarisJurnal::Kredit(PeranAkun::EkuitasSaldoAwal, Uang::Dari('11000.00')),
        ]));

        expect(PemeriksaInvarian::PeriksaJurnalSeimbang($t['Tenant']->Id))->toBe([])
            ->and(JurnalDetail::query()->sum('Debit'))->toEqual(JurnalDetail::query()->sum('Kredit'))
            ->and(Jurnal::query()->count())->toBe(5);
    });
});
