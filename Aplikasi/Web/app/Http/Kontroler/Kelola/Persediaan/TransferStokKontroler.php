<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Persediaan;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Enum\JenisGudang;
use App\Domain\Persediaan\Aksi\BatalkanTransferStok;
use App\Domain\Persediaan\Aksi\KirimTransferStok;
use App\Domain\Persediaan\Aksi\SimpanTransferStok;
use App\Domain\Persediaan\Aksi\TerimaTransferStok;
use App\Domain\Persediaan\Aksi\TutupTransferStok;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Kebijakan\DokumenPersediaanKebijakan;
use App\Domain\Persediaan\Kueri\DaftarTransferStok;
use App\Domain\Persediaan\Kueri\DetailTransferStok;
use App\Domain\Persediaan\Kueri\DokumenCetakTransferStok;
use App\Domain\Persediaan\Model\TransferStok;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Permintaan\Kelola\Persediaan\AlasanDokumenPersediaanPermintaan;
use App\Http\Permintaan\Kelola\Persediaan\SimpanTransferStokPermintaan;
use App\Http\Permintaan\Kelola\Persediaan\TerimaTransferStokPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman & aksi transfer stok F-05b (routes/PersediaanDokumen.php): daftar, form draf, detail, kirim, terima
 * (parsial), tutup dengan selisih, batal draf. Transfer terlihat bila outlet asal atau tujuan boleh diakses (selain
 * itu 404). Membuat/mengubah/mengirim/membatalkan butuh akses outlet asal; menerima/menutup butuh akses outlet tujuan
 * (403). Izin rute: lihat `persediaan.lihat`, lainnya `persediaan.kelola`.
 */
final class TransferStokKontroler extends DasarDokumenPersediaanKontroler
{
    private const ALAMAT = 'kelola.persediaan.transfer.detail';

    public function Daftar(Request $permintaan, DaftarTransferStok $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarTransferStok::KOLOM_URUT, DaftarTransferStok::URUT_BAWAAN, DaftarTransferStok::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Persediaan/Transfer/Daftar', 'Transfer', fn (): array => $daftar->AmbilTabel($tabel, $this->IdOutletBoleh()), fn (): array => [
            'OpsiGudang' => $this->AmbilOpsiGudangDokumen(hanyaAktif: false),
            'OpsiStatus' => array_map(fn (StatusTransferStok $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusTransferStok::cases()),
            'Izin' => $this->AmbilIzinDokumen(),
        ]);
    }

    public function Buat(Request $permintaan): Response
    {
        // D-95: tombol Muat/Bongkar di halaman Kanvas membawa lokasi bak kendaraan lewat `?asal=` / `?tujuan=` (uuid).
        $awal = $permintaan->query('arah') === 'retur-ke-gudang'
            ? $this->SusunAwalReturKeGudang()
            : ['UuidGudangAsal' => '', 'UuidGudangTujuan' => ''];

        foreach (['asal' => 'UuidGudangAsal', 'tujuan' => 'UuidGudangTujuan'] as $kunci => $kolom) {
            $uuid = $permintaan->query($kunci);

            if (is_string($uuid) && $uuid !== '') {
                $awal[$kolom] = $this->CariGudangTenant($uuid)->uuid;
            }
        }

        return $this->RenderForm('Buat', null, $awal);
    }

    public function Simpan(SimpanTransferStokPermintaan $permintaan, SimpanTransferStok $simpan): RedirectResponse
    {
        $asal = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudangAsal'));
        $tujuan = $this->CariGudangTenant((string) $permintaan->validated('UuidGudangTujuan'));
        $transfer = $simpan->Jalankan($permintaan->AmbilData($asal->id, $tujuan->id), null);
        $hasil = redirect()->route(self::ALAMAT, ['transferStok' => $transfer->Uuid])
            ->with('Kilat', 'Draf transfer disimpan. Periksa lagi, lalu kirim supaya stok berpindah ke lokasi dalam perjalanan.');

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Kirim($transfer->Uuid, app(KirimTransferStok::class)), self::ALAMAT, ['transferStok' => $transfer->Uuid]);
    }

    public function Detail(string $transferStok, DetailTransferStok $detail): Response
    {
        $t = $this->CariTransfer($transferStok);
        $kelola = $this->AmbilIzinDokumen()['Kelola'];
        $asal = $this->CekBolehOutlet($t->IdOutletAsal);
        $tujuan = $this->CekBolehOutlet($t->IdOutletTujuan);

        return Inertia::render('Kelola/Persediaan/Transfer/Detail', [
            ...$detail->Ambil($t),
            'Tindakan' => [
                'Ubah' => $kelola && $asal && $t->Status === StatusTransferStok::Draf,
                'Kirim' => $kelola && $asal && $t->Status === StatusTransferStok::Draf,
                'Batalkan' => $kelola && $asal && $t->Status === StatusTransferStok::Draf,
                'Terima' => $kelola && $tujuan && $t->Status->CekDalamPerjalanan(),
                'Tutup' => $kelola && $tujuan && $t->Status->CekDalamPerjalanan(),
            ],
            'Izin' => $this->AmbilIzinDokumen(),
            'HariIni' => $this->HariIni(),
        ]);
    }

    /**
     * Cetak A4 surat jalan transfer (D-94): tanpa nilai barang, dengan kolom tanda tangan pengirim, pembawa, dan penerima.
     * Draf belum punya pergerakan stok sehingga tidak dicetak; yang dibatalkan tetap bisa dibuka dengan tanda DIBATALKAN.
     */
    public function Cetak(string $transferStok, DokumenCetakTransferStok $cetak, ProfilTenant $profil): Response
    {
        $t = $this->CariTransfer($transferStok);
        abort_if($t->Status === StatusTransferStok::Draf, 409, 'Transfer masih draf. Kirim dulu supaya surat jalannya bisa dicetak.');
        $usaha = $profil->Ambil($this->IdTenant());

        return Inertia::render('Kelola/Persediaan/Transfer/Cetak', [
            ...$cetak->Ambil($t),
            'Usaha' => ['Nama' => $usaha['Nama'], 'Npwp' => $usaha['Npwp']],
        ]);
    }

    /**
     * Daftar ambil barang (*picking list*) untuk petugas gudang asal (D-95): barang & jumlah yang harus diambil dari rak,
     * dengan kotak centang. Berguna sebelum barang dikirim, jadi hanya untuk draf dan transfer yang masih dalam perjalanan.
     */
    public function CetakAmbil(string $transferStok, DokumenCetakTransferStok $cetak, ProfilTenant $profil): Response
    {
        $t = $this->CariTransfer($transferStok);
        abort_unless($t->Status === StatusTransferStok::Draf || $t->Status->CekDalamPerjalanan(), 409, 'Daftar ambil barang hanya untuk transfer yang masih draf atau dalam perjalanan.');
        $usaha = $profil->Ambil($this->IdTenant());

        return Inertia::render('Kelola/Persediaan/Transfer/CetakAmbil', [
            ...$cetak->Ambil($t),
            'Usaha' => ['Nama' => $usaha['Nama'], 'Npwp' => $usaha['Npwp']],
        ]);
    }

    public function Ubah(string $transferStok, DetailTransferStok $detail): Response|RedirectResponse
    {
        $t = $this->CariTransfer($transferStok, asal: true);

        if ($t->Status !== StatusTransferStok::Draf) {
            return redirect()->route(self::ALAMAT, ['transferStok' => $t->Uuid])->withErrors(['Umum' => "Transfer berstatus {$t->Status->AmbilLabel()} tidak bisa diubah."]);
        }

        return $this->RenderForm('Ubah', $detail->AmbilForm($t));
    }

    public function Perbarui(SimpanTransferStokPermintaan $permintaan, string $transferStok, SimpanTransferStok $simpan): RedirectResponse
    {
        $t = $this->CariTransfer($transferStok, asal: true);
        $asal = $this->CariGudangBoleh((string) $permintaan->validated('UuidGudangAsal'));
        $tujuan = $this->CariGudangTenant((string) $permintaan->validated('UuidGudangTujuan'));
        $t = $simpan->Jalankan($permintaan->AmbilData($asal->id, $tujuan->id), $t);
        $hasil = redirect()->route(self::ALAMAT, ['transferStok' => $t->Uuid])->with('Kilat', 'Draf transfer disimpan.');

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Kirim($t->Uuid, app(KirimTransferStok::class)), self::ALAMAT, ['transferStok' => $t->Uuid]);
    }

    public function Kirim(string $transferStok, KirimTransferStok $kirim): RedirectResponse
    {
        $t = $kirim->Jalankan($this->CariTransfer($transferStok, asal: true), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['transferStok' => $t->Uuid])
            ->with('Kilat', "Transfer {$t->Nomor} dikirim. Stok berada di lokasi dalam perjalanan sampai diterima.");
    }

    public function Terima(TerimaTransferStokPermintaan $permintaan, string $transferStok, TerimaTransferStok $terima): RedirectResponse
    {
        $t = $terima->Jalankan($this->CariTransfer($transferStok, tujuan: true), $permintaan->AmbilBaris(), $permintaan->AmbilTanggal(), $this->Pelaku()->Id);
        $pesan = $t->Status === StatusTransferStok::Diterima
            ? "Transfer {$t->Nomor} diterima seluruhnya."
            : 'Penerimaan dicatat. Sisa barang masih dalam perjalanan; terima lagi atau tutup transfer dengan alasan selisih.';

        return redirect()->route(self::ALAMAT, ['transferStok' => $t->Uuid])->with('Kilat', $pesan);
    }

    public function Tutup(AlasanDokumenPersediaanPermintaan $permintaan, string $transferStok, TutupTransferStok $tutup): RedirectResponse
    {
        $t = $tutup->Jalankan($this->CariTransfer($transferStok, tujuan: true), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['transferStok' => $t->Uuid])
            ->with('Kilat', "Transfer {$t->Nomor} ditutup. Selisih dicatat sebagai susut.");
    }

    public function Batalkan(AlasanDokumenPersediaanPermintaan $permintaan, string $transferStok, BatalkanTransferStok $batalkan): RedirectResponse
    {
        $t = $batalkan->Jalankan($this->CariTransfer($transferStok, asal: true), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return redirect()->route(self::ALAMAT, ['transferStok' => $t->Uuid])->with('Kilat', 'Draf transfer dibatalkan. Stok tidak berubah.');
    }

    private function CariTransfer(string $uuid, bool $asal = false, bool $tujuan = false): TransferStok
    {
        $t = TransferStok::query()->where('Uuid', $uuid)->firstOrFail();
        abort_unless(app(DokumenPersediaanKebijakan::class)->CekBolehLihatTransfer($t, $this->IdOutletBoleh()), 404);
        abort_if($asal && ! $this->CekBolehOutlet($t->IdOutletAsal), 403, 'Hanya pengguna di outlet asal yang bisa mengubah atau mengirim transfer ini.');
        abort_if($tujuan && ! $this->CekBolehOutlet($t->IdOutletTujuan), 403, 'Hanya pengguna di outlet tujuan yang bisa menerima transfer ini.');

        return $t;
    }

    /**
     * D-96: pintasan "Retur ke Gudang" — stok sisa/tak laku dikembalikan dari area jual (Toko) ke Gudang di outlet
     * yang sama. Lokasi asal = Toko pertama yang boleh dipakai pengguna; tujuan = Gudang aktif di outlet itu.
     * Tidak ada pasangan yang cocok → kosong, pengguna memilih sendiri.
     *
     * @return array{UuidGudangAsal: string, UuidGudangTujuan: string}
     */
    private function SusunAwalReturKeGudang(): array
    {
        $tujuan = $this->AmbilOpsiGudangDokumen(semuaOutlet: true);

        foreach ($this->AmbilOpsiGudangDokumen() as $asal) {
            if ($asal['Jenis'] !== JenisGudang::Toko->value) {
                continue;
            }

            foreach ($tujuan as $g) {
                if ($g['Jenis'] === JenisGudang::Gudang->value && $g['NamaOutlet'] === $asal['NamaOutlet']) {
                    return ['UuidGudangAsal' => $asal['Uuid'], 'UuidGudangTujuan' => $g['Uuid']];
                }
            }
        }

        return ['UuidGudangAsal' => '', 'UuidGudangTujuan' => ''];
    }

    /**
     * @param  array<string, mixed>|null  $transfer
     * @param  array{UuidGudangAsal: string, UuidGudangTujuan: string}  $awal
     */
    private function RenderForm(string $mode, ?array $transfer, array $awal = ['UuidGudangAsal' => '', 'UuidGudangTujuan' => '']): Response
    {
        return Inertia::render('Kelola/Persediaan/Transfer/Form', [
            'Mode' => $mode,
            'Transfer' => $transfer,
            'Awal' => $awal,
            'OpsiGudangAsal' => $this->AmbilOpsiGudangDokumen(),
            'OpsiGudangTujuan' => $this->AmbilOpsiGudangDokumen(semuaOutlet: true),
            'HariIni' => $this->HariIni(),
            'BatasBaris' => (int) config('persediaan.Dokumen.MaksimalBaris', 500),
        ]);
    }
}
