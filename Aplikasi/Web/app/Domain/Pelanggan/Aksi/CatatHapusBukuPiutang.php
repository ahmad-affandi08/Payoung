<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Akuntansi\Layanan\PenyediaAkunPeran;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Enum\StatusPiutang;
use App\Domain\Pelanggan\Model\HapusBukuPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Hapus-buku piutang (E8, izin `akuntansi.kelola`): sisa piutang yang tidak akan tertagih dibukukan sebagai beban.
 * Jurnal: Dr `BebanPiutangTakTertagih`, Cr `PiutangUsaha` di outlet piutangnya, sebesar seluruh sisa (tidak bisa
 * sebagian: sisa yang masih mungkin tertagih jangan dihapus-buku, dan piutang yang "dihapus sebagian" membuat umur
 * dan statusnya ambigu).
 *
 * Piutangnya tidak diubah angkanya selain memindahkan sisa ke `JumlahDikurangi` dan statusnya menjadi `DihapusBuku`,
 * sehingga tidak muncul lagi di daftar piutang terbuka, umur piutang, limit kredit, maupun pengingat — semua kueri itu
 * memang hanya membaca status terbuka. Hanya piutang yang sudah lewat jatuh tempo yang boleh dihapus-buku. Tanggal
 * tidak boleh setelah hari ini atau di periode terkunci. Idempoten per piutang: yang sudah dihapus-buku ditolak
 * (`SudahDihapusBuku`). Nomor `HB/{YYYY}/{MM}/{SEQ4}`. Audit `piutang.hapus-buku`.
 *
 * PPN keluaran atas penjualan yang tidak tertagih **tidak** dikoreksi di sini; itu urusan pelaporan pajak dan perlu
 * keputusan tersendiri (dicatat di PRD D-120).
 */
final class CatatHapusBukuPiutang
{
    public const PANJANG_ALASAN_MINIMAL = 5;

    public function __construct(
        private readonly PenomorDokumen $penomor,
        private readonly PostingJurnal $postingJurnal,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly PenyediaAkunPeran $penyediaAkun,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis PiutangTidakDitemukan, SudahDihapusBuku, PiutangTidakTerbuka, BelumJatuhTempo,
     *                                 AlasanTidakValid, TanggalMasaDepan, PeriodeTerkunci
     */
    public function Jalankan(string $uuidPiutang, CarbonImmutable $tanggal, string $alasan, int $idPengguna): HapusBukuPiutang
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < self::PANJANG_ALASAN_MINIMAL || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan hapus-buku wajib diisi, '.self::PANJANG_ALASAN_MINIMAL.' sampai 255 karakter.', 'Alasan');
        }

        $hariIni = $this->tanggalBisnis->Hitung(null);

        if ($tanggal->toDateString() > $hariIni->toDateString()) {
            throw new PelanggaranAturanBisnis('TanggalMasaDepan', 'Tanggal hapus-buku tidak boleh setelah hari ini ('.$hariIni->format('d/m/Y').').', 'Tanggal');
        }

        $this->penjagaPeriode->PastikanTerbuka($tanggal);

        return DB::transaction(
            fn (): HapusBukuPiutang => $this->Catat($uuidPiutang, $tanggal, $alasan, $idPengguna),
            max(1, (int) config('persediaan.PercobaanTransaksi', 3)),
        );
    }

    private function Catat(string $uuidPiutang, CarbonImmutable $tanggal, string $alasan, int $idPengguna): HapusBukuPiutang
    {
        $piutang = Piutang::query()->where('Uuid', $uuidPiutang)->lockForUpdate()->first()
            ?? throw new PelanggaranAturanBisnis('PiutangTidakDitemukan', 'Piutang tidak ditemukan.');

        if ($piutang->Status === StatusPiutang::DihapusBuku) {
            throw new PelanggaranAturanBisnis('SudahDihapusBuku', "Piutang {$piutang->Nomor} sudah dihapus-buku.");
        }

        $sisa = $piutang->AmbilSisa();

        if (! $piutang->Status->CekTerbuka() || $sisa->Bandingkan(Uang::Nol()) <= 0) {
            throw new PelanggaranAturanBisnis('PiutangTidakTerbuka', "Piutang {$piutang->Nomor} tidak punya sisa tagihan yang bisa dihapus-buku.");
        }

        if ($piutang->JatuhTempo->toDateString() >= $tanggal->toDateString()) {
            throw new PelanggaranAturanBisnis('BelumJatuhTempo', "Piutang {$piutang->Nomor} baru jatuh tempo {$piutang->JatuhTempo->format('d/m/Y')}. Hanya piutang yang sudah lewat jatuh tempo yang bisa dihapus-buku.");
        }

        $idAkunBeban = $this->penyediaAkun->Pastikan(PeranAkun::BebanPiutangTakTertagih, '6-5100', 'Beban Piutang Tak Tertagih');
        $dokumen = HapusBukuPiutang::query()->create([
            'Nomor' => $this->penomor->AmbilNomorBerikutnya(JenisDokumenBernomor::HapusBukuPiutang, $tanggal->format('Y-m')),
            'IdPiutang' => $piutang->Id,
            'IdPelanggan' => $piutang->IdPelanggan,
            'IdOutlet' => $piutang->IdOutlet,
            'NomorPiutang' => $piutang->Nomor,
            'Tanggal' => $tanggal->toDateString(),
            'Jumlah' => $sisa->KeString(),
            'Alasan' => $alasan,
            'IdPiutangAktif' => $piutang->Id,
            'DibuatOleh' => $idPengguna,
        ]);

        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::HapusBukuPiutang,
            idSumber: $dokumen->Id,
            uuidSumber: $dokumen->Uuid,
            nomorSumber: $dokumen->Nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Hapus-buku piutang {$piutang->Nomor}", 0, 255),
            baris: [
                new DataBarisJurnal(null, $idAkunBeban, $piutang->IdOutlet, $sisa, Uang::Nol(), "Hapus-buku {$piutang->Nomor}"),
                DataBarisJurnal::Kredit(PeranAkun::PiutangUsaha, $sisa, $piutang->IdOutlet, $piutang->Nomor),
            ],
            idPengguna: $idPengguna,
        ));

        $dokumen->IdJurnal = $jurnal->idJurnal;
        $dokumen->save();

        $asal = $piutang->Status;
        $piutang->JumlahDikurangi = Uang::Dari($piutang->JumlahDikurangi)->Tambah($sisa)->KeString();
        $piutang->Status = StatusPiutang::DihapusBuku;
        $piutang->save();

        $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $piutang->Id, $asal->value, StatusPiutang::DihapusBuku->value, $idPengguna, $alasan);
        $this->riwayat->Catat(HapusBukuPiutang::JENIS_DOKUMEN, $dokumen->Id, null, $dokumen->Status->value, $idPengguna, $alasan);
        $this->audit->Catat('piutang.hapus-buku', $dokumen, nilaiBaru: [
            'Nomor' => $dokumen->Nomor,
            'NomorPiutang' => $piutang->Nomor,
            'Jumlah' => $sisa->KeString(),
            'Alasan' => $alasan,
            'NomorJurnal' => $jurnal->nomor,
        ], idPengguna: $idPengguna);

        return $dokumen;
    }
}
