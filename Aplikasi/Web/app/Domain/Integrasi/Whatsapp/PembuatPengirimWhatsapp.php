<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Whatsapp;

use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Model\WhatsappTenant;
use App\Domain\Integrasi\Whatsapp\Adaptor\AdaptorFonnte;
use App\Domain\Integrasi\Whatsapp\Adaptor\AdaptorMetaCloud;
use App\Domain\Integrasi\Whatsapp\Adaptor\AdaptorStarSender;
use App\Domain\Integrasi\Whatsapp\Adaptor\AdaptorWablas;
use App\Domain\Integrasi\Whatsapp\Adaptor\AdaptorWatzap;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Membuat pengirim WhatsApp dari kode penyedia P-05 atau dari konfigurasi aktif (`config('integrasi.Whatsapp')`).
 *
 * D-108: `AmbilAktif()` dipakai semua pengiriman atas nama tenant dan tahu siapa pengirimnya. Bila tenant punya
 * WhatsApp sendiri yang aktif (`WhatsappTenant`), pesan lewat nomornya dan tidak memakai kuota. Bila tidak, pesan
 * lewat nomor Payoung dan dihitung ke kuota paket (`PengirimWhatsappBerkuota`). Tanpa konteks tenant, atau untuk pesan
 * Payoung kepada pemilik (tagihan langganan), pakai `AmbilAktifPlatform()` yang tidak menghitung kuota.
 * Nama templat resmi dibaca dari pengaturan pengirim yang sama dengan yang dipakai mengirim.
 */
final class PembuatPengirimWhatsapp
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly MeterKuotaWhatsapp $meter,
        private readonly PemeriksaFiturTenant $fitur,
    ) {}

    /**
     * @param  array<string, string|int>  $pengaturan
     * @param  array<string, string>  $kredensial
     */
    public function Buat(string $penyedia, array $pengaturan, array $kredensial): ?PengirimWhatsapp
    {
        return match ($penyedia) {
            'MetaCloud' => new AdaptorMetaCloud($pengaturan, $kredensial),
            'Fonnte' => new AdaptorFonnte($pengaturan, $kredensial),
            'Wablas' => new AdaptorWablas($pengaturan, $kredensial),
            'StarSender' => new AdaptorStarSender($pengaturan, $kredensial),
            'Watzap' => new AdaptorWatzap($pengaturan, $kredensial),
            default => null,
        };
    }

    /** Pengirim atas nama tenant aktif: nomor milik tenant (tanpa kuota) atau nomor Payoung berkuota. */
    public function AmbilAktif(): ?PengirimWhatsapp
    {
        $idTenant = $this->konteks->Ambil();

        if ($idTenant === null) {
            return $this->AmbilAktifPlatform();
        }

        $milik = $this->AmbilMilikTenant();

        if ($milik !== null) {
            return $milik;
        }

        $platform = $this->AmbilAktifPlatform();

        return $platform === null ? null : new PengirimWhatsappBerkuota($platform, $this->meter, $idTenant);
    }

    /** Nomor Payoung apa adanya, tanpa meteran: pesan Payoung ke pemilik, uji koneksi, dan tanpa konteks tenant. */
    public function AmbilAktifPlatform(): ?PengirimWhatsapp
    {
        $konfigurasi = config('integrasi.Whatsapp');

        if (! is_array($konfigurasi) || ! is_string($konfigurasi['Penyedia'] ?? null)) {
            return null;
        }

        /** @var array<string, string|int> $pengaturan */
        $pengaturan = is_array($konfigurasi['Pengaturan'] ?? null) ? $konfigurasi['Pengaturan'] : [];
        /** @var array<string, string> $kredensial */
        $kredensial = is_array($konfigurasi['Kredensial'] ?? null) ? $konfigurasi['Kredensial'] : [];

        return $this->Buat($konfigurasi['Penyedia'], $pengaturan, $kredensial);
    }

    /** WhatsApp milik tenant aktif bila sudah diuji dan diaktifkan; null bila belum ada atau kredensialnya rusak. */
    public function AmbilMilikTenant(): ?PengirimWhatsapp
    {
        // Nomor sendiri tidak berkuota, jadi satu-satunya penjaganya adalah fitur paket: tenant yang turun paket tidak
        // boleh terus mengirim tanpa batas lewat nomor yang sudah tersimpan (laundry, reservasi, servis, insight).
        $idTenant = $this->konteks->Ambil();

        if ($idTenant !== null && ! $this->fitur->CekAktif($idTenant, PemeriksaFiturTenant::KUNCI_WHATSAPP)) {
            return null;
        }

        $baris = WhatsappTenant::query()->where('Aktif', true)->first();

        return $baris === null ? null : $this->BuatDariTenant($baris);
    }

    /** Adaptor dari baris WhatsApp tenant (tanpa memeriksa status aktif). Null bila kredensial tidak bisa didekripsi. */
    public function BuatDariTenant(WhatsappTenant $baris): ?PengirimWhatsapp
    {
        try {
            // Didekripsi eksplisit (bukan lewat cast) agar kunci APP_KEY yang salah tertangkap di sini.
            $isi = json_decode(Crypt::decryptString((string) $baris->getRawOriginal('Kredensial')), true);
        } catch (DecryptException) {
            Log::error('Kredensial WhatsApp tenant tidak bisa didekripsi; periksa APP_KEY/APP_PREVIOUS_KEYS.', ['IdTenant' => $baris->IdTenant]);

            return null;
        }

        $kredensial = [];

        foreach (is_array($isi) ? $isi : [] as $kunci => $nilai) {
            if (is_string($kunci) && is_string($nilai)) {
                $kredensial[$kunci] = $nilai;
            }
        }

        return $this->Buat($baris->Penyedia->value, $baris->Pengaturan, $kredensial);
    }

    /**
     * Pengaturan penyedia yang sedang dipakai: milik tenant bila aktif, selain itu konfigurasi Payoung. Sumber nama
     * templat resmi, karena templat Meta terdaftar per akun.
     *
     * @return array<string, mixed>
     */
    private function AmbilPengaturanAktif(): array
    {
        if ($this->konteks->Ambil() !== null) {
            $baris = WhatsappTenant::query()->where('Aktif', true)->first();

            if ($baris !== null) {
                return $baris->Pengaturan;
            }
        }

        $pengaturan = config('integrasi.Whatsapp.Pengaturan');

        return is_array($pengaturan) ? $pengaturan : [];
    }

    private function AmbilNamaTemplat(string $kunci): ?string
    {
        $nama = $this->AmbilPengaturanAktif()[$kunci] ?? null;

        return is_string($nama) && $nama !== '' ? $nama : null;
    }

    /** Nama templat resmi untuk struk digital (hanya WhatsApp Cloud API); null = kirim teks. */
    public function AmbilTemplatStruk(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatStruk');
    }

    /** Nama templat resmi untuk pengingat reservasi H-1 (F-07 mode service); null = kirim teks. */
    public function AmbilTemplatPengingatReservasi(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatPengingatReservasi');
    }

    /** Nama templat resmi untuk tautan persetujuan estimasi servis bengkel (§9.10); null = kirim teks. */
    public function AmbilTemplatPersetujuanServis(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatPersetujuanServis');
    }

    /** Nama templat resmi untuk pengingat servis berkala bengkel (§9.10); null = kirim teks. */
    public function AmbilTemplatPengingatServis(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatPengingatServis');
    }

    /** Nama templat resmi untuk notifikasi cucian siap diambil (laundry §9.9); null = kirim teks. */
    public function AmbilTemplatLaundrySiap(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatLaundrySiap');
    }

    /** Nama templat resmi untuk ringkasan pagi Kotak Tindakan ke anggota tenant (D-23 D, D-33); null = kirim teks. */
    public function AmbilTemplatRingkasanTindakan(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatRingkasanTindakan');
    }

    /** Nama templat resmi untuk insight penjualan mingguan ke anggota tenant (X6, D-33); null = kirim teks. */
    public function AmbilTemplatInsightMingguan(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatInsightMingguan');
    }

    /** Nama templat resmi untuk pengingat tagihan langganan Payoung ke Owner (P-08); null = kirim teks. */
    public function AmbilTemplatPengingatTagihan(): ?string
    {
        // Pesan Payoung kepada pemilik: selalu templat akun Payoung, bukan akun WhatsApp milik tenant.
        $nama = config('integrasi.Whatsapp.Pengaturan.NamaTemplatPengingatTagihan');

        return is_string($nama) && $nama !== '' ? $nama : null;
    }

    /** Nama templat resmi untuk pengingat piutang (D-23 D, hanya WhatsApp Cloud API); null = kirim teks. */
    public function AmbilTemplatPengingatPiutang(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatPengingatPiutang');
    }

    /** Nama templat utilitas pesanan pembelian ke pemasok (hanya WhatsApp Cloud API); null = kirim teks. */
    public function AmbilTemplatPesananPembelian(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatPesananPembelian');
    }

    /** Nama templat utilitas invoice & pengingat tagihan ke pelanggan (F-21, hanya WhatsApp Cloud API); null = kirim teks. */
    public function AmbilTemplatInvoice(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatInvoice');
    }

    /** Nama templat autentikasi untuk kode masuk pembeli toko online (F-17 bagian 3); null = kirim teks. */
    public function AmbilTemplatKodeMasuk(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatKodeMasuk');
    }

    /** Nama templat utilitas status pesanan toko online (F-17 bagian 3, v3.32); null = kirim teks. */
    public function AmbilTemplatStatusPesanan(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatStatusPesanan');
    }

    /** Nama templat pemasaran untuk kampanye pesan CRM-07 (hanya WhatsApp Cloud API); null = kirim teks (penyedia tidak resmi). */
    public function AmbilTemplatPromosi(): ?string
    {
        return $this->AmbilNamaTemplat('NamaTemplatPromosi');
    }
}
