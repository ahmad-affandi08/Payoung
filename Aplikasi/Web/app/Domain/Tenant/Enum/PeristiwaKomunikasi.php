<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Enum;

/**
 * Peristiwa komunikasi platform kepada pemilik tenant yang teksnya bisa diatur lewat templat (PGL-07, P-06). Hanya
 * peristiwa yang pengirimnya benar-benar membaca templat yang terdaftar di sini: dunning tagihan langganan
 * (H-7, H-3, H0, H+3) lewat email, WhatsApp, dan notifikasi aplikasi, serta balasan tim Dukungan atas tiket lewat
 * notifikasi aplikasi (emailnya masih berbentuk tampilan tetap). Tanpa versi terbit, pengirim memakai teks bawaan di bawah, yang sama
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
    case TiketBalasanTim = 'tiket.balasan-tim';

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
            self::TiketBalasanTim => 'Tim Dukungan membalas tiket',
        };
    }

    public function AmbilKeterangan(): string
    {
        return match ($this) {
            self::TagihanHMinus7 => 'Dikirim ke Owner 7 hari sebelum jatuh tempo tagihan langganan (dunning).',
            self::TagihanHMinus3 => 'Dikirim ke Owner 3 hari sebelum jatuh tempo bila tagihan belum dibayar.',
            self::TagihanHariH => 'Dikirim ke Owner pada hari jatuh tempo bila tagihan belum dibayar.',
            self::TagihanHPlus3 => 'Dikirim ke Owner 3 hari setelah jatuh tempo bila tagihan belum dibayar.',
            self::TiketBalasanTim => 'Dikirim ke pelapor tiket begitu tim Dukungan membalas. Isi balasan tidak ikut: pelapor membukanya di halaman tiket setelah masuk.',
        };
    }

    /**
     * @return list<KanalKomunikasi>
     */
    public function AmbilKanal(): array
    {
        return match ($this) {
            self::TiketBalasanTim => [KanalKomunikasi::Notifikasi],
            default => [KanalKomunikasi::Email, KanalKomunikasi::WhatsApp, KanalKomunikasi::Notifikasi],
        };
    }

    /**
     * Variabel yang boleh dipakai di templat peristiwa ini beserta contoh nilai untuk pratinjau.
     *
     * @return list<array{Nama: string, Keterangan: string, Contoh: string}>
     */
    public function AmbilVariabel(): array
    {
        if ($this === self::TiketBalasanTim) {
            return [
                ['Nama' => 'NamaPelapor', 'Keterangan' => 'Nama pelapor tiket', 'Contoh' => 'Rina Wulandari'],
                ['Nama' => 'NomorTiket', 'Keterangan' => 'Nomor tiket', 'Contoh' => 'TKT-2026-000123'],
                ['Nama' => 'JudulTiket', 'Keterangan' => 'Judul tiket', 'Contoh' => 'Printer struk tidak mencetak'],
                ['Nama' => 'NamaPetugas', 'Keterangan' => 'Nama petugas Dukungan yang membalas', 'Contoh' => 'Dewi'],
                ['Nama' => 'Tautan', 'Keterangan' => 'Tautan halaman tiket', 'Contoh' => 'https://dashboard.payoung.id/kelola/bantuan/01K5TIKET'],
            ];
        }

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
        if ($kanal === KanalKomunikasi::Notifikasi) {
            return $this->AmbilBawaanNotifikasi($bahasa);
        }

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
            // Tidak ada kanal email untuk balasan tiket (emailnya tampilan tetap); cadangan agar match tetap lengkap.
            self::TiketBalasanTim => ['Balasan tiket {NomorTiket}', 'Tim Dukungan membalas tiket Anda'],
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
                self::TiketBalasanTim => 'has a reply from our support team',
            };

            return ['Subjek' => null, 'Judul' => null, 'Isi' => "Hello {NamaPemilik}, your {NamaPaket} plan invoice {NomorTagihan} {$keadaan}.\nAmount: {Total}.\nPay at {Tautan}"];
        }

        return match ($this) {
            self::TagihanHMinus7 => ['Subjek' => 'Invoice {NomorTagihan} has been issued', 'Judul' => 'Your subscription invoice has been issued', 'Isi' => 'Your {NamaPaket} plan invoice has been issued. Please pay by {JatuhTempo} so your service continues without interruption.'],
            self::TagihanHMinus3 => ['Subjek' => 'Invoice {NomorTagihan} is due in 3 days', 'Judul' => 'Your invoice is due in 3 days', 'Isi' => 'Your {NamaPaket} plan invoice is due on {JatuhTempo}. Please pay now so your service is not interrupted.'],
            self::TagihanHariH => ['Subjek' => 'Invoice {NomorTagihan} is due today', 'Judul' => 'Today is the payment deadline for your invoice', 'Isi' => 'Today ({JatuhTempo}) is the payment deadline for your {NamaPaket} plan invoice.'],
            self::TagihanHPlus3 => ['Subjek' => 'Invoice {NomorTagihan} is overdue', 'Judul' => 'Your invoice is overdue', 'Isi' => 'Your {NamaPaket} plan invoice has been overdue since {JatuhTempo}. Please complete the payment to keep your plan active.'],
            self::TiketBalasanTim => ['Subjek' => 'Reply to ticket {NomorTiket}', 'Judul' => 'Our support team replied to your ticket', 'Isi' => '{NamaPetugas} replied to ticket {NomorTiket}: {JudulTiket}.'],
        };
    }

    /**
     * Teks notifikasi aplikasi: judul pendek (tampil sebagai judul push) dan isi satu-dua kalimat. Tagihan memakai
     * variabel rinci; balasan tiket tidak memuat isi balasan.
     *
     * @return array{Subjek: string|null, Judul: string|null, Isi: string}
     */
    private function AmbilBawaanNotifikasi(BahasaKomunikasi $bahasa): array
    {
        $inggris = $bahasa === BahasaKomunikasi::Inggris;
        [$judul, $isi] = match ($this) {
            self::TagihanHMinus7 => $inggris
                ? ['Invoice {NomorTagihan} has been issued', 'Your {NamaPaket} plan invoice ({Total}) is due on {JatuhTempo}. Pay in the app to avoid interruption.']
                : ['Tagihan {NomorTagihan} sudah terbit', 'Tagihan paket {NamaPaket} ({Total}) jatuh tempo {JatuhTempo}. Bayar supaya layanan tidak terganggu.'],
            self::TagihanHMinus3 => $inggris
                ? ['Invoice due in 3 days', 'Invoice {NomorTagihan} ({Total}) is due on {JatuhTempo}.']
                : ['Tagihan jatuh tempo 3 hari lagi', 'Tagihan {NomorTagihan} ({Total}) jatuh tempo {JatuhTempo}.'],
            self::TagihanHariH => $inggris
                ? ['Invoice is due today', 'Today is the payment deadline for invoice {NomorTagihan} ({Total}).']
                : ['Tagihan jatuh tempo hari ini', 'Hari ini batas pembayaran tagihan {NomorTagihan} ({Total}).'],
            self::TagihanHPlus3 => $inggris
                ? ['Invoice is overdue', 'Invoice {NomorTagihan} ({Total}) is overdue since {JatuhTempo}. Pay now to keep your plan active.']
                : ['Tagihan lewat jatuh tempo', 'Tagihan {NomorTagihan} ({Total}) lewat jatuh tempo sejak {JatuhTempo}. Bayar sekarang agar paket tetap aktif.'],
            self::TiketBalasanTim => $inggris
                ? ['Support replied to your ticket', '{NamaPetugas} replied to ticket {NomorTiket}: {JudulTiket}.']
                : ['Dukungan membalas tiket Anda', '{NamaPetugas} membalas tiket {NomorTiket}: {JudulTiket}.'],
        };

        return ['Subjek' => null, 'Judul' => $judul, 'Isi' => $isi];
    }
}
