<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Peristiwa\PeristiwaIntegrasi;
use App\Domain\Bersama\Sinkron\Enum\StatusItemSinkron;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Kasir\Data\DataTutupShift;
use App\Domain\Kasir\Enum\StatusShift;
use App\Domain\Kasir\Kueri\LaporanShift;
use App\Domain\Kasir\Layanan\PenyusunJurnalSelisihKas;
use App\Domain\Kasir\Model\BukaUlangShift;
use App\Domain\Kasir\Model\Shift;
use App\Domain\Organisasi\Data\DataAnggotaOutlet;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Penjualan\Data\DataRingkasanPenjualanShift;
use App\Domain\Penjualan\Kueri\RingkasanPenjualanShift;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * F-11 (PRD "Rincian F-11"): tutup shift yang dihitung di perangkat (bisa offline) diterima server lewat sinkron.
 * Satu transaksi DB; baris shift dikunci eksklusif sehingga penjualan & kas shift itu tidak masuk di tengah hitungan.
 *
 * - Shift wajib milik perangkat pengirim (`ShiftTidakDikenal`). Sudah `Tertutup`: data sama (penutup, waktu, kas
 *   aktual) = `Duplikat`, selain itu `ShiftSudahDitutup`.
 * - Penutup: anggota outlet ber-izin `penjualan.buat`; di shift yang bukan bersama (BR-06.2) hanya pembuka shift atau
 *   pemegang `shift.selisih.setujui` (supervisor) (`BukanShiftSendiri`).
 * - `DitutupPada` ≥ `DibukaPada` dan tidak lebih dari 10 menit di masa depan (`WaktuTidakValid`). Hitungan pecahan
 *   (opsional) wajib berjumlah = kas aktual (`PecahanTidakSesuai`). Non-tunai hanya metode non-tunai tenant
 *   (`MetodeBayarTidakDikenal`).
 * - Server menghitung ulang kas seharusnya dari datanya (`LaporanShift`). Berbeda dengan ringkasan perangkat → tetap
 *   diterima dengan angka server + `PerluTinjauan` (`KasSeharusnyaBerbeda`). |selisih server| > `ToleransiSelisihKas`
 *   → wajib alasan (`AlasanDiperlukan`) + penyetuju ber-izin `shift.selisih.setujui` (`PersetujuanDiperlukan`/
 *   `PenyetujuTidakBerwenang`); penutup yang sendiri ber-izin boleh menjadi penyetuju.
 * - Jurnal selisih J-11.1/J-11.2 di transaksi yang sama (aturan #10); periode terkunci → `PeriodeTerkunci`.
 */
final class TutupShift
{
    private const TOLERANSI_JAM_DETIK = 600;

    private const PANJANG_ALASAN_MINIMAL = 5;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly AnggotaOutlet $anggota,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PengaturanKasirTenant $pengaturan,
        private readonly LaporanShift $laporan,
        private readonly RingkasanPenjualanShift $ringkasanPenjualan,
        private readonly PenyusunJurnalSelisihKas $penyusun,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly PetaUuidOutlet $petaOutlet,
    ) {}

    public function Jalankan(DataTutupShift $data): StatusItemSinkron
    {
        if ($data->ditutupPada->greaterThan(CarbonImmutable::now()->addSeconds(self::TOLERANSI_JAM_DETIK))) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Waktu tutup shift ada di masa depan. Periksa jam perangkat.', 'DitutupPada');
        }

        $this->PeriksaPecahan($data);

        return DB::transaction(fn (): StatusItemSinkron => $this->Proses($data));
    }

    private function Proses(DataTutupShift $data): StatusItemSinkron
    {
        $idTenant = $this->konteks->Wajib();
        $shift = Shift::query()->where('Uuid', $data->uuidShift)->where('IdPerangkat', $data->idPerangkat)->lockForUpdate()->first();

        if ($shift === null) {
            throw new PelanggaranAturanBisnis('ShiftTidakDikenal', 'Shift yang ditutup tidak ditemukan di perangkat ini. Kirim data shift lebih dulu.', 'UuidShift');
        }

        $penutup = $this->anggota->Cari($idTenant, $data->uuidPenutup, $shift->IdOutlet);
        // Kiriman ulang dicocokkan dengan Id pengguna walau aksesnya sudah dicabut sejak shift ditutup.
        $idPenutup = $penutup->id ?? $this->anggota->CariDiTenant($idTenant, $data->uuidPenutup, $shift->IdOutlet)[0]->id ?? null;

        if ($shift->Status === StatusShift::Tertutup) {
            return $this->BandingkanDuplikat($shift, $data, $idPenutup);
        }

        // K-18: tutup lama yang terkirim ulang setelah shift dibuka ulang = Duplikat (bukan menutup lagi).
        if ($shift->Status === StatusShift::DibukaUlang && $this->CekTutupSebelumBukaUlang($shift, $data, $idPenutup)) {
            return StatusItemSinkron::Duplikat;
        }

        if (! $shift->Status->CekAktif()) {
            throw new PelanggaranAturanBisnis('ShiftTidakAktif', "Shift ini {$shift->Status->AmbilLabel()}. Tidak bisa ditutup.", 'UuidShift');
        }

        if ($penutup === null) {
            throw new PelanggaranAturanBisnis('KasirTidakDitemukan', 'Pengguna ini tidak terdaftar di outlet shift ini.', 'UuidPengguna');
        }

        if (! $penutup->CekIzin(IzinTenant::PenjualanBuat->value)) {
            throw new PelanggaranAturanBisnis('TanpaIzin', "{$penutup->nama} tidak punya izin menutup shift di POS.", 'UuidPengguna', 403);
        }

        if (! $shift->Bersama && $penutup->id !== $shift->DibukaOleh && ! $penutup->CekIzin(IzinTenant::ShiftSelisihSetujui->value)) {
            throw new PelanggaranAturanBisnis('BukanShiftSendiri', 'Shift hanya bisa ditutup oleh kasir pemilik shift atau supervisor (BR-06.2: shift ini bukan shift bersama).', 'UuidPengguna', 403);
        }

        if ($data->ditutupPada->lessThan(CarbonImmutable::parse($shift->DibukaPada))) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Waktu tutup shift lebih awal dari waktu buka shift.', 'DitutupPada');
        }

        $laporan = $this->laporan->Hitung($shift);
        $selisih = $data->kasAktual->Kurangi($laporan->kasSeharusnya);
        $penyetuju = $this->PeriksaPersetujuan($data, $selisih, $idTenant, $shift->IdOutlet);
        $nonTunai = $this->SusunRingkasanNonTunai($data, $laporan->penjualan);

        $tinjauan = [];

        if ($shift->AlasanTinjauan !== null) {
            $tinjauan[] = $shift->AlasanTinjauan;
        }

        if (! $laporan->kasSeharusnya->SamaDengan($data->ringkasanKasSeharusnya)) {
            $tinjauan[] = "KasSeharusnyaBerbeda: perangkat {$data->ringkasanKasSeharusnya->FormatRupiah()}, server {$laporan->kasSeharusnya->FormatRupiah()}";
        }

        $statusLama = $shift->Status;
        $shift->Status = StatusShift::Tertutup;
        $shift->DitutupOleh = $penutup->id;
        $shift->DitutupPada = Carbon::instance($data->ditutupPada);
        $shift->KasSeharusnya = $laporan->kasSeharusnya->KeString();
        $shift->KasAktual = $data->kasAktual->KeString();
        $shift->Selisih = $selisih->KeString();
        $shift->PecahanKasAkhir = $data->pecahan;
        $shift->RingkasanNonTunai = $nonTunai;
        $shift->AlasanSelisih = $data->alasan;
        $shift->IdPenyetujuSelisih = $penyetuju?->id;

        if ($tinjauan !== []) {
            $shift->PerluTinjauan = true;
            $shift->AlasanTinjauan = mb_substr(implode('; ', $tinjauan), 0, 255);
        }

        $shift->save();

        // K-18: tutup setelah buka ulang ke-n memakai kunci sumber jurnal tersendiri.
        $bukaUlang = BukaUlangShift::query()->where('IdShift', $shift->Id)->count();
        $jurnal = $this->penyusun->Susun($shift, $selisih, $this->tanggalBisnis->Hitung($shift->IdOutlet, $data->ditutupPada), $penutup->id, $bukaUlang === 0 ? 'Utama' : 'Tutup-'.($bukaUlang + 1));

        if ($jurnal !== null) {
            $this->postingJurnal->Jalankan($jurnal);
        }

        $this->riwayat->Catat(Shift::JENIS_DOKUMEN, $shift->Id, $statusLama->value, StatusShift::Tertutup->value, $penutup->id, $data->alasan);
        $this->audit->Catat('shift.tutup', $shift, nilaiBaru: [
            'KasSeharusnya' => $shift->KasSeharusnya,
            'KasAktual' => $shift->KasAktual,
            'Selisih' => $shift->Selisih,
            'AlasanSelisih' => $data->alasan,
            'DisetujuiOleh' => $penyetuju?->nama,
            'PerluTinjauan' => $shift->PerluTinjauan,
        ], idPengguna: $penutup->id);

        // X7 §16.4: webhook `shift.ditutup` (setelah commit; hanya angka kas, tanpa data pribadi kasir).
        PeristiwaIntegrasi::dispatch($idTenant, 'shift.ditutup', $shift->Id, [
            'Uuid' => $shift->Uuid,
            'UuidOutlet' => $this->petaOutlet->Ambil([$shift->IdOutlet])[$shift->IdOutlet] ?? null,
            'TanggalBisnis' => $shift->TanggalBisnis->toDateString(),
            'DibukaPada' => $shift->DibukaPada->toIso8601ZuluString(),
            'DitutupPada' => $shift->DitutupPada->toIso8601ZuluString(),
            'KasAwal' => Uang::Dari($shift->KasAwal)->KeString(),
            'KasSeharusnya' => $laporan->kasSeharusnya->KeString(),
            'KasAktual' => $data->kasAktual->KeString(),
            'Selisih' => $selisih->KeString(),
        ], kunci: (string) ($bukaUlang + 1));

        return StatusItemSinkron::Diterima;
    }

    private function PeriksaPecahan(DataTutupShift $data): void
    {
        if ($data->pecahan === null) {
            return;
        }

        $total = Uang::Nol();

        foreach ($data->pecahan as $baris) {
            $total = $total->Tambah(Uang::Dari($baris['Nominal'])->Kali($baris['Jumlah']));
        }

        if (! $total->SamaDengan($data->kasAktual)) {
            throw new PelanggaranAturanBisnis('PecahanTidakSesuai', "Jumlah hitungan pecahan ({$total->FormatRupiah()}) tidak sama dengan kas aktual ({$data->kasAktual->FormatRupiah()}).", 'PecahanKasAkhir');
        }
    }

    private function CekTutupSebelumBukaUlang(Shift $shift, DataTutupShift $data, ?int $idPenutup): bool
    {
        if ($idPenutup === null) {
            return false;
        }

        foreach (BukaUlangShift::query()->where('IdShift', $shift->Id)->get(['SnapshotTutup']) as $log) {
            $snapshot = $log->SnapshotTutup;

            if ($snapshot['DitutupOleh'] === $idPenutup
                && $snapshot['DitutupPada'] !== null && CarbonImmutable::parse($snapshot['DitutupPada'])->getTimestamp() === $data->ditutupPada->getTimestamp()
                && $snapshot['KasAktual'] !== null && Uang::Dari($snapshot['KasAktual'])->SamaDengan($data->kasAktual)) {
                return true;
            }
        }

        return false;
    }

    private function BandingkanDuplikat(Shift $shift, DataTutupShift $data, ?int $idPenutup): StatusItemSinkron
    {
        $sama = $idPenutup !== null
            && $shift->DitutupOleh === $idPenutup
            && $shift->DitutupPada?->getTimestamp() === $data->ditutupPada->getTimestamp()
            && $shift->KasAktual !== null
            && Uang::Dari($shift->KasAktual)->SamaDengan($data->kasAktual);

        if (! $sama) {
            throw new PelanggaranAturanBisnis('ShiftSudahDitutup', 'Shift ini sudah ditutup dengan data lain. Hubungi supervisor untuk meninjau.', 'UuidShift', 409);
        }

        return StatusItemSinkron::Duplikat;
    }

    private function PeriksaPersetujuan(DataTutupShift $data, Uang $selisih, int $idTenant, int $idOutlet): ?DataAnggotaOutlet
    {
        $toleransi = $this->pengaturan->Ambil()->toleransiSelisihKas;
        $mutlak = $selisih->BernilaiNegatif() ? Uang::Nol()->Kurangi($selisih) : $selisih;
        $wajib = $mutlak->Bandingkan($toleransi) > 0;

        if ($wajib && mb_strlen(trim((string) $data->alasan)) < self::PANJANG_ALASAN_MINIMAL) {
            throw new PelanggaranAturanBisnis('AlasanDiperlukan', "Selisih kas {$selisih->FormatRupiah()} melebihi toleransi {$toleransi->FormatRupiah()}. Tulis alasan minimal 5 karakter.", 'Alasan', 422, [
                'Selisih' => $selisih->KeString(),
                'ToleransiSelisihKas' => $toleransi->KeString(),
            ]);
        }

        if ($data->uuidPenyetuju === null) {
            if ($wajib) {
                throw new PelanggaranAturanBisnis('PersetujuanDiperlukan', "Selisih kas {$selisih->FormatRupiah()} melebihi toleransi {$toleransi->FormatRupiah()}. Wajib disetujui supervisor dengan PIN.", 'UuidPenyetuju', 422, [
                    'Selisih' => $selisih->KeString(),
                    'ToleransiSelisihKas' => $toleransi->KeString(),
                ]);
            }

            return null;
        }

        $penyetuju = $this->anggota->Cari($idTenant, $data->uuidPenyetuju, $idOutlet);

        if ($penyetuju === null || ! $penyetuju->CekIzin(IzinTenant::ShiftSelisihSetujui->value)) {
            throw new PelanggaranAturanBisnis('PenyetujuTidakBerwenang', 'Penyetuju tidak punya izin menyetujui selisih kas di outlet ini.', 'UuidPenyetuju', 403);
        }

        return $penyetuju;
    }

    /**
     * Non-tunai per metode: total sistem (penjualan shift) dan hitungan kasir (bila diisi), untuk pencocokan slip.
     *
     * @return list<array{UuidMetodePembayaran: string, Jenis: string, Nama: string, JumlahSistem: string, JumlahDilaporkan: string|null}>
     */
    private function SusunRingkasanNonTunai(DataTutupShift $data, DataRingkasanPenjualanShift $penjualan): array
    {
        $dilaporkan = [];

        foreach ($data->nonTunai as $indeks => $baris) {
            if (isset($dilaporkan[$baris['UuidMetodePembayaran']])) {
                throw new PelanggaranAturanBisnis('DataTidakValid', 'Satu metode pembayaran hanya boleh diisi sekali.', "NonTunai.{$indeks}.UuidMetodePembayaran");
            }

            $dilaporkan[$baris['UuidMetodePembayaran']] = $baris['Jumlah'];
        }

        $metode = $this->ringkasanPenjualan->AmbilMetode(array_keys($dilaporkan));

        foreach (array_keys($dilaporkan) as $indeks => $uuid) {
            if (! isset($metode[$uuid]) || $metode[$uuid]->tunai) {
                throw new PelanggaranAturanBisnis('MetodeBayarTidakDikenal', 'Metode pembayaran non-tunai tidak dikenal.', "NonTunai.{$indeks}.UuidMetodePembayaran");
            }
        }

        $hasil = [];

        foreach ($penjualan->perMetode as $m) {
            if (! $m->tunai) {
                $metode[$m->uuidMetode] = $m;
            }
        }

        foreach ($metode as $uuid => $m) {
            $hasil[] = [
                'UuidMetodePembayaran' => $uuid,
                'Jenis' => $m->jenis,
                'Nama' => $m->nama,
                'JumlahSistem' => $penjualan->AmbilJumlahMetode($uuid)->KeString(),
                'JumlahDilaporkan' => isset($dilaporkan[$uuid]) ? $dilaporkan[$uuid]->KeString() : null,
            ];
        }

        return $hasil;
    }
}
