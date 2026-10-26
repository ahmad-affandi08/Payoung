<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Pelanggan;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bengkel\Kueri\DaftarKendaraan;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Aksi\AturTierPelanggan;
use App\Domain\Pelanggan\Aksi\SesuaikanPoin;
use App\Domain\Pelanggan\Aksi\SimpanPelanggan;
use App\Domain\Pelanggan\Aksi\UbahPelangganMassal;
use App\Domain\Pelanggan\Aksi\UbahStatusPelanggan;
use App\Domain\Pelanggan\Enum\StatusPelanggan;
use App\Domain\Pelanggan\Kueri\DaftarPelanggan;
use App\Domain\Pelanggan\Kueri\DaftarSaldoSesi;
use App\Domain\Pelanggan\Kueri\DaftarTierPelanggan;
use App\Domain\Pelanggan\Kueri\KreditPelanggan;
use App\Domain\Pelanggan\Kueri\PengaturanDepositTenant;
use App\Domain\Pelanggan\Kueri\PengaturanLoyaltiTenant;
use App\Domain\Pelanggan\Kueri\PengaturanSesiTenant;
use App\Domain\Pelanggan\Kueri\RiwayatDeposit;
use App\Domain\Pelanggan\Kueri\RiwayatPoin;
use App\Domain\Pelanggan\Layanan\BukuPoin;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\TierPelanggan;
use App\Domain\Penjualan\Kueri\BelanjaPelanggan;
use App\Domain\Penjualan\Kueri\RiwayatBelanjaPembeliOnline;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Pelanggan\SimpanPelangganPermintaan;
use App\Http\Permintaan\Kelola\Pelanggan\UbahPelangganMassalPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pelanggan back-office (F-16a CRM-01, `/kelola/pelanggan`): daftar (`TabelData`), tambah/ubah, arsipkan/pulihkan
 * (izin `pelanggan.kelola`), dan detail dengan riwayat belanja (izin `pelanggan.lihat`). Izin rute dijaga
 * `WajibIzinTenant`; prop `Izin` hanya untuk tampilan.
 */
final class PelangganKontroler extends DasarKelolaKontroler
{
    public function Daftar(Request $permintaan, DaftarPelanggan $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPelanggan::KOLOM_URUT, DaftarPelanggan::URUT_BAWAAN, DaftarPelanggan::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Pelanggan/Daftar', 'Pelanggan', fn (): array => $daftar->AmbilTabel($tabel), fn (): array => [
            'Izin' => $this->AmbilIzin(),
            'OpsiTag' => $daftar->AmbilSemuaTag(),
            'OpsiTier' => app(DaftarTierPelanggan::class)->AmbilOpsi(),
        ]);
    }

    public function Detail(
        string $pelanggan,
        BelanjaPelanggan $belanja,
        DaftarTierPelanggan $tier,
        BukuPoin $buku,
        RiwayatPoin $riwayatPoin,
        PengaturanLoyaltiTenant $loyalti,
        KreditPelanggan $kredit,
        TanggalBisnisOutlet $tanggal,
        RiwayatDeposit $riwayatDeposit,
        PengaturanDepositTenant $deposit,
        DaftarAkunPilihan $akun,
        DaftarSaldoSesi $saldoSesi,
        PengaturanSesiTenant $sesi,
        RiwayatBelanjaPembeliOnline $riwayatOnline,
        ProfilTenant $profil,
        DaftarKendaraan $kendaraan,
    ): Response {
        $data = $this->CariPelanggan($pelanggan);
        $izin = $this->AmbilIzin();
        $tierPelanggan = $data->IdTier === null ? null : ($tier->AmbilPeta([$data->IdTier])[$data->IdTier] ?? null);

        return Inertia::render('Kelola/Pelanggan/Detail', [
            'Pelanggan' => [
                ...DaftarPelanggan::Petakan($data, $tierPelanggan, $buku->AmbilSaldo($data->Id)),
                // Identitas pajak (Faktur Pajak Coretax): utuh hanya untuk yang boleh mengelola pelanggan, selain itu empat digit terakhir.
                'Npwp' => self::TampilkanDigit($data->Npwp, $izin['Kelola']),
                'Nik' => self::TampilkanDigit($data->Nik, $izin['Kelola']),
                'NamaNpwp' => $data->NamaNpwp,
                'AlamatNpwp' => $data->AlamatNpwp,
                ...($belanja->AmbilRingkasan([$data->Id])[$data->Id] ?? ['JumlahTransaksi' => 0, 'TotalBelanja' => '0.00', 'TerakhirPada' => null]),
            ],
            'Riwayat' => $belanja->AmbilRiwayat($data->Id),
            'RiwayatPoin' => $riwayatPoin->Ambil($data->Id),
            'OpsiTier' => $tier->AmbilOpsi($tierPelanggan['Kode'] ?? null),
            'LoyaltiBerlaku' => $loyalti->Ambil()->CekBerlaku(),
            // F-12: posisi kredit (sisa piutang terbuka & hari terlama lewat jatuh tempo).
            'Kredit' => $kredit->AmbilRingkas([$data->Id], $tanggal->Hitung(null))[$data->Id] ?? null,
            // F-16d bagian 1: saldo & riwayat deposit; akun kas/bank hanya untuk yang boleh menarik deposit.
            'Deposit' => [
                'Saldo' => (string) $data->SaldoDeposit,
                'Berlaku' => $deposit->CekBerlaku(),
                'Riwayat' => $riwayatDeposit->Ambil($data->Id),
                'AkunKasBank' => $izin['KelolaDeposit'] ? $akun->AmbilKasBank() : [],
            ],
            // F-16d bagian 2: paket sesi pelanggan (aktif lebih dulu).
            'PaketSesi' => [
                'Berlaku' => $sesi->CekBerlaku(),
                'Daftar' => $saldoSesi->AmbilPerPelanggan($data->Id),
            ],
            // F-17 bagian 3: pesanan toko online pelanggan ini & nomor yang sudah dibuktikan lewat kode WhatsApp.
            'PesananOnline' => $riwayatOnline->Ambil($this->IdTenant(), $data->Id, $profil->AmbilSlug($this->IdTenant()))['Pesanan'],
            'NoHpTerverifikasi' => $data->NoHpTerverifikasiPada !== null,
            // Bengkel (§9.10): kendaraan pelanggan, hanya untuk pemegang izin `bengkel.kelola` (null = bagian disembunyikan).
            'Kendaraan' => app(AksesPengguna::class)->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::BengkelKelola)
                ? $kendaraan->AmbilMilikPelanggan($data->Id)
                : null,
            'Izin' => $izin,
        ]);
    }

    /** Aksi massal pelanggan terpilih: arsipkan, pulihkan, atau atur tier. */
    public function Massal(UbahPelangganMassalPermintaan $permintaan, UbahPelangganMassal $ubah): RedirectResponse
    {
        /** @var list<string> $uuid */
        $uuid = array_values((array) $permintaan->validated('Uuid'));
        $aksi = (string) $permintaan->validated('Aksi');
        $uuidTier = $permintaan->validated('UuidTier');
        $hasil = $ubah->Jalankan($aksi, $uuid, $this->Pelaku()->Id, is_string($uuidTier) ? $uuidTier : null, $permintaan->boolean('TierTetap'));
        $kata = match ($aksi) {
            'Arsipkan' => 'diarsipkan',
            'Pulihkan' => 'diaktifkan kembali',
            default => 'diatur tiernya',
        };
        $lewat = $hasil['Dilewati'] > 0 ? " {$hasil['Dilewati']} dilewati karena sudah berstatus itu." : '';

        return back()->with('Kilat', "{$hasil['Diubah']} pelanggan {$kata}.{$lewat}");
    }

    /** F-16b: atur tier manual & kunci tier. */
    public function AturTier(Request $permintaan, string $pelanggan, AturTierPelanggan $atur): RedirectResponse
    {
        $valid = $permintaan->validate([
            'UuidTier' => ['nullable', 'string', 'ulid'],
            'TierTetap' => ['required', 'boolean'],
        ], attributes: ['UuidTier' => 'tier']);
        $tier = is_string($valid['UuidTier'] ?? null) ? TierPelanggan::query()->where('Uuid', $valid['UuidTier'])->first() : null;

        if (is_string($valid['UuidTier'] ?? null) && $tier === null) {
            return back()->withErrors(['UuidTier' => 'Tier tidak ditemukan.']);
        }

        $hasil = $atur->Jalankan($this->CariPelanggan($pelanggan), $tier, $permintaan->boolean('TierTetap'), $this->Pelaku()->Id);

        return back()->with('Kilat', "Tier {$hasil->Nama} disimpan.");
    }

    /** F-16b: penyesuaian poin manual dengan alasan. */
    public function SesuaikanPoin(Request $permintaan, string $pelanggan, SesuaikanPoin $sesuaikan, TanggalBisnisOutlet $tanggal): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Poin' => ['required', 'integer', 'between:-100000,100000'],
            'Alasan' => ['required', 'string', 'max:255'],
        ], attributes: ['Poin' => 'poin', 'Alasan' => 'alasan']);
        $data = $this->CariPelanggan($pelanggan);
        $saldo = $sesuaikan->Jalankan($data, (int) $valid['Poin'], (string) $valid['Alasan'], $this->Pelaku()->Id, $tanggal->Hitung(null));

        return back()->with('Kilat', "Poin {$data->Nama} sekarang {$saldo}.");
    }

    /** Halaman penuh "Tambah pelanggan" (pola sama dengan Tambah produk). */
    public function Buat(): Response
    {
        return Inertia::render('Kelola/Pelanggan/Buat');
    }

    /** Setelah ditambah, arahkan ke detail pelanggan baru (bukan kembali ke halaman buat). */
    public function Simpan(SimpanPelangganPermintaan $permintaan, SimpanPelanggan $simpan): RedirectResponse
    {
        $pelanggan = $simpan->Jalankan($permintaan->AmbilData($this->Pelaku()->Id));

        return redirect()->route('kelola.pelanggan.detail', ['pelanggan' => $pelanggan->Uuid])->with('Kilat', "Pelanggan {$pelanggan->Nama} ditambahkan.");
    }

    public function Perbarui(SimpanPelangganPermintaan $permintaan, string $pelanggan, SimpanPelanggan $simpan): RedirectResponse
    {
        $hasil = $simpan->Jalankan($permintaan->AmbilData($this->Pelaku()->Id), $this->CariPelanggan($pelanggan));

        return back()->with('Kilat', "Pelanggan {$hasil->Nama} disimpan.");
    }

    public function Arsipkan(string $pelanggan, UbahStatusPelanggan $ubah): RedirectResponse
    {
        $hasil = $ubah->Jalankan($this->CariPelanggan($pelanggan), StatusPelanggan::Diarsipkan, $this->Pelaku()->Id);

        return back()->with('Kilat', "Pelanggan {$hasil->Nama} diarsipkan. Tidak muncul lagi di pencarian kasir.");
    }

    public function Pulihkan(string $pelanggan, UbahStatusPelanggan $ubah): RedirectResponse
    {
        $hasil = $ubah->Jalankan($this->CariPelanggan($pelanggan), StatusPelanggan::Aktif, $this->Pelaku()->Id);

        return back()->with('Kilat', "Pelanggan {$hasil->Nama} dipulihkan.");
    }

    private function CariPelanggan(string $uuid): Pelanggan
    {
        return Pelanggan::query()->where('Uuid', $uuid)->firstOrFail();
    }

    private static function TampilkanDigit(?string $digit, bool $utuh): ?string
    {
        return $digit === null || $utuh ? $digit : SimpanPelanggan::SamarkanDigit($digit);
    }

    /**
     * @return array{Kelola: bool, LihatPenjualan: bool, KelolaDeposit: bool}
     */
    private function AmbilIzin(): array
    {
        $akses = app(AksesPengguna::class);

        return [
            'Kelola' => $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::PelangganKelola),
            'LihatPenjualan' => $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::LaporanPenjualanLihat),
            'KelolaDeposit' => $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::PelangganDepositKelola),
        ];
    }
}
