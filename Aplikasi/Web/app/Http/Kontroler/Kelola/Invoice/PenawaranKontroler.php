<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Invoice;

use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pajak\Kueri\DaftarKelompokPajak;
use App\Domain\Penjualan\Aksi\AntrekanPengirimanPenawaran;
use App\Domain\Penjualan\Aksi\BatalkanPenawaran;
use App\Domain\Penjualan\Aksi\BuatInvoiceDariPenawaran;
use App\Domain\Penjualan\Aksi\PutuskanPenawaran;
use App\Domain\Penjualan\Aksi\SimpanPenawaran;
use App\Domain\Penjualan\Aksi\TerbitkanPenawaran;
use App\Domain\Penjualan\Enum\StatusPenawaran;
use App\Domain\Penjualan\Enum\StatusTampilPenawaran;
use App\Domain\Penjualan\Kueri\DaftarPenawaran;
use App\Domain\Penjualan\Kueri\DetailPenawaran;
use App\Domain\Penjualan\Kueri\DokumenPenawaran;
use App\Domain\Penjualan\Kueri\IsianFormPenawaran;
use App\Domain\Penjualan\Model\Penawaran;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Invoice\AlasanInvoicePermintaan;
use App\Http\Permintaan\Kelola\Penawaran\KeputusanPenawaranPermintaan;
use App\Http\Permintaan\Kelola\Penawaran\SimpanPenawaranPermintaan;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Penawaran harga (F-21 bagian 3, D-168, `/kelola/penawaran`): daftar, form draf (isi baris sama dengan invoice), detail,
 * terbit, batal, kirim WhatsApp, catat keputusan pelanggan, dijadikan invoice, dan cetak A4. Izin `invoice.kelola`. Penawaran
 * di outlet di luar akses pelaku = 404. Total & pajak dihitung server dari baris.
 */
final class PenawaranKontroler extends DasarKelolaKontroler
{
    public function Daftar(Request $permintaan, DaftarPenawaran $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPenawaran::KOLOM_URUT, DaftarPenawaran::URUT_BAWAAN, DaftarPenawaran::KOLOM_SARING);
        $hariIni = $this->HariIni();

        return ResponsTabel::Kirim($permintaan, 'Kelola/Penawaran/Daftar', 'Penawaran', fn (): array => $daftar->Ambil($tabel, $this->IdOutletBoleh(), $hariIni), fn (): array => [
            'OpsiStatus' => array_map(fn (StatusTampilPenawaran $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusTampilPenawaran::cases()),
            'Izin' => $this->AmbilIzin(),
            'HariIni' => $hariIni,
        ]);
    }

    /** Ekspor daftar penawaran sesuai saringan & cari yang aktif di tabel (Excel, CSV, atau cetak; maks. 5.000 baris). */
    public function Ekspor(Request $permintaan, DaftarPenawaran $daftar): SymfonyResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPenawaran::KOLOM_URUT, DaftarPenawaran::URUT_BAWAAN, DaftarPenawaran::KOLOM_SARING);
        $hariIni = $this->HariIni();
        $idOutlet = $this->IdOutletBoleh();
        $baris = $this->AmbilSemuaBarisTabel($tabel, fn (DataPermintaanTabel $t): array => $daftar->Ambil($t, $idOutlet, $hariIni));
        $kolom = [
            new KolomLaporan('Nomor', JenisKolom::Teks, 24), new KolomLaporan('Pelanggan', JenisKolom::Teks, 30),
            new KolomLaporan('Perihal', JenisKolom::Teks, 30), new KolomLaporan('Tanggal', JenisKolom::Tanggal),
            new KolomLaporan('Berlaku sampai', JenisKolom::Tanggal), new KolomLaporan('Status', JenisKolom::Teks, 20),
            new KolomLaporan('Total', JenisKolom::Uang, jumlahkan: true),
        ];
        $isi = array_map(fn (array $b): array => [
            $b['Nomor'] ?? 'Draf', $b['NamaPelanggan'], $b['Perihal'] ?? '', $b['Tanggal'], $b['BerlakuSampai'], $b['LabelStatusTampil'], $b['Total'],
        ], $baris);

        return $this->SajikanLaporan($permintaan, 'Daftar Penawaran', 'daftar-penawaran', $kolom, $isi, [['Per tanggal', CarbonImmutable::parse($hariIni)->format('d/m/Y')]]);
    }

    /** Form baru; `?salin=<uuid>` mengisi dari penawaran lain (revisi): hasilnya penawaran **baru**, bukan mengubah yang lama. */
    public function Buat(Request $permintaan, IsianFormPenawaran $isian): Response
    {
        $salin = $permintaan->query('salin');
        $isi = null;

        if (is_string($salin) && $salin !== '') {
            $isi = $isian->Draf($this->CariPenawaran($salin), salinan: true);
        }

        return $this->RenderForm($isi, $isian);
    }

    public function Simpan(SimpanPenawaranPermintaan $permintaan, SimpanPenawaran $simpan): RedirectResponse
    {
        $outlet = $this->CariOutlet((string) $permintaan->validated('UuidOutlet'));
        $penawaran = $simpan->Jalankan($permintaan->AmbilData($outlet->Id), $this->Pelaku()->Id);
        $hasil = to_route('kelola.penawaran.detail', ['penawaran' => $penawaran->Uuid])->with('Kilat', 'Draf penawaran disimpan. Periksa angkanya, lalu terbitkan.');

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Terbitkan($penawaran->Uuid, app(TerbitkanPenawaran::class)), 'kelola.penawaran.detail', ['penawaran' => $penawaran->Uuid]);
    }

    public function Detail(string $penawaran, DetailPenawaran $detail): Response
    {
        $dokumen = $this->CariPenawaran($penawaran);
        $izin = $this->AmbilIzin();
        $hariIni = $this->HariIni();
        $isi = $detail->Ambil($dokumen, $hariIni);
        $tampil = StatusTampilPenawaran::from($isi['Penawaran']['StatusTampil']);
        $terbit = $dokumen->Status === StatusPenawaran::Terbit;
        $invoiceTerbit = ($isi['Penawaran']['Invoice']['Status'] ?? null) === 'Terbit';

        return Inertia::render('Kelola/Penawaran/Detail', [
            ...$isi,
            'Izin' => $izin,
            'HariIni' => $hariIni,
            'Tindakan' => [
                'Ubah' => $izin['Kelola'] && $dokumen->Status->CekBolehDiubah(),
                'Terbitkan' => $izin['Kelola'] && $dokumen->Status === StatusPenawaran::Draf,
                'Batalkan' => $izin['Kelola'] && $dokumen->Status !== StatusPenawaran::Dibatalkan && $dokumen->Status !== StatusPenawaran::Ditolak && ! $invoiceTerbit && $tampil !== StatusTampilPenawaran::DiInvoicekan,
                'Kirim' => $izin['Kelola'] && $tampil === StatusTampilPenawaran::MenungguKeputusan,
                'Putuskan' => $izin['Kelola'] && $terbit,
                'JadikanInvoice' => $izin['Kelola'] && in_array($dokumen->Status, [StatusPenawaran::Terbit, StatusPenawaran::Diterima], true) && ! $invoiceTerbit,
                'Salin' => $izin['Kelola'] && $dokumen->Status !== StatusPenawaran::Draf,
            ],
        ]);
    }

    public function Ubah(string $penawaran, IsianFormPenawaran $isian): Response|RedirectResponse
    {
        $dokumen = $this->CariPenawaran($penawaran);

        if (! $dokumen->Status->CekBolehDiubah()) {
            return to_route('kelola.penawaran.detail', ['penawaran' => $dokumen->Uuid])
                ->withErrors(['Umum' => "Penawaran berstatus {$dokumen->Status->AmbilLabel()} tidak bisa diubah. Salin menjadi penawaran baru bila perlu revisi."]);
        }

        return $this->RenderForm($isian->Draf($dokumen), $isian);
    }

    public function Perbarui(SimpanPenawaranPermintaan $permintaan, string $penawaran, SimpanPenawaran $simpan): RedirectResponse
    {
        $dokumen = $this->CariPenawaran($penawaran);
        $outlet = $this->CariOutlet((string) $permintaan->validated('UuidOutlet'));
        $simpan->Jalankan($permintaan->AmbilData($outlet->Id), $this->Pelaku()->Id, $dokumen->Uuid);
        $hasil = to_route('kelola.penawaran.detail', ['penawaran' => $dokumen->Uuid])->with('Kilat', 'Draf penawaran disimpan.');

        return $this->LanjutkanSetelahSimpan($permintaan, $hasil, fn () => $this->Terbitkan($dokumen->Uuid, app(TerbitkanPenawaran::class)), 'kelola.penawaran.detail', ['penawaran' => $dokumen->Uuid]);
    }

    public function TerbitkanDraf(string $penawaran, TerbitkanPenawaran $terbitkan): RedirectResponse
    {
        return $this->Terbitkan($this->CariPenawaran($penawaran)->Uuid, $terbitkan);
    }

    public function Batalkan(AlasanInvoicePermintaan $permintaan, string $penawaran, BatalkanPenawaran $batalkan): RedirectResponse
    {
        $dokumen = $this->CariPenawaran($penawaran);
        $hasil = $batalkan->Jalankan($dokumen->Uuid, $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.penawaran.detail', ['penawaran' => $hasil->Uuid])->with('Kilat', 'Penawaran '.($hasil->Nomor ?? '(draf)').' dibatalkan.');
    }

    /** Kirim penawaran ke WhatsApp pelanggan; kiriman berjalan di antrean. */
    public function Kirim(string $penawaran, AntrekanPengirimanPenawaran $antrekan): RedirectResponse
    {
        $dokumen = $this->CariPenawaran($penawaran);
        $antrekan->Jalankan($this->IdTenant(), $dokumen, $this->Pelaku()->Id);

        return to_route('kelola.penawaran.detail', ['penawaran' => $dokumen->Uuid])->with('Kilat', 'Penawaran dikirim ke WhatsApp pelanggan.');
    }

    /** Mencatat keputusan yang diterima penagih lewat jalur lain (telepon, email). */
    public function Putuskan(KeputusanPenawaranPermintaan $permintaan, string $penawaran, PutuskanPenawaran $putuskan): RedirectResponse
    {
        $dokumen = $this->CariPenawaran($penawaran);
        $keputusan = $permintaan->AmbilKeputusan();
        $putuskan->Jalankan($dokumen->Id, $keputusan, Penawaran::SUMBER_STAF, $permintaan->validated('Nama'), $permintaan->validated('Catatan'), $this->Pelaku()->Id);

        return to_route('kelola.penawaran.detail', ['penawaran' => $dokumen->Uuid])->with('Kilat', $keputusan->AmbilStatus() === StatusPenawaran::Diterima ? 'Penawaran ditandai diterima.' : 'Penawaran ditandai ditolak.');
    }

    /** Menyalin isi penawaran menjadi draf invoice (tanggal hari ini, angka dihitung ulang) untuk diperiksa lalu diterbitkan. */
    public function JadikanInvoice(string $penawaran, BuatInvoiceDariPenawaran $buat): RedirectResponse
    {
        $dokumen = $this->CariPenawaran($penawaran);
        $invoice = $buat->Jalankan($dokumen->Uuid, $this->Pelaku()->Id);

        return to_route('kelola.invoice.detail', ['invoice' => $invoice->Uuid])->with('Kilat', 'Draf invoice dibuat dari penawaran '.$dokumen->Nomor.'. Periksa angkanya, lalu terbitkan.');
    }

    /** Cetak A4: satu keluaran dengan halaman publik. */
    public function Cetak(string $penawaran, DokumenPenawaran $dokumen): Response
    {
        $model = $this->CariPenawaran($penawaran);
        abort_if($model->Status === StatusPenawaran::Draf && ! $this->CekIzin(IzinTenant::InvoiceKelola), 404);

        return Inertia::render('Kelola/Penawaran/Cetak', [...$dokumen->Ambil($model, $this->HariIni()), 'Uuid' => $model->Uuid]);
    }

    private function Terbitkan(string $uuid, TerbitkanPenawaran $terbitkan): RedirectResponse
    {
        $hasil = $terbitkan->Jalankan($uuid, $this->Pelaku()->Id);

        return to_route('kelola.penawaran.detail', ['penawaran' => $hasil->Uuid])->with('Kilat', "Penawaran {$hasil->Nomor} diterbitkan. Kirim ke pelanggan lewat WhatsApp atau bagikan tautannya.");
    }

    private function CariPenawaran(string $uuid): Penawaran
    {
        $dokumen = Penawaran::query()->where('Uuid', $uuid)->firstOrFail();
        $boleh = $this->IdOutletBoleh();
        abort_if($boleh !== null && ! in_array($dokumen->IdOutlet, $boleh, true), 404);

        return $dokumen;
    }

    private function HariIni(): string
    {
        return app(TanggalBisnisOutlet::class)->Hitung(null)->format('Y-m-d');
    }

    private function CekIzin(IzinTenant $izin): bool
    {
        return app(AksesPengguna::class)->CekIzin($this->IdTenant(), $this->Pelaku()->Id, $izin);
    }

    /**
     * @return array{Kelola: bool}
     */
    private function AmbilIzin(): array
    {
        return ['Kelola' => $this->CekIzin(IzinTenant::InvoiceKelola)];
    }

    /**
     * @param  array<string, mixed>|null  $isian
     */
    private function RenderForm(?array $isian, IsianFormPenawaran $kueri): Response
    {
        $peta = app(PetaUuidOutlet::class);
        $ringkas = $peta->AmbilRingkas($this->IdOutletBoleh(), true);
        $identitas = $peta->AmbilIdentitas(array_column($ringkas, 'Id'));

        return Inertia::render('Kelola/Penawaran/Form', [
            'Isian' => $isian,
            'Kebiasaan' => $isian === null || ($isian['Uuid'] ?? null) === null ? $kueri->Kebiasaan() : null,
            'MasaBerlakuBawaan' => IsianFormPenawaran::MASA_BERLAKU_BAWAAN,
            'OpsiOutlet' => array_map(fn (array $o): array => [...$o, 'Kode' => $identitas[$o['Id']]['Kode'] ?? ''], $ringkas),
            'OpsiKelompokPajak' => array_map(fn (array $k): array => ['Uuid' => $k['Uuid'], 'Nama' => $k['Nama']], app(DaftarKelompokPajak::class)->AmbilOpsi()),
            'HariIni' => $this->HariIni(),
            'Izin' => $this->AmbilIzin(),
        ]);
    }
}
