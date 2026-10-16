<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use App\Domain\Tenant\Model\OutletFitur;
use Illuminate\Support\Facades\DB;

/**
 * Ganti jenis usaha: fitur outlet yang dibawa template sektor lama dan tidak dibutuhkan template baru dilepas
 * (baris `OutletFitur` dihapus; fitur hanya sakelar, tidak menyimpan data transaksi). `pos.retail` tidak pernah
 * dilepas, tetapi konfigurasinya (mode kasir) diganti mengikuti template baru supaya kasir langsung berubah rupa.
 */
final class LepasFiturOutletTemplate
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenguncianTenant $penguncian,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  list<string>  $kunciLama  fitur dari template sektor lama
     * @param  list<string>  $kunciBaru  fitur dari template sektor baru
     * @param  array<string, mixed>|null  $konfigurasiPosBaru  mode kasir template baru (null = jangan diubah)
     * @return list<string> kunci fitur yang dilepas (atau akan dilepas bila `$terapkan` false)
     */
    public function Jalankan(int $idOutlet, array $kunciLama, array $kunciBaru, ?array $konfigurasiPosBaru, bool $terapkan): array
    {
        $dilepas = array_values(array_diff($kunciLama, $kunciBaru, [OutletFitur::KUNCI_POS]));

        if (! $terapkan) {
            return array_values(array_filter($dilepas, fn (string $kunci): bool => OutletFitur::query()->where('IdOutlet', $idOutlet)->where('KunciFitur', $kunci)->exists()));
        }

        return DB::transaction(function () use ($idOutlet, $dilepas, $konfigurasiPosBaru): array {
            $this->penguncian->Kunci($this->konteks->Wajib());
            $hasil = [];

            foreach ($dilepas as $kunci) {
                $baris = OutletFitur::query()->where('IdOutlet', $idOutlet)->where('KunciFitur', $kunci)->first();

                if ($baris === null) {
                    continue;
                }

                $baris->delete();
                $hasil[] = $kunci;
            }

            if ($hasil !== []) {
                $this->audit->Catat('outlet.fitur.lepas-template', nilaiLama: ['IdOutlet' => $idOutlet, 'Kunci' => $hasil]);
            }

            $pos = OutletFitur::query()->where('IdOutlet', $idOutlet)->where('KunciFitur', OutletFitur::KUNCI_POS)->first();

            if ($pos instanceof OutletFitur && $konfigurasiPosBaru !== null && $pos->Konfigurasi !== $konfigurasiPosBaru) {
                $lama = $pos->Konfigurasi;
                $pos->Konfigurasi = $konfigurasiPosBaru;
                $pos->save();
                $this->audit->Catat('outlet.fitur.konfigurasi-template', $pos, nilaiLama: ['Konfigurasi' => $lama], nilaiBaru: [
                    'IdOutlet' => $idOutlet,
                    'Kunci' => OutletFitur::KUNCI_POS,
                    'Konfigurasi' => $konfigurasiPosBaru,
                ]);
            }

            return $hasil;
        });
    }
}
