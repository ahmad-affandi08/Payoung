<?php

declare(strict_types=1);

namespace App\Domain\Situs\Kueri;

use App\Domain\Situs\Model\PengaturanSitus;

/**
 * Pengaturan situs pemasaran (D-21) = bawaan digabung isian konsol. Bawaan dipakai sampai konsol menyimpan pengaturan,
 * sehingga situs langsung tampil rapi setelah dipasang.
 */
final class PengaturanSitusBerlaku
{
    /**
     * @return array<string, mixed>
     */
    public function Ambil(): array
    {
        $tersimpan = PengaturanSitus::query()->where('Kunci', PengaturanSitus::KUNCI_UMUM)->value('Nilai');
        $nilai = is_string($tersimpan) ? json_decode($tersimpan, true) : $tersimpan;

        return self::Gabung(self::AmbilBawaan(), is_array($nilai) ? $nilai : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function AmbilBawaan(): array
    {
        return [
            'NamaSitus' => 'Payoung',
            'Slogan' => 'Smart Choice Your Business Partner',
            'JudulSeo' => 'Payoung | Aplikasi Kasir & Pembukuan untuk Usaha Indonesia',
            'DeskripsiSeo' => 'Aplikasi kasir (POS) yang tetap jalan saat offline, lengkap dengan stok, pajak, promo, pelanggan, dan laporan keuangan. Cocok untuk kafe, resto, toko, salon, dan laundry.',
            'KataKunci' => 'aplikasi kasir, POS, kasir offline, aplikasi kasir kafe, aplikasi kasir toko, pembukuan UMKM',
            'UuidGambarOg' => null,
            'UuidLogo' => null,
            'VerifikasiGoogle' => null,
            'Kontak' => [
                'WhatsApp' => null,
                'PesanWhatsApp' => 'Halo Payoung, saya ingin tahu lebih lanjut tentang aplikasi kasirnya.',
                'Email' => null,
                'Telepon' => null,
                'Alamat' => null,
                'JamLayanan' => 'Senin–Sabtu, 08.00–20.00 WIB',
            ],
            'MediaSosial' => [
                'Instagram' => null,
                'Facebook' => null,
                'Tiktok' => null,
                'Youtube' => null,
                'Linkedin' => null,
                'X' => null,
            ],
            'Pengumuman' => ['Aktif' => false, 'Teks' => null, 'Tautan' => null],
            'Menu' => [
                ['Label' => 'Fitur', 'Tautan' => '/fitur'],
                // D-91: `@solusi` menjadi dropdown sektor (daftar dari template sektor aktif).
                ['Label' => 'Solusi', 'Tautan' => '@solusi'],
                ['Label' => 'Harga', 'Tautan' => '/harga'],
                ['Label' => 'Kontak', 'Tautan' => '/kontak'],
            ],
            'MenuKaki' => [
                ['Judul' => 'Produk', 'Tautan' => [
                    ['Label' => 'Fitur', 'Tautan' => '/fitur'],
                    ['Label' => 'Harga', 'Tautan' => '/harga'],
                    ['Label' => 'Perangkat kompatibel', 'Tautan' => '/kompatibilitas-perangkat'],
                ]],
                ['Judul' => 'Solusi', 'Tautan' => [
                    ['Label' => 'Kafe & Resto', 'Tautan' => '/solusi/kafe-resto'],
                    ['Label' => 'Toko & Retail', 'Tautan' => '/solusi/toko-retail'],
                    ['Label' => 'Salon, Laundry & Jasa', 'Tautan' => '/solusi/jasa'],
                ]],
                ['Judul' => 'Perusahaan', 'Tautan' => [
                    ['Label' => 'Tentang kami', 'Tautan' => '/tentang'],
                    ['Label' => 'Kontak', 'Tautan' => '/kontak'],
                    ['Label' => 'Syarat & ketentuan', 'Tautan' => '/legal/syarat-ketentuan'],
                    ['Label' => 'Kebijakan privasi', 'Tautan' => '/legal/kebijakan-privasi'],
                ]],
            ],
            'TeksKaki' => 'Payoung membantu usaha di Indonesia berjualan, mengelola stok, dan membaca laporan keuangan dari satu aplikasi.',
            'TautanUnduh' => ['Android' => null, 'Ios' => null, 'Windows' => null],
            'TeksTombolDaftar' => 'Coba gratis',
            'TeksTombolMasuk' => 'Masuk',
            'TombolWhatsAppMelayang' => true,
            // Bagian B: analitik hanya dimuat setelah pengunjung menyetujui cookie (UU PDP).
            'Analitik' => ['IdGoogleAnalytics' => null, 'IdMetaPixel' => null],
            'Prospek' => ['EmailNotifikasi' => null],
        ];
    }

    /**
     * Gabung isian di atas bawaan per kunci tingkat pertama & kedua (larik objek); daftar (Menu, MenuKaki) diganti utuh.
     *
     * @param  array<string, mixed>  $bawaan
     * @param  array<string, mixed>  $isian
     * @return array<string, mixed>
     */
    private static function Gabung(array $bawaan, array $isian): array
    {
        $hasil = $bawaan;

        foreach ($bawaan as $kunci => $nilaiBawaan) {
            if (! array_key_exists($kunci, $isian)) {
                continue;
            }

            $hasil[$kunci] = is_array($nilaiBawaan) && ! array_is_list($nilaiBawaan) && is_array($isian[$kunci])
                ? array_merge($nilaiBawaan, array_intersect_key($isian[$kunci], $nilaiBawaan))
                : $isian[$kunci];
        }

        return $hasil;
    }
}
