<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Akuntansi;

use App\Domain\Penjualan\Enum\HasilPenutupanTagihanQris;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Isian penutupan uang QRIS tanpa penjualan (E8): hasil, catatan, dan (untuk pengakuan pendapatan) akun + tanggal. */
final class TutupTagihanQrisPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'Hasil' => ['required', 'string', Rule::enum(HasilPenutupanTagihanQris::class)],
            'Catatan' => ['required', 'string', 'min:5', 'max:255'],
            'UuidAkun' => ['nullable', 'string', 'ulid'],
            'Tanggal' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['Hasil' => 'hasil penutupan', 'Catatan' => 'catatan', 'UuidAkun' => 'akun kas/bank', 'Tanggal' => 'tanggal'];
    }

    public function AmbilHasil(): HasilPenutupanTagihanQris
    {
        return HasilPenutupanTagihanQris::from((string) $this->validated('Hasil'));
    }

    public function AmbilTanggal(): ?CarbonImmutable
    {
        $tanggal = $this->validated('Tanggal');

        return is_string($tanggal) && $tanggal !== '' ? CarbonImmutable::parse($tanggal) : null;
    }
}
