<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Aksi;

use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Data\HasilPostingJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Layanan\PenentuAkun;
use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use Illuminate\Support\Facades\DB;

/**
 * Posting jurnal seimbang, idempoten per (JenisSumber, IdSumber, KunciSumber) (J-05.1, §11.1, DesainF05a C.5).
 *
 * Dipanggil di dalam transaksi dokumen sumber (aturan #10): transaksi di sini menjadi savepoint, jadi dokumen, stok,
 * dan jurnal tersimpan atau batal bersama. Nomor `JU/…` diambil paling akhir (kunci L7) agar tidak ada celah.
 * Jurnal tidak pernah diubah; koreksi lewat `BalikkanJurnal` (aturan #8). LogAudit `jurnal.posting` hanya untuk jurnal
 * manual (H-12); jurnal otomatis diaudit lewat dokumen sumbernya.
 */
final class PostingJurnal
{
    private const MAKS_MEMO = 255;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenentuAkun $penentuAkun,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly PenomorDokumen $penomor,
        private readonly PetaUuidOutlet $petaOutlet,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis BarisJurnalTidakValid, JurnalSumberGanda, PemetaanAkunBelumAda, JurnalTidakSeimbang,
     *                                 JurnalKosong, PeriodeTerkunci, JurnalTidakDikenal, JurnalSudahDibalik
     */
    public function Jalankan(DataJurnal $data): HasilPostingJurnal
    {
        $this->konteks->Wajib();
        $baris = $this->SaringBaris($data->baris);
        [$totalDebit, $totalKredit] = self::HitungTotal($baris);

        return DB::transaction(function () use ($data, $baris, $totalDebit, $totalKredit): HasilPostingJurnal {
            // 1. Idempotensi per sumber (kunci baris sumber; pemanggil sudah memegang kunci dokumennya).
            $ada = Jurnal::query()
                ->where('JenisSumber', $data->jenisSumber->value)
                ->where('IdSumber', $data->idSumber)
                ->where('KunciSumber', $data->kunciSumber)
                ->lockForUpdate()
                ->first(['Id', 'Uuid', 'Nomor', 'TotalDebit', 'TotalKredit']);

            if ($ada instanceof Jurnal) {
                if (! Uang::Dari($ada->TotalDebit)->SamaDengan($totalDebit) || ! Uang::Dari($ada->TotalKredit)->SamaDengan($totalKredit)) {
                    throw new PelanggaranAturanBisnis(
                        'JurnalSumberGanda',
                        "Dokumen sumber ini sudah punya jurnal {$ada->Nomor} dengan nilai berbeda. Jurnal yang sudah diposting tidak bisa diubah. Koreksi lewat jurnal pembalik.",
                        detail: ['Nomor' => $ada->Nomor, 'TotalDebitAda' => $ada->TotalDebit, 'TotalDebitBaru' => $totalDebit->KeString()],
                    );
                }

                return new HasilPostingJurnal($ada->Id, $ada->Uuid, $ada->Nomor, true);
            }

            // 3. Akun per peran/outlet, lalu gabung baris sama (akun, outlet, sisi).
            $barisAkun = $this->GabungBaris($this->TentukanAkun($baris));

            // 4. Seimbang dan tidak nol.
            if (! $totalDebit->SamaDengan($totalKredit)) {
                throw new PelanggaranAturanBisnis(
                    'JurnalTidakSeimbang',
                    "Jurnal tidak seimbang: total debit {$totalDebit->FormatRupiah()}, total kredit {$totalKredit->FormatRupiah()}.",
                    detail: ['TotalDebit' => $totalDebit->KeString(), 'TotalKredit' => $totalKredit->KeString()],
                );
            }

            if ($totalDebit->BernilaiNol()) {
                throw new PelanggaranAturanBisnis('JurnalKosong', 'Jurnal tanpa nilai tidak dicatat.');
            }

            // 5. Periode terbuka. F-15/§18: transaksi POS di periode terkunci dibukukan di periode terbuka berikutnya.
            // Jurnal penutup tahun (J-15.1) justru wajib di 31 Desember yang sudah terkunci (dijaga `TutupTahunAkuntansi`).
            $tanggalPosting = $data->jenisSumber === JenisSumberJurnal::TutupTahun
                ? $data->tanggal
                : $this->penjagaPeriode->SesuaikanTanggalPosting($data->tanggal);

            if ($tanggalPosting->toDateString() !== $data->tanggal->toDateString()) {
                $data = $data->DenganTanggal($tanggalPosting, " (tanggal transaksi {$data->tanggal->toDateString()}, periodenya terkunci)");
            }

            // 6. Jurnal yang dibalik ada di tenant ini dan belum pernah dibalik.
            if ($data->idJurnalDibalik !== null) {
                $this->PastikanBisaDibalik($data->idJurnalDibalik);
            }

            // 7. Nomor (kunci terakhir L7), 8. simpan.
            $periode = $data->tanggal->format('Y-m');
            $nomor = $this->penomor->AmbilNomorBerikutnya(JenisDokumenBernomor::Jurnal, $periode);

            $jurnal = Jurnal::query()->create([
                'Nomor' => $nomor,
                'Tanggal' => $data->tanggal->toDateString(),
                'Periode' => $periode,
                'JenisSumber' => $data->jenisSumber,
                'IdSumber' => $data->idSumber,
                'UuidSumber' => $data->uuidSumber,
                'NomorSumber' => $data->nomorSumber,
                'KunciSumber' => $data->kunciSumber,
                'Keterangan' => mb_substr(trim($data->keterangan), 0, 255),
                'Otomatis' => $data->otomatis,
                'IdJurnalDibalik' => $data->idJurnalDibalik,
                'TotalDebit' => $totalDebit->KeString(),
                'TotalKredit' => $totalKredit->KeString(),
                'DibuatOleh' => $data->idPengguna,
            ]);

            $this->SimpanDetail($jurnal, $barisAkun, $data->tanggal->toDateString());

            // 9. Audit hanya jurnal manual (H-12).
            if (! $data->otomatis) {
                $this->audit->Catat('jurnal.posting', $jurnal, nilaiBaru: [
                    'Nomor' => $nomor,
                    'Tanggal' => $data->tanggal->toDateString(),
                    'JenisSumber' => $data->jenisSumber->value,
                    'IdSumber' => $data->idSumber,
                    'KunciSumber' => $data->kunciSumber,
                    'TotalDebit' => $totalDebit->KeString(),
                    'IdJurnalDibalik' => $data->idJurnalDibalik,
                ], idPengguna: $data->idPengguna);
            }

            return new HasilPostingJurnal($jurnal->Id, $jurnal->Uuid, $nomor, false);
        }, 3);
    }

    /**
     * Langkah 2: buang baris bernilai nol di kedua sisi; tolak baris negatif, dua sisi, atau tanpa/ganda akun.
     *
     * @param  list<DataBarisJurnal>  $baris
     * @return list<DataBarisJurnal>
     */
    private function SaringBaris(array $baris): array
    {
        $hasil = [];

        foreach ($baris as $indeks => $satu) {
            $urutan = $indeks + 1;

            if (($satu->peran === null) === ($satu->idAkun === null)) {
                throw self::GalatBaris($urutan, 'harus menyebut tepat satu: peran akun atau akun');
            }

            if ($satu->debit->BernilaiNegatif() || $satu->kredit->BernilaiNegatif()) {
                throw self::GalatBaris($urutan, 'tidak boleh bernilai negatif');
            }

            if (! $satu->debit->BernilaiNol() && ! $satu->kredit->BernilaiNol()) {
                throw self::GalatBaris($urutan, 'hanya boleh berisi debit atau kredit, tidak keduanya');
            }

            if ($satu->debit->BernilaiNol() && $satu->kredit->BernilaiNol()) {
                continue;
            }

            $hasil[] = $satu;
        }

        return $hasil;
    }

    /**
     * @param  list<DataBarisJurnal>  $baris
     * @return array{0: Uang, 1: Uang}
     */
    private static function HitungTotal(array $baris): array
    {
        $debit = Uang::Nol();
        $kredit = Uang::Nol();

        foreach ($baris as $satu) {
            $debit = $debit->Tambah($satu->debit);
            $kredit = $kredit->Tambah($satu->kredit);
        }

        return [$debit, $kredit];
    }

    /**
     * @param  list<DataBarisJurnal>  $baris
     * @return list<array{IdAkun: int, IdOutlet: int|null, Debit: Uang, Kredit: Uang, Memo: string|null}>
     */
    private function TentukanAkun(array $baris): array
    {
        $this->PastikanOutletTenant($baris);
        $this->PastikanAkunTenant($baris);
        $dibutuhkan = [];

        foreach ($baris as $satu) {
            if ($satu->peran !== null) {
                $dibutuhkan[] = [$satu->peran, $satu->idOutlet];
            }
        }

        $idPerPeran = $this->penentuAkun->AmbilIdAkunBanyak($dibutuhkan);
        $hasil = [];

        foreach ($baris as $satu) {
            if ($satu->peran !== null) {
                $kunci = $satu->peran->value.'|'.($satu->idOutlet ?? 0);
                // Peran yang tidak terselesaikan sekaligus (belum dipetakan / tipe salah) dilempar galat jelasnya di sini.
                $idPerPeran[$kunci] ??= $this->penentuAkun->AmbilIdAkun($satu->peran, $satu->idOutlet);
                $idAkun = $idPerPeran[$kunci];
            } else {
                $idAkun = (int) $satu->idAkun;
            }

            $memo = $satu->memo === null ? null : mb_substr(trim($satu->memo), 0, self::MAKS_MEMO);

            $hasil[] = [
                'IdAkun' => $idAkun,
                'IdOutlet' => $satu->idOutlet,
                'Debit' => $satu->debit,
                'Kredit' => $satu->kredit,
                'Memo' => $memo === '' ? null : $memo,
            ];
        }

        return $hasil;
    }

    /**
     * Gabung baris dengan (akun, outlet, sisi) sama; debit dulu lalu kredit, urutan kemunculan pertama dipertahankan.
     *
     * @param  list<array{IdAkun: int, IdOutlet: int|null, Debit: Uang, Kredit: Uang, Memo: string|null}>  $baris
     * @return list<array{IdAkun: int, IdOutlet: int|null, Debit: Uang, Kredit: Uang, Memo: string|null}>
     */
    private function GabungBaris(array $baris): array
    {
        $debit = [];
        $kredit = [];

        foreach ($baris as $satu) {
            $sisiDebit = ! $satu['Debit']->BernilaiNol();
            $kunci = $satu['IdAkun'].'|'.($satu['IdOutlet'] ?? 0);

            if ($sisiDebit) {
                $debit[$kunci] = isset($debit[$kunci])
                    ? [...$debit[$kunci], 'Debit' => $debit[$kunci]['Debit']->Tambah($satu['Debit']), 'Memo' => $debit[$kunci]['Memo'] ?? $satu['Memo']]
                    : $satu;
            } else {
                $kredit[$kunci] = isset($kredit[$kunci])
                    ? [...$kredit[$kunci], 'Kredit' => $kredit[$kunci]['Kredit']->Tambah($satu['Kredit']), 'Memo' => $kredit[$kunci]['Memo'] ?? $satu['Memo']]
                    : $satu;
            }
        }

        return [...array_values($debit), ...array_values($kredit)];
    }

    /**
     * @param  list<array{IdAkun: int, IdOutlet: int|null, Debit: Uang, Kredit: Uang, Memo: string|null}>  $baris
     */
    private function SimpanDetail(Jurnal $jurnal, array $baris, string $tanggal): void
    {
        $sekarang = now();
        $isi = [];

        foreach ($baris as $indeks => $satu) {
            $isi[] = [
                'IdTenant' => $jurnal->IdTenant,
                'IdJurnal' => $jurnal->Id,
                'Urutan' => $indeks + 1,
                'IdAkun' => $satu['IdAkun'],
                'IdOutlet' => $satu['IdOutlet'],
                'Debit' => $satu['Debit']->KeString(),
                'Kredit' => $satu['Kredit']->KeString(),
                'Memo' => $satu['Memo'],
                'Tanggal' => $tanggal,
                'DibuatPada' => $sekarang,
                'DiubahPada' => $sekarang,
            ];
        }

        foreach (array_chunk($isi, 500) as $potongan) {
            JurnalDetail::query()->insert($potongan);
        }
    }

    /**
     * Outlet dimensi harus milik tenant aktif (FK saja tidak mencegah Id outlet tenant lain).
     *
     * @param  list<DataBarisJurnal>  $baris
     */
    private function PastikanOutletTenant(array $baris): void
    {
        $idOutlet = array_values(array_unique(array_filter(array_map(fn (DataBarisJurnal $b): ?int => $b->idOutlet, $baris), 'is_int')));
        $dikenal = $this->petaOutlet->Ambil($idOutlet);

        foreach ($idOutlet as $id) {
            if (! isset($dikenal[$id])) {
                throw new PelanggaranAturanBisnis('BarisJurnalTidakValid', 'Outlet pada baris jurnal tidak dikenal.', detail: ['IdOutlet' => $id]);
            }
        }
    }

    /**
     * Akun yang disebut langsung harus milik tenant aktif.
     *
     * @param  list<DataBarisJurnal>  $baris
     */
    private function PastikanAkunTenant(array $baris): void
    {
        $idAkun = array_values(array_unique(array_filter(array_map(fn (DataBarisJurnal $b): ?int => $b->idAkun, $baris), 'is_int')));

        if ($idAkun === []) {
            return;
        }

        $dikenal = Akun::query()->whereKey($idAkun)->pluck('Id')->all();

        foreach ($idAkun as $id) {
            if (! in_array($id, $dikenal, true)) {
                throw new PelanggaranAturanBisnis('BarisJurnalTidakValid', 'Akun pada baris jurnal tidak dikenal.', detail: ['IdAkun' => $id]);
            }
        }
    }

    private function PastikanBisaDibalik(int $idJurnalDibalik): void
    {
        $asal = Jurnal::query()->whereKey($idJurnalDibalik)->sharedLock()->first(['Id', 'Nomor']);

        if (! $asal instanceof Jurnal) {
            throw new PelanggaranAturanBisnis('JurnalTidakDikenal', 'Jurnal yang akan dibalik tidak ditemukan.', detail: ['IdJurnal' => $idJurnalDibalik]);
        }

        $pembalik = Jurnal::query()->where('IdJurnalDibalik', $idJurnalDibalik)->lockForUpdate()->first(['Nomor']);

        if ($pembalik instanceof Jurnal) {
            throw new PelanggaranAturanBisnis(
                'JurnalSudahDibalik',
                "Jurnal {$asal->Nomor} sudah dibalik oleh jurnal {$pembalik->Nomor}.",
                detail: ['Nomor' => $asal->Nomor, 'NomorPembalik' => $pembalik->Nomor],
            );
        }
    }

    private static function GalatBaris(int $urutan, string $alasan): PelanggaranAturanBisnis
    {
        return new PelanggaranAturanBisnis('BarisJurnalTidakValid', "Baris jurnal ke-{$urutan} {$alasan}.", detail: ['Urutan' => $urutan]);
    }
}
