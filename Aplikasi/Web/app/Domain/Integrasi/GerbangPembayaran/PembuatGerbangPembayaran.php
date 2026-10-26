<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\GerbangPembayaran;

use App\Domain\Integrasi\Enum\PenyediaGerbang;
use App\Domain\Integrasi\GerbangPembayaran\Adaptor\AdaptorDoku;
use App\Domain\Integrasi\Layanan\KatalogPenyediaGerbang;
use App\Domain\Integrasi\Model\GerbangPembayaranTenant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Membuat adaptor gerbang pembayaran dari kode penyedia (`PenyediaGerbang`) atau dari gerbang milik tenant aktif
 * (`GerbangPembayaranTenant`, PRD v2.06: dana langsung ke akun merchant tenant). Sejak v2.06 tidak ada lagi gerbang
 * tingkat platform untuk transaksi. Hanya DOKU yang dikenal: kode penyedia lain (termasuk penyedia lama yang sudah
 * dihapus) menghasilkan null.
 */
final class PembuatGerbangPembayaran
{
    /** @var list<string> */
    public const PENYEDIA = ['Doku'];

    public function __construct(private readonly KatalogPenyediaGerbang $katalog) {}

    /**
     * @param  array<string, string|int>  $pengaturan
     * @param  array<string, string>  $kredensial
     */
    public function Buat(string $penyedia, array $pengaturan, array $kredensial): ?GerbangPembayaran
    {
        return match ($penyedia) {
            'Doku' => new AdaptorDoku($pengaturan, $kredensial),
            default => null,
        };
    }

    /** Adaptor dari gerbang tenant (tanpa memeriksa status aktif). Null bila kredensial tidak bisa didekripsi. */
    public function BuatDariTenant(GerbangPembayaranTenant $baris): ?GerbangPembayaran
    {
        try {
            // Didekripsi eksplisit (bukan lewat cast) agar kunci APP_KEY yang salah tertangkap di sini.
            $isi = json_decode(Crypt::decryptString((string) $baris->getRawOriginal('Kredensial')), true);
        } catch (DecryptException) {
            Log::error('Kredensial gerbang pembayaran tenant tidak bisa didekripsi. Periksa APP_KEY/APP_PREVIOUS_KEYS.', ['IdTenant' => $baris->IdTenant]);

            return null;
        }

        $kredensial = [];

        foreach (is_array($isi) ? $isi : [] as $kunci => $nilai) {
            if (is_string($kunci) && is_string($nilai)) {
                $kredensial[$kunci] = $nilai;
            }
        }

        return $this->Buat($baris->Penyedia->value, $baris->AmbilPengaturanAdaptor(), $kredensial);
    }

    /**
     * Gerbang aktif tenant yang sedang berjalan (scope `MilikTenant`) dan penyedianya masih diizinkan platform.
     * Null = tenant belum mengaktifkan gerbang, atau penyedianya dilarang platform.
     */
    public function AmbilAktifTenant(): ?GerbangTenant
    {
        $baris = GerbangPembayaranTenant::query()->where('Aktif', true)->first();

        if ($baris === null || ! $this->katalog->CekDiizinkan($baris->Penyedia)) {
            return null;
        }

        $gerbang = $this->BuatDariTenant($baris);

        return $gerbang === null ? null : new GerbangTenant($gerbang, self::BuatUrlWebhook($baris->Penyedia, $baris->TokenWebhook), $baris->IdTenant);
    }

    /**
     * Cek status tagihan yang sudah dibuat: memakai konfigurasi tenant saat ini selama penyedianya sama dengan penyedia
     * tagihan (aktif atau tidak, diizinkan platform atau tidak, karena uangnya mungkin sudah masuk). Tenant sudah
     * berganti penyedia = null (tagihan tidak bisa dicek; tetap Menunggu sampai kedaluwarsa atau webhook masuk).
     */
    public function AmbilUntukTagihan(string $penyedia): ?GerbangPembayaran
    {
        $baris = GerbangPembayaranTenant::query()->first();

        return $baris === null || $baris->Penyedia->value !== $penyedia ? null : $this->BuatDariTenant($baris);
    }

    public static function BuatUrlWebhook(PenyediaGerbang $penyedia, string $tokenWebhook): string
    {
        return route('webhook.gerbang-pembayaran.tenant', ['penyedia' => $penyedia->AmbilKodeUrl(), 'tokenWebhook' => $tokenWebhook]);
    }
}
