<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Enum;

/**
 * Katalog penyedia WhatsApp (P-05, D-108). Definisi bidang dan label dipindah ke domain Integrasi, mengikuti
 * `PenyediaGerbang`, supaya kode tenant (WhatsApp milik tenant) tidak perlu memakai domain Pengelola. Nilai enum = nilai
 * `PenyediaIntegrasi` dengan jenis `Whatsapp` = kode adaptor `PembuatPengirimWhatsapp`. Bidang `NamaTemplatPengingatTagihan`
 * hanya untuk nomor Payoung (pesan platform kepada pemilik) dan disaring di sisi tenant.
 */
enum PenyediaWhatsapp: string
{
    case MetaCloud = 'MetaCloud';
    case Fonnte = 'Fonnte';
    case Wablas = 'Wablas';
    case StarSender = 'StarSender';
    case Watzap = 'Watzap';

    /** Hanya penyedia resmi Meta yang bernilai true; penyedia lain memakai WhatsApp Web (tidak resmi). */
    public function CekResmi(): bool
    {
        return $this === self::MetaCloud;
    }

    /**
     * @return list<array{Kunci: string, Label: string, Jenis: string, Wajib: bool, Opsi?: list<string>, Bawaan?: string|int, Keterangan?: string}>
     */
    public function AmbilBidangPengaturan(): array
    {
        return match ($this) {
            self::MetaCloud => [
                ['Kunci' => 'IdNomorTelepon', 'Label' => 'Phone number ID', 'Jenis' => 'Teks', 'Wajib' => true, 'Keterangan' => 'Dari WhatsApp Manager › API Setup.'],
                ['Kunci' => 'VersiApi', 'Label' => 'Versi Graph API', 'Jenis' => 'Teks', 'Wajib' => true, 'Bawaan' => 'v21.0'],
                ['Kunci' => 'NamaTemplatStruk', 'Label' => 'Nama templat struk', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat utilitas yang disetujui Meta dengan 3 variabel: {{1}} toko, {{2}} total, {{3}} tautan struk. Kosong = kirim teks (hanya dalam 24 jam setelah pelanggan mengirim pesan).'],
                ['Kunci' => 'NamaTemplatPengingatPiutang', 'Label' => 'Nama templat pengingat piutang', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat utilitas yang disetujui Meta dengan 4 variabel: {{1}} toko, {{2}} nomor nota, {{3}} sisa tagihan, {{4}} tanggal jatuh tempo. Kosong = kirim teks (hanya dalam 24 jam setelah pelanggan mengirim pesan).'],
                ['Kunci' => 'NamaTemplatPesananPembelian', 'Label' => 'Nama templat pesanan pembelian', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat utilitas yang disetujui Meta dengan 4 variabel: {{1}} toko, {{2}} nomor pesanan, {{3}} ringkasan barang, {{4}} total. Dipakai kirim pesanan pembelian ke pemasok. Kosong = kirim teks (hanya dalam 24 jam setelah pemasok mengirim pesan).'],
                ['Kunci' => 'NamaTemplatInvoice', 'Label' => 'Nama templat invoice', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat utilitas yang disetujui Meta dengan 4 variabel: {{1}} toko, {{2}} nomor invoice, {{3}} kalimat ringkas (jumlah & jatuh tempo), {{4}} tautan invoice. Dipakai kirim invoice dan pengingat tagihan ke pelanggan. Kosong = kirim teks (hanya dalam 24 jam setelah pelanggan mengirim pesan).'],
                ['Kunci' => 'NamaTemplatPenawaran', 'Label' => 'Nama templat penawaran', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat utilitas yang disetujui Meta dengan 4 variabel: {{1}} toko, {{2}} nomor penawaran, {{3}} kalimat ringkas (total & masa berlaku), {{4}} tautan penawaran. Dipakai kirim penawaran harga ke pelanggan. Kosong = kirim teks (hanya dalam 24 jam setelah pelanggan mengirim pesan).'],
                ['Kunci' => 'NamaTemplatPengingatReservasi', 'Label' => 'Nama templat pengingat reservasi', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat utilitas yang disetujui Meta dengan 4 variabel: {{1}} toko, {{2}} layanan, {{3}} waktu, {{4}} tautan reservasi. Kosong = kirim teks.'],
                ['Kunci' => 'NamaTemplatPengingatTagihan', 'Label' => 'Nama templat pengingat tagihan langganan', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat utilitas yang disetujui Meta dengan 4 variabel: {{1}} nama, {{2}} kalimat pengingat, {{3}} jumlah, {{4}} tautan bayar. Kosong = kirim teks.'],
                ['Kunci' => 'NamaTemplatRingkasanTindakan', 'Label' => 'Nama templat ringkasan pagi', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat utilitas yang disetujui Meta dengan 4 variabel: {{1}} nama, {{2}} nama usaha, {{3}} ringkasan satu baris, {{4}} tautan Kotak Tindakan. Kosong = kirim teks.'],
                ['Kunci' => 'NamaTemplatInsightMingguan', 'Label' => 'Nama templat insight mingguan', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat utilitas yang disetujui Meta dengan 4 variabel: {{1}} nama, {{2}} nama usaha, {{3}} ringkasan satu baris, {{4}} tautan laporan. Kosong = kirim teks.'],
                ['Kunci' => 'NamaTemplatLaundrySiap', 'Label' => 'Nama templat cucian siap', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat utilitas yang disetujui Meta dengan 3 variabel: {{1}} toko, {{2}} nomor nota, {{3}} tautan status. Kosong = kirim teks.'],
                ['Kunci' => 'NamaTemplatKodeMasuk', 'Label' => 'Nama templat kode masuk', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat autentikasi (Authentication) yang disetujui Meta dengan tombol salin kode: {{1}} kode 6 digit. Dipakai pembeli toko online yang masuk dengan WhatsApp. Kosong = kirim teks (hanya dalam 24 jam setelah pembeli mengirim pesan, jadi sebaiknya diisi).'],
                ['Kunci' => 'NamaTemplatStatusPesanan', 'Label' => 'Nama templat status pesanan', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat utilitas yang disetujui Meta dengan 4 variabel: {{1}} toko, {{2}} nomor pesanan, {{3}} status, {{4}} tautan status. Dipakai pemberitahuan pesanan toko online. Kosong = kirim teks.'],
                ['Kunci' => 'NamaTemplatPromosi', 'Label' => 'Nama templat promosi', 'Jenis' => 'Teks', 'Wajib' => false, 'Keterangan' => 'Templat pemasaran (Marketing) yang disetujui Meta dengan 4 variabel: {{1}} toko, {{2}} nama pelanggan, {{3}} isi pesan, {{4}} tautan berhenti berlangganan. Wajib untuk kampanye pesan WhatsApp lewat API resmi.'],
                ['Kunci' => 'BahasaTemplat', 'Label' => 'Kode bahasa templat', 'Jenis' => 'Teks', 'Wajib' => true, 'Bawaan' => 'id'],
            ],
            self::Wablas => [
                ['Kunci' => 'Domain', 'Label' => 'Domain server Wablas', 'Jenis' => 'Url', 'Wajib' => true, 'Keterangan' => 'Misal https://jkt.wablas.com (lihat dasbor Wablas).'],
            ],
            self::Watzap => [
                ['Kunci' => 'KunciNomor', 'Label' => 'Number key', 'Jenis' => 'Teks', 'Wajib' => true, 'Keterangan' => 'Kunci nomor WhatsApp di dasbor Watzap.'],
            ],
            self::Fonnte, self::StarSender => [],
        };
    }

    /**
     * @return list<array{Kunci: string, Label: string, Wajib: bool}>
     */
    public function AmbilBidangKredensial(): array
    {
        return match ($this) {
            self::MetaCloud => [['Kunci' => 'TokenAkses', 'Label' => 'Token akses permanen (system user)', 'Wajib' => true]],
            self::Fonnte => [['Kunci' => 'Token', 'Label' => 'Token perangkat Fonnte', 'Wajib' => true]],
            self::Wablas => [
                ['Kunci' => 'Token', 'Label' => 'Token Wablas', 'Wajib' => true],
                ['Kunci' => 'KunciRahasia', 'Label' => 'Secret key Wablas (bila diaktifkan)', 'Wajib' => false],
            ],
            self::StarSender, self::Watzap => [['Kunci' => 'KunciApi', 'Label' => 'API key', 'Wajib' => true]],
        };
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::MetaCloud => 'WhatsApp Cloud API (resmi, Meta)',
            self::Fonnte => 'Fonnte (tidak resmi)',
            self::Wablas => 'Wablas (tidak resmi)',
            self::StarSender => 'StarSender (tidak resmi)',
            self::Watzap => 'Watzap (tidak resmi)',
        };
    }

    public function AmbilKeterangan(): string
    {
        return match ($this) {
            self::MetaCloud => 'Resmi dan aman dari pemblokiran. Di luar 24 jam percakapan wajib memakai templat yang disetujui Meta (berbayar per percakapan).',
            self::Fonnte, self::Wablas, self::StarSender, self::Watzap => 'Tidak resmi (WhatsApp Web): murah dan mudah, tetapi nomor bisa diblokir WhatsApp bila mengirim massal. Pakai nomor khusus, bukan nomor utama usaha.',
        };
    }
}
