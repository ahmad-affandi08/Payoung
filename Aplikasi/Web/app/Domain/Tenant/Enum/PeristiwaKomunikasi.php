<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Peristiwa komunikasi platform kepada pemilik tenant yang teksnya bisa diatur lewat templat (PGL-07, P-06). Hanya
 * peristiwa yang pengirimnya benar-benar membaca templat yang terdaftar di sini: saat ini dunning tagihan langganan
 * (H-7, H-3, H0, H+3) lewat email dan WhatsApp. Tanpa versi terbit, pengirim memakai teks bawaan di bawah, yang sama
 * dengan teks sebelum fitur ini ada.
 *
 * Variabel ditulis `{Nama}` di templat. `Kalimat` adalah kalimat Indonesia per tahap yang dirakit pengirim dari data
 * tagihan; templat Inggris memakai variabel rinci (`NamaPaket`, `JatuhTempo`, ...) supaya tidak bercampur bahasa.
 */
enum PeristiwaKomunikasi: string
{
    case TagihanHMinus7 = 'tagihan.pengingat.h-7';
    case TagihanHMinus3 = 'tagihan.pengingat.h-3';
    case TagihanHariH = 'tagihan.pengingat.hari-h';
    case TagihanHPlus3 = 'tagihan.pengingat.h-plus-3';

    public static function DariTahap(TahapPengingatTagihan $tahap): self
    {
        return match ($tahap) {
            TahapPengingatTagihan::HMinus7 => self::TagihanHMinus7,
            TahapPengingatTagihan::HMinus3 => self::TagihanHMinus3,
            TahapPengingatTagihan::HariH => self::TagihanHariH,
            TahapPengingatTagihan::HPlus3 => self::TagihanHPlus3,
        };
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::TagihanHMinus7 => 'Tagihan langganan terbit (H-7)',
            self::TagihanHMinus3 => 'Tagihan jatuh tempo 3 hari lagi (H-3)',
            self::TagihanHariH => 'Tagihan jatuh tempo hari ini (H0)',
            self::TagihanHPlus3 => 'Tagihan lewat jatuh tempo (H+3)',
        };
    }

    public function AmbilKeterangan(): string
    {
        return match ($this) {
            self::TagihanHMinus7 => 'Dikirim ke Owner 7 hari sebelum jatuh tempo tagihan langganan (dunning).',
            self::TagihanHMinus3 => 'Dikirim ke Owner 3 hari sebelum jatuh tempo bila tagihan belum dibayar.',
            self::TagihanHariH => 'Dikirim ke Owner pada hari jatuh tempo bila tagihan belum dibayar.',
            self::TagihanHPlus3 => 'Dikirim ke Owner 3 hari setelah jatuh tempo bila tagihan belum dibayar.',
        };
    }

    /**
     * @return list<KanalKomunikasi>
     */
    public function AmbilKanal(): array
    {
        return [KanalKomunikasi::Email, KanalKomunikasi::WhatsApp];
    }

    /**
     * Variabel yang boleh dipakai di templat peristiwa ini beserta contoh nilai untuk pratinjau.
     *
     * @return list<array{Nama: string, Keterangan: string, Contoh: string}>
     */
    public function AmbilVariabel(): array
    {
        $kalimat = match ($this) {
            self::TagihanHMinus7 => 'Tagihan langganan paket Pro sudah terbit. Bayar paling lambat 27 Oktober 2026 supaya layanan tetap berjalan tanpa jeda.',
            self::TagihanHMinus3 => 'Tagihan langganan paket Pro jatuh tempo pada 27 Oktober 2026. Bayar sekarang supaya layanan tidak terganggu.',
            self::TagihanHariH => 'Hari ini (27 Oktober 2026) batas pembayaran tagihan langganan paket Pro.',
            self::TagihanHPlus3 => 'Tagihan langganan paket Pro sudah lewat jatuh tempo sejak 27 Oktober 2026. Bila belum dibayar, layanan akan ditangguhkan pada 3 November 2026.',
        };

        return [
            ['Nama' => 'NamaPemilik', 'Keterangan' => 'Nama Owner penerima', 'Contoh' => 'Rina Wulandari'],
            ['Nama' => 'NomorTagihan', 'Keterangan' => 'Nomor tagihan langganan', 'Contoh' => 'INV/2610/0042'],
            ['Nama' => 'NamaPaket', 'Keterangan' => 'Nama paket langganan', 'Contoh' => 'Pro'],
            ['Nama' => 'Total', 'Keterangan' => 'Total tagihan dalam Rupiah', 'Contoh' => 'Rp 149.000'],
            ['Nama' => 'JatuhTempo', 'Keterangan' => 'Tanggal jatuh tempo', 'Contoh' => '27 Oktober 2026'],
            ['Nama' => 'TanggalDitangguhkan', 'Keterangan' => 'Tanggal layanan ditangguhkan bila belum dibayar (kosong bila belum ada)', 'Contoh' => '3 November 2026'],
            ['Nama' => 'CatatanPenangguhan', 'Keterangan' => 'Kalimat penutup H+3: peringatan penangguhan atau ajakan menyelesaikan pembayaran', 'Contoh' => 'Bila belum dibayar, layanan akan ditangguhkan pada 3 November 2026.'],
            ['Nama' => 'Kalimat', 'Keterangan' => 'Kalimat Indonesia siap pakai untuk tahap ini', 'Contoh' => $kalimat],
            ['Nama' => 'Tautan', 'Keterangan' => 'Tautan halaman langganan untuk membayar', 'Contoh' => 'https://dashboard.payoung.id/kelola/langganan'],
        ];
    }

    /**
     * Variabel yang wajib muncul di templat kanal ini (tanpa itu pesan tidak berguna bagi penerima).
     *
     * @return list<string>
     */
    public function AmbilVariabelWajib(KanalKomunikasi $kanal): array
    {
        return $kanal === KanalKomunikasi::WhatsApp ? ['Tautan'] : [];
    }

    /**
     * Teks bawaan kode untuk kanal dan bahasa: `Subjek`/`Judul` hanya untuk email (null untuk WhatsApp).
     *
     * @return array{Subjek: string|null, Judul: string|null, Isi: string}
     */
    public function AmbilBawaan(KanalKomunikasi $kanal, BahasaKomunikasi $bahasa): array
    {
        if ($bahasa === BahasaKomunikasi::Inggris) {
            return $this->AmbilBawaanInggris($kanal);
        }

        if ($kanal === KanalKomunikasi::WhatsApp) {
            return ['Subjek' => null, 'Judul' => null, 'Isi' => "Halo {NamaPemilik}, {Kalimat}\nTagihan {NomorTagihan}: {Total}.\nBayar di {Tautan}"];
        }

        [$subjek, $judul] = match ($this) {
            self::TagihanHMinus7 => ['Tagihan {NomorTagihan} sudah terbit', 'Tagihan langganan Anda sudah terbit'],
            self::TagihanHMinus3 => ['Tagihan {NomorTagihan} jatuh tempo 3 hari lagi', 'Tagihan Anda jatuh tempo 3 hari lagi'],
            self::TagihanHariH => ['Tagihan {NomorTagihan} jatuh tempo hari ini', 'Hari ini batas pembayaran tagihan Anda'],
            self::TagihanHPlus3 => ['Tagihan {NomorTagihan} sudah lewat jatuh tempo', 'Tagihan Anda sudah lewat jatuh tempo'],
        };

        return ['Subjek' => $subjek, 'Judul' => $judul, 'Isi' => '{Kalimat}'];
    }

    /**
     * @return array{Subjek: string|null, Judul: string|null, Isi: string}
     */
    private function AmbilBawaanInggris(KanalKomunikasi $kanal): array
    {
        if ($kanal === KanalKomunikasi::WhatsApp) {
            $keadaan = match ($this) {
                self::TagihanHMinus7 => 'has been issued and is due on {JatuhTempo}',
                self::TagihanHMinus3 => 'is due in 3 days, on {JatuhTempo}',
                self::TagihanHariH => 'is due today ({JatuhTempo})',
                self::TagihanHPlus3 => 'has been overdue since {JatuhTempo}',
            };

            return ['Subjek' => null, 'Judul' => null, 'Isi' => "Hello {NamaPemilik}, your {NamaPaket} plan invoice {NomorTagihan} {$keadaan}.\nAmount: {Total}.\nPay at {Tautan}"];
        }

        return match ($this) {
            self::TagihanHMinus7 => ['Subjek' => 'Invoice {NomorTagihan} has been issued', 'Judul' => 'Your subscription invoice has been issued', 'Isi' => 'Your {NamaPaket} plan invoice has been issued. Please pay by {JatuhTempo} so your service continues without interruption.'],
            self::TagihanHMinus3 => ['Subjek' => 'Invoice {NomorTagihan} is due in 3 days', 'Judul' => 'Your invoice is due in 3 days', 'Isi' => 'Your {NamaPaket} plan invoice is due on {JatuhTempo}. Please pay now so your service is not interrupted.'],
            self::TagihanHariH => ['Subjek' => 'Invoice {NomorTagihan} is due today', 'Judul' => 'Today is the payment deadline for your invoice', 'Isi' => 'Today ({JatuhTempo}) is the payment deadline for your {NamaPaket} plan invoice.'],
            self::TagihanHPlus3 => ['Subjek' => 'Invoice {NomorTagihan} is overdue', 'Judul' => 'Your invoice is overdue', 'Isi' => 'Your {NamaPaket} plan invoice has been overdue since {JatuhTempo}. Please complete the payment to keep your plan active.'],
        };
    }
}
