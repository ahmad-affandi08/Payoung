<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Sinkron\Enum\StatusItemSinkron;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Kasir\Data\DataBukaShift;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * F-06 langkah 2: shift yang dibuka di perangkat (bisa offline, BR-06.3) diterima server lewat sinkron.
 *
 * - Idempoten per `Uuid`: Uuid yang sudah diterima dari perangkat yang sama = `Duplikat` (diperiksa sebelum akses
 *   kasir, supaya kiriman ulang setelah izin dicabut tidak macet di outbox); dipakai data lain = `UuidSudahDipakai`.
 * - Pembuka wajib anggota aktif dengan akses outlet perangkat dan izin `penjualan.buat` (berjualan & shift sendiri).
 * - BR-06.1: perangkat yang masih punya shift aktif lain ditolak (`ShiftSudahTerbuka`). Kasir yang sudah punya shift
 *   aktif di perangkat lain pada outlet yang sama (bukan shift bersama, BR-06.2) tetap diterima karena bisa terjadi
 *   saat offline, tetapi ditandai `PerluTinjauan` dan diaudit.
 * - D-341: shift lama yang tanggal bisnisnya sudah lewat dari tanggal bisnis shift baru (kasir lupa menutup kemarin)
 *   tidak menahan shift baru. Server menutupnya otomatis lewat `TutupShiftPaksa` (kas aktual = kas seharusnya, ditandai
 *   `PerluTinjauan`, tercatat di audit), baik di perangkat yang sama maupun di perangkat lain.
 * - Kas awal ≥ 0; pecahan (opsional) wajib berjumlah sama dengan kas awal. Kas awal tidak dijurnal (uang hanya
 *   berpindah di dalam kas usaha, PRD v1.34).
 */
final class BukaShift
{
    private const TOLERANSI_JAM_DETIK = 600;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly AnggotaOutlet $anggota,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly TutupShiftPaksa $tutupPaksa,
    ) {}

    public function Jalankan(DataBukaShift $data): StatusItemSinkron
    {
        $idTenant = $this->konteks->Wajib();
        $pembuka = $this->anggota->Cari($idTenant, $data->uuidPembuka, $data->idOutlet);
        $sudahAda = Shift::query()->where('Uuid', $data->uuid)->first();

        // Kiriman ulang tetap `Duplikat` walau akses kasir dicabut setelah shift diterima.
        if ($sudahAda !== null) {
            $idPembuka = $pembuka->id ?? $this->anggota->CariDiTenant($idTenant, $data->uuidPembuka, $data->idOutlet)[0]->id ?? null;

            return $this->BandingkanDuplikat($sudahAda, $data, $idPembuka);
        }

        if ($pembuka === null) {
            throw new PelanggaranAturanBisnis('KasirTidakDitemukan', 'Kasir ini tidak terdaftar di outlet perangkat ini.', 'UuidPengguna');
        }

        if (! $pembuka->CekIzin(IzinTenant::PenjualanBuat->value)) {
            throw new PelanggaranAturanBisnis('TanpaIzin', "{$pembuka->nama} tidak punya izin berjualan dan membuka shift.", 'UuidPengguna', 403);
        }

        $this->PastikanNilaiValid($data);

        try {
            return DB::transaction(function () use ($data, $pembuka): StatusItemSinkron {
                $lama = Shift::query()->where('Uuid', $data->uuid)->lockForUpdate()->first();

                if ($lama !== null) {
                    return $this->BandingkanDuplikat($lama, $data, $pembuka->id);
                }

                $this->TutupShiftKedaluwarsa($data, $pembuka->id);

                $aktifPerangkat = Shift::query()
                    ->where('IdPerangkat', $data->idPerangkat)
                    ->whereIn('Status', [StatusShift::Terbuka->value, StatusShift::DibukaUlang->value])
                    ->lockForUpdate()
                    ->first();

                if ($aktifPerangkat !== null) {
                    throw new PelanggaranAturanBisnis('ShiftSudahTerbuka', 'Perangkat ini masih punya shift terbuka. Tutup shift itu dulu sebelum membuka shift baru.', 'Uuid', 409, [
                        'UuidShift' => $aktifPerangkat->Uuid,
                    ]);
                }

                $tinjauan = $data->bersama ? null : $this->CariKonflikKasir($data, $pembuka->id);
                $shift = Shift::query()->create([
                    'Uuid' => $data->uuid,
                    'IdOutlet' => $data->idOutlet,
                    'IdPerangkat' => $data->idPerangkat,
                    'Status' => StatusShift::Terbuka,
                    'Bersama' => $data->bersama,
                    'DibukaOleh' => $pembuka->id,
                    'DibukaPada' => $data->dibukaPada,
                    'TanggalBisnis' => $this->tanggalBisnis->Hitung($data->idOutlet, $data->dibukaPada)->toDateString(),
                    'KasAwal' => $data->kasAwal->KeString(),
                    'PecahanKasAwal' => $data->pecahan,
                    'PerluTinjauan' => $tinjauan !== null,
                    'AlasanTinjauan' => $tinjauan,
                    'DiterimaPada' => CarbonImmutable::now(),
                ]);

                $this->riwayat->Catat(Shift::JENIS_DOKUMEN, $shift->Id, null, StatusShift::Terbuka->value, $pembuka->id);
                $this->audit->Catat('shift.buka', $shift, nilaiBaru: [
                    'KasAwal' => $data->kasAwal->KeString(),
                    'Bersama' => $data->bersama,
                    'PerluTinjauan' => $tinjauan !== null,
                ], idPengguna: $pembuka->id);

                return StatusItemSinkron::Diterima;
            });
        } catch (QueryException $galat) {
            if (($galat->errorInfo[1] ?? null) === 1062) {
                throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Kode unik shift ini sudah dipakai. Buat ulang shift di aplikasi.', 'Uuid');
            }

            throw $galat;
        }
    }

    /**
     * D-341: tutup otomatis shift milik perangkat ini atau milik kasir yang sama (bukan shift bersama) yang tanggal
     * bisnisnya sebelum tanggal bisnis shift baru. Shift hari yang sama tidak disentuh, jadi aturan BR-06.1 tetap
     * berlaku untuk shift ganda yang sungguhan.
     */
    private function TutupShiftKedaluwarsa(DataBukaShift $data, int $idPembuka): void
    {
        $tanggalBaru = $this->tanggalBisnis->Hitung($data->idOutlet, $data->dibukaPada)->toDateString();
        $kedaluwarsa = Shift::query()
            ->where('IdOutlet', $data->idOutlet)
            ->whereIn('Status', [StatusShift::Terbuka->value, StatusShift::DibukaUlang->value])
            ->whereDate('TanggalBisnis', '<', $tanggalBaru)
            ->where(function ($kueri) use ($data, $idPembuka): void {
                $kueri->where('IdPerangkat', $data->idPerangkat)
                    ->orWhere(function ($kasir) use ($idPembuka): void {
                        $kasir->where('DibukaOleh', $idPembuka)->where('Bersama', false);
                    });
            })
            ->orderBy('DibukaPada')
            ->lockForUpdate()
            ->get();

        foreach ($kedaluwarsa as $lama) {
            $this->tutupPaksa->Jalankan(
                $lama->Uuid,
                $idPembuka,
                "Ditutup otomatis: shift tanggal {$lama->TanggalBisnis->toDateString()} lupa ditutup saat shift baru dibuka. Kas dianggap sama dengan kas seharusnya, mohon dicek.",
                null,
                null,
            );
        }
    }

    private function PastikanNilaiValid(DataBukaShift $data): void
    {
        if ($data->kasAwal->BernilaiNegatif()) {
            throw new PelanggaranAturanBisnis('KasAwalTidakValid', 'Kas awal tidak boleh minus.', 'KasAwal');
        }

        if ($data->dibukaPada->greaterThan(CarbonImmutable::now()->addSeconds(self::TOLERANSI_JAM_DETIK))) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Waktu buka shift ada di masa depan. Periksa jam perangkat.', 'DibukaPada');
        }

        if ($data->pecahan === null) {
            return;
        }

        $total = Uang::Nol();

        foreach ($data->pecahan as $baris) {
            $total = $total->Tambah(Uang::Dari($baris['Nominal'])->Kali($baris['Jumlah']));
        }

        if (! $total->SamaDengan($data->kasAwal)) {
            throw new PelanggaranAturanBisnis('PecahanTidakSesuai', "Jumlah hitungan pecahan ({$total->FormatRupiah()}) tidak sama dengan kas awal ({$data->kasAwal->FormatRupiah()}).", 'Pecahan');
        }
    }

    private function BandingkanDuplikat(Shift $lama, DataBukaShift $data, ?int $idPembuka): StatusItemSinkron
    {
        $sama = $idPembuka !== null
            && $lama->IdPerangkat === $data->idPerangkat
            && $lama->DibukaOleh === $idPembuka
            && Uang::Dari($lama->KasAwal)->SamaDengan($data->kasAwal);

        if (! $sama) {
            throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Kode unik shift ini sudah dipakai data lain. Buat ulang shift di aplikasi.', 'Uuid');
        }

        return StatusItemSinkron::Duplikat;
    }

    /** Alasan tinjauan bila kasir sudah punya shift aktif (bukan bersama) di perangkat lain pada outlet yang sama. */
    private function CariKonflikKasir(DataBukaShift $data, int $idPembuka): ?string
    {
        $lain = Shift::query()
            ->where('IdOutlet', $data->idOutlet)
            ->where('DibukaOleh', $idPembuka)
            ->where('Bersama', false)
            ->where('IdPerangkat', '!=', $data->idPerangkat)
            ->whereIn('Status', [StatusShift::Terbuka->value, StatusShift::DibukaUlang->value])
            ->first();

        return $lain === null ? null : "BR-06.1: kasir sudah punya shift terbuka di perangkat lain (shift {$lain->Uuid}), kemungkinan dibuka saat offline.";
    }
}
