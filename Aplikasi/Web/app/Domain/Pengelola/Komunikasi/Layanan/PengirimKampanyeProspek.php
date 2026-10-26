<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Layanan;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Memilih pengirim email kampanye prospek (D-216). Bila integrasi `EmailKampanye` aktif, dipakai pengirim terpisah itu
 * (domain pengirim sendiri, reputasi email transaksi terjaga). Bila belum diisi, jatuh ke pengirim email bawaan yang
 * sudah terpasang (`mail.default`, mis. dari Integrasi › Email atau `.env`) dengan alamat pengirim bawaannya, selama
 * pengirim itu sungguhan (bukan `log`/`array`). Kampanye lewat pengirim bawaan memakai reputasi domain yang sama dengan
 * email transaksi, jadi batas kirim per hari tetap harus kecil.
 */
final class PengirimKampanyeProspek
{
    private const MAILER_PALSU = ['log', 'array'];

    public static function MemakaiPengirimKhusus(): bool
    {
        return config('integrasi.EmailKampanyeAktif') === true;
    }

    public static function MemakaiPengirimBawaan(): bool
    {
        if (self::MemakaiPengirimKhusus()) {
            return false;
        }

        $mailer = (string) config('mail.default');

        return $mailer !== ''
            && ! in_array($mailer, self::MAILER_PALSU, true)
            && config("mail.mailers.{$mailer}") !== null
            && self::AlamatPengirim() !== '';
    }

    public static function Tersedia(): bool
    {
        return self::MemakaiPengirimKhusus() || self::MemakaiPengirimBawaan();
    }

    public static function Mailer(): Mailer
    {
        return self::MemakaiPengirimKhusus() ? Mail::mailer('kampanye') : Mail::mailer();
    }

    public static function AlamatPengirim(): string
    {
        return (string) (self::MemakaiPengirimKhusus() ? config('integrasi.EmailKampanye.AlamatPengirim') : config('mail.from.address'));
    }

    public static function NamaPengirim(): string
    {
        return (string) (self::MemakaiPengirimKhusus() ? config('integrasi.EmailKampanye.NamaPengirim') : config('mail.from.name'));
    }
}
