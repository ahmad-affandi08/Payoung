<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Penjualan\Enum\HasilPenutupanTagihanQris;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Enum\SumberTagihanQris;
use App\Domain\Penjualan\Model\TagihanQris;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Menutup **uang QRIS yang masuk tanpa penjualan** (E8, izin `akuntansi.kelola`): tagihan QRIS dinamis POS yang `Lunas`
 * (uang nyata diterima) tetapi dibayar setelah kedaluwarsa/dibatalkan/tidak pasti dan tidak terhubung ke penjualan mana
 * pun (`PerluTinjauan`, `UuidPenjualan` kosong). Dua jalan keluar:
 * - `Dikembalikan`: dana dikembalikan ke pelanggan lewat dasbor gerbang. **Tanpa jurnal** — uang ini tidak pernah
 *   dibukukan (tidak ada penjualan yang mendebet kliring), jadi masuk-lalu-keluar tidak meninggalkan jejak buku. Catatan
 *   (referensi pengembalian, 5–255 karakter) wajib agar penutupan bisa dibuktikan;
 * - `DiakuiPendapatan`: pelanggan tidak menuntut. Jurnal J-08.2: Dr akun kas/bank tempat uangnya mendarat (dipilih
 *   operator), Cr `PendapatanLain`, sebesar `JumlahDiterima`, di outlet tagihan. Tanggal tidak boleh setelah hari ini
 *   atau di periode terkunci.
 *
 * Hanya tagihan ber-`Sumber` Pos: tagihan toko online yang terlambat dibayar sudah dibukukan sebagai uang muka pesanan
 * (J-17.1) dan dikembalikan lewat pesanannya. Idempoten bagi pemanggil yang menekan ganda: tagihan yang sudah ditutup
 * ditolak (`SudahDitutup`), bukan ditutup dua kali. Audit `tagihan-qris.tutup`.
 */
final class TutupTagihanQrisTanpaPenjualan
{
    public function __construct(
        private readonly DaftarAkunPilihan $akun,
        private readonly PostingJurnal $postingJurnal,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis TagihanTidakDitemukan, TagihanTidakPerluDitutup, SudahDitutup, CatatanTidakValid,
     *                                 AkunKasBankWajib, TanggalMasaDepan, PeriodeTerkunci
     */
    public function Jalankan(string $uuidTagihan, HasilPenutupanTagihanQris $hasil, string $catatan, ?string $uuidAkun, ?CarbonImmutable $tanggal, int $idPengguna): TagihanQris
    {
        $catatan = trim($catatan);

        if (mb_strlen($catatan) < 5 || mb_strlen($catatan) > 255) {
            throw new PelanggaranAturanBisnis('CatatanTidakValid', 'Catatan penutupan wajib diisi, 5 sampai 255 karakter.', 'Catatan');
        }

        $idAkun = null;
        $tanggalJurnal = null;

        if ($hasil === HasilPenutupanTagihanQris::DiakuiPendapatan) {
            $idAkun = $uuidAkun === null || $uuidAkun === '' ? null : ($this->akun->CariKasBankDariUuid($uuidAkun)['Id'] ?? null);

            if ($idAkun === null) {
                throw new PelanggaranAturanBisnis('AkunKasBankWajib', 'Pilih akun kas atau bank tempat uang QRIS ini mendarat.', 'UuidAkun');
            }

            $hariIni = $this->tanggalBisnis->Hitung(null);
            $tanggalJurnal = $tanggal ?? $hariIni;

            if ($tanggalJurnal->toDateString() > $hariIni->toDateString()) {
                throw new PelanggaranAturanBisnis('TanggalMasaDepan', 'Tanggal pengakuan tidak boleh setelah hari ini ('.$hariIni->format('d/m/Y').').', 'Tanggal');
            }

            $this->penjagaPeriode->PastikanTerbuka($tanggalJurnal);
        }

        return DB::transaction(
            fn (): TagihanQris => $this->Tutup($uuidTagihan, $hasil, $catatan, $idAkun, $tanggalJurnal, $idPengguna),
            max(1, (int) config('persediaan.PercobaanTransaksi', 3)),
        );
    }

    private function Tutup(string $uuid, HasilPenutupanTagihanQris $hasil, string $catatan, ?int $idAkun, ?CarbonImmutable $tanggal, int $idPengguna): TagihanQris
    {
        $tagihan = TagihanQris::query()->where('Uuid', $uuid)->lockForUpdate()->first()
            ?? throw new PelanggaranAturanBisnis('TagihanTidakDitemukan', 'Tagihan QRIS tidak ditemukan.');

        if ($tagihan->DitutupPada !== null) {
            throw new PelanggaranAturanBisnis('SudahDitutup', "Tagihan {$tagihan->NomorPesanan} sudah ditutup.");
        }

        if (
            $tagihan->Sumber !== SumberTagihanQris::Pos
            || $tagihan->Status !== StatusTagihanQris::Lunas
            || ! $tagihan->PerluTinjauan
            || $tagihan->UuidPenjualan !== null
        ) {
            throw new PelanggaranAturanBisnis('TagihanTidakPerluDitutup', "Tagihan {$tagihan->NomorPesanan} bukan uang QRIS tanpa penjualan, jadi tidak perlu ditutup.");
        }

        $jumlah = Uang::Dari($tagihan->JumlahDiterima ?? $tagihan->Jumlah);
        $jurnal = null;

        if ($hasil === HasilPenutupanTagihanQris::DiakuiPendapatan && $idAkun !== null && $tanggal !== null) {
            $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
                jenisSumber: JenisSumberJurnal::TagihanQris,
                idSumber: $tagihan->Id,
                uuidSumber: $tagihan->Uuid,
                nomorSumber: $tagihan->NomorPesanan,
                tanggal: $tanggal,
                keterangan: mb_substr("Uang QRIS tanpa penjualan {$tagihan->NomorPesanan} diakui sebagai pendapatan lain", 0, 255),
                baris: [
                    new DataBarisJurnal(null, $idAkun, $tagihan->IdOutlet, $jumlah, Uang::Nol(), "QRIS {$tagihan->NomorPesanan}"),
                    DataBarisJurnal::Kredit(PeranAkun::PendapatanLain, $jumlah, $tagihan->IdOutlet, $tagihan->NomorPesanan),
                ],
                idPengguna: $idPengguna,
                kunciSumber: 'Penutupan-'.now()->format('YmdHisu'),
            ));
        }

        $tagihan->DitutupPada = now();
        $tagihan->DitutupOleh = $idPengguna;
        $tagihan->HasilPenutupan = $hasil;
        $tagihan->CatatanPenutupan = $catatan;
        $tagihan->IdJurnalPenutupan = $jurnal?->idJurnal;
        $tagihan->PerluTinjauan = false;
        $tagihan->save();

        $this->riwayat->Catat(TagihanQris::JENIS_DOKUMEN, $tagihan->Id, StatusTagihanQris::Lunas->value, StatusTagihanQris::Lunas->value, $idPengguna, "Ditutup: {$hasil->AmbilLabel()}. {$catatan}");
        $this->audit->Catat('tagihan-qris.tutup', $tagihan, nilaiBaru: [
            'NomorPesanan' => $tagihan->NomorPesanan,
            'Hasil' => $hasil->value,
            'Jumlah' => $jumlah->KeString(),
            'Catatan' => $catatan,
            'NomorJurnal' => $jurnal?->nomor,
        ], idPengguna: $idPengguna);

        return $tagihan;
    }
}
