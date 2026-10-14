<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pajak\Enum\KategoriJenisPajak;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\Penjualan;
use Carbon\CarbonImmutable;

/**
 * Jurnal penjualan satu dokumen (PRD §11.3 J-07.1 & J-07.2, "Rincian F-07b"), dimensi outlet penjualan:
 * - Dr per pembayaran: tunai → akun metode atau Kas Outlet (dikurangi kembalian); QRIS statis/dinamis/EDC/e-wallet → akun
 *   kliring metode atau Piutang Pencairan; transfer → akun metode atau Bank.
 * - Dr Diskon Penjualan (total diskon baris + pesanan).
 * - Cr Penjualan / Pendapatan Jasa per baris = bruto − pajak inklusif baris.
 * - Cr Pendapatan Biaya Layanan; Cr pajak menurut kategori `JenisPajak` (PRD v1.46): kategori Ppn → PPN Keluaran,
 *   Pbjt & lainnya → Hutang PB1/PBJT (kode jenis pajak tidak dibaca sebagai string tetap).
 * - Cr Pendapatan Pengiriman sebesar `BiayaKirim` **kotor** dan Dr Diskon Penjualan sebesar `DiskonKirim`
 *   (F-17 bagian 3), bukan selisihnya: promo gratis ongkir adalah biaya promo yang harus terlihat di laporan, bukan
 *   pendapatan yang tidak pernah dicatat.
 * - Pembulatan tunai ke Pendapatan Lain (kredit bila positif, debit bila negatif).
 * - J-07.2: Dr HPP / Cr persediaan per peran akun persediaan (dari hasil mutasi stok).
 * Seimbang karena TotalAkhir + TotalDiskon + DiskonKirim
 * = Σ Bruto + BiayaLayanan + BiayaKirim + PajakEksklusif + Pembulatan.
 */
final class PenyusunJurnalPenjualan
{
    public function __construct(private readonly DaftarKelompokPajak $kelompokPajak) {}

    /**
     * @param  list<array{0: PeranAkun, 1: Uang}>  $pendapatan  per baris: [Penjualan|PendapatanJasa, bruto − pajak inklusif]
     * @param  array<string, Uang>  $pajak  kode jenis pajak → jumlah pajak dokumen
     * @param  list<array{0: MetodePembayaran, 1: Uang}>  $pembayaran  [metode, nilai bersih (tunai sudah dikurangi kembalian)]
     * @param  array<string, Uang>  $perubahanPersediaan  nilai PeranAkun persediaan → Σ TotalHpp mutasi (bertanda, keluar negatif)
     */
    public function Susun(Penjualan $penjualan, array $pendapatan, array $pajak, array $pembayaran, array $perubahanPersediaan): DataJurnal
    {
        $idOutlet = $penjualan->IdOutlet;
        $baris = [];

        foreach ($pembayaran as [$metode, $nilai]) {
            $baris[] = self::BarisPembayaran($metode, $nilai, $idOutlet);
        }

        $baris[] = DataBarisJurnal::Debit(PeranAkun::DiskonPenjualan, Uang::Dari($penjualan->TotalDiskon), $idOutlet);

        foreach ($pendapatan as [$peran, $nilai]) {
            $baris[] = DataBarisJurnal::DariSelisih($peran, Uang::Nol()->Kurangi($nilai), $idOutlet);
        }

        $baris[] = DataBarisJurnal::Kredit(PeranAkun::PendapatanBiayaLayanan, Uang::Dari($penjualan->BiayaLayanan), $idOutlet);
        $baris[] = DataBarisJurnal::Kredit(PeranAkun::PendapatanPengiriman, Uang::Dari($penjualan->BiayaKirim), $idOutlet, 'Ongkir');
        $baris[] = DataBarisJurnal::Debit(PeranAkun::DiskonPenjualan, Uang::Dari($penjualan->DiskonKirim), $idOutlet, 'Diskon ongkir');

        $akunPajak = $this->TentukanAkunPajak(array_map('strval', array_keys($pajak)));

        foreach ($pajak as $kode => $jumlah) {
            $baris[] = DataBarisJurnal::Kredit($akunPajak[(string) $kode], $jumlah, $idOutlet);
        }

        $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::PendapatanLain, Uang::Nol()->Kurangi(Uang::Dari($penjualan->Pembulatan)), $idOutlet);

        $totalPerubahan = Uang::Nol();

        foreach ($perubahanPersediaan as $peran => $perubahan) {
            $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::from($peran), $perubahan, $idOutlet);
            $totalPerubahan = $totalPerubahan->Tambah($perubahan);
        }

        $baris[] = DataBarisJurnal::DariSelisih(PeranAkun::Hpp, Uang::Nol()->Kurangi($totalPerubahan), $idOutlet);

        return new DataJurnal(
            jenisSumber: JenisSumberJurnal::Penjualan,
            idSumber: $penjualan->Id,
            uuidSumber: $penjualan->Uuid,
            nomorSumber: $penjualan->Nomor,
            tanggal: CarbonImmutable::parse($penjualan->TanggalBisnis->toDateString()),
            keterangan: mb_substr("Penjualan {$penjualan->Nomor}", 0, 255),
            baris: array_values(array_filter($baris, fn (?DataBarisJurnal $b): bool => $b !== null)),
            idPengguna: $penjualan->IdPengguna,
        );
    }

    /**
     * Peran akun pajak keluaran per kode jenis pajak (juga dipakai retur F-09) dari kategori `JenisPajak` (PRD v1.46):
     * `Ppn` → PPN Keluaran; `Pbjt` dan `Lainnya` (termasuk kode yang tidak dikenal) → Hutang PB1/PBJT.
     *
     * @param  list<string>  $kode
     * @return array<string, PeranAkun>
     */
    public function TentukanAkunPajak(array $kode): array
    {
        $kategori = $this->kelompokPajak->AmbilKategoriJenisPajak($kode);
        $hasil = [];

        foreach ($kode as $k) {
            $hasil[$k] = ($kategori[$k] ?? KategoriJenisPajak::Lainnya) === KategoriJenisPajak::Ppn ? PeranAkun::PpnKeluaran : PeranAkun::HutangPbjt;
        }

        return $hasil;
    }

    /**
     * Akun metode pembayaran (juga dipakai refund retur F-09): tunai → akun metode atau Kas Outlet; transfer → akun
     * metode atau Bank; tempo (F-12) → Piutang Usaha; uang muka pre-order (F-12 bagian 2) → Uang Muka Pelanggan; deposit (F-16d) → Saldo Deposit Pelanggan; QRIS statis/QRIS dinamis (F-08)/EDC/e-wallet → akun kliring metode atau Piutang Pencairan.
     *
     * @return array{0: int|null, 1: PeranAkun} [Id akun eksplisit metode, peran cadangan]
     */
    public static function TentukanAkunMetode(MetodePembayaran $metode): array
    {
        return match ($metode->Jenis) {
            JenisMetodePembayaran::Tunai => [$metode->IdAkun, PeranAkun::KasOutlet],
            JenisMetodePembayaran::Transfer => [$metode->IdAkun, PeranAkun::Bank],
            JenisMetodePembayaran::Tempo => [null, PeranAkun::PiutangUsaha],
            JenisMetodePembayaran::UangMuka => [null, PeranAkun::UangMukaPelanggan],
            // F-16d bagian 1 (J-07.1 & retur): deposit pelanggan → Saldo Deposit Pelanggan (2-1500).
            JenisMetodePembayaran::Deposit => [null, PeranAkun::DepositPelanggan],
            // CRM-06 (D-153): gift card → Saldo Gift Card (2-1550).
            JenisMetodePembayaran::GiftCard => [null, PeranAkun::SaldoGiftCard],
            // K-11: tukar barang → kliring (Cr saat retur, Dr saat penjualan pengganti).
            JenisMetodePembayaran::Tukar => [null, PeranAkun::KliringTukarBarang],
            default => [$metode->IdAkunKliring, PeranAkun::PiutangPencairan],
        };
    }

    private static function BarisPembayaran(MetodePembayaran $metode, Uang $nilai, int $idOutlet): DataBarisJurnal
    {
        [$idAkun, $peran] = self::TentukanAkunMetode($metode);

        return $idAkun !== null
            ? new DataBarisJurnal(null, $idAkun, $idOutlet, $nilai, Uang::Nol(), $metode->Nama)
            : DataBarisJurnal::Debit($peran, $nilai, $idOutlet, $metode->Nama);
    }
}
