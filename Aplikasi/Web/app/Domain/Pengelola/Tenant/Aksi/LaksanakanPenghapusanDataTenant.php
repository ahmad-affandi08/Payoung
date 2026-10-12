<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\PemilikTenant;
use App\Domain\Pengelola\Tenant\Layanan\KonteksPengelola;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Aksi\TutupAkunTenant;
use App\Domain\Tenant\Enum\StatusPermintaanPenghapusanData;
use App\Domain\Tenant\Layanan\PenghapusBerkasTenant;
use App\Domain\Tenant\Model\PermintaanPenghapusanData;
use App\Domain\Tenant\Model\Tenant;
use App\Domain\Tenant\Surel\PenghapusanDataSelesai;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Super Admin melaksanakan permintaan penghapusan data setelah masa tunggu (UU PDP, P-07): data pribadi dihapus dan
 * identitas pada data transaksi dianonimkan (`TutupAkunTenant`), berkas pribadi dihapus dari penyimpanan, lalu bukti
 * dikirim ke Owner. Pembukuan tidak pernah dihapus (BR-P07.1, retensi pajak). Data pemilik tenant dan nama usaha diambil
 * sebelum dianonimkan hanya untuk email bukti; tidak ada salinannya yang disimpan. Email gagal tidak membatalkan
 * pelaksanaan (dicatat di log; kode bukti tetap tersimpan di permintaan).
 */
final class LaksanakanPenghapusanDataTenant
{
    public function __construct(
        private readonly KonteksPengelola $konteks,
        private readonly PemilikTenant $pemilik,
        private readonly TutupAkunTenant $tutupAkun,
        private readonly PenghapusBerkasTenant $penghapusBerkas,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $pelaku, PermintaanPenghapusanData $permintaan, string $catatan): PermintaanPenghapusanData
    {
        $idTenant = $permintaan->IdTenant;
        $namaUsaha = (string) Tenant::query()->whereKey($idTenant)->value('Nama');
        $kontak = $this->pemilik->AmbilKontak($idTenant);

        $hasil = $this->konteks->JalankanLintasTenant(
            'Melaksanakan penghapusan data dan menutup akun (UU PDP)',
            fn (): array => DB::transaction(function () use ($pelaku, $permintaan, $catatan, $idTenant): array {
                $terkini = PermintaanPenghapusanData::query()->whereKey($permintaan->Id)->lockForUpdate()->firstOrFail();

                if ($terkini->Status !== StatusPermintaanPenghapusanData::Menunggu) {
                    throw new PelanggaranAturanBisnis('PermintaanTidakMenunggu', "Permintaan ini sudah {$terkini->Status->AmbilLabel()}.");
                }

                if ($terkini->BerlakuPada->isFuture()) {
                    $tanggal = $terkini->BerlakuPada->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y H:i');

                    throw new PelanggaranAturanBisnis('MasaTungguBelumSelesai', "Masa tunggu baru berakhir {$tanggal} WIB. Pelaksanaan sebelum itu merampas hak Owner untuk membatalkan.");
                }

                $ringkasan = $this->tutupAkun->Jalankan($idTenant);
                $terkini->update([
                    'Status' => StatusPermintaanPenghapusanData::Dilaksanakan,
                    'IdPenggunaPengelolaPemutus' => $pelaku->Id,
                    'DiputuskanPada' => now(),
                    'CatatanPemutus' => $catatan,
                    'DilaksanakanPada' => now(),
                    'Ringkasan' => $ringkasan,
                ]);
                $this->audit->Catat(
                    'tenant.penghapusan-data-laksana',
                    $terkini,
                    nilaiBaru: [
                        'TabelDianonimkan' => count($ringkasan['Dianonimkan']),
                        'TabelDihapus' => count($ringkasan['Dihapus']),
                        'PenggunaDianonimkan' => $ringkasan['PenggunaDianonimkan'],
                    ],
                    alasan: $catatan,
                    idPelaku: $pelaku->Id,
                    idTenant: $idTenant,
                );

                return ['Permintaan' => $terkini, 'Ringkasan' => $ringkasan];
            }),
            $idTenant,
        );

        /** @var PermintaanPenghapusanData $terlaksana */
        $terlaksana = $hasil['Permintaan'];
        /** @var array<string, mixed> $ringkasan */
        $ringkasan = $hasil['Ringkasan'];
        $berkas = $this->penghapusBerkas->HapusPribadi($idTenant);
        $ringkasan['Berkas'] = $berkas;
        $terlaksana->forceFill(['Ringkasan' => $ringkasan])->saveQuietly();

        if ($berkas['Gagal'] > 0) {
            Log::warning('Sebagian berkas pribadi tenant gagal dihapus saat penghapusan data.', ['IdTenant' => $idTenant, 'Gagal' => $berkas['Gagal']]);
        }

        $this->KirimBukti($terlaksana, $namaUsaha, $kontak, $ringkasan);

        return $terlaksana;
    }

    /**
     * @param  list<array{Nama: string, Email: string|null, NoHp: string|null}>  $kontak
     * @param  array<string, mixed>  $ringkasan
     */
    private function KirimBukti(PermintaanPenghapusanData $permintaan, string $namaUsaha, array $kontak, array $ringkasan): void
    {
        $waktu = ($permintaan->DilaksanakanPada ?? now())->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y H:i').' WIB';
        $terkirim = false;

        foreach ($kontak as $penerima) {
            if ($penerima['Email'] === null) {
                continue;
            }

            try {
                Mail::to($penerima['Email'])->send(new PenghapusanDataSelesai(
                    $penerima['Nama'],
                    $namaUsaha,
                    $waktu,
                    $permintaan->Uuid,
                    self::UraikanDihapus($ringkasan),
                    self::UraikanDianonimkan($ringkasan),
                    self::UraikanDipertahankan(),
                ));
                $terkirim = true;
            } catch (Throwable $galat) {
                Log::warning('Email bukti penghapusan data gagal dikirim.', ['IdTenant' => $permintaan->IdTenant, 'Galat' => $galat->getMessage()]);
            }
        }

        if ($terkirim) {
            $permintaan->forceFill(['BuktiDikirimPada' => now()])->saveQuietly();
        }
    }

    /**
     * @param  array<string, mixed>  $ringkasan
     * @return list<string>
     */
    public static function UraikanDihapus(array $ringkasan): array
    {
        $dihapus = is_array($ringkasan['Dihapus'] ?? null) ? $ringkasan['Dihapus'] : [];
        $baris = [
            'Kredensial dan token integrasi (gerbang pembayaran, WhatsApp, webhook, token API, kode aktivasi perangkat)',
            'Data wajah, foto absensi, foto bukti pengiriman, foto produk, logo, dan lampiran bantuan',
            'Berkas impor, hasil ekspor data, dan berkas verifikasi merchant pembayaran',
            'Pesan terkirim (WhatsApp/email), pengingat, kampanye, dan notifikasi',
            'Catatan internal dukungan tentang usaha Anda',
        ];

        if (($ringkasan['PenggunaDianonimkan'] ?? 0) > 0) {
            $baris[] = 'Akun pengguna: '.$ringkasan['PenggunaDianonimkan'].' akun dianonimkan, semua sesi dan akses dicabut';
        }

        if (isset($dihapus['TenantPengguna'])) {
            $baris[] = 'Keanggotaan usaha: '.$dihapus['TenantPengguna'].' anggota dilepas';
        }

        return $baris;
    }

    /**
     * @param  array<string, mixed>  $ringkasan
     * @return list<string>
     */
    public static function UraikanDianonimkan(array $ringkasan): array
    {
        $dianonimkan = is_array($ringkasan['Dianonimkan'] ?? null) ? $ringkasan['Dianonimkan'] : [];
        $label = [
            'Pelanggan' => 'Pelanggan (nama, telepon, email, alamat, tanggal lahir, NPWP/NIK)',
            'Karyawan' => 'Karyawan (nama, gaji pokok)',
            'Pemasok' => 'Pemasok (nama, kontak, alamat, rekening, NPWP)',
            'Kurir' => 'Kurir',
            'Kendaraan' => 'Kendaraan pelanggan (nomor polisi, rangka, mesin)',
            'PesananOnline' => 'Pesanan toko online (nama, telepon, email, alamat pembeli)',
            'Reservasi' => 'Reservasi',
            'TiketLaundry' => 'Tiket laundry',
            'ResepPenjualan' => 'Resep apotek (dokter dan pasien)',
            'Perangkat' => 'Perangkat (nama dan token akses dicabut)',
            'Outlet' => 'Outlet (nama dan alamat)',
            'Tenant' => 'Profil usaha (nama, NPWP, pengaturan)',
        ];
        $baris = [];

        foreach ($label as $tabel => $teks) {
            if (isset($dianonimkan[$tabel])) {
                $baris[] = "{$teks}: {$dianonimkan[$tabel]} data";
            }
        }

        $baris[] = 'Catatan bebas pada transaksi dan jejak audit (alamat IP dan nilai yang memuat data pribadi dikosongkan)';

        return $baris;
    }

    /** @return list<string> */
    public static function UraikanDipertahankan(): array
    {
        $tahun = (int) config('tenant.TahunRetensiPembukuan');

        return [
            "Dokumen transaksi dan pembukuan (penjualan, pembelian, retur, jurnal, stok, pajak) dengan identitas dianonimkan, selama masa retensi pajak {$tahun} tahun",
            "Lampiran pembukuan (faktur pembelian, bukti kas dan bank) selama {$tahun} tahun, lalu dihapus otomatis",
            'Catatan penagihan langganan Payoung dan bukti persetujuan dokumen legal sebagai dasar pembelaan hukum',
        ];
    }
}
