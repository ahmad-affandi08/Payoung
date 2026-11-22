<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Model;

use App\Domain\Bersama\Model\ModelDasar;
use Database\Pabrik\PenggunaPabrik;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Access\Authorizable as KontrakDapatDiotorisasi;
use Illuminate\Contracts\Auth\Authenticatable as KontrakDapatDiautentikasi;
use Illuminate\Contracts\Auth\CanResetPassword as KontrakDapatResetKataSandi;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Akun pengguna tenant (PRD §15.3 `Pengguna`). Satu pengguna boleh menjadi anggota beberapa tenant
 * lewat `TenantPengguna` (BR-00.1), sehingga tabel ini tidak memakai MilikTenant.
 *
 * Akun tim internal Platform Pengelola memakai tabel terpisah `PenggunaPengelola` (§13.8).
 *
 * @property int $Id
 * @property string $Uuid
 * @property string $Nama
 * @property string|null $Email null = karyawan hanya kasir (masuk aplikasi kasir dengan PIN, D-22)
 * @property string|null $GoogleSub pengenal tetap akun Google yang ditautkan (D-57)
 * @property Carbon|null $GoogleDitautkanPada
 * @property bool $KataSandiOtomatis kata sandi acak buatan sistem; Google belum boleh dilepas (D-57)
 * @property string|null $NoHp
 * @property string $KataSandi
 * @property bool $WajibGantiKataSandi kata sandi awal dibuat admin (D-22), wajib diganti saat pertama masuk
 * @property Carbon|null $EmailDiverifikasiPada
 * @property bool $EmailBelumDibuktikan
 * @property string|null $Rahasia2fa
 * @property list<string>|null $KodePemulihan2fa
 * @property Carbon|null $DuaFaktorAktifPada
 */
final class Pengguna extends ModelDasar implements KontrakDapatDiautentikasi, KontrakDapatDiotorisasi, KontrakDapatResetKataSandi
{
    use Authenticatable;
    use Authorizable;
    use CanResetPassword;

    /** @use HasFactory<PenggunaPabrik> */
    use HasFactory;

    use Notifiable;

    protected $table = 'Pengguna';

    /** @var list<string> */
    protected $hidden = ['KataSandi', 'TokenIngat', 'Rahasia2fa', 'KodePemulihan2fa'];

    /**
     * Nilai bawaan kolom 2FA, sama dengan default migrasi (agar model baru lengkap walau belum dimuat ulang).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'Rahasia2fa' => null,
        'KodePemulihan2fa' => null,
        'DuaFaktorAktifPada' => null,
        'WajibGantiKataSandi' => false,
        'GoogleSub' => null,
        'GoogleDitautkanPada' => null,
        'KataSandiOtomatis' => false,
    ];

    public function getAuthPasswordName(): string
    {
        return 'KataSandi';
    }

    public function getRememberTokenName(): string
    {
        return 'TokenIngat';
    }

    public function getEmailForPasswordReset(): string
    {
        return (string) $this->Email;
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->Email;
    }

    /** D-22: karyawan tanpa email hanya bisa masuk aplikasi kasir dengan PIN, tidak ke back-office. */
    public function CekHanyaKasir(): bool
    {
        return $this->Email === null;
    }

    /** D-57: akun ini sudah ditautkan ke akun Google. */
    public function CekGoogleTertaut(): bool
    {
        return $this->GoogleSub !== null;
    }

    /** 2FA TOTP akun tenant (§20.2): aktif bila rahasia sudah dikonfirmasi dengan kode pertama. */
    public function CekDuaFaktorAktif(): bool
    {
        return $this->DuaFaktorAktifPada !== null && $this->Rahasia2fa !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'KataSandi' => 'hashed',
            'Rahasia2fa' => 'encrypted',
            'KodePemulihan2fa' => 'encrypted:array',
            'DuaFaktorAktifPada' => 'datetime',
            'EmailDiverifikasiPada' => 'datetime',
            'WajibGantiKataSandi' => 'boolean',
            'KataSandiOtomatis' => 'boolean',
            'EmailBelumDibuktikan' => 'boolean',
            'GoogleDitautkanPada' => 'datetime',
        ];
    }
}
