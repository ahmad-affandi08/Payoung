<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Kueri;

use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Model\TiketDukungan;
use App\Domain\Pengelola\Operasional\Kueri\KondisiOperasional;
use App\Domain\Pengelola\Operasional\Model\AlertOperasional;
use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\Tenant\Model\SkorKesehatanTenant;
use App\Domain\Pengelola\Tenant\Model\TugasTenant;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\StatusInsiden;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusPermintaanPenghapusanData;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Enum\StatusTenant;
use App\Domain\Tenant\Model\InsidenPlatform;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\Tenant;

/**
 * Ringkasan Beranda konsol Platform Pengelola: satu layar yang menjawab "apa yang perlu saya urus sekarang?" tanpa
 * membuka sepuluh menu. Tiap bagian hanya diisi bila pelaku punya izin lihat yang bersangkutan (bagian lain `null`),
 * dan tenant berpenanda Uji/Demo/Internal tidak dihitung sebagai pelanggan. Angka tenant & tagihan dari data platform;
 * jumlah tiket membuka data lintas tenant lewat `KonteksPengelola` (tercatat di audit).
 */
final class RingkasanBerandaPengelola
{
    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly KondisiOperasional $kondisi,
    ) {}

    /**
     * @return array{Tenant: array<string, mixed>|null, Tagihan: array<string, mixed>|null, Dukungan: array<string, mixed>|null, Operasional: array<string, mixed>|null, Perhatian: list<array{Judul: string, Keterangan: string, Tautan: string, Tingkat: string}>}
     */
    public function Ambil(PenggunaPengelola $pelaku): array
    {
        $tenant = $pelaku->PunyaIzin(IzinPengelola::TenantLihat) ? $this->RingkasTenant() : null;
        $tagihan = $pelaku->PunyaIzin(IzinPengelola::TagihanLihat) ? $this->RingkasTagihan() : null;
        $dukungan = $pelaku->PunyaIzin(IzinPengelola::DukunganTiketLihat) ? $this->RingkasDukungan() : null;
        $operasional = $pelaku->PunyaIzin(IzinPengelola::OperasionalLihat) ? $this->RingkasOperasional() : null;

        return [
            'Tenant' => $tenant,
            'Tagihan' => $tagihan,
            'Dukungan' => $dukungan,
            'Operasional' => $operasional,
            'Perhatian' => $this->SusunPerhatian($tenant, $tagihan, $dukungan, $operasional, $pelaku),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function RingkasTenant(): array
    {
        $pelanggan = Tenant::query()->whereNull('Penanda')->where('Status', StatusTenant::Aktif->value);
        $idPelanggan = (clone $pelanggan)->pluck('Id')->all();
        $perStatus = Langganan::query()->whereIn('IdTenant', $idPelanggan)->selectRaw('Status, COUNT(*) AS Jumlah')->groupBy('Status')->pluck('Jumlah', 'Status');
        $tanggalSkor = SkorKesehatanTenant::query()->max('Tanggal');
        $berisiko = $tanggalSkor === null ? 0 : SkorKesehatanTenant::query()
            ->where('Tanggal', $tanggalSkor)
            ->where('Kategori', KategoriKesehatan::Berisiko->value)
            ->whereIn('IdTenant', $idPelanggan)
            ->count();

        return [
            'Total' => count($idPelanggan),
            'Baru7Hari' => (clone $pelanggan)->where('DibuatPada', '>=', now()->subDays(7))->count(),
            'Trial' => (int) ($perStatus[StatusLangganan::Trial->value] ?? 0),
            'Berbayar' => (int) ($perStatus[StatusLangganan::Aktif->value] ?? 0),
            'Tertunggak' => (int) ($perStatus[StatusLangganan::Tertunggak->value] ?? 0),
            'Ditangguhkan' => (int) ($perStatus[StatusLangganan::Ditangguhkan->value] ?? 0),
            'Berisiko' => $berisiko,
            'TugasTerbuka' => TugasTenant::query()->where('Status', TugasTenant::TERBUKA)->count(),
            'PenghapusanDataMenunggu' => $this->konteks->KueriDataPlatform(PermintaanPenghapusanData::class)->where('Status', StatusPermintaanPenghapusanData::Menunggu->value)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function RingkasTagihan(): array
    {
        $tagihan = fn () => $this->konteks->KueriDataPlatform(TagihanLangganan::class);
        $jatuhTempo = $tagihan()->where('Status', StatusTagihanLangganan::JatuhTempo->value);
        $nilaiJatuhTempo = Uang::Nol();

        foreach ((clone $jatuhTempo)->get(['Total']) as $baris) {
            $nilaiJatuhTempo = $nilaiJatuhTempo->Tambah(Uang::Dari($baris->Total));
        }

        return [
            'JatuhTempo' => (clone $jatuhTempo)->count(),
            'NilaiJatuhTempo' => $nilaiJatuhTempo->KeString(),
            'MenungguVerifikasi' => $this->konteks->KueriDataPlatform(PembayaranLangganan::class)->where('Status', StatusPembayaranLangganan::Menunggu->value)->count(),
            'Terbit' => $tagihan()->where('Status', StatusTagihanLangganan::Terbit->value)->count(),
            'LunasBulanIni' => $tagihan()->where('Status', StatusTagihanLangganan::Lunas->value)->where('DibayarPada', '>=', now()->startOfMonth())->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function RingkasDukungan(): array
    {
        $terbuka = array_map(fn (StatusTiketDukungan $s): string => $s->value, StatusTiketDukungan::AmbilTerbuka());

        return $this->konteks->JalankanLintasTenant('Ringkasan tiket dukungan di Beranda konsol', function (KonteksPengelola $konteks) use ($terbuka): array {
            $tiket = fn () => $konteks->KueriLintas(TiketDukungan::class);

            return [
                'Terbuka' => $tiket()->whereIn('Status', $terbuka)->count(),
                'Baru' => $tiket()->where('Status', StatusTiketDukungan::Baru->value)->count(),
                'LewatSla' => $tiket()->whereIn('Status', $terbuka)->where('BatasSlaPada', '<', now())->count(),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function RingkasOperasional(): array
    {
        $penjadwal = $this->kondisi->AmbilPenjadwal();
        $antrean = $this->kondisi->AmbilAntrean();
        $backup = $this->kondisi->AmbilBackup();

        return [
            'PenjadwalSehat' => $penjadwal['Sehat'],
            'AntreanSehat' => $antrean['Sehat'],
            'BackupSehat' => $backup['Sehat'],
            'AlertAktif' => AlertOperasional::query()->whereNull('SelesaiPada')->count(),
            'InsidenAktif' => InsidenPlatform::query()->where('Status', '!=', StatusInsiden::Selesai->value)->count(),
        ];
    }

    /**
     * Butir yang menuntut tindakan, paling mendesak dulu. Hanya dari bagian yang boleh dilihat pelaku.
     *
     * @param  array<string, mixed>|null  $tenant
     * @param  array<string, mixed>|null  $tagihan
     * @param  array<string, mixed>|null  $dukungan
     * @param  array<string, mixed>|null  $operasional
     * @return list<array{Judul: string, Keterangan: string, Tautan: string, Tingkat: string}>
     */
    private function SusunPerhatian(?array $tenant, ?array $tagihan, ?array $dukungan, ?array $operasional, PenggunaPengelola $pelaku): array
    {
        $butir = [];

        if ($operasional !== null) {
            $mati = array_filter(['penjadwal (scheduler)' => $operasional['PenjadwalSehat'], 'antrean tugas' => $operasional['AntreanSehat'], 'backup' => $operasional['BackupSehat']], fn (bool $sehat): bool => ! $sehat);

            if ($mati !== []) {
                $butir[] = ['Judul' => 'Kondisi sistem bermasalah', 'Keterangan' => 'Perlu dicek: '.implode(', ', array_keys($mati)).'.', 'Tautan' => '/operasional', 'Tingkat' => 'bahaya'];
            }

            if ($operasional['InsidenAktif'] > 0) {
                $butir[] = ['Judul' => $operasional['InsidenAktif'].' insiden belum selesai', 'Keterangan' => 'Perbarui status dan kabari pengguna lewat halaman status.', 'Tautan' => '/operasional/insiden', 'Tingkat' => 'bahaya'];
            }
        }

        if ($dukungan !== null && $dukungan['LewatSla'] > 0) {
            $butir[] = ['Judul' => $dukungan['LewatSla'].' tiket melewati batas SLA', 'Keterangan' => 'Tangani lebih dulu agar tidak makin terlambat.', 'Tautan' => '/dukungan/tiket', 'Tingkat' => 'bahaya'];
        }

        if ($tagihan !== null && $pelaku->PunyaIzin(IzinPengelola::TagihanVerifikasi) && $tagihan['MenungguVerifikasi'] > 0) {
            $butir[] = ['Judul' => $tagihan['MenungguVerifikasi'].' pembayaran menunggu verifikasi', 'Keterangan' => 'Pelanggan sudah mengunggah bukti transfer.', 'Tautan' => '/tagihan', 'Tingkat' => 'peringatan'];
        }

        if ($tagihan !== null && $tagihan['JatuhTempo'] > 0) {
            $butir[] = ['Judul' => $tagihan['JatuhTempo'].' tagihan lewat jatuh tempo', 'Keterangan' => 'Total '.Uang::Dari($tagihan['NilaiJatuhTempo'])->FormatRupiah().' belum dibayar.', 'Tautan' => '/tagihan', 'Tingkat' => 'peringatan'];
        }

        if ($dukungan !== null && $dukungan['Baru'] > 0) {
            $butir[] = ['Judul' => $dukungan['Baru'].' tiket baru belum ditangani', 'Keterangan' => 'Ambil tiket dari antrean dukungan.', 'Tautan' => '/dukungan/tiket', 'Tingkat' => 'info'];
        }

        if ($tenant !== null && $tenant['Berisiko'] > 0) {
            $butir[] = ['Judul' => $tenant['Berisiko'].' tenant berisiko berhenti', 'Keterangan' => 'Skor kesehatan rendah; hubungi sebelum mereka pergi.', 'Tautan' => '/tugas-tenant', 'Tingkat' => 'peringatan'];
        }

        if ($tenant !== null && $pelaku->PunyaIzin(IzinPengelola::TenantPenghapusanDataKelola) && $tenant['PenghapusanDataMenunggu'] > 0) {
            $butir[] = ['Judul' => $tenant['PenghapusanDataMenunggu'].' permintaan penghapusan data', 'Keterangan' => 'Pemilik usaha meminta data dihapus (UU PDP); tinjau setelah masa tunggu.', 'Tautan' => '/tenant', 'Tingkat' => 'info'];
        }

        return $butir;
    }
}
