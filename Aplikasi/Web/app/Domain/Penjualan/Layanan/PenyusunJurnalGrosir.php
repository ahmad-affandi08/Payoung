<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Nilai\Uang;

/**
 * Baris jurnal dokumen grosir (PRD §11.3 J-12.1 & J-12.3).
 *
 * **J-12.1 surat jalan** — penyerahan barang, bukan penagihan:
 * - Dr `PiutangBelumDifakturkan` sebesar total penyerahan (termasuk PPN yang sudah terutang);
 * - Dr `DiskonPenjualan` sebesar diskon baris, Cr `Penjualan` sebesar bruto − pajak inklusif (persis pola J-07.1,
 *   supaya laporan penjualan grosir & ritel bisa dibandingkan apa adanya);
 * - Cr `PpnKeluaran` / `HutangPbjt` per kode jenis pajak;
 * - Dr `Hpp`, Cr persediaan per peran akun dari hasil mutasi stok (J-07.2).
 *
 * Seimbang tanpa bergantung pembulatan: Total + Diskon = Subtotal + PajakEksklusif, dan Pendapatan = DPP + Diskon
 * = Subtotal − PajakInklusif, sehingga sisi debit dan kredit sama-sama Subtotal + PajakEksklusif.
 *
 * **J-12.2 faktur penjualan** — penagihan, bukan penjualan baru: Dr `PiutangUsaha` / Cr `PiutangBelumDifakturkan`
 * sebesar total faktur. Tidak ada pendapatan maupun PPN yang bergerak lagi, karena keduanya sudah diakui di penyerahan;
 * itulah yang membuat faktur **tidak bisa menggandakan pendapatan**, betapa pun sering ditagihkan ulang.
 *
 * **J-12.4 retur grosir** — cermin J-12.1 yang dibalik sebagian, dengan dua beda yang disengaja: pendapatan yang
 * dibalik masuk akun kontra `ReturPenjualan` (bukan mengurangi `Penjualan`, supaya retur terlihat di laporan alih-alih
 * menghilang dari omzet), dan lawan kreditnya `PiutangUsaha` bila surat jalannya sudah difakturkan — itulah nota
 * kreditnya — atau `PiutangBelumDifakturkan` bila belum.
 *
 * **J-12.3 pembatalan** = seluruh baris J-12.1 dengan sisi terbalik (`pembalik: true`); perubahan persediaan tidak
 * dibalik di sini karena hasil mutasi pembalik sudah bertanda benar (stok masuk kembali).
 *
 * Alasan `PiutangBelumDifakturkan` dipakai sebagai lawan, bukan langsung `PiutangUsaha`: PPN keluaran terutang saat
 * penyerahan (UU PPN Pasal 11 ayat 1, PP 44/2022) sedangkan piutang yang bisa ditagih baru ada setelah faktur
 * diterbitkan (UU PPN Pasal 13 ayat 2 mengizinkan satu faktur gabungan per bulan kalender). Tanpa akun perantara ini,
 * umur piutang akan menghitung tagihan yang belum pernah dikirim ke pembeli.
 */
final class PenyusunJurnalGrosir
{
    public function __construct(private readonly PenyusunJurnalPenjualan $penyusunPenjualan) {}

    /**
     * J-12.2: reklasifikasi piutang saat faktur diterbitkan (`pembalik` = saat faktur dibatalkan).
     *
     * @return list<DataBarisJurnal>
     */
    public function BarisFaktur(Uang $total, ?int $idOutlet, bool $pembalik = false): array
    {
        $arah = fn (Uang $nilai): Uang => $pembalik ? Uang::Nol()->Kurangi($nilai) : $nilai;
        $baris = [
            DataBarisJurnal::DariSelisih(PeranAkun::PiutangUsaha, $arah($total), $idOutlet),
            DataBarisJurnal::DariSelisih(PeranAkun::PiutangBelumDifakturkan, $arah(Uang::Nol()->Kurangi($total)), $idOutlet),
        ];

        return array_values(array_filter($baris, fn (?DataBarisJurnal $b): bool => $b !== null));
    }

    /**
     * J-12.4: retur grosir (`pembalik` = saat returnya dibatalkan).
     *
     * `mengurangiPiutang` true = tagihannya sudah terbit, jadi yang berkurang `PiutangUsaha` beserta baris `Piutang`
     * fakturnya; false = surat jalannya belum difakturkan, jadi yang berkurang `PiutangBelumDifakturkan`.
     *
     * @param  array<string, Uang>  $pajak  kode jenis pajak → pajak bagian retur
     * @param  array<string, Uang>  $perubahanPersediaan  nilai `PeranAkun` persediaan → Σ TotalHpp mutasi (bertanda, masuk positif)
     * @return list<DataBarisJurnal>
     */
    public function BarisReturGrosir(
        Uang $total,
        Uang $diskon,
        Uang $pendapatan,
        array $pajak,
        array $perubahanPersediaan,
        ?int $idOutlet,
        bool $mengurangiPiutang,
        bool $pembalik = false,
        ?Uang $refund = null,
        ?int $idAkunRefund = null,
    ): array {
        $refund ??= Uang::Nol();
        $arah = fn (Uang $nilai): Uang => $pembalik ? Uang::Nol()->Kurangi($nilai) : $nilai;
        // BR-12.7: bagian nilai retur yang melebihi sisa tagihan dikembalikan sebagai uang dari kas/bank.
        $kreditPiutang = $total->Kurangi($refund);
        $baris = [
            DataBarisJurnal::DariSelisih(PeranAkun::ReturPenjualan, $arah($pendapatan), $idOutlet),
            DataBarisJurnal::DariSelisih(PeranAkun::DiskonPenjualan, $arah(Uang::Nol()->Kurangi($diskon)), $idOutlet),
            DataBarisJurnal::DariSelisih(
                $mengurangiPiutang ? PeranAkun::PiutangUsaha : PeranAkun::PiutangBelumDifakturkan,
                $arah(Uang::Nol()->Kurangi($kreditPiutang)),
                $idOutlet,
            ),
            $idAkunRefund === null || $refund->BernilaiNol()
                ? null
                : self::BarisAkun($idAkunRefund, $arah(Uang::Nol()->Kurangi($refund)), $idOutlet, 'Pengembalian uang retur grosir'),
        ];
        $akunPajak = $this->penyusunPenjualan->TentukanAkunPajak(array_map('strval', array_keys($pajak)));

        foreach ($pajak as $kode => $jumlah) {
            $baris[] = DataBarisJurnal::DariSelisih($akunPajak[(string) $kode], $arah($jumlah), $idOutlet);
        }

        $totalPerubahan = Uang::Nol();

        foreach ($perubahanPersediaan as $peran => $perubahan) {
            $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::from($peran), $perubahan, $idOutlet);
            $totalPerubahan = $totalPerubahan->Tambah($perubahan);
        }

        $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::Hpp, Uang::Nol()->Kurangi($totalPerubahan), $idOutlet);

        return array_values(array_filter($baris, fn (?DataBarisJurnal $b): bool => $b !== null));
    }

    /**
     * @param  array<string, Uang>  $pajak  kode jenis pajak → jumlah pajak dokumen
     * @param  array<string, Uang>  $perubahanPersediaan  nilai `PeranAkun` persediaan → Σ TotalHpp mutasi (bertanda)
     * @return list<DataBarisJurnal>
     */
    public function BarisSuratJalan(
        Uang $total,
        Uang $diskon,
        Uang $pendapatan,
        array $pajak,
        array $perubahanPersediaan,
        ?int $idOutlet,
        bool $pembalik = false,
    ): array {
        $arah = fn (Uang $nilai): Uang => $pembalik ? Uang::Nol()->Kurangi($nilai) : $nilai;
        $baris = [
            DataBarisJurnal::DariSelisih(PeranAkun::PiutangBelumDifakturkan, $arah($total), $idOutlet),
            DataBarisJurnal::DariSelisih(PeranAkun::DiskonPenjualan, $arah($diskon), $idOutlet),
            DataBarisJurnal::DariSelisih(PeranAkun::Penjualan, $arah(Uang::Nol()->Kurangi($pendapatan)), $idOutlet),
        ];
        $akunPajak = $this->penyusunPenjualan->TentukanAkunPajak(array_map('strval', array_keys($pajak)));

        foreach ($pajak as $kode => $jumlah) {
            $baris[] = DataBarisJurnal::DariSelisih($akunPajak[(string) $kode], $arah(Uang::Nol()->Kurangi($jumlah)), $idOutlet);
        }

        $totalPerubahan = Uang::Nol();

        foreach ($perubahanPersediaan as $peran => $perubahan) {
            $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::from($peran), $perubahan, $idOutlet);
            $totalPerubahan = $totalPerubahan->Tambah($perubahan);
        }

        $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::Hpp, Uang::Nol()->Kurangi($totalPerubahan), $idOutlet);

        return array_values(array_filter($baris, fn (?DataBarisJurnal $b): bool => $b !== null));
    }

    /** Baris ke akun tertentu (mis. kas/bank pilihan pengguna): positif = debit, negatif = kredit, nol = tanpa baris. */
    private static function BarisAkun(int $idAkun, Uang $bertanda, ?int $idOutlet, string $memo): ?DataBarisJurnal
    {
        if ($bertanda->BernilaiNol()) {
            return null;
        }

        return $bertanda->BernilaiNegatif()
            ? new DataBarisJurnal(null, $idAkun, $idOutlet, Uang::Nol(), Uang::Nol()->Kurangi($bertanda), $memo)
            : new DataBarisJurnal(null, $idAkun, $idOutlet, $bertanda, Uang::Nol(), $memo);
    }
}
