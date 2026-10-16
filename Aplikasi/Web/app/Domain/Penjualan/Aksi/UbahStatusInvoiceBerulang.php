<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Penjualan\Enum\StatusInvoiceBerulang;
use App\Domain\Penjualan\Model\InvoiceBerulang;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Jeda, lanjutkan, atau hentikan templat invoice berulang (F-21). Melanjutkan templat yang dijeda **tidak** menerbitkan
 * siklus yang terlewat selama jeda: jadwal maju ke siklus pertama yang belum lewat, supaya pelanggan tidak menerima
 * tumpukan invoice sekaligus. Menghentikan bersifat tetap (`Selesai`); invoice yang sudah terbit tidak tersentuh.
 */
final class UbahStatusInvoiceBerulang
{
    public function __construct(
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis TemplatTidakDitemukan, StatusTidakBisaBerubah
     */
    public function Jalankan(string $uuidTemplat, StatusInvoiceBerulang $tujuan, int $idPengguna): InvoiceBerulang
    {
        return DB::transaction(function () use ($uuidTemplat, $tujuan, $idPengguna): InvoiceBerulang {
            $templat = InvoiceBerulang::query()->where('Uuid', $uuidTemplat)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('TemplatTidakDitemukan', 'Templat invoice berulang tidak ditemukan.');

            if ($templat->Status === $tujuan) {
                return $templat;
            }

            if (! $templat->Status->BisaBerubahKe($tujuan)) {
                throw new PelanggaranAturanBisnis('StatusTidakBisaBerubah', "Templat yang {$templat->Status->AmbilLabel()} tidak bisa menjadi {$tujuan->AmbilLabel()}.");
            }

            $asal = $templat->Status;
            $templat->UbahStatus($tujuan);
            $templat->DiubahOleh = $idPengguna;

            if ($tujuan === StatusInvoiceBerulang::Selesai) {
                $templat->BerikutnyaPada = null;
            }

            if ($asal === StatusInvoiceBerulang::Dijeda && $tujuan === StatusInvoiceBerulang::Aktif) {
                $this->MajukanKeSiklusBerikutnya($templat);
            }

            $templat->save();
            $this->audit->Catat(match ($tujuan) {
                StatusInvoiceBerulang::Dijeda => 'invoice-berulang.jeda',
                StatusInvoiceBerulang::Aktif => 'invoice-berulang.lanjut',
                StatusInvoiceBerulang::Selesai => 'invoice-berulang.hentikan',
            }, $templat, nilaiLama: ['Status' => $asal->value], nilaiBaru: ['Status' => $tujuan->value, 'BerikutnyaPada' => $templat->BerikutnyaPada?->toDateString()], idPengguna: $idPengguna);

            return $templat;
        });
    }

    /** Siklus pertama yang tanggalnya belum lewat; selesai bila jadwalnya habis sebelum itu. */
    private function MajukanKeSiklusBerikutnya(InvoiceBerulang $templat): void
    {
        $hariIni = $this->tanggalBisnis->Hitung($templat->IdOutlet)->toDateString();
        $mulai = $templat->TanggalMulai->toImmutable();

        while (true) {
            $tanggal = $templat->Frekuensi->HitungTanggal($mulai, $templat->Interval, $templat->SiklusBerikutnya);

            if ($templat->BatasKali !== null && $templat->JumlahTerbit >= $templat->BatasKali) {
                $this->Selesaikan($templat);

                return;
            }

            if ($templat->TanggalBerakhir !== null && $tanggal->toDateString() > $templat->TanggalBerakhir->toDateString()) {
                $this->Selesaikan($templat);

                return;
            }

            if ($tanggal->toDateString() >= $hariIni) {
                $templat->BerikutnyaPada = Carbon::parse($tanggal->toDateString());

                return;
            }

            $templat->SiklusBerikutnya++;
        }
    }

    private function Selesaikan(InvoiceBerulang $templat): void
    {
        $templat->UbahStatus(StatusInvoiceBerulang::Selesai);
        $templat->BerikutnyaPada = null;
    }
}
