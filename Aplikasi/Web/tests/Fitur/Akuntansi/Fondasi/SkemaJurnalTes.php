<?php

declare(strict_types=1);

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\KunciPeriode;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Tests\Pendukung\Persediaan\BantuanPersediaan;
use Tests\Pendukung\Tenant\BantuanPendaftaran;

beforeEach(function (): void {
    BantuanPendaftaran::SiapkanPrasyarat();
});

/**
 * Jurnal stok awal 12.345,68 (Dr Persediaan barang dagang, Cr Ekuitas saldo awal) langsung lewat model.
 *
 * @return array{Jurnal: Jurnal, Debit: JurnalDetail, Kredit: JurnalDetail}
 */
function BuatJurnalUjiSkema(string $nomor = 'JU/2026/09/000001', string $kunciSumber = 'Utama', int $idSumber = 1): array
{
    $idPersediaan = (int) PemetaanAkun::query()->where('Kunci', 'PersediaanBarangDagang')->value('IdAkun');
    $idEkuitas = (int) PemetaanAkun::query()->where('Kunci', 'EkuitasSaldoAwal')->value('IdAkun');

    $jurnal = Jurnal::query()->create([
        'Nomor' => $nomor,
        'Tanggal' => '2026-09-24',
        'Periode' => '2026-09',
        'JenisSumber' => JenisSumberJurnal::StokAwal,
        'IdSumber' => $idSumber,
        'KunciSumber' => $kunciSumber,
        'Keterangan' => 'Stok awal Gudang Outlet Utama',
        'TotalDebit' => '12345.68',
        'TotalKredit' => '12345.68',
    ]);
    $baris = fn (int $urutan, int $idAkun, string $debit, string $kredit): JurnalDetail => JurnalDetail::query()->create([
        'IdJurnal' => $jurnal->Id, 'Urutan' => $urutan, 'IdAkun' => $idAkun, 'Debit' => $debit, 'Kredit' => $kredit, 'Tanggal' => '2026-09-24',
    ]);

    return ['Jurnal' => $jurnal, 'Debit' => $baris(1, $idPersediaan, '12345.68', '0.00'), 'Kredit' => $baris(2, $idEkuitas, '0.00', '12345.68')];
}

describe('F-05a skema jurnal (DesainF05a B.2, C.5)', function (): void {
    it('tabel Jurnal, JurnalDetail, KunciPeriode dengan kolom desain', function (): void {
        expect(Schema::hasColumns('Jurnal', [
            'Id', 'Uuid', 'IdTenant', 'Nomor', 'Tanggal', 'Periode', 'JenisSumber', 'IdSumber', 'UuidSumber', 'NomorSumber', 'KunciSumber',
            'Keterangan', 'Otomatis', 'IdJurnalDibalik', 'TotalDebit', 'TotalKredit', 'DibuatOleh', 'DibuatPada', 'DiubahPada',
        ]))->toBeTrue()
            ->and(Schema::hasColumns('JurnalDetail', ['Id', 'IdTenant', 'IdJurnal', 'Urutan', 'IdAkun', 'IdOutlet', 'Debit', 'Kredit', 'Memo', 'Tanggal']))->toBeTrue()
            ->and(Schema::hasColumns('KunciPeriode', ['Id', 'IdTenant', 'Periode', 'DikunciPada', 'DikunciOleh']))->toBeTrue();
    });

    it('jurnal tersimpan milik tenant. Idempotensi sumber dan nomor unik per tenant dijaga indeks', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        ['Jurnal' => $jurnal] = BuatJurnalUjiSkema();

        expect($jurnal->refresh()->IdTenant)->toBe($t['Tenant']->Id)
            ->and($jurnal->JenisSumber)->toBe(JenisSumberJurnal::StokAwal)
            ->and($jurnal->KunciSumber)->toBe('Utama')
            ->and($jurnal->Otomatis)->toBeTrue()
            ->and($jurnal->TotalDebit)->toBe('12345.68')
            ->and($jurnal->Detail()->count())->toBe(2)
            ->and(fn () => BuatJurnalUjiSkema('JU/2026/09/000002'))->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => BuatJurnalUjiSkema('JU/2026/09/000001', 'Pembatalan'))->toThrow(UniqueConstraintViolationException::class);

        // Sumber sama dengan KunciSumber lain (jurnal pembatalan) boleh.
        ['Jurnal' => $pembalik] = BuatJurnalUjiSkema('JU/2026/09/000003', 'Pembatalan');
        expect($pembalik->Id)->not->toBe($jurnal->Id);
    });

    it('aturan #8: jurnal & baris jurnal yang sudah diposting tidak bisa diubah atau dihapus', function (): void {
        BantuanPersediaan::SiapkanTenant();
        ['Jurnal' => $jurnal, 'Debit' => $debit] = BuatJurnalUjiSkema();

        expect(fn () => $jurnal->update(['Keterangan' => 'diubah']))->toThrow(LogicException::class, Jurnal::PESAN_TIDAK_BISA_DIUBAH)
            ->and(fn () => $jurnal->delete())->toThrow(LogicException::class, Jurnal::PESAN_TIDAK_BISA_DIUBAH)
            ->and(fn () => $debit->update(['Debit' => '1.00']))->toThrow(LogicException::class, Jurnal::PESAN_TIDAK_BISA_DIUBAH)
            ->and(fn () => $debit->delete())->toThrow(LogicException::class, Jurnal::PESAN_TIDAK_BISA_DIUBAH)
            ->and(JurnalDetail::query()->sum('Debit'))->toEqual('12345.68');
    });

    it('KunciPeriode unik per tenant & periode. Nomor jurnal JU 6 digit dari PenomorDokumen', function (): void {
        $t = BantuanPersediaan::SiapkanTenant();
        $kunci = BantuanPersediaan::KunciPeriode('2026-08', $t['Pemilik']->Id);

        expect($kunci->IdTenant)->toBe($t['Tenant']->Id)
            ->and(KunciPeriode::query()->where('Periode', '2026-08')->exists())->toBeTrue()
            ->and(fn () => BantuanPersediaan::KunciPeriode('2026-08'))->toThrow(UniqueConstraintViolationException::class)
            ->and(app(PenomorDokumen::class)->AmbilNomorBerikutnya(JenisDokumenBernomor::Jurnal, '2026-09'))->toBe('JU/2026/09/000001')
            ->and(app(PenomorDokumen::class)->AmbilNomorBerikutnya(JenisDokumenBernomor::Jurnal, '2026-09'))->toBe('JU/2026/09/000002');

        BantuanPersediaan::SiapkanTenant('Toko Kedua Makmur');
        expect(KunciPeriode::query()->count())->toBe(0);
    });
});
