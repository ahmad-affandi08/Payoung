<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Enum;

/** Jenis tugas otomatis dari skor kesehatan (P-07 PGL-09). */
enum JenisTugasTenant: string
{
    /** Tenant baru yang belum bertransaksi 48 jam setelah daftar: hubungi untuk bantuan onboarding. */
    case OnboardingMacet = 'OnboardingMacet';

    /** Tenant lama yang skornya berisiko: hubungi sebelum berhenti berlangganan. */
    case Berisiko = 'Berisiko';

    public function AmbilLabel(): string
    {
        return match ($this) {
            self::OnboardingMacet => 'Bantuan onboarding',
            self::Berisiko => 'Tenant berisiko',
        };
    }
}
