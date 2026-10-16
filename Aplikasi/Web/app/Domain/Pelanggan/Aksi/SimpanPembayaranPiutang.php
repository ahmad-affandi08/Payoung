<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Akuntansi\Aksi\CatatGiro;
use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\ArahGiro;
use App\Domain\Akuntansi\Enum\JenisSumberGiro;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Akuntansi\Layanan\PenyediaAkunPeran;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Peristiwa\PeristiwaIntegrasi;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Data\DataPembayaranPiutang;
use App\Domain\Pelanggan\Enum\StatusPembayaranPiutang;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\PembayaranPiutangAlokasi;
use App\Domain\Pelanggan\Model\Piutang;
use Illuminate\Support\Facades\DB;

/**
 * Pelunasan piutang (F-12, izin `akuntansi.kelola`) ke akun kas/bank untuk satu atau banyak piutang terbuka satu
 * pelanggan, boleh sebagian: tiap alokasi > 0 dan ≤ sisa. Tanggal tidak boleh setelah hari ini atau di periode terkunci.
 * Jurnal: Dr akun kas/bank, Cr `PiutangUsaha` per outlet piutang. Status piutang ikut sisa. Nomor `BP/{YYMM}/{SEQ4}`.
 * v3.42: dengan giro/cek mundur debitnya Giro Mundur Diterima dan baris `Giro` dicatat (cair/tolak dari halaman giro).
 * F-21 (D-166): PPh yang dipotong pelanggan (`potonganPph` per piutang, ≤ alokasinya): piutang tetap lunas sebesar alokasi,
 * tetapi yang masuk ke kas/bank hanya alokasi dikurangi PPh; selisihnya didebit ke `PphDibayarDimuka` (kredit pajak) di
 * outlet piutangnya, lengkap dengan nomor bukti potong. Audit `pelunasan-piutang.posting`. Urutan kunci: piutang (urut Id) → pelunasan baru → penomor.
 */
final class SimpanPembayaranPiutang
{
    public const MAKS_PIUTANG = 100;

    public function __construct(
        private readonly DaftarAkunPilihan $akun,
        private readonly PenomorDokumen $penomor,
        private readonly PostingJurnal $postingJurnal,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly CatatGiro $catatGiro,
        private readonly PenyediaAkunPeran $penyediaAkun,
    ) {}

    public function Jalankan(DataPembayaranPiutang $data): PembayaranPiutang
    {
        $akun = $data->giro === null ? $this->akun->CariKasBankDariUuid($data->uuidAkun) : null;

        if ($data->giro === null && $akun === null) {
            throw new PelanggaranAturanBisnis('AkunKasBankWajib', 'Pilih akun kas/bank aktif penerima pelunasan.', 'UuidAkun');
        }

        if ($data->giro !== null) {
            $this->catatGiro->PeriksaIsian($data->giro, $data->tanggal);
        }

        $hariIni = $this->tanggalBisnis->Hitung(null);

        if ($data->tanggal->toDateString() > $hariIni->toDateString()) {
            throw new PelanggaranAturanBisnis('TanggalMasaDepan', 'Tanggal pelunasan tidak boleh setelah hari ini ('.$hariIni->format('d/m/Y').').', 'Tanggal');
        }

        $this->penjagaPeriode->PastikanTerbuka($data->tanggal);

        return DB::transaction(fn (): PembayaranPiutang => $this->Proses($data, $akun['Id'] ?? $this->catatGiro->AmbilIdAkunPenampung(ArahGiro::Masuk)), 3);
    }

    private function Proses(DataPembayaranPiutang $data, int $idAkun): PembayaranPiutang
    {
        $pelanggan = Pelanggan::query()->where('Uuid', $data->uuidPelanggan)->first();

        if ($pelanggan === null) {
            throw new PelanggaranAturanBisnis('PelangganTidakDitemukan', 'Pelanggan tidak ditemukan.', 'UuidPelanggan');
        }

        if ($data->alokasi === [] || count($data->alokasi) > self::MAKS_PIUTANG) {
            throw new PelanggaranAturanBisnis('AlokasiTidakValid', 'Pilih 1 sampai '.self::MAKS_PIUTANG.' piutang yang dilunasi.', 'Alokasi');
        }

        $piutang = Piutang::query()->whereIn('Uuid', array_keys($data->alokasi))->orderBy('Id')->lockForUpdate()->get()->keyBy('Uuid');
        $total = Uang::Nol();

        foreach ($data->alokasi as $uuid => $jumlah) {
            $p = $piutang->get((string) $uuid);

            if (! $p instanceof Piutang || $p->IdPelanggan !== $pelanggan->Id || ! $p->Status->CekTerbuka()) {
                throw new PelanggaranAturanBisnis('AlokasiTidakValid', "Piutang yang dilunasi harus piutang terbuka milik {$pelanggan->Nama}.", "Alokasi.{$uuid}");
            }

            if ($jumlah->Bandingkan(Uang::Nol()) <= 0 || $jumlah->Bandingkan($p->AmbilSisa()) > 0) {
                throw new PelanggaranAturanBisnis('MelebihiSisa', "Pelunasan {$p->Nomor} harus lebih dari Rp 0 dan paling banyak sisanya {$p->AmbilSisa()->FormatRupiah()}.", "Alokasi.{$uuid}");
            }

            $total = $total->Tambah($jumlah);
        }

        $pph = $this->PeriksaPotonganPph($data);
        $uang = $total->Kurangi($pph);

        $pembayaran = PembayaranPiutang::query()->create([
            'Nomor' => $this->penomor->AmbilNomorBerikutnya(JenisDokumenBernomor::PembayaranPiutang, $data->tanggal->format('Y-m')),
            'IdPelanggan' => $pelanggan->Id,
            'IdAkun' => $idAkun,
            'Tanggal' => $data->tanggal->toDateString(),
            'Jumlah' => $total->KeString(),
            'PotonganPph' => $pph->KeString(),
            'NomorBuktiPotong' => $pph->BernilaiNol() ? null : $this->Bersihkan($data->nomorBuktiPotong, 40),
            'Status' => StatusPembayaranPiutang::Diposting,
            'Catatan' => $data->catatan === null || trim($data->catatan) === '' ? null : mb_substr(trim($data->catatan), 0, 500),
            'DibuatOleh' => $data->idPengguna,
        ]);

        // Yang didebit ke kas/bank/giro hanya uang yang benar-benar diterima; PPh yang dipotong didebit terpisah di bawah.
        $baris = $uang->BernilaiNol() ? [] : [new DataBarisJurnal(null, $idAkun, null, $uang, Uang::Nol(), "Pelunasan {$pembayaran->Nomor}")];

        if (! $pph->BernilaiNol()) {
            // Akun & pemetaannya baru ada di template terbaru; tenant lama dilengkapi saat pertama kali dipakai.
            $this->penyediaAkun->Pastikan(PeranAkun::PphDibayarDimuka, '1-1750', 'PPh Dibayar Dimuka (Dipotong Pelanggan)');
        }

        foreach ($data->alokasi as $uuid => $jumlah) {
            /** @var Piutang $p */
            $p = $piutang->get((string) $uuid);
            $pphAlokasi = $data->potonganPph[(string) $uuid] ?? Uang::Nol();
            PembayaranPiutangAlokasi::query()->create(['IdPembayaranPiutang' => $pembayaran->Id, 'IdPiutang' => $p->Id, 'Jumlah' => $jumlah->KeString(), 'PotonganPph' => $pphAlokasi->KeString()]);
            $asal = $p->Status;
            $p->JumlahDibayar = Uang::Dari($p->JumlahDibayar)->Tambah($jumlah)->KeString();
            $p->SelaraskanStatus();
            $p->save();

            if ($asal !== $p->Status) {
                $this->riwayat->Catat(Piutang::JENIS_DOKUMEN, $p->Id, $asal->value, $p->Status->value, $data->idPengguna);
            }

            $baris[] = DataBarisJurnal::Kredit(PeranAkun::PiutangUsaha, $jumlah, $p->IdOutlet, $p->Nomor);

            if (! $pphAlokasi->BernilaiNol()) {
                $baris[] = DataBarisJurnal::Debit(PeranAkun::PphDibayarDimuka, $pphAlokasi, $p->IdOutlet, "PPh dipotong atas {$p->Nomor}");
            }
        }

        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::PembayaranPiutang,
            idSumber: $pembayaran->Id,
            uuidSumber: $pembayaran->Uuid,
            nomorSumber: $pembayaran->Nomor,
            tanggal: $data->tanggal,
            keterangan: mb_substr("Pelunasan piutang {$pembayaran->Nomor} dari {$pelanggan->Nama}", 0, 255),
            baris: $baris,
            idPengguna: $data->idPengguna,
        ));

        $pembayaran->IdJurnal = $jurnal->idJurnal;
        $pembayaran->save();

        if ($data->giro !== null) {
            $this->catatGiro->Jalankan(ArahGiro::Masuk, JenisSumberGiro::PembayaranPiutang, $pembayaran->Id, $pembayaran->Nomor, $pelanggan->Nama, $data->giro, $data->tanggal, $uang, $data->idPengguna);
        }

        $this->riwayat->Catat(PembayaranPiutang::JENIS_DOKUMEN, $pembayaran->Id, null, StatusPembayaranPiutang::Diposting->value, $data->idPengguna);
        $this->audit->Catat('pelunasan-piutang.posting', $pembayaran, nilaiBaru: [
            'Nomor' => $pembayaran->Nomor,
            'Pelanggan' => $pelanggan->Nama,
            'Jumlah' => $pembayaran->Jumlah,
            'PotonganPph' => $pembayaran->PotonganPph,
            'NomorBuktiPotong' => $pembayaran->NomorBuktiPotong,
            'Piutang' => $piutang->map(fn (Piutang $p): string => $p->Nomor)->values()->all(),
            'NomorJurnal' => $jurnal->nomor,
        ], idPengguna: $data->idPengguna);

        $alokasi = [];

        foreach ($data->alokasi as $uuid => $jumlah) {
            $alokasi[] = ['NomorPiutang' => $piutang->get((string) $uuid)?->Nomor, 'Jumlah' => $jumlah->KeString()];
        }

        // X7 §16.4 (v4.07): webhook `pembayaran.diterima` setelah commit.
        PeristiwaIntegrasi::dispatch($pembayaran->IdTenant, 'pembayaran.diterima', $pembayaran->Id, [
            'Sumber' => 'PelunasanPiutang',
            'Uuid' => $pembayaran->Uuid,
            'Nomor' => $pembayaran->Nomor,
            'UuidPelanggan' => $pelanggan->Uuid,
            'Tanggal' => $data->tanggal->toDateString(),
            'Jumlah' => $total->KeString(),
            'PotonganPph' => $pph->KeString(),
            'Metode' => null,
            'Giro' => $data->giro !== null,
            'Alokasi' => $alokasi,
        ]);

        return $pembayaran;
    }

    /**
     * Potongan PPh per piutang: kuncinya harus piutang yang dilunasi, nilainya tidak negatif dan tidak melebihi alokasinya.
     *
     * @throws PelanggaranAturanBisnis PphTidakValid
     */
    private function PeriksaPotonganPph(DataPembayaranPiutang $data): Uang
    {
        $total = Uang::Nol();

        foreach ($data->potonganPph as $uuid => $nilai) {
            $alokasi = $data->alokasi[(string) $uuid] ?? null;

            if ($alokasi === null) {
                throw new PelanggaranAturanBisnis('PphTidakValid', 'Potongan PPh hanya untuk piutang yang sedang dilunasi.', "PotonganPph.{$uuid}");
            }

            if ($nilai->BernilaiNegatif() || $nilai->Bandingkan($alokasi) > 0) {
                throw new PelanggaranAturanBisnis('PphTidakValid', "Potongan PPh harus antara Rp 0 dan nilai pelunasannya ({$alokasi->FormatRupiah()}).", "PotonganPph.{$uuid}");
            }

            $total = $total->Tambah($nilai);
        }

        return $total;
    }

    private function Bersihkan(?string $nilai, int $panjang): ?string
    {
        $bersih = $nilai === null ? '' : trim($nilai);

        return $bersih === '' ? null : mb_substr($bersih, 0, $panjang);
    }
}
