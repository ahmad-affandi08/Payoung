<?php

declare(strict_types=1);

namespace App\Domain\PanduanAwal\Enum;

/**
 * Langkah wizard panduan awal (F-01 langkah 1–6). Setiap langkah bisa dilewati dan dilanjutkan kapan saja; langkah 7
 * (selesai) dicatat terpisah di `ProgresPanduanAwal.SelesaiPada`.
 */
enum LangkahPanduan: string
{
    case ProfilUsaha = 'ProfilUsaha';
    case Sektor = 'Sektor';
    case Pajak = 'Pajak';
    case Produk = 'Produk';
    case MetodePembayaran = 'MetodePembayaran';
    case Perangkat = 'Perangkat';

    /** Segmen URL kebab-case (D-06), misal `/kelola/panduan-awal/metode-pembayaran`. */
    public function AmbilSlug(): string
    {
        return match ($this) {
            self::ProfilUsaha => 'profil-usaha',
            self::Sektor => 'sektor',
            self::Pajak => 'pajak',
            self::Produk => 'produk',
            self::MetodePembayaran => 'metode-pembayaran',
            self::Perangkat => 'perangkat',
        };
    }

    public static function DariSlug(string $slug): ?self
    {
        foreach (self::cases() as $langkah) {
            if ($langkah->AmbilSlug() === $slug) {
                return $langkah;
            }
        }

        return null;
    }

    /**
     * Langkah yang boleh ditandai Selesai langsung (tombol "Lanjutkan") karena tidak punya Aksi simpan sendiri atau
     * isinya opsional: produk, metode pembayaran, perangkat. Profil usaha, sektor, dan pajak hanya Selesai lewat Aksi
     * langkahnya (datanya wajib tersimpan dulu). Semua langkah tetap boleh dilewati.
     */
    public function CekBisaDitandaiSelesaiLangsung(): bool
    {
        return in_array($this, [self::Produk, self::MetodePembayaran, self::Perangkat], true);
    }

    /**
     * D-24: langkah yang wajib Selesai sebelum tenant baru boleh membuka back-office. Perangkat kasir boleh dilewati
     * (pemilik sering mendaftar dari laptop sebelum tablet kasir tersedia); pengingatnya pindah ke Kotak Tindakan.
     */
    public function CekWajib(): bool
    {
        return $this !== self::Perangkat;
    }

    public function AmbilJudul(): string
    {
        return match ($this) {
            self::ProfilUsaha => 'Profil usaha',
            self::Sektor => 'Jenis usaha & template',
            self::Pajak => 'Pajak',
            self::Produk => 'Produk awal',
            self::MetodePembayaran => 'Metode pembayaran',
            self::Perangkat => 'Perangkat kasir',
        };
    }

    /**
     * D-183: usaha Perusahaan tanpa kasir (semua sektor `BIZ-`) menagih lewat invoice, jadi langkah Metode pembayaran
     * kasir dan Perangkat kasir tidak berlaku baginya.
     */
    public function CekBerlaku(bool $tanpaKasir): bool
    {
        return ! $tanpaKasir || ! in_array($this, [self::MetodePembayaran, self::Perangkat], true);
    }

    /** Langkah yang berlaku bagi tenant ini, urut tampil. @return list<self> */
    public static function AmbilBerlaku(bool $tanpaKasir): array
    {
        return array_values(array_filter(self::cases(), fn (self $l): bool => $l->CekBerlaku($tanpaKasir)));
    }

    public function AmbilBerikutnya(bool $tanpaKasir = false): ?self
    {
        $berlaku = self::AmbilBerlaku($tanpaKasir);
        $posisi = array_search($this, $berlaku, true);

        return is_int($posisi) ? ($berlaku[$posisi + 1] ?? null) : null;
    }

    /** Nama rute halaman langkah, misal `kelola.panduan-awal.pajak`. */
    public function AmbilNamaRute(): string
    {
        return 'kelola.panduan-awal.'.$this->AmbilSlug();
    }
}
