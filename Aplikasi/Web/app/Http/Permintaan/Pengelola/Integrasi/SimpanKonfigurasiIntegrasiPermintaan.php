<?php

declare(strict_types=1);

namespace App\Http\Permintaan\Pengelola\Integrasi;

use App\Domain\Pengelola\Integrasi\Data\DataKonfigurasiIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\JenisIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\LingkunganIntegrasi;
use App\Domain\Pengelola\Integrasi\Enum\PenyediaIntegrasi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aturan bidang mengikuti penyedia jenis integrasi yang dipilih (P-05). Kredensial boleh kosong saat menyunting
 * (BR-P05.6: kosong = pertahankan); kewajiban kredensial saat membuat dinilai di Aksi.
 */
final class SimpanKonfigurasiIntegrasiPermintaan extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $aturan = [
            'Jenis' => ['required', 'string', Rule::enum(JenisIntegrasi::class)],
            'Lingkungan' => ['required', 'string', Rule::enum(LingkunganIntegrasi::class)],
            'Penyedia' => ['nullable', 'string', Rule::enum(PenyediaIntegrasi::class)],
            'Pengaturan' => ['present', 'array'],
            'Kredensial' => ['present', 'array'],
            'RotasiSetiapHari' => ['required', 'integer', 'min:7', 'max:365'],
            'Alasan' => ['nullable', 'string', 'max:500'],
        ];
        $penyedia = $this->AmbilPenyediaTerpilih();

        if ($penyedia === null) {
            return $aturan;
        }

        foreach ($penyedia->AmbilBidangPengaturan() as $bidang) {
            $aturan["Pengaturan.{$bidang['Kunci']}"] = ! $bidang['Wajib'] ? ['nullable', 'string', 'max:'.($bidang['Maks'] ?? 255)] : match ($bidang['Jenis']) {
                'Angka' => ['required', 'integer', 'min:1', 'max:65535'],
                'Email' => ['required', 'email', 'max:150'],
                'Url' => ['required', 'url:https', 'max:255'],
                'Pilihan' => ['required', 'string', Rule::in($bidang['Opsi'] ?? [])],
                default => ['required', 'string', 'max:255'],
            };
        }

        foreach ($penyedia->AmbilBidangKredensial() as $bidang) {
            $aturan["Kredensial.{$bidang['Kunci']}"] = ['nullable', 'string', 'max:'.($bidang['Maks'] ?? 2000)];
        }

        return $aturan;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $nama = [];

        foreach ($this->AmbilPenyediaTerpilih()?->AmbilBidangPengaturan() ?? [] as $bidang) {
            $nama["Pengaturan.{$bidang['Kunci']}"] = $bidang['Label'];
        }

        return $nama;
    }

    public function AmbilData(): DataKonfigurasiIntegrasi
    {
        $jenis = JenisIntegrasi::from($this->string('Jenis')->toString());
        $penyedia = $this->AmbilPenyediaTerpilih() ?? $jenis->AmbilPenyedia();
        $pengaturan = [];
        $kredensial = [];

        foreach ($penyedia->AmbilBidangPengaturan() as $bidang) {
            $kunci = $bidang['Kunci'];
            $pengaturan[$kunci] = $bidang['Jenis'] === 'Angka'
                ? $this->integer("Pengaturan.{$kunci}")
                : trim($this->string("Pengaturan.{$kunci}")->toString());
        }

        foreach ($penyedia->AmbilBidangKredensial() as $bidang) {
            $nilai = trim($this->string("Kredensial.{$bidang['Kunci']}")->toString());

            if ($nilai !== '') {
                $kredensial[$bidang['Kunci']] = $nilai;
            }
        }

        return new DataKonfigurasiIntegrasi(
            jenis: $jenis,
            lingkungan: LingkunganIntegrasi::from($this->string('Lingkungan')->toString()),
            pengaturan: $pengaturan,
            kredensial: $kredensial,
            rotasiSetiapHari: $this->integer('RotasiSetiapHari'),
            alasan: $this->filled('Alasan') ? trim($this->string('Alasan')->toString()) : null,
            penyedia: $penyedia,
        );
    }

    /** Penyedia pilihan (v2.04) atau bawaan jenis bila tidak dikirim. */
    private function AmbilPenyediaTerpilih(): ?PenyediaIntegrasi
    {
        $jenis = JenisIntegrasi::tryFrom($this->string('Jenis')->toString());

        if ($jenis === null) {
            return null;
        }

        return $this->filled('Penyedia') ? PenyediaIntegrasi::tryFrom($this->string('Penyedia')->toString()) : $jenis->AmbilPenyedia();
    }
}
