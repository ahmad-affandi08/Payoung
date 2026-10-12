<?php

declare(strict_types=1);

namespace App\Domain\Katalog\Impor\Enum;

/**
 * Bidang produk yang bisa diimpor/diekspor (F-03, DesainF03 C.6). Nilai = kunci pemetaan & kunci `Data` baris;
 * `AmbilJudul()` = judul kolom preset `Umum`, templat, dan ekspor (urutan kasus = urutan kolom).
 * `HargaModal` dan `Stok` hanya dikenali untuk diberi peringatan (diisi di menu Stok awal, F-05a).
 */
enum BidangImpor: string
{
    case Nama = 'Nama';
    case NamaStruk = 'NamaStruk';
    case Sku = 'Sku';
    case Jenis = 'Jenis';
    case LacakStok = 'LacakStok';
    case Kategori = 'Kategori';
    case Merek = 'Merek';
    case Satuan = 'Satuan';
    case HargaJual = 'HargaJual';
    case Barcode = 'Barcode';
    case KelompokPajak = 'KelompokPajak';
    case HargaTermasukPajak = 'HargaTermasukPajak';
    case TampilDiPos = 'TampilDiPos';
    case TampilOnline = 'TampilOnline';
    case Favorit = 'Favorit';
    case BolehMinus = 'BolehMinus';
    case Pelacakan = 'Pelacakan';
    case NamaInduk = 'NamaInduk';
    case Varian = 'Varian';
    case SatuanAlternatif1 = 'SatuanAlternatif1';
    case KonversiSatuanAlternatif1 = 'KonversiSatuanAlternatif1';
    case HargaSatuanAlternatif1 = 'HargaSatuanAlternatif1';
    case BarcodeSatuanAlternatif1 = 'BarcodeSatuanAlternatif1';
    case SatuanAlternatif2 = 'SatuanAlternatif2';
    case KonversiSatuanAlternatif2 = 'KonversiSatuanAlternatif2';
    case HargaSatuanAlternatif2 = 'HargaSatuanAlternatif2';
    case BarcodeSatuanAlternatif2 = 'BarcodeSatuanAlternatif2';
    case SatuanAlternatif3 = 'SatuanAlternatif3';
    case KonversiSatuanAlternatif3 = 'KonversiSatuanAlternatif3';
    case HargaSatuanAlternatif3 = 'HargaSatuanAlternatif3';
    case BarcodeSatuanAlternatif3 = 'BarcodeSatuanAlternatif3';
    case JumlahGrosir1 = 'JumlahGrosir1';
    case HargaGrosir1 = 'HargaGrosir1';
    case JumlahGrosir2 = 'JumlahGrosir2';
    case HargaGrosir2 = 'HargaGrosir2';
    case JumlahGrosir3 = 'JumlahGrosir3';
    case HargaGrosir3 = 'HargaGrosir3';
    case Status = 'Status';
    case HargaModal = 'HargaModal';
    case Stok = 'Stok';

    public const JUMLAH_SATUAN_ALTERNATIF = 3;

    public const JUMLAH_GROSIR = 3;

    public function AmbilJudul(): string
    {
        return match ($this) {
            self::Nama => 'Nama Produk',
            self::NamaStruk => 'Nama di Struk',
            self::Sku => 'SKU',
            self::Jenis => 'Jenis',
            self::LacakStok => 'Lacak Stok',
            self::Kategori => 'Kategori',
            self::Merek => 'Merek',
            self::Satuan => 'Satuan Dasar',
            self::HargaJual => 'Harga Jual',
            self::Barcode => 'Barcode',
            self::KelompokPajak => 'Kelompok Pajak',
            self::HargaTermasukPajak => 'Harga Termasuk Pajak',
            self::TampilDiPos => 'Tampil di POS',
            self::TampilOnline => 'Tampil Online',
            self::Favorit => 'Favorit',
            self::BolehMinus => 'Boleh Jual Saat Stok Kosong',
            self::Pelacakan => 'Pelacakan',
            self::NamaInduk => 'Nama Induk Varian',
            self::Varian => 'Varian',
            self::SatuanAlternatif1, self::SatuanAlternatif2, self::SatuanAlternatif3 => 'Satuan Alternatif '.$this->AmbilNomor(),
            self::KonversiSatuanAlternatif1, self::KonversiSatuanAlternatif2, self::KonversiSatuanAlternatif3 => 'Isi Satuan Alternatif '.$this->AmbilNomor(),
            self::HargaSatuanAlternatif1, self::HargaSatuanAlternatif2, self::HargaSatuanAlternatif3 => 'Harga Satuan Alternatif '.$this->AmbilNomor(),
            self::BarcodeSatuanAlternatif1, self::BarcodeSatuanAlternatif2, self::BarcodeSatuanAlternatif3 => 'Barcode Satuan Alternatif '.$this->AmbilNomor(),
            self::JumlahGrosir1, self::JumlahGrosir2, self::JumlahGrosir3 => 'Min. Qty Grosir '.$this->AmbilNomor(),
            self::HargaGrosir1, self::HargaGrosir2, self::HargaGrosir3 => 'Harga Grosir '.$this->AmbilNomor(),
            self::Status => 'Status',
            self::HargaModal => 'Harga Modal',
            self::Stok => 'Stok',
        };
    }

    public function CekWajib(): bool
    {
        return $this === self::Nama;
    }

    /** Bidang harga jual: butuh izin `produk.harga.ubah`; tanpa izin, kolom ini diabaikan dengan peringatan. */
    public function CekHarga(): bool
    {
        return match ($this) {
            self::HargaJual, self::HargaSatuanAlternatif1, self::HargaSatuanAlternatif2, self::HargaSatuanAlternatif3,
            self::JumlahGrosir1, self::JumlahGrosir2, self::JumlahGrosir3,
            self::HargaGrosir1, self::HargaGrosir2, self::HargaGrosir3 => true,
            default => false,
        };
    }

    /** Bidang yang dikenali tetapi tidak diimpor (peringatan, bukan galat). */
    public function CekDiabaikan(): bool
    {
        return $this === self::HargaModal || $this === self::Stok;
    }

    /** Bidang yang ikut ditulis di templat & ekspor (bidang yang diabaikan tidak ditulis). */
    public function CekDiekspor(): bool
    {
        return ! $this->CekDiabaikan();
    }

    public function AmbilKeterangan(): string
    {
        return match ($this) {
            self::Nama => 'Wajib, maksimal 150 karakter.',
            self::Sku => 'Kosong = dibuat otomatis. Produk dengan SKU yang sudah ada diperbarui atau dilewati.',
            self::Jenis => 'Misal Barang stok, Jasa, Bahan baku. Kosong = jenis bawaan.',
            self::LacakStok => 'Ya = barang stok, Tidak = tanpa stok (dipakai bila Jenis kosong).',
            self::Kategori => 'Misal "Minuman > Kopi", maksimal 3 tingkat.',
            self::Satuan => 'Nama atau simbol satuan, misal pcs atau Kilogram. Kosong = pcs.',
            self::HargaJual => 'Misal 15000, 15.000, atau Rp 15.000,50.',
            self::Barcode => 'Beberapa barcode dipisah koma, titik koma, atau |.',
            self::KelompokPajak => 'Nama kelompok pajak, atau PPN/PB1/Tanpa pajak.',
            self::HargaTermasukPajak => 'Ya, Tidak, atau kosong = ikut outlet.',
            self::Favorit => 'Ya = tampil di kategori Favorit kasir. Kosong = tidak diubah. Bahan baku tidak bisa favorit.',
            self::NamaInduk => 'Isi untuk varian: nama produk induknya.',
            self::Varian => 'Misal "Ukuran: M; Warna: Merah".',
            self::JumlahGrosir1, self::JumlahGrosir2, self::JumlahGrosir3 => 'Jumlah minimal (lebih dari 1, naik berurutan).',
            self::Status => 'Aktif atau Diarsipkan.',
            self::HargaModal, self::Stok => 'Diabaikan: HPP & stok awal diisi di menu Stok awal.',
            default => '',
        };
    }

    /** Nomor urut 1–3 untuk bidang satuan alternatif/grosir; 0 untuk bidang lain. */
    public function AmbilNomor(): int
    {
        return preg_match('/(\d)$/', $this->value, $cocok) === 1 ? (int) $cocok[1] : 0;
    }

    public static function SatuanAlternatif(int $nomor): self
    {
        return self::from('SatuanAlternatif'.$nomor);
    }

    public static function KonversiSatuanAlternatif(int $nomor): self
    {
        return self::from('KonversiSatuanAlternatif'.$nomor);
    }

    public static function HargaSatuanAlternatif(int $nomor): self
    {
        return self::from('HargaSatuanAlternatif'.$nomor);
    }

    public static function BarcodeSatuanAlternatif(int $nomor): self
    {
        return self::from('BarcodeSatuanAlternatif'.$nomor);
    }

    public static function JumlahGrosir(int $nomor): self
    {
        return self::from('JumlahGrosir'.$nomor);
    }

    public static function HargaGrosir(int $nomor): self
    {
        return self::from('HargaGrosir'.$nomor);
    }

    /**
     * @return list<self>
     */
    public static function AmbilDiekspor(): array
    {
        return array_values(array_filter(self::cases(), fn (self $bidang): bool => $bidang->CekDiekspor()));
    }
}
