<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Aksi;

use App\Domain\Akuntansi\Aksi\BalikkanJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Kueri\JurnalSumberAktif;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Sinkron\Enum\StatusItemSinkron;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Kasir\Data\DataBukaUlangShift;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Model\BukaUlangShift as LogBukaUlangShift;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Kasir\Model\TutupHarian;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * K-18 (F-06 `Tertutup → DibukaUlang`, §19.1): supervisor membuka ulang shift yang sudah ditutup di perangkat yang
 * sama, misal kasir lupa mencatat transaksi atau salah hitung kas. Lewat outbox `Shift.BukaUlang` (bisa offline).
 *
 * - Idempoten per `Uuid` item (`Duplikat`). Shift wajib milik perangkat pengirim (`ShiftTidakDikenal`) dan berstatus
 *   `Tertutup` (`ShiftTidakTertutup`); hari bisnis outlet yang sudah ditutup harian tidak bisa dibuka (`HariSudahDitutup`).
 * - Alasan minimal 5 karakter (`AlasanDiperlukan`); peminta anggota outlet ber-izin `penjualan.buat`; penyetuju
 *   ber-izin `shift.selisih.setujui` (supervisor, `PenyetujuTidakBerwenang`), boleh orang yang sama.
 * - Data tutup disalin ke `BukaUlangShift.SnapshotTutup` lalu dikosongkan; jurnal selisih kas tutup sebelumnya dibalik
 *   (aturan #8) pada tanggal bisnis buka ulang. Tutup berikutnya membuat jurnal selisih baru (`Tutup-{n}`).
 */
final class BukaUlangShift
{
    private const TOLERANSI_JAM_DETIK = 600;

    private const PANJANG_ALASAN_MINIMAL = 5;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly AnggotaOutlet $anggota,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly JurnalSumberAktif $jurnalAktif,
        private readonly BalikkanJurnal $balikkan,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(DataBukaUlangShift $data): StatusItemSinkron
    {
        if ($data->dibukaUlangPada->greaterThan(CarbonImmutable::now()->addSeconds(self::TOLERANSI_JAM_DETIK))) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Waktu buka ulang shift ada di masa depan. Periksa jam perangkat.', 'DibukaUlangPada');
        }

        if (mb_strlen(trim($data->alasan)) < self::PANJANG_ALASAN_MINIMAL) {
            throw new PelanggaranAturanBisnis('AlasanDiperlukan', 'Tulis alasan buka ulang shift minimal 5 karakter.', 'Alasan');
        }

        return DB::transaction(fn (): StatusItemSinkron => $this->Proses($data));
    }

    private function Proses(DataBukaUlangShift $data): StatusItemSinkron
    {
        if (LogBukaUlangShift::query()->where('Uuid', $data->uuid)->exists()) {
            return StatusItemSinkron::Duplikat;
        }

        $idTenant = $this->konteks->Wajib();
        $shift = Shift::query()->where('Uuid', $data->uuidShift)->where('IdPerangkat', $data->idPerangkat)->lockForUpdate()->first();

        if ($shift === null) {
            throw new PelanggaranAturanBisnis('ShiftTidakDikenal', 'Shift yang dibuka ulang tidak ditemukan di perangkat ini.', 'UuidShift');
        }

        if ($shift->Status !== StatusShift::Tertutup) {
            throw new PelanggaranAturanBisnis('ShiftTidakTertutup', "Shift ini {$shift->Status->AmbilLabel()}; hanya shift yang sudah ditutup yang bisa dibuka ulang.", 'UuidShift', 409);
        }

        if (TutupHarian::query()->where('IdOutlet', $shift->IdOutlet)->where('TanggalBisnis', $shift->TanggalBisnis->toDateString())->exists()) {
            throw new PelanggaranAturanBisnis('HariSudahDitutup', 'Hari bisnis shift ini sudah ditutup harian. Shift tidak bisa dibuka ulang.', 'UuidShift', 409);
        }

        $peminta = $this->anggota->Cari($idTenant, $data->uuidPeminta, $shift->IdOutlet);

        if ($peminta === null || ! $peminta->CekIzin(IzinTenant::PenjualanBuat->value)) {
            throw new PelanggaranAturanBisnis('KasirTidakDitemukan', 'Pengguna ini tidak terdaftar sebagai kasir di outlet shift ini.', 'UuidPengguna');
        }

        $penyetuju = $this->anggota->Cari($idTenant, $data->uuidPenyetuju, $shift->IdOutlet);

        if ($penyetuju === null || ! $penyetuju->CekIzin(IzinTenant::ShiftSelisihSetujui->value)) {
            throw new PelanggaranAturanBisnis('PenyetujuTidakBerwenang', 'Buka ulang shift wajib disetujui supervisor.', 'UuidPenyetuju', 403);
        }

        if ($shift->DitutupPada !== null && $data->dibukaUlangPada->lessThan(CarbonImmutable::instance($shift->DitutupPada))) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Waktu buka ulang lebih awal dari waktu tutup shift.', 'DibukaUlangPada');
        }

        $urutan = LogBukaUlangShift::query()->where('IdShift', $shift->Id)->count() + 1;
        $idJurnal = $this->jurnalAktif->AmbilId(JenisSumberJurnal::TutupShift, $shift->Id);
        $pembalik = $idJurnal === null ? null : $this->balikkan->Jalankan(
            $idJurnal,
            $this->tanggalBisnis->Hitung($shift->IdOutlet, $data->dibukaUlangPada),
            mb_substr("Pembalik selisih kas: shift dibuka ulang ({$shift->Uuid})", 0, 255),
            JenisSumberJurnal::TutupShift,
            $shift->Id,
            "BukaUlang-{$urutan}",
            $penyetuju->id,
        );

        $log = LogBukaUlangShift::query()->create([
            'Uuid' => $data->uuid,
            'IdShift' => $shift->Id,
            'IdPerangkat' => $data->idPerangkat,
            'Urutan' => $urutan,
            'Alasan' => trim($data->alasan),
            'DimintaOleh' => $peminta->id,
            'DisetujuiOleh' => $penyetuju->id,
            'DibukaUlangPada' => $data->dibukaUlangPada,
            'SnapshotTutup' => [
                'DitutupOleh' => $shift->DitutupOleh,
                'DitutupPada' => $shift->DitutupPada?->toIso8601ZuluString(),
                'KasSeharusnya' => $shift->KasSeharusnya,
                'KasAktual' => $shift->KasAktual,
                'Selisih' => $shift->Selisih,
                'AlasanSelisih' => $shift->AlasanSelisih,
            ],
            'IdJurnalPembalik' => $pembalik?->idJurnal,
        ]);

        $shift->Status = StatusShift::DibukaUlang;
        $shift->DitutupOleh = null;
        $shift->DitutupPada = null;
        $shift->KasSeharusnya = null;
        $shift->KasAktual = null;
        $shift->Selisih = null;
        $shift->PecahanKasAkhir = null;
        $shift->RingkasanNonTunai = null;
        $shift->AlasanSelisih = null;
        $shift->IdPenyetujuSelisih = null;
        $shift->save();

        $this->riwayat->Catat(Shift::JENIS_DOKUMEN, $shift->Id, StatusShift::Tertutup->value, StatusShift::DibukaUlang->value, $penyetuju->id, trim($data->alasan));
        $this->audit->Catat('shift.buka-ulang', $shift, nilaiLama: $log->SnapshotTutup, nilaiBaru: [
            'Alasan' => trim($data->alasan),
            'DimintaOleh' => $peminta->nama,
            'DisetujuiOleh' => $penyetuju->nama,
        ], idPengguna: $penyetuju->id);

        return StatusItemSinkron::Diterima;
    }
}
