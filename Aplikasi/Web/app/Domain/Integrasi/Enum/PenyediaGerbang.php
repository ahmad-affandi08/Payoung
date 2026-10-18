<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Enum;

/**
 * Katalog penyedia gerbang pembayaran QRIS dinamis toko (F-08, P-05). Sejak keputusan pemilik produk (menggantikan D-19)
 * seluruh tenant memakai DOKU, sehingga katalog ini hanya berisi `Doku`; Midtrans, Xendit, Tripay, Duitku, dan iPaymu
 * sudah dihapus dari jalur gerbang toko. Nilai enum = kode adaptor `PembuatGerbangPembayaran` dan nilai
 * `PenyediaIntegrasi` (P-05). Gerbang tagihan langganan platform (`PenyediaIntegrasi::DokuBilling`, akun DOKU milik Payoung) adalah
 * jalur lain dan tidak termasuk katalog ini.
 *
 * Bidang pengaturan tidak rahasia dan boleh tampil; bidang kredensial disimpan terenkripsi dan tidak pernah ditampilkan
 * ulang (hanya 4 karakter terakhir, BR-P05.1). Mode Sandbox/Produksi tidak termasuk bidang: disimpan di kolom
 * `Lingkungan` dan diteruskan ke adaptor sebagai pengaturan `Mode`.
 */
enum PenyediaGerbang: string
{
    case Doku = 'Doku';

    /** Segmen URL webhook (`/webhook/{kode}/{tokenWebhook}`). */
    public function AmbilKodeUrl(): string
    {
        return strtolower($this->value);
    }

    public static function DariKodeUrl(string $kode): ?self
    {
        foreach (self::cases() as $penyedia) {
            if ($penyedia->AmbilKodeUrl() === $kode) {
                return $penyedia;
            }
        }

        return null;
    }

    /** Nama untuk pengguna. Memakai `match` tanpa `default` supaya menambah penyedia baru memaksa label dilengkapi (D-179). */
    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Doku => 'DOKU',
        };
    }

    public function AmbilKeterangan(): string
    {
        return match ($this) {
            self::Doku => 'DOKU Checkout (halaman bayar QRIS). Salin URL webhook ke pengaturan notifikasi di dasbor DOKU.',
        };
    }

    /**
     * @return list<array{Kunci: string, Label: string, Jenis: string, Wajib: bool, Opsi?: list<string>, Bawaan?: string|int, Keterangan?: string}>
     */
    public function AmbilBidangPengaturan(): array
    {
        return match ($this) {
            self::Doku => [
                ['Kunci' => 'IdKlien', 'Label' => 'Client ID', 'Jenis' => 'Teks', 'Wajib' => true, 'Keterangan' => 'DOKU Checkout menampilkan halaman bayar QRIS (QR berisi tautan halaman bayar).'],
            ],
        };
    }

    /**
     * @return list<array{Kunci: string, Label: string, Wajib: bool}>
     */
    public function AmbilBidangKredensial(): array
    {
        return match ($this) {
            self::Doku => [['Kunci' => 'KunciRahasia', 'Label' => 'Secret key', 'Wajib' => true]],
        };
    }
}
