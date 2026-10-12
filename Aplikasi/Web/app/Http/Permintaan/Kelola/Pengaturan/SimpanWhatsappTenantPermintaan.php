<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Kelola\Pengaturan;

use App\Domain\Integrasi\Data\DataWhatsappTenant;
use App\Domain\Integrasi\Enum\PenyediaWhatsapp;
use App\Domain\Integrasi\Whatsapp\DaftarPenyediaWhatsapp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WhatsApp milik tenant (D-108): aturan bidang mengikuti penyedia yang dipilih. Kredensial boleh kosong saat
 * menyunting (kosong = pertahankan); kewajibannya saat membuat atau ganti penyedia dinilai di Aksi.
 */
final class SimpanWhatsappTenantPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $aturan = [
            'Penyedia' => ['required', 'string', Rule::in(array_map(fn (PenyediaWhatsapp $p): string => $p->value, DaftarPenyediaWhatsapp::Ambil()))],
            'Pengaturan' => ['present', 'array'],
            'Kredensial' => ['present', 'array'],
        ];
        $penyedia = $this->AmbilPenyedia();

        foreach ($penyedia === null ? [] : DaftarPenyediaWhatsapp::AmbilBidangPengaturan($penyedia) as $bidang) {
            $aturan["Pengaturan.{$bidang['Kunci']}"] = $bidang['Wajib'] ? ['required', 'string', 'max:255'] : ['nullable', 'string', 'max:255'];
        }

        foreach ($penyedia?->AmbilBidangKredensial() ?? [] as $bidang) {
            $aturan["Kredensial.{$bidang['Kunci']}"] = ['nullable', 'string', 'max:2000'];
        }

        return $aturan;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $nama = ['Penyedia' => 'penyedia'];
        $penyedia = $this->AmbilPenyedia();

        foreach ($penyedia === null ? [] : DaftarPenyediaWhatsapp::AmbilBidangPengaturan($penyedia) as $bidang) {
            $nama["Pengaturan.{$bidang['Kunci']}"] = $bidang['Label'];
        }

        return $nama;
    }

    public function AmbilData(): DataWhatsappTenant
    {
        $penyedia = PenyediaWhatsapp::from($this->string('Penyedia')->toString());
        $pengaturan = [];
        $kredensial = [];

        foreach (DaftarPenyediaWhatsapp::AmbilBidangPengaturan($penyedia) as $bidang) {
            $pengaturan[$bidang['Kunci']] = trim($this->string("Pengaturan.{$bidang['Kunci']}")->toString());
        }

        foreach ($penyedia->AmbilBidangKredensial() as $bidang) {
            $nilai = trim($this->string("Kredensial.{$bidang['Kunci']}")->toString());

            if ($nilai !== '') {
                $kredensial[$bidang['Kunci']] = $nilai;
            }
        }

        return new DataWhatsappTenant($penyedia, $pengaturan, $kredensial);
    }

    private function AmbilPenyedia(): ?PenyediaWhatsapp
    {
        $penyedia = PenyediaWhatsapp::tryFrom($this->string('Penyedia')->toString());

        return $penyedia !== null && DaftarPenyediaWhatsapp::CekDikenal($penyedia) ? $penyedia : null;
    }
}
