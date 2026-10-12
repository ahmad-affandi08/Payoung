<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Integrasi\Enum;

use App\Domain\Integrasi\Enum\PenyediaGerbang;
use App\Domain\Integrasi\Enum\PenyediaWhatsapp;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiDokuBilling;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiDokuPartner;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiFcm;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiGerbangPembayaran;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiGoogle;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiKoneksi;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiKoneksiPenyedia;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiS3;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiSmtp;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiTurnstile;
use App\Domain\Pengelola\Integrasi\Penguji\PengujiWhatsapp;

/**
 * Katalog penyedia per jenis integrasi beserta bidang isiannya (P-05, v2.04). Tim platform tinggal memilih penyedia
 * lalu mengisi bidang; nilai `Bawaan` mengisi formulir otomatis (misal host SMTP). Bidang pengaturan tidak rahasia dan
 * boleh tampil; bidang kredensial disimpan terenkripsi dan tidak pernah ditampilkan ulang (BR-P05.1).
 *
 * - Email: semua penyedia lewat SMTP (relay SMTP resmi tiap penyedia), sehingga satu jalur kirim & satu penguji.
 * - Gerbang pembayaran toko: sejak v2.06 diatur per tenant dan sejak keputusan pemilik produk hanya DOKU; definisi
 *   bidang di `PenyediaGerbang` (domain Integrasi), di sini hanya diteruskan. Gerbang tagihan langganan platform adalah
 *   jalur terpisah (`DokuBilling`, jenis `GerbangBilling`): akun DOKU milik Payoung.
 * - WhatsApp: resmi (WhatsApp Cloud API, Meta) atau tidak resmi berbasis WhatsApp Web (risiko nomor diblokir).
 */
enum PenyediaIntegrasi: string
{
    case Smtp = 'Smtp';
    case AmazonSes = 'AmazonSes';
    case Mailgun = 'Mailgun';
    case SendGrid = 'SendGrid';
    case Brevo = 'Brevo';
    case Postmark = 'Postmark';
    case Resend = 'Resend';
    case Mailjet = 'Mailjet';
    case Mailtrap = 'Mailtrap';
    case ZeptoMail = 'ZeptoMail';
    case ElasticEmail = 'ElasticEmail';
    case Gmail = 'Gmail';
    case Microsoft365 = 'Microsoft365';
    case ZohoMail = 'ZohoMail';
    case Hostinger = 'Hostinger';
    case Turnstile = 'Turnstile';
    case S3 = 'S3';
    case Doku = 'Doku';
    case MetaCloud = 'MetaCloud';
    case Fonnte = 'Fonnte';
    case Wablas = 'Wablas';
    case StarSender = 'StarSender';
    case Watzap = 'Watzap';
    case Fcm = 'Fcm';
    case DokuBilling = 'DokuBilling';
    case Google = 'Google';
    case DokuPartner = 'DokuPartner';

    private const MODE = ['Kunci' => 'Mode', 'Label' => 'Mode', 'Jenis' => 'Pilihan', 'Wajib' => true, 'Opsi' => ['Sandbox', 'Produksi'], 'Bawaan' => 'Sandbox', 'Keterangan' => 'Sandbox untuk uji coba tanpa uang sungguhan.'];

    public function AmbilJenis(): JenisIntegrasi
    {
        return match ($this) {
            self::Turnstile => JenisIntegrasi::Captcha,
            self::S3 => JenisIntegrasi::Penyimpanan,
            self::Doku => JenisIntegrasi::GerbangPembayaran,
            self::MetaCloud, self::Fonnte, self::Wablas, self::StarSender, self::Watzap => JenisIntegrasi::Whatsapp,
            self::Fcm => JenisIntegrasi::Push,
            self::DokuBilling => JenisIntegrasi::GerbangBilling,
            self::Google => JenisIntegrasi::LoginSosial,
            self::DokuPartner => JenisIntegrasi::PendaftaranMerchant,
            default => JenisIntegrasi::Email,
        };
    }

    /** Hanya WhatsApp: penyedia resmi Meta. Penyedia lain bernilai true. */
    public function CekResmi(): bool
    {
        return ! in_array($this, [self::Fonnte, self::Wablas, self::StarSender, self::Watzap], true);
    }

    /**
     * @return list<array{Kunci: string, Label: string, Jenis: string, Wajib: bool, Opsi?: list<string>, Bawaan?: string|int, Keterangan?: string}>
     */
    public function AmbilBidangPengaturan(): array
    {
        if ($this->AmbilJenis() === JenisIntegrasi::Email) {
            [$host, $port, $enkripsi, $pengguna] = $this->AmbilBawaanSmtp();

            return [
                ['Kunci' => 'Host', 'Label' => 'Host SMTP', 'Jenis' => 'Teks', 'Wajib' => true, 'Bawaan' => $host, 'Keterangan' => $host === '' ? 'Misal smtp.hostinger.com' : 'Terisi otomatis untuk penyedia ini.'],
                ['Kunci' => 'Port', 'Label' => 'Port', 'Jenis' => 'Angka', 'Wajib' => true, 'Bawaan' => $port, 'Keterangan' => '465 untuk SSL, 587/2525 untuk TLS'],
                ['Kunci' => 'Enkripsi', 'Label' => 'Enkripsi', 'Jenis' => 'Pilihan', 'Wajib' => true, 'Opsi' => ['Ssl', 'Tls'], 'Bawaan' => $enkripsi],
                ['Kunci' => 'NamaPengguna', 'Label' => 'Nama pengguna', 'Jenis' => 'Teks', 'Wajib' => true, 'Bawaan' => $pengguna],
                ['Kunci' => 'AlamatPengirim', 'Label' => 'Alamat pengirim', 'Jenis' => 'Email', 'Wajib' => true, 'Keterangan' => 'Domain pengirim harus sudah diverifikasi di penyedia (SPF/DKIM).'],
                ['Kunci' => 'NamaPengirim', 'Label' => 'Nama pengirim', 'Jenis' => 'Teks', 'Wajib' => true, 'Bawaan' => 'Payoung'],
            ];
        }

        $gerbang = $this->AmbilPenyediaGerbang();

        if ($gerbang !== null) {
            return [self::MODE, ...$gerbang->AmbilBidangPengaturan()];
        }

        $whatsapp = $this->AmbilPenyediaWhatsapp();

        if ($whatsapp !== null) {
            return $whatsapp->AmbilBidangPengaturan();
        }

        return match ($this) {
            self::Google => [
                ['Kunci' => 'ClientId', 'Label' => 'Client ID (aplikasi web)', 'Wajib' => true, 'Jenis' => 'Teks', 'Keterangan' => 'OAuth client jenis "Web application" dari Google Cloud Console › APIs & Services › Credentials. Tambahkan Authorized redirect URI: https://<domain dashboard>/masuk/google/panggilan-balik.'],
                ['Kunci' => 'ClientIdTambahan', 'Label' => 'Client ID tambahan (Android/iOS)', 'Wajib' => false, 'Jenis' => 'Teks', 'Keterangan' => 'Opsional. Client ID Android/iOS Aplikasi Owner, dipisah koma, agar token dari aplikasi diterima. Tanpa isian ini aplikasi memakai Client ID web di atas.'],
            ],
            self::Turnstile => [
                ['Kunci' => 'KunciSitus', 'Label' => 'Kunci situs (site key)', 'Jenis' => 'Teks', 'Wajib' => true, 'Keterangan' => 'Kunci publik yang dipasang di halaman registrasi'],
            ],
            self::S3 => [
                ['Kunci' => 'Endpoint', 'Label' => 'Endpoint', 'Jenis' => 'Url', 'Wajib' => true, 'Keterangan' => 'Misal https://<akun>.r2.cloudflarestorage.com'],
                ['Kunci' => 'Wilayah', 'Label' => 'Wilayah (region)', 'Jenis' => 'Teks', 'Wajib' => true, 'Keterangan' => 'Cloudflare R2: auto'],
                ['Kunci' => 'Bucket', 'Label' => 'Bucket', 'Jenis' => 'Teks', 'Wajib' => true],
            ],
            self::DokuBilling => [
                self::MODE,
                // Client ID DOKU bukan rahasia (ikut terkirim di setiap header permintaan); yang rahasia hanya secret key.
                ['Kunci' => 'IdKlien', 'Label' => 'Client ID', 'Jenis' => 'Teks', 'Wajib' => true, 'Keterangan' => 'Client ID akun DOKU induk milik Payoung (dasbor DOKU › Integration › API Keys), dipakai untuk menagih langganan tenant.'],
            ],
            self::DokuPartner => [
                self::MODE,
                // Brand ID partner bukan rahasia (ikut terkirim di header `Client-Id` setiap permintaan); yang rahasia hanya secret key.
                ['Kunci' => 'IdKlien', 'Label' => 'Brand ID partner (Client-Id)', 'Jenis' => 'Teks', 'Wajib' => true, 'Keterangan' => 'Brand ID Partner yang diberikan account manager DOKU. Mode Sandbox memakai api-uat.doku.com, Produksi memakai api.doku.com.'],
            ],
            default => [],
        };
    }

    /**
     * @return list<array{Kunci: string, Label: string, Wajib: bool}>
     */
    public function AmbilBidangKredensial(): array
    {
        if ($this->AmbilJenis() === JenisIntegrasi::Email) {
            return [['Kunci' => 'KataSandi', 'Label' => $this->AmbilLabelKataSandi(), 'Wajib' => true]];
        }

        $gerbang = $this->AmbilPenyediaGerbang();

        if ($gerbang !== null) {
            return $gerbang->AmbilBidangKredensial();
        }

        $whatsapp = $this->AmbilPenyediaWhatsapp();

        if ($whatsapp !== null) {
            return $whatsapp->AmbilBidangKredensial();
        }

        return match ($this) {
            self::Turnstile => [['Kunci' => 'KunciRahasia', 'Label' => 'Kunci rahasia (secret key)', 'Wajib' => true]],
            self::Google => [['Kunci' => 'ClientSecret', 'Label' => 'Client secret', 'Wajib' => true]],
            self::S3 => [
                ['Kunci' => 'IdKunciAkses', 'Label' => 'ID kunci akses (access key ID)', 'Wajib' => true],
                ['Kunci' => 'KunciAksesRahasia', 'Label' => 'Kunci akses rahasia (secret access key)', 'Wajib' => true],
            ],
            // Satu berkas JSON berisi client_email, private_key, dan project_id; tidak ada yang perlu diketik terpisah.
            self::Fcm => [['Kunci' => 'AkunLayanan', 'Label' => 'Akun layanan Firebase (isi berkas JSON)', 'Wajib' => true]],
            self::DokuBilling, self::DokuPartner => [['Kunci' => 'KunciRahasia', 'Label' => 'Secret key', 'Wajib' => true]],
            default => [],
        };
    }

    /**
     * @return class-string<PengujiKoneksi>|class-string<PengujiKoneksiPenyedia>
     */
    public function AmbilKelasPenguji(): string
    {
        return match ($this->AmbilJenis()) {
            JenisIntegrasi::Email => PengujiSmtp::class,
            JenisIntegrasi::Captcha => PengujiTurnstile::class,
            JenisIntegrasi::Penyimpanan => PengujiS3::class,
            JenisIntegrasi::GerbangPembayaran => PengujiGerbangPembayaran::class,
            JenisIntegrasi::Whatsapp => PengujiWhatsapp::class,
            JenisIntegrasi::Push => PengujiFcm::class,
            JenisIntegrasi::GerbangBilling => PengujiDokuBilling::class,
            JenisIntegrasi::LoginSosial => PengujiGoogle::class,
            JenisIntegrasi::PendaftaranMerchant => PengujiDokuPartner::class,
        };
    }

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::Smtp => 'SMTP (server sendiri / hosting)',
            self::AmazonSes => 'Amazon SES',
            self::Mailgun => 'Mailgun',
            self::SendGrid => 'Twilio SendGrid',
            self::Brevo => 'Brevo (Sendinblue)',
            self::Postmark => 'Postmark',
            self::Resend => 'Resend',
            self::Mailjet => 'Mailjet',
            self::Mailtrap => 'Mailtrap Email Sending',
            self::ZeptoMail => 'Zoho ZeptoMail',
            self::ElasticEmail => 'Elastic Email',
            self::Gmail => 'Gmail / Google Workspace',
            self::Microsoft365 => 'Microsoft 365 / Outlook',
            self::ZohoMail => 'Zoho Mail',
            self::Hostinger => 'Hostinger Email',
            self::Turnstile => 'Cloudflare Turnstile',
            self::S3 => 'S3-compatible (misal Cloudflare R2)',
            self::Doku => $this->AmbilPenyediaGerbang()?->AmbilLabel() ?? $this->value,
            self::MetaCloud, self::Fonnte, self::Wablas, self::StarSender, self::Watzap => $this->AmbilPenyediaWhatsapp()?->AmbilLabel() ?? $this->value,
            self::Fcm => 'Firebase Cloud Messaging',
            self::DokuBilling => 'Akun DOKU Payoung (induk)',
            self::Google => 'Google (Masuk dengan Google)',
            self::DokuPartner => 'DOKU Partner (pendaftaran merchant)',
        };
    }

    public function AmbilKeterangan(): string
    {
        return match ($this) {
            self::Smtp => 'Server SMTP apa pun, misal dari hosting.',
            self::AmazonSes => 'Region Jakarta (ap-southeast-3); ganti host bila memakai region lain. Pakai kredensial SMTP SES, bukan access key IAM.',
            self::Mailgun => 'Pakai smtp.eu.mailgun.org untuk akun region EU.',
            self::SendGrid => 'Nama pengguna selalu "apikey".',
            self::Brevo => 'Nama pengguna = login SMTP di menu SMTP & API.',
            self::Postmark => 'Nama pengguna dan kata sandi sama-sama server API token.',
            self::Resend => 'Nama pengguna selalu "resend".',
            self::Mailjet => 'Nama pengguna = API key Mailjet.',
            self::Mailtrap => 'Untuk pengiriman nyata (bukan sandbox).',
            self::ZeptoMail => 'Nama pengguna selalu "emailapikey".',
            self::ElasticEmail => 'Nama pengguna = email akun Elastic Email.',
            self::Gmail => 'Wajib verifikasi 2 langkah + sandi aplikasi. Batas kirim harian Google berlaku.',
            self::Microsoft365 => 'SMTP AUTH harus diaktifkan untuk kotak surat ini.',
            self::ZohoMail => 'Pakai smtp.zoho.com.au/.eu sesuai pusat data akun.',
            self::Hostinger => '',
            self::Doku => $this->AmbilPenyediaGerbang()?->AmbilKeterangan() ?? '',
            self::MetaCloud, self::Fonnte, self::Wablas, self::StarSender, self::Watzap => $this->AmbilPenyediaWhatsapp()?->AmbilKeterangan() ?? '',
            self::DokuBilling => 'Akun DOKU induk milik Payoung. Dipakai untuk menagih langganan tenant (DOKU Checkout). Berbeda dari gerbang QRIS milik toko, yang akunnya milik tenant masing-masing. Setel URL notifikasi di dasbor DOKU akun ini ke https://<domain-aplikasi>/webhook/billing/doku.',
            self::DokuPartner => 'Akun Partner Payoung di DOKU (Brand ID partner + secret key) untuk mendaftarkan merchant tenant lewat KYB: tenant cukup mengunggah foto KTP, selfie, foto tempat usaha, dan rekening. Payoung harus berstatus Partner di DOKU. URL callback KYB tidak perlu disetel manual: alamatnya dikirim otomatis saat pendaftaran (https://<domain-aplikasi>/webhook/pembayaran/doku-partner). Aktivasi QRIS per Brand masih dilakukan di DOKU Dashboard.',
            self::Google => 'Pemilik toko daftar dan masuk dengan akun Google. Masuk dengan Google menggantikan verifikasi dua langkah (2FA). Panduan lengkap: Panduan/LoginGoogle.md.',
            self::Fcm => 'Satu proyek Firebase melayani Android & iOS sekaligus; sertifikat APNs diunggah di Firebase, bukan di sini. Isi berkas akun layanan dari Setelan proyek → Akun layanan → Buat kunci baru.',
            default => '',
        };
    }

    /** D-108: definisi penyedia WhatsApp ada di katalog `PenyediaWhatsapp` (domain Integrasi), dipakai juga WhatsApp milik tenant. */
    public function AmbilPenyediaWhatsapp(): ?PenyediaWhatsapp
    {
        return PenyediaWhatsapp::tryFrom($this->value);
    }

    /** v2.06: definisi penyedia gerbang pembayaran dipindah ke katalog `PenyediaGerbang` (domain Integrasi). */
    public function AmbilPenyediaGerbang(): ?PenyediaGerbang
    {
        return PenyediaGerbang::tryFrom($this->value);
    }

    /**
     * @return array{0: string, 1: int, 2: string, 3: string}
     */
    private function AmbilBawaanSmtp(): array
    {
        return match ($this) {
            self::Smtp => ['', 587, 'Tls', ''],
            self::AmazonSes => ['email-smtp.ap-southeast-3.amazonaws.com', 587, 'Tls', ''],
            self::Mailgun => ['smtp.mailgun.org', 587, 'Tls', 'postmaster@domain-anda'],
            self::SendGrid => ['smtp.sendgrid.net', 587, 'Tls', 'apikey'],
            self::Brevo => ['smtp-relay.brevo.com', 587, 'Tls', ''],
            self::Postmark => ['smtp.postmarkapp.com', 587, 'Tls', ''],
            self::Resend => ['smtp.resend.com', 465, 'Ssl', 'resend'],
            self::Mailjet => ['in-v3.mailjet.com', 587, 'Tls', ''],
            self::Mailtrap => ['live.smtp.mailtrap.io', 587, 'Tls', 'api'],
            self::ZeptoMail => ['smtp.zeptomail.com', 587, 'Tls', 'emailapikey'],
            self::ElasticEmail => ['smtp.elasticemail.com', 2525, 'Tls', ''],
            self::Gmail => ['smtp.gmail.com', 465, 'Ssl', ''],
            self::Microsoft365 => ['smtp.office365.com', 587, 'Tls', ''],
            self::ZohoMail => ['smtp.zoho.com', 465, 'Ssl', ''],
            self::Hostinger => ['smtp.hostinger.com', 465, 'Ssl', ''],
            default => ['', 587, 'Tls', ''],
        };
    }

    private function AmbilLabelKataSandi(): string
    {
        return match ($this) {
            self::Smtp => 'Kata sandi SMTP',
            self::AmazonSes => 'Kata sandi SMTP SES',
            self::Mailgun => 'Kata sandi SMTP Mailgun',
            self::SendGrid => 'API key SendGrid',
            self::Brevo => 'Kunci SMTP Brevo',
            self::Postmark => 'Server API token',
            self::Resend => 'API key Resend',
            self::Mailjet => 'Secret key Mailjet',
            self::Mailtrap => 'API token Mailtrap',
            self::ZeptoMail => 'Token kirim ZeptoMail',
            self::ElasticEmail => 'API key Elastic Email',
            self::Gmail => 'Sandi aplikasi Google',
            self::Microsoft365 => 'Kata sandi akun',
            self::ZohoMail => 'Kata sandi / sandi aplikasi Zoho',
            self::Hostinger => 'Kata sandi email Hostinger',
            default => 'Kata sandi',
        };
    }
}
