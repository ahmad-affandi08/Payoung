<?php

declare(strict_types=1);

namespace App\Domain\Situs\Layanan;

/**
 * Skema blok (bagian) halaman situs pemasaran (D-21). Setiap blok `{Jenis, ...}`; bidang per jenis:
 * - `['Teks', maks, wajib?]` teks satu baris; `['TeksPanjang', maks, wajib?]` teks bebas (baris baru, daftar `- `,
 *   judul `## `, **tebal**, [tautan](url) — dirender aman di React, tanpa HTML);
 * - `['Tombol']` `{Label, Tautan}` (keduanya kosong = tidak ada tombol); `['Tautan']`; `['Gambar']` Uuid `GambarSitus`;
 * - `['Ikon']` nama dari `IKON`; `['Pilihan', [..]]`; `['Bilangan', min, maks]`; `['Benar']`;
 * - `['Daftar', min, maks, [subskema]]` daftar item.
 *
 * Tautan: jalur situs (`/harga`, `#faq`), `https://…`, `mailto:`, `tel:`, atau pintasan `@daftar`, `@masuk`,
 * `@whatsapp`, `@unduh-android`, `@unduh-ios`, `@unduh-windows` yang diterjemahkan server saat dirender (mengikuti
 * domain & pengaturan kontak terkini).
 */
final class SkemaBagianSitus
{
    public const MAKS_BAGIAN = 40;

    public const PINTASAN_TAUTAN = ['@daftar', '@masuk', '@whatsapp', '@unduh-android', '@unduh-ios', '@unduh-windows'];

    /** Ikon yang boleh dipakai (nama komponen lucide-react di `Komponen/Situs/IkonSitus.tsx`). */
    public const IKON = [
        'WifiOff', 'Receipt', 'Package', 'ChartColumn', 'Users', 'Store', 'Coffee', 'Scissors', 'Shirt', 'Truck',
        'ShieldCheck', 'Smartphone', 'Printer', 'Tag', 'Gift', 'Wallet', 'Calculator', 'Clock', 'Cloud', 'Headphones',
        'Zap', 'BadgeCheck', 'QrCode', 'UtensilsCrossed', 'ShoppingBasket', 'Building', 'ChartLine', 'Percent', 'Star',
        'Heart', 'Sparkles', 'Monitor', 'CreditCard', 'Boxes', 'ClipboardList', 'WashingMachine', 'Warehouse', 'Globe',
        'Lock', 'RefreshCw', 'MessageCircle', 'Bell', 'FileText', 'Landmark', 'HandCoins', 'ChefHat', 'Pill', 'Car',
    ];

    /**
     * Spesimen = tangkapan layar **asli** aplikasi Payoung (berkas di `resources/js/Aset/Situs` yang ikut build Vite, dibuat
     * `Spesifikasi/Merek/BuatFotoSitus.py`) atau keluaran produk (struk, jurnal). Dipakai isi bawaan karena gambar
     * unggahan konsol dirujuk lewat Uuid. Peta ke berkas ada di `Komponen/Situs/SpesimenSitus.tsx`.
     */
    public const SPESIMEN = [
        'Kasir', 'KasirBayar', 'KasirBerhasil', 'KasirShift', 'KasirRiwayat', 'KasirKas', 'KasirHp',
        'KasirSektorKafe', 'KasirSektorToko', 'KasirSektorSalon', 'KasirSektorLaundry', 'KasirSektorApotek', 'KasirSektorBengkel',
        'Pemilik', 'PemilikDuo', 'PemilikLaporan', 'PemilikPersetujuan', 'PemilikKaryawan', 'PemilikInsight',
        'Struk', 'Jurnal',
    ];

    /** @var array<string, string> */
    public const LABEL = [
        'Hero' => 'Pembuka (hero)',
        'HeroGeser' => 'Pembuka geser (carousel)',
        'TabUsaha' => 'Tab jenis usaha',
        'Langkah' => 'Langkah bernomor',
        'Integrasi' => 'Integrasi & perangkat',
        'Keunggulan' => 'Keunggulan / fitur',
        'Sektor' => 'Jenis usaha',
        'GambarTeks' => 'Gambar & teks',
        'Statistik' => 'Angka statistik',
        'Testimoni' => 'Testimoni',
        'Harga' => 'Harga paket (otomatis dari konsol)',
        'Faq' => 'Tanya jawab (FAQ)',
        'Cta' => 'Ajakan (call to action)',
        'TeksBebas' => 'Teks bebas',
        'LogoMitra' => 'Logo mitra / klien',
        'Video' => 'Video YouTube',
        'UnduhAplikasi' => 'Unduh aplikasi',
        'Kontak' => 'Kontak',
        'FormulirProspek' => 'Formulir kontak / minta demo',
    ];

    /**
     * @return array<string, array<string, array<int, mixed>>>
     */
    public static function AmbilSkema(): array
    {
        $judulBagian = ['Label' => ['Teks', 60], 'Judul' => ['Teks', 140], 'Subjudul' => ['TeksPanjang', 400]];

        return [
            'Hero' => [
                'Label' => ['Teks', 60],
                'Judul' => ['Teks', 140, true],
                'Subjudul' => ['TeksPanjang', 400],
                'TombolUtama' => ['Tombol'],
                'TombolKedua' => ['Tombol'],
                'Gambar' => ['Gambar'],
                'Catatan' => ['Teks', 160],
                // D-39: tiga alasan singkat untuk percaya, tampil sebagai baris centang di bawah tombol.
                'Poin' => ['Daftar', 0, 4, ['Teks' => ['Teks', 60, true]]],
                'Latar' => ['Pilihan', ['Terang', 'Merek', 'Navy']],
                'Spesimen' => ['Pilihan', self::SPESIMEN],
            ],
            // Pembuka beranda berupa carousel (permintaan pemilik produk): tiap sorotan punya judul, satu kalimat,
            // tombol, dan tangkapan layar asli. Semua teks sorotan selalu ada di HTML; hanya satu yang terlihat.
            'HeroGeser' => [
                'Sorotan' => ['Daftar', 2, 8, [
                    'Label' => ['Teks', 40, true],
                    'Judul' => ['Teks', 120, true],
                    'Teks' => ['TeksPanjang', 260],
                    'TombolUtama' => ['Tombol'],
                    'TombolKedua' => ['Tombol'],
                    'Gambar' => ['Gambar'],
                    'Spesimen' => ['Pilihan', self::SPESIMEN],
                ]],
                'Poin' => ['Daftar', 0, 4, ['Teks' => ['Teks', 60, true]]],
                'Catatan' => ['Teks', 160],
            ],
            'TabUsaha' => $judulBagian + [
                'Item' => ['Daftar', 2, 8, [
                    'Label' => ['Teks', 40, true],
                    'Ikon' => ['Ikon'],
                    'Judul' => ['Teks', 120, true],
                    'Teks' => ['TeksPanjang', 400],
                    'Poin' => ['Daftar', 0, 6, ['Teks' => ['Teks', 140, true]]],
                    'Gambar' => ['Gambar'],
                    'Spesimen' => ['Pilihan', self::SPESIMEN],
                    'Tombol' => ['Tombol'],
                ]],
            ],
            'Langkah' => $judulBagian + [
                'Item' => ['Daftar', 2, 6, ['Ikon' => ['Ikon'], 'Judul' => ['Teks', 80, true], 'Teks' => ['TeksPanjang', 240]]],
                'Tombol' => ['Tombol'],
            ],
            'Integrasi' => $judulBagian + [
                'Kelompok' => ['Daftar', 1, 6, [
                    'Ikon' => ['Ikon'],
                    'Judul' => ['Teks', 60, true],
                    'Teks' => ['TeksPanjang', 200],
                    'Item' => ['Daftar', 1, 12, ['Nama' => ['Teks', 40, true]]],
                ]],
                'Catatan' => ['Teks', 200],
            ],
            'Keunggulan' => $judulBagian + [
                'TataLetak' => ['Pilihan', ['Grid', 'Daftar', 'Sorot']],
                'Kolom' => ['Pilihan', ['2', '3', '4']],
                'Item' => ['Daftar', 1, 12, ['Ikon' => ['Ikon'], 'Judul' => ['Teks', 80, true], 'Teks' => ['TeksPanjang', 300]]],
            ],
            'Sektor' => $judulBagian + [
                'Item' => ['Daftar', 1, 12, [
                    'Ikon' => ['Ikon'],
                    'Nama' => ['Teks', 60, true],
                    'Teks' => ['TeksPanjang', 240],
                    'Tautan' => ['Tautan'],
                    'Gambar' => ['Gambar'],
                ]],
            ],
            'GambarTeks' => $judulBagian + [
                'Teks' => ['TeksPanjang', 2000],
                'Poin' => ['Daftar', 0, 8, ['Teks' => ['Teks', 140, true]]],
                'Gambar' => ['Gambar'],
                'PosisiGambar' => ['Pilihan', ['Kanan', 'Kiri']],
                'Spesimen' => ['Pilihan', self::SPESIMEN],
                'Tombol' => ['Tombol'],
            ],
            'Statistik' => $judulBagian + [
                'Item' => ['Daftar', 1, 6, ['Angka' => ['Teks', 20, true], 'Keterangan' => ['Teks', 80, true]]],
            ],
            'Testimoni' => $judulBagian + [
                'Item' => ['Daftar', 1, 12, [
                    'Nama' => ['Teks', 80, true],
                    'Usaha' => ['Teks', 100],
                    'Kutipan' => ['TeksPanjang', 500, true],
                    'Foto' => ['Gambar'],
                    'Bintang' => ['Bilangan', 0, 5],
                ]],
            ],
            'Harga' => $judulBagian + [
                'TampilkanTahunan' => ['Benar'],
                'PaketDisorot' => ['Teks', 30],
                'TeksTombol' => ['Teks', 40],
                'CatatanKaki' => ['TeksPanjang', 400],
            ],
            'Faq' => $judulBagian + [
                'Item' => ['Daftar', 1, 40, ['Pertanyaan' => ['Teks', 200, true], 'Jawaban' => ['TeksPanjang', 2000, true]]],
            ],
            'Cta' => [
                'Judul' => ['Teks', 140, true],
                'Teks' => ['TeksPanjang', 400],
                'TombolUtama' => ['Tombol'],
                'TombolKedua' => ['Tombol'],
            ],
            'TeksBebas' => $judulBagian + ['Isi' => ['TeksPanjang', 20000, true]],
            'LogoMitra' => $judulBagian + [
                'Item' => ['Daftar', 1, 24, ['Gambar' => ['Gambar', true], 'Nama' => ['Teks', 80, true], 'Tautan' => ['Tautan']]],
            ],
            'Video' => $judulBagian + ['UrlYoutube' => ['Tautan', true]],
            'UnduhAplikasi' => $judulBagian,
            'Kontak' => $judulBagian,
            // Bagian B: isian masuk ke konsol Situs → Prospek (persetujuan data wajib, UU PDP).
            'FormulirProspek' => $judulBagian + [
                'JenisProspek' => ['Pilihan', ['Kontak', 'Demo']],
                'TeksTombol' => ['Teks', 40],
            ],
        ];
    }
}
