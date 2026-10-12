<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Aksi;

use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Data\HasilPostingJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Jurnal pembalik: cermin persis jurnal asal (debit ↔ kredit per akun & outlet), `IdJurnalDibalik` terisi
 * (aturan #8, DesainF05a C.5). Jurnal asal tidak diubah. Idempoten per (jenis, idSumber, kunciSumber) lewat
 * `PostingJurnal`; satu jurnal hanya bisa dibalik sekali (`JurnalSudahDibalik`).
 */
final class BalikkanJurnal
{
    public function __construct(private readonly PostingJurnal $posting) {}

    /**
     * @throws PelanggaranAturanBisnis JurnalTidakDikenal, JurnalSudahDibalik, PeriodeTerkunci, JurnalSumberGanda
     */
    public function Jalankan(int $idJurnal, CarbonImmutable $tanggal, string $keterangan, JenisSumberJurnal $jenis, int $idSumber, string $kunciSumber, ?int $idPengguna, ?string $uuidSumber = null, ?string $nomorSumber = null): HasilPostingJurnal
    {
        return DB::transaction(function () use ($idJurnal, $tanggal, $keterangan, $jenis, $idSumber, $kunciSumber, $idPengguna, $uuidSumber, $nomorSumber): HasilPostingJurnal {
            $asal = Jurnal::query()->whereKey($idJurnal)->sharedLock()->first();

            if (! $asal instanceof Jurnal) {
                throw new PelanggaranAturanBisnis('JurnalTidakDikenal', 'Jurnal yang akan dibalik tidak ditemukan.', detail: ['IdJurnal' => $idJurnal]);
            }

            $baris = JurnalDetail::query()
                ->where('IdJurnal', $asal->Id)
                ->orderBy('Urutan')
                ->get(['IdAkun', 'IdOutlet', 'Debit', 'Kredit', 'Memo'])
                ->map(fn (JurnalDetail $d): DataBarisJurnal => new DataBarisJurnal(
                    peran: null,
                    idAkun: $d->IdAkun,
                    idOutlet: $d->IdOutlet,
                    debit: Uang::Dari($d->Kredit),
                    kredit: Uang::Dari($d->Debit),
                    memo: $d->Memo,
                ))
                ->values()
                ->all();

            $sumberSama = $asal->JenisSumber === $jenis && $asal->IdSumber === $idSumber;

            return $this->posting->Jalankan(new DataJurnal(
                jenisSumber: $jenis,
                idSumber: $idSumber,
                uuidSumber: $sumberSama ? $asal->UuidSumber : $uuidSumber,
                nomorSumber: $sumberSama ? $asal->NomorSumber : $nomorSumber,
                tanggal: $tanggal,
                keterangan: $keterangan,
                baris: array_values($baris),
                idPengguna: $idPengguna,
                kunciSumber: $kunciSumber,
                otomatis: $asal->Otomatis,
                idJurnalDibalik: $asal->Id,
            ));
        }, 3);
    }
}
