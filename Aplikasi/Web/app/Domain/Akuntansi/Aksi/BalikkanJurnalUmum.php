<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Aksi;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalUmum;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FIN-05 koreksi jurnal umum (aturan #8, izin `akuntansi.kelola`): dokumen pembalik `JM/…` baru
 * (`IdJurnalUmumDibalik`) dengan jurnal pembalik (debit ↔ kredit, `IdJurnalDibalik`) di transaksi DB yang sama.
 * Dokumen asal tidak diubah. Satu dokumen hanya bisa dibalik sekali (dijaga juga oleh indeks unik skema); dokumen
 * pembalik tidak bisa dibalik lagi. Tanggal pembalik tidak boleh sebelum tanggal asal, tidak setelah hari ini, dan harus
 * di periode terbuka — pembalik akrual awal bulan berikutnya adalah pemakaian yang lazim. Jenis diwarisi dari asal.
 * LogAudit `jurnal-umum.balik`.
 */
final class BalikkanJurnalUmum
{
    public function __construct(
        private readonly PenomorDokumen $penomor,
        private readonly BalikkanJurnal $balikkanJurnal,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanTidakValid, PembalikTidakBisaDibalik, JurnalUmumSudahDibalik,
     *                                 TanggalPembalikTidakValid, TanggalMasaDepan, PeriodeTerkunci, JurnalTidakDikenal
     */
    public function Jalankan(JurnalUmum $asal, CarbonImmutable $tanggal, string $alasan, ?int $idPengguna): JurnalUmum
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 200) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan koreksi wajib diisi, 5 sampai 200 karakter.', 'Alasan');
        }

        $hariIni = $this->tanggalBisnis->Hitung(null);

        if ($tanggal->toDateString() > $hariIni->toDateString()) {
            throw new PelanggaranAturanBisnis('TanggalMasaDepan', 'Tanggal pembalik tidak boleh setelah hari ini ('.$hariIni->format('d/m/Y').').', 'Tanggal');
        }

        return DB::transaction(function () use ($asal, $tanggal, $alasan, $idPengguna): JurnalUmum {
            $asal = JurnalUmum::query()->whereKey($asal->Id)->lockForUpdate()->firstOrFail();

            if ($asal->IdJurnalUmumDibalik !== null) {
                throw new PelanggaranAturanBisnis('PembalikTidakBisaDibalik', "{$asal->Nomor} adalah dokumen pembalik dan tidak bisa dibalik lagi.");
            }

            $pembalikAda = JurnalUmum::query()->where('IdJurnalUmumDibalik', $asal->Id)->value('Nomor');

            if (is_string($pembalikAda)) {
                throw new PelanggaranAturanBisnis('JurnalUmumSudahDibalik', "{$asal->Nomor} sudah dibalik oleh {$pembalikAda}.");
            }

            if ($tanggal->lt($asal->Tanggal->toImmutable()->startOfDay())) {
                throw new PelanggaranAturanBisnis('TanggalPembalikTidakValid', 'Tanggal pembalik tidak boleh sebelum tanggal jurnal asal ('.$asal->Tanggal->format('d/m/Y').').', 'Tanggal');
            }

            $jurnalAsal = Jurnal::query()
                ->where('JenisSumber', JenisSumberJurnal::JurnalUmum->value)
                ->where('IdSumber', $asal->Id)
                ->where('KunciSumber', 'Utama')
                ->first(['Id']);

            if (! $jurnalAsal instanceof Jurnal) {
                throw new PelanggaranAturanBisnis('JurnalTidakDikenal', "Jurnal {$asal->Nomor} tidak ditemukan.");
            }

            $nomor = $this->penomor->AmbilNomorBerikutnya(JenisDokumenBernomor::JurnalUmum, $tanggal->format('Y-m'));
            $keterangan = mb_substr("Pembalik {$asal->Nomor}: {$alasan}", 0, 255);
            $pembalik = JurnalUmum::query()->create([
                'Nomor' => $nomor,
                'Jenis' => $asal->Jenis,
                'Tanggal' => $tanggal->toDateString(),
                'Referensi' => $asal->Nomor,
                'Keterangan' => $keterangan,
                // Pembalik dibuat server, bukan formulir: kunci idempotensinya diturunkan dari dokumen asal, jadi
                // pembalik kedua untuk asal yang sama gagal di indeks unik, bukan menjadi dokumen ganda.
                'UuidKlien' => $asal->Uuid,
                'TotalNilai' => $asal->TotalNilai,
                'IdJurnalUmumDibalik' => $asal->Id,
                'DibuatOleh' => $idPengguna,
            ]);

            $jurnal = $this->balikkanJurnal->Jalankan(
                $jurnalAsal->Id,
                $tanggal,
                $keterangan,
                JenisSumberJurnal::JurnalUmum,
                $pembalik->Id,
                'Utama',
                $idPengguna,
                $pembalik->Uuid,
                $nomor,
            );

            $this->audit->Catat('jurnal-umum.balik', $pembalik, nilaiBaru: [
                'Nomor' => $nomor,
                'NomorDibalik' => $asal->Nomor,
                'Tanggal' => $tanggal->toDateString(),
                'TotalNilai' => $asal->TotalNilai,
                'Alasan' => $alasan,
                'NomorJurnal' => $jurnal->nomor,
            ], idPengguna: $idPengguna);

            return $pembalik;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
