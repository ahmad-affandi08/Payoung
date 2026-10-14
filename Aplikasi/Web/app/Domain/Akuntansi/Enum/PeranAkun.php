<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Enum;

/**
 * Kunci `PemetaanAkun` (PRD §11.1, §11.3): peran akun yang dipakai `AturanPosting` per jenis peristiwa.
 * Template sektor wajib memetakan semua peran ke akun COA-nya dengan tipe yang sesuai (BR-P03.3).
 */
enum PeranAkun: string
{
    case KasOutlet = 'KasOutlet';
    case KasBrankas = 'KasBrankas';
    case Bank = 'Bank';
    case PiutangPencairan = 'PiutangPencairan';
    case PiutangUsaha = 'PiutangUsaha';
    case PiutangKaryawan = 'PiutangKaryawan';
    case PiutangKlaimPemasok = 'PiutangKlaimPemasok';
    case PiutangBelumDifakturkan = 'PiutangBelumDifakturkan';
    case PersediaanBarangDagang = 'PersediaanBarangDagang';
    case PersediaanBahanBaku = 'PersediaanBahanBaku';
    case PersediaanDalamPerjalanan = 'PersediaanDalamPerjalanan';
    case PpnMasukan = 'PpnMasukan';
    case HutangUsaha = 'HutangUsaha';
    case HutangBelumDifakturkan = 'HutangBelumDifakturkan';
    case HutangKonsinyasi = 'HutangKonsinyasi';
    case PpnKeluaran = 'PpnKeluaran';
    case HutangPbjt = 'HutangPbjt';
    case UangMukaPelanggan = 'UangMukaPelanggan';
    case DepositPelanggan = 'DepositPelanggan';
    // CRM-06 (D-153): saldo gift card yang beredar (kewajiban); dikredit saat kartu dijual, didebit saat dipakai membayar.
    case SaldoGiftCard = 'SaldoGiftCard';
    case PendapatanDiterimaDimuka = 'PendapatanDiterimaDimuka';
    // K-11: nilai barang retur yang dipakai membayar barang pengganti (tukar barang). Dikredit saat retur, didebit
    // saat penjualan pengganti; saldo bersih nol bila keduanya sudah diterima server.
    case KliringTukarBarang = 'KliringTukarBarang';
    case EkuitasSaldoAwal = 'EkuitasSaldoAwal';
    case LabaDitahan = 'LabaDitahan';
    case Penjualan = 'Penjualan';
    case DiskonPenjualan = 'DiskonPenjualan';
    case ReturPenjualan = 'ReturPenjualan';
    case PendapatanJasa = 'PendapatanJasa';
    case PendapatanBiayaLayanan = 'PendapatanBiayaLayanan';
    // F-17 bagian 3: ongkir yang ditagih ke pembeli; pendapatan tersendiri, bukan bagian `Penjualan`, supaya
    // margin barang tidak tercampur jasa antar (dan supaya biaya kurirnya bisa dibandingkan dengan pendapatannya).
    case PendapatanPengiriman = 'PendapatanPengiriman';
    case PendapatanLain = 'PendapatanLain';
    case Hpp = 'Hpp';
    case SelisihHpp = 'SelisihHpp';
    case SusutPersediaan = 'SusutPersediaan';
    // F-05e: overhead produksi yang dibebankan ke HPP barang jadi (J-05.6, sisi kredit; pengurang biaya).
    case OverheadProduksiDibebankan = 'OverheadProduksiDibebankan';
    case BebanBiayaPembayaran = 'BebanBiayaPembayaran';
    case BebanSelisihKas = 'BebanSelisihKas';
    // E8: hapus-buku piutang yang tidak akan tertagih (Dr beban, Cr PiutangUsaha).
    case BebanPiutangTakTertagih = 'BebanPiutangTakTertagih';

    // FIN-10 (v3.38): aset tetap & penyusutan garis lurus.
    case AsetTetap = 'AsetTetap';

    case AkumulasiPenyusutan = 'AkumulasiPenyusutan';

    case BebanPenyusutan = 'BebanPenyusutan';

    // v3.42 (F-12 giro/cek mundur): giro dari pelanggan yang belum cair (aset) dan giro ke pemasok yang belum cair
    // (kewajiban).
    case GiroDiterima = 'GiroDiterima';

    case HutangGiro = 'HutangGiro';

    /**
     * Kunci lama sebelum istilah kamus §13.7.1 ditetapkan (DesainF01 H1). Versi template yang sudah terbit tidak boleh
     * diubah (BR-P03.4), jadi kunci lama di dalamnya tetap dibaca lewat alias ini, bukan ditulis ulang.
     */
    public const KUNCI_LAMA = [
        'PiutangSettlement' => 'PiutangPencairan',
        'Waste' => 'SusutPersediaan',
    ];

    /** Peran dari kunci `PemetaanAkun`, termasuk kunci lama. Null bila kunci tidak dikenal. */
    public static function DariKunci(string $kunci): ?self
    {
        return self::tryFrom(self::KUNCI_LAMA[$kunci] ?? $kunci);
    }

    /**
     * Pemetaan dengan kunci peran terbaru. Kunci lama diganti kunci barunya; bila keduanya ada, kunci baru menang.
     * Kunci yang tidak dikenal dibiarkan apa adanya agar tetap dilaporkan validator.
     *
     * @param  array<array-key, mixed>  $pemetaan
     * @return array<array-key, mixed>
     */
    public static function NormalisasiPemetaan(array $pemetaan): array
    {
        $hasil = [];

        foreach ($pemetaan as $kunci => $kode) {
            $peran = self::DariKunci((string) $kunci);
            $kunciBaru = $peran === null ? $kunci : $peran->value;

            if ($peran !== null && $kunciBaru !== $kunci && array_key_exists($kunciBaru, $pemetaan)) {
                continue;
            }

            $hasil[$kunciBaru] = $kode;
        }

        return $hasil;
    }

    public function AmbilTipeAkun(): TipeAkun
    {
        return match ($this) {
            self::KasOutlet, self::KasBrankas, self::Bank, self::PiutangPencairan, self::PiutangUsaha,
            self::PiutangKaryawan, self::PiutangKlaimPemasok, self::PiutangBelumDifakturkan,
            self::PersediaanBarangDagang, self::PersediaanBahanBaku,
            self::PersediaanDalamPerjalanan, self::PpnMasukan, self::AsetTetap, self::AkumulasiPenyusutan, self::GiroDiterima => TipeAkun::Aset,
            self::HutangUsaha, self::HutangBelumDifakturkan, self::HutangKonsinyasi, self::PpnKeluaran,
            self::HutangPbjt, self::UangMukaPelanggan, self::DepositPelanggan, self::SaldoGiftCard,
            self::PendapatanDiterimaDimuka, self::HutangGiro, self::KliringTukarBarang => TipeAkun::Kewajiban,
            self::EkuitasSaldoAwal, self::LabaDitahan => TipeAkun::Ekuitas,
            self::Penjualan, self::DiskonPenjualan, self::ReturPenjualan, self::PendapatanJasa,
            self::PendapatanBiayaLayanan, self::PendapatanPengiriman, self::PendapatanLain => TipeAkun::Pendapatan,
            self::Hpp, self::SelisihHpp, self::SusutPersediaan, self::OverheadProduksiDibebankan => TipeAkun::Hpp,
            self::BebanBiayaPembayaran, self::BebanSelisihKas, self::BebanPenyusutan, self::BebanPiutangTakTertagih => TipeAkun::Beban,
        };
    }

    /** Diskon & retur penjualan adalah akun kontra pendapatan (§11.2); akumulasi penyusutan kontra aset (FIN-10). */
    public function CekWajibKontra(): bool
    {
        return $this === self::DiskonPenjualan || $this === self::ReturPenjualan || $this === self::AkumulasiPenyusutan;
    }

    /**
     * FIN-05 (D-131): akun kendali yang saldonya adalah turunan buku pembantu (aturan #9 — stok & saldo akun adalah
     * turunan peristiwa). Jurnal umum manual tidak boleh menyentuhnya: `SaldoStok`, `Piutang`, `Hutang`, deposit, sesi,
     * giro, dan kartu aset tetap tidak ikut berubah, sehingga neraca dan buku pembantunya akan berselisih tanpa jejak.
     * Penyesuaiannya lewat dokumen modulnya (penyesuaian stok, hapus-buku piutang, pembayaran hutang, dst.).
     */
    public function CekAkunKendali(): bool
    {
        return match ($this) {
            self::PersediaanBarangDagang, self::PersediaanBahanBaku, self::PersediaanDalamPerjalanan,
            self::PiutangUsaha, self::PiutangBelumDifakturkan, self::PiutangPencairan, self::PiutangKaryawan, self::PiutangKlaimPemasok,
            self::HutangUsaha, self::HutangBelumDifakturkan, self::HutangKonsinyasi,
            self::UangMukaPelanggan, self::DepositPelanggan, self::SaldoGiftCard, self::PendapatanDiterimaDimuka, self::KliringTukarBarang,
            self::AsetTetap, self::AkumulasiPenyusutan, self::GiroDiterima, self::HutangGiro => true,
            default => false,
        };
    }

    /** Peran kas & bank: akunnya ditandai akun kas/bank di bagan akun (F-13a transaksi kas & bank). */
    public function CekPeranKasBank(): bool
    {
        return $this === self::KasOutlet || $this === self::KasBrankas || $this === self::Bank;
    }

    /**
     * Satu sumber aturan tipe akun per peran (BR-P03.3): dipakai validator template sektor dan pemetaan akun tenant
     * (F-13a). Tipe harus sama dengan `AmbilTipeAkun()` dan sifat kontra harus sama dengan `CekWajibKontra()`.
     *
     * @return list<string> pesan pelanggaran (kosong = akun cocok)
     */
    public function PeriksaAkun(TipeAkun $tipe, bool $kontra, string $kode): array
    {
        $galat = [];

        if ($tipe !== $this->AmbilTipeAkun()) {
            $galat[] = "Peran \"{$this->AmbilLabel()}\" harus memakai akun {$this->AmbilTipeAkun()->AmbilLabel()}, bukan {$tipe->AmbilLabel()} ({$kode}).";
        }

        if ($this->CekWajibKontra() !== $kontra) {
            $galat[] = $this->CekWajibKontra()
                ? "Peran \"{$this->AmbilLabel()}\" harus memakai akun kontra ({$kode} bukan akun kontra)."
                : "Peran \"{$this->AmbilLabel()}\" tidak boleh memakai akun kontra ({$kode}).";
        }

        return $galat;
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::KasOutlet => 'Kas outlet',
            self::KasBrankas => 'Kas brankas',
            self::Bank => 'Bank',
            self::PiutangPencairan => 'Piutang pencairan (QRIS/EDC/gateway/ojol)',
            self::PiutangUsaha => 'Piutang usaha',
            self::PiutangKaryawan => 'Piutang karyawan (kasbon)',
            self::PiutangKlaimPemasok => 'Piutang klaim promosi pemasok',
            self::PiutangBelumDifakturkan => 'Piutang belum difakturkan',
            self::PersediaanBarangDagang => 'Persediaan barang dagang',
            self::PersediaanBahanBaku => 'Persediaan bahan baku',
            self::PersediaanDalamPerjalanan => 'Persediaan dalam perjalanan',
            self::PpnMasukan => 'PPN masukan',
            self::HutangUsaha => 'Hutang usaha',
            self::HutangBelumDifakturkan => 'Hutang belum difakturkan (GRNI)',
            self::HutangKonsinyasi => 'Hutang konsinyasi',
            self::PpnKeluaran => 'PPN keluaran',
            self::HutangPbjt => 'Hutang PB1/PBJT',
            self::UangMukaPelanggan => 'Uang muka pelanggan (DP)',
            self::DepositPelanggan => 'Deposit pelanggan',
            self::SaldoGiftCard => 'Saldo gift card beredar',
            self::PendapatanDiterimaDimuka => 'Pendapatan diterima dimuka',
            self::KliringTukarBarang => 'Kliring tukar barang (nilai retur untuk barang pengganti)',
            self::EkuitasSaldoAwal => 'Ekuitas saldo awal',
            self::LabaDitahan => 'Laba ditahan',
            self::Penjualan => 'Penjualan',
            self::DiskonPenjualan => 'Diskon penjualan',
            self::ReturPenjualan => 'Retur penjualan',
            self::PendapatanJasa => 'Pendapatan jasa',
            self::PendapatanBiayaLayanan => 'Pendapatan biaya layanan',
            self::PendapatanPengiriman => 'Pendapatan pengiriman (ongkir ditagih ke pembeli)',
            self::PendapatanLain => 'Pendapatan lain (selisih kas lebih, pembulatan)',
            self::Hpp => 'Harga pokok penjualan',
            self::SelisihHpp => 'Selisih HPP / penyesuaian persediaan',
            self::SusutPersediaan => 'Susut & barang rusak',
            self::OverheadProduksiDibebankan => 'Overhead produksi dibebankan',
            self::BebanBiayaPembayaran => 'Beban biaya pembayaran (MDR, komisi ojol)',
            self::BebanSelisihKas => 'Beban selisih kas',
            self::AsetTetap => 'Aset tetap (harga perolehan)',
            self::AkumulasiPenyusutan => 'Akumulasi penyusutan aset tetap',
            self::BebanPenyusutan => 'Beban penyusutan',
            self::GiroDiterima => 'Giro/cek mundur diterima (belum cair)',
            self::HutangGiro => 'Hutang giro (giro keluar belum cair)',
            self::BebanPiutangTakTertagih => 'Beban piutang tak tertagih (hapus-buku piutang)',
        };
    }
}
