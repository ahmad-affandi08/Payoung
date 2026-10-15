<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Layanan;

use App\Domain\Integrasi\Model\GerbangPembayaranTenant;
use App\Domain\Lisensi\Enum\EdisiAplikasi;

/**
 * Ringkasan gerbang pembayaran milik tenant aktif untuk back-office (F-08 QRIS dinamis, v2.06): ada/tidaknya gerbang
 * aktif yang bisa dipakai, label penyedianya, dan tautan ke halaman pengaturannya. Kredensial & pengaturan tidak
 * pernah keluar dari sini.
 */
final class InfoGerbangPembayaran
{
    public const TAUTAN = '/kelola/pembayaran/gerbang';

    /** D-162: tenant SaaS mengaktifkan QRIS lewat Aktivasi QRIS (foto KTP); edisi Lisensi lewat akun merchant sendiri. */
    public const TAUTAN_AKTIVASI = '/kelola/pembayaran/aktivasi-qris';

    /** D-162: halaman kredensial gerbang milik toko dibuka hanya di edisi Lisensi, kecuali `integrasi.GerbangTokoTampil` diisi. */
    public static function CekGerbangTokoTampil(): bool
    {
        $sakelar = config('integrasi.GerbangTokoTampil');

        return is_bool($sakelar) ? $sakelar : EdisiAplikasi::CekLisensi();
    }

    public static function AmbilTautan(): string
    {
        return self::CekGerbangTokoTampil() ? self::TAUTAN : self::TAUTAN_AKTIVASI;
    }

    /** Kalimat pembuka di panduan awal saat QRIS dinamis belum aktif. */
    public static function AmbilPetunjuk(): string
    {
        return self::CekGerbangTokoTampil()
            ? 'QRIS dinamis butuh akun merchant toko di salah satu penyedia gerbang pembayaran (dana langsung ke rekening toko).'
            : 'QRIS dinamis diaktifkan lewat pendaftaran di Payoung: cukup foto KTP, selfie, foto tempat usaha, dan nomor rekening. Uang pembayaran langsung masuk ke rekening toko.';
    }

    public static function AmbilNamaMenu(): string
    {
        return self::CekGerbangTokoTampil() ? 'Gerbang pembayaran' : 'Aktivasi QRIS';
    }

    public function __construct(private readonly KatalogPenyediaGerbang $katalog) {}

    /**
     * @return array{Aktif: bool, Penyedia: string|null, Tautan: string, NamaMenu: string, Petunjuk: string}
     */
    public function Ambil(): array
    {
        $baris = GerbangPembayaranTenant::query()->where('Aktif', true)->first(['Id', 'Penyedia', 'Aktif']);
        $aktif = $baris !== null && $this->katalog->CekDiizinkan($baris->Penyedia);

        return ['Aktif' => $aktif, 'Penyedia' => $aktif ? $baris->Penyedia->AmbilLabel() : null, 'Tautan' => self::AmbilTautan(), 'NamaMenu' => self::AmbilNamaMenu(), 'Petunjuk' => self::AmbilPetunjuk()];
    }
}
