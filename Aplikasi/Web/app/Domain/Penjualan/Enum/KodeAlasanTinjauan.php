<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Enum;

/**
 * Kode alasan tinjauan dokumen penjualan/retur POS (F-07b, F-09, PRD v1.46). `AlasanTinjauan` disimpan sebagai
 * `Kode: keterangan; Kode: keterangan`; halaman web menampilkan label manusiawi dari kode ini, bukan kode mesin.
 */
enum KodeAlasanTinjauan: string
{
    case StokTidakCukup = 'StokTidakCukup';
    case PilihanTidakDikenal = 'PilihanTidakDikenal';
    case ProdukDihapus = 'ProdukDihapus';
    case ShiftSudahDitutup = 'ShiftSudahDitutup';
    case IzinBerubah = 'IzinBerubah';
    case DiskonMelebihiBatas = 'DiskonMelebihiBatas';
    case PengaturanBerbeda = 'PengaturanBerbeda';
    case PajakBerbeda = 'PajakBerbeda';
    case TarifPajakLama = 'TarifPajakLama';
    case LokasiRusakTidakAda = 'LokasiRusakTidakAda';
    case PesananDibayarGanda = 'PesananDibayarGanda';
    case PesananTidakDikenal = 'PesananTidakDikenal';
    case PelangganTidakDikenal = 'PelangganTidakDikenal';
    case PenukaranPoin = 'PenukaranPoin';
    case PromoBerbeda = 'PromoBerbeda';
    case TempoBermasalah = 'TempoBermasalah';
    // F-08 BR-08.5: pembayaran QRIS dinamis yang tagihannya bermasalah saat penjualan diterima.
    case QrisDinamisTidakDikenal = 'QrisDinamisTidakDikenal';
    case QrisDinamisDipakaiUlang = 'QrisDinamisDipakaiUlang';
    case QrisDinamisBelumLunas = 'QrisDinamisBelumLunas';
    case QrisDinamisJumlahBerbeda = 'QrisDinamisJumlahBerbeda';
    // F-16d bagian 1: saldo deposit kurang saat penjualan dibayar deposit diterima; isi deposit untuk pelanggan arsip.
    case DepositKurang = 'DepositKurang';
    case PelangganDiarsipkan = 'PelangganDiarsipkan';
    // Apotek (§9.5): obat wajib resep tanpa resep lengkap; obat keras/OWA/psikotropika/narkotika tanpa apoteker berizin.
    case ResepTidakLengkap = 'ResepTidakLengkap';
    case ApotekerTidakBerwenang = 'ApotekerTidakBerwenang';
    // Kode tinjauan dari fitur lanjutan (tukar barang, pre-order, sesi, reservasi, laundry, bengkel, komisi, voucher,
    // batch & nomor seri, retur tanpa struk) supaya halaman web menampilkan labelnya, bukan kode mesin.
    case TukarBermasalah = 'TukarBermasalah';
    case UangMukaBermasalah = 'UangMukaBermasalah';
    case PaketSesi = 'PaketSesi';
    case Reservasi = 'Reservasi';
    case Laundry = 'Laundry';
    case PerintahKerja = 'PerintahKerja';
    case StafTidakDikenal = 'StafTidakDikenal';
    case VoucherTidakBerlaku = 'VoucherTidakBerlaku';
    case BatchTidakCukup = 'BatchTidakCukup';
    case BatchKedaluwarsa = 'BatchKedaluwarsa';
    case SerialBermasalah = 'SerialBermasalah';
    case BatasReturTanpaStruk = 'BatasReturTanpaStruk';
    case HppTidakDiketahui = 'HppTidakDiketahui';
    // FnB minimum charge (§9.1, D-144): belanja meja kurang dari minimum meja/areanya dan selisihnya tidak ditagih.
    case MinimumChargeTidakTerpenuhi = 'MinimumChargeTidakTerpenuhi';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::StokTidakCukup => 'Stok tidak cukup saat penjualan diterima',
            self::PilihanTidakDikenal => 'Pilihan produk sudah dihapus',
            self::ProdukDihapus => 'Produk sudah dihapus',
            self::ShiftSudahDitutup => 'Diterima setelah shift ditutup',
            self::IzinBerubah => 'Izin atau outlet kasir berubah',
            self::DiskonMelebihiBatas => 'Diskon melebihi batas yang berlaku',
            self::PengaturanBerbeda => 'Pengaturan kasir di perangkat berbeda',
            self::PajakBerbeda => 'Pajak di perangkat berbeda dengan pengaturan pajak',
            self::TarifPajakLama => 'Tarif pajak di perangkat sudah diganti',
            self::LokasiRusakTidakAda => 'Lokasi stok Rusak belum ada',
            self::PesananDibayarGanda => 'Pesanan meja sudah dibayar atau dibatalkan di perangkat lain',
            self::PesananTidakDikenal => 'Pesanan meja belum diterima server',
            self::PelangganTidakDikenal => 'Pelanggan belum diterima server',
            self::PenukaranPoin => 'Penukaran poin perlu diperiksa',
            self::PromoBerbeda => 'Promo di perangkat berbeda dengan promo server',
            self::TempoBermasalah => 'Penjualan tempo perlu diperiksa',
            self::QrisDinamisTidakDikenal => 'Tagihan QRIS dinamis tidak dikenal server',
            self::QrisDinamisDipakaiUlang => 'Tagihan QRIS dinamis sudah dipakai penjualan lain',
            self::QrisDinamisBelumLunas => 'Tagihan QRIS dinamis belum lunas saat penjualan diterima',
            self::QrisDinamisJumlahBerbeda => 'Jumlah tagihan QRIS dinamis berbeda dengan pembayaran',
            self::DepositKurang => 'Saldo deposit pelanggan kurang',
            self::PelangganDiarsipkan => 'Pelanggan sudah diarsipkan',
            self::ResepTidakLengkap => 'Obat wajib resep tanpa resep lengkap',
            self::ApotekerTidakBerwenang => 'Obat keras diserahkan tanpa apoteker berizin',
            self::TukarBermasalah => 'Tukar barang perlu diperiksa',
            self::UangMukaBermasalah => 'Uang muka pre-order perlu diperiksa',
            self::PaketSesi => 'Paket sesi perlu diperiksa',
            self::Reservasi => 'Reservasi tidak bisa diselesaikan',
            self::Laundry => 'Tiket laundry tidak bisa dibuat',
            self::PerintahKerja => 'Perintah kerja bengkel tidak bisa ditagih',
            self::StafTidakDikenal => 'Staf pelayan tidak dikenal, komisi tidak dicatat',
            self::VoucherTidakBerlaku => 'Voucher tidak berlaku saat penjualan diterima',
            self::BatchTidakCukup => 'Stok batch tidak cukup',
            self::BatchKedaluwarsa => 'Batch yang terjual sudah kedaluwarsa',
            self::SerialBermasalah => 'Nomor seri tidak tersedia di stok',
            self::BatasReturTanpaStruk => 'Retur tanpa struk melewati batas harian',
            self::HppTidakDiketahui => 'Harga pokok barang retur tidak diketahui',
            self::MinimumChargeTidakTerpenuhi => 'Belanja meja kurang dari minimum charge',
        };
    }

    /**
     * Urai `AlasanTinjauan` menjadi daftar alasan. Bagian tanpa kode dikenal tetap ditampilkan dengan label umum.
     *
     * @return list<array{Kode: string|null, Label: string, Keterangan: string}>
     */
    public static function Urai(?string $alasan): array
    {
        if ($alasan === null || trim($alasan) === '') {
            return [];
        }

        $hasil = [];

        foreach (preg_split('/;\s*(?=[A-Z][A-Za-z]+:)/', $alasan) ?: [] as $bagian) {
            $bagian = trim($bagian);

            if ($bagian === '') {
                continue;
            }

            $kode = preg_match('/^([A-Z][A-Za-z]+):\s*(.*)$/s', $bagian, $cocok) === 1 ? self::tryFrom($cocok[1]) : null;
            $hasil[] = $kode === null
                ? ['Kode' => null, 'Label' => 'Perlu diperiksa', 'Keterangan' => $bagian]
                : ['Kode' => $kode->value, 'Label' => $kode->AmbilLabel(), 'Keterangan' => trim($cocok[2])];
        }

        return $hasil;
    }
}
