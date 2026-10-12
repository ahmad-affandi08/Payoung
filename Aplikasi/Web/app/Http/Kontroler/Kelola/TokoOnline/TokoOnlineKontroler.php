<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\TokoOnline;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\NamaTampilUsaha;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Domain\Organisasi\Layanan\PembuatQrMeja;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pemenuhan\Aksi\AturTautanPortalKurir;
use App\Domain\Pemenuhan\Aksi\SimpanKurir;
use App\Domain\Pemenuhan\Aksi\UbahStatusPengirimanPesanan;
use App\Domain\Pemenuhan\Enum\JenisKurir;
use App\Domain\Pemenuhan\Enum\StatusKurir;
use App\Domain\Pemenuhan\Enum\StatusPengirimanPesanan;
use App\Domain\Pemenuhan\Layanan\PenyimpanBuktiPengiriman;
use App\Domain\Pemenuhan\Model\Kurir;
use App\Domain\Pemenuhan\Model\PengirimanPesanan;
use App\Domain\Penjualan\Aksi\KembalikanUangPesananOnline;
use App\Domain\Penjualan\Aksi\SimpanPengaturanTokoOnline;
use App\Domain\Penjualan\Aksi\SimpanZonaPengiriman;
use App\Domain\Penjualan\Aksi\UbahStatusPesananOnline;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Layanan\PenentuAkunTokoOnline;
use App\Domain\Penjualan\Layanan\PenentuKonteksTokoOnline;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\ZonaPengiriman;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TokoOnlineKontroler extends DasarKelolaKontroler
{
    public function Tampilkan(PetaUuidOutlet $peta, ProfilTenant $profil, DaftarAkunPilihan $akun, AksesPengguna $akses, IdentitasPelanggan $identitas, PenentuAkunTokoOnline $akunPembeli, PembuatQrMeja $qr): Response
    {
        $boleh = $this->IdOutletBoleh();
        $outlet = $peta->AmbilRingkas($boleh, hanyaAktif: true);
        $idOutlet = array_column($outlet, 'Id');
        $pesanan = PesananOnline::query()->with('Detail')->whereIn('IdOutlet', $idOutlet)->latest('DibuatPada')->limit(100)->get();
        $pengiriman = PengirimanPesanan::query()->whereIn('IdOutlet', $idOutlet)->get()->keyBy('IdPesananOnline');
        $uuidOutlet = $peta->Ambil($idOutlet);
        $daftarKurir = Kurir::query()->orderBy('Nama')->get();
        $slug = $profil->AmbilSlug($this->IdTenant());
        $uuidKurir = $daftarKurir->pluck('Uuid', 'Id');
        $atur = PengaturanTokoOnline::query()->first() ?? new PengaturanTokoOnline;
        $bolehRefund = $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::AkuntansiKelola);
        // F-17 bagian 3: pesanan pembeli yang masuk tertaut ke data pelanggan toko.
        $pelanggan = $identitas->AmbilNamaBanyak(array_values(array_filter($pesanan->pluck('IdPelanggan')->all(), 'is_int')));

        return Inertia::render('Kelola/TokoOnline/Daftar', [
            'TautanPublik' => url('/'.$profil->AmbilSlug($this->IdTenant())),
            // D-110: QR tautan toko untuk dibagikan atau dicetak.
            'QrSvg' => $qr->BuatSvg(url('/'.$profil->AmbilSlug($this->IdTenant()))),
            'Pengaturan' => $atur->only(['Aktif', 'BayarSaatAmbilAktif', 'CodAktif', 'QrisAktif', 'AkunPelangganAktif', 'NotifikasiWhatsappAktif', 'MinimalPesanan', 'MenitKedaluwarsa', 'PesanTutup']),
            // Sakelar akun pembeli hanya berarti bila platform punya WhatsApp aktif (kode masuk dikirim lewat sana).
            'AkunPembeliTersedia' => $akunPembeli->CekWhatsappTersedia(),
            // F-17 bagian 2: pengembalian uang muka memilih akun kas/bank, jadi daftarnya ikut dikirim.
            'OpsiAkun' => $bolehRefund ? $akun->AmbilKasBank() : [],
            'IzinRefund' => $bolehRefund,
            'Outlet' => array_map(function (array $o): array {
                $baris = $this->CariOutlet($o['Uuid']);

                return [...$o, ...$baris->only(['TokoOnlineAktif', 'AmbilSendiriAktif', 'KirimAktif'])];
            }, $outlet),
            'Zona' => ZonaPengiriman::query()->whereIn('IdOutlet', $idOutlet)->orderBy('Urutan')->orderBy('Nama')->get()->map(fn (ZonaPengiriman $z): array => [...$z->only(['Uuid', 'Nama', 'KodePos', 'Ongkir', 'GratisMulai', 'EstimasiHariMin', 'EstimasiHariMaks', 'Urutan', 'Aktif']), 'UuidOutlet' => $uuidOutlet[$z->IdOutlet] ?? null])->all(),
            // v3.49: tautan portal kurir (rahasia) hanya untuk staf berizin pengiriman.kelola yang memang membuka halaman ini.
            'Kurir' => $daftarKurir->map(fn (Kurir $k): array => [
                ...$k->only(['Uuid', 'Nama', 'Jenis', 'NamaPenyedia', 'Status']), 'NoHp' => $k->NoHp,
                'TautanPortal' => $k->TokenPortal === null ? null : url("/{$slug}/kurir/{$k->TokenPortal}"),
            ])->all(),
            'Pesanan' => $pesanan->map(function (PesananOnline $p) use ($pengiriman, $uuidOutlet, $uuidKurir, $pelanggan): array {
                $kirim = $pengiriman->get($p->Id);

                return [
                    ...$p->only(['Uuid', 'Nomor', 'NamaPelanggan', 'JenisPemenuhan', 'MetodePembayaran', 'Subtotal', 'Ongkir', 'DiskonOngkir', 'Total', 'Status', 'Catatan', 'IdPenjualan']),
                    'NoHp' => $p->NoHp, 'Alamat' => $p->Alamat, 'Kelurahan' => $p->Kelurahan,
                    'Kecamatan' => $p->Kecamatan, 'Kota' => $p->Kota, 'Provinsi' => $p->Provinsi,
                    'KodePos' => $p->KodePos, 'UuidOutlet' => $uuidOutlet[$p->IdOutlet] ?? null,
                    'Pelanggan' => $p->IdPelanggan === null ? null : ($pelanggan[$p->IdPelanggan] ?? null),
                    'DibuatPada' => $p->DibuatPada?->toIso8601String(),
                    'DibayarPada' => $p->DibayarPada?->toIso8601String(),
                    'JumlahDibayar' => $p->JumlahDibayar, 'UangMukaTerpakai' => $p->UangMukaTerpakai,
                    'SisaUangMuka' => $p->AmbilSisaUangMuka()->KeString(),
                    'DikembalikanPada' => $p->DikembalikanPada?->toIso8601String(),
                    'Baris' => $p->Detail->map(fn ($d): array => $d->only(['NamaProduk', 'Jumlah', 'HargaSatuan', 'HargaPilihan', 'Pilihan', 'Catatan', 'TotalBaris']))->all(),
                    'Pengiriman' => $kirim === null ? null : [
                        ...$kirim->only(['Uuid', 'NamaPenyedia', 'NomorResi', 'Status', 'NamaPenerima', 'Alasan']),
                        'UuidKurir' => $kirim->IdKurir === null ? null : $uuidKurir->get($kirim->IdKurir),
                        'AdaBukti' => $kirim->PathBukti !== null,
                    ],
                ];
            })->all(),
            'OpsiStatusPesanan' => array_map(fn (StatusPesananOnline $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusPesananOnline::PilihanStaf()),
            'OpsiStatusPengiriman' => array_map(fn (StatusPengirimanPesanan $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusPengirimanPesanan::cases()),
        ]);
    }

    /** D-110: poster A5 berisi QR dan tautan toko online, dicetak atau disimpan PDF lewat peramban. */
    public function Poster(ProfilTenant $profil, PembuatQrMeja $qr, PenentuKonteksTokoOnline $penentu, NamaTampilUsaha $namaTampil): Response
    {
        $idTenant = $this->IdTenant();
        $url = url('/'.$profil->AmbilSlug($idTenant));
        $konteks = $penentu->Cari();

        return Inertia::render('Kelola/TokoOnline/Poster', [
            'NamaToko' => $konteks === null ? $profil->Ambil($idTenant)['Nama'] : $namaTampil->UntukOutlet($konteks->idOutlet, $idTenant),
            'Tautan' => $url,
            'QrSvg' => $qr->BuatSvg($url),
            'Aktif' => $konteks?->aktif === true,
        ]);
    }

    public function SimpanPengaturan(Request $request, SimpanPengaturanTokoOnline $simpan): RedirectResponse
    {
        $valid = $request->validate([
            'Outlet' => ['required', 'ulid'], 'Aktif' => ['required', 'boolean'], 'TokoOnlineAktif' => ['required', 'boolean'],
            'AmbilSendiriAktif' => ['required', 'boolean'], 'KirimAktif' => ['required', 'boolean'],
            'BayarSaatAmbilAktif' => ['required', 'boolean'], 'CodAktif' => ['required', 'boolean'],
            'QrisAktif' => ['required', 'boolean'], 'AkunPelangganAktif' => ['sometimes', 'boolean'], 'NotifikasiWhatsappAktif' => ['sometimes', 'boolean'],
            'MinimalPesanan' => ['required', 'decimal:0,2', 'min:0'], 'MenitKedaluwarsa' => ['required', 'integer', 'min:15', 'max:1440'],
            'PesanTutup' => ['nullable', 'string', 'max:255'],
        ]);
        $simpan->Jalankan($this->CariOutlet((string) $valid['Outlet']), $valid, $this->Pelaku()->Id);

        return back()->with('Kilat', 'Pengaturan toko online disimpan.');
    }

    /**
     * F-17 bagian 2 (J-17.2): mencatat pengembalian uang muka pesanan online yang tidak jadi. Uangnya dipindahkan
     * sendiri oleh toko; halaman ini hanya membukukannya. Butuh `akuntansi.kelola` karena memilih akun kas/bank.
     */
    public function KembalikanUang(Request $request, string $pesanan, KembalikanUangPesananOnline $kembalikan): RedirectResponse
    {
        $valid = $request->validate([
            'UuidAkun' => ['required', 'ulid'],
            'Alasan' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $kembalikan->Jalankan($this->CariPesanan($pesanan), (string) $valid['UuidAkun'], (string) $valid['Alasan'], $this->Pelaku()->Id);

        return back()->with('Kilat', 'Pengembalian uang muka dicatat.');
    }

    public function SimpanZona(Request $request, SimpanZonaPengiriman $simpan): RedirectResponse
    {
        $valid = $this->ValidasiZona($request);
        $simpan->Jalankan($this->CariOutlet((string) $valid['Outlet']), $valid, $this->Pelaku()->Id);

        return back()->with('Kilat', 'Zona pengiriman ditambahkan.');
    }

    public function PerbaruiZona(Request $request, string $zona, SimpanZonaPengiriman $simpan): RedirectResponse
    {
        $baris = ZonaPengiriman::query()->where('Uuid', strtoupper($zona))->firstOrFail();
        $valid = $this->ValidasiZona($request);
        $outlet = $this->CariOutlet((string) $valid['Outlet']);
        abort_if($baris->IdOutlet !== $outlet->Id, 404);
        $simpan->Jalankan($outlet, $valid, $this->Pelaku()->Id, $baris);

        return back()->with('Kilat', 'Zona pengiriman diperbarui.');
    }

    public function SimpanKurir(Request $request, SimpanKurir $simpan): RedirectResponse
    {
        $valid = $this->ValidasiKurir($request);
        $simpan->Jalankan($valid, $this->Pelaku()->Id);

        return back()->with('Kilat', 'Kurir ditambahkan.');
    }

    public function PerbaruiKurir(Request $request, string $kurir, SimpanKurir $simpan): RedirectResponse
    {
        $baris = Kurir::query()->where('Uuid', strtoupper($kurir))->firstOrFail();
        $simpan->Jalankan($this->ValidasiKurir($request), $this->Pelaku()->Id, $baris);

        return back()->with('Kilat', 'Kurir diperbarui.');
    }

    public function BuatTautanPortal(string $kurir, AturTautanPortalKurir $atur): RedirectResponse
    {
        $atur->Jalankan(Kurir::query()->where('Uuid', strtoupper($kurir))->firstOrFail(), true, $this->Pelaku()->Id);

        return back()->with('Kilat', 'Tautan portal kurir dibuat. Tautan lama tidak berlaku lagi.');
    }

    public function CabutTautanPortal(string $kurir, AturTautanPortalKurir $atur): RedirectResponse
    {
        $atur->Jalankan(Kurir::query()->where('Uuid', strtoupper($kurir))->firstOrFail(), false, $this->Pelaku()->Id);

        return back()->with('Kilat', 'Tautan portal kurir dicabut.');
    }

    public function UnduhBukti(string $pengiriman, PenyimpanBuktiPengiriman $bukti): StreamedResponse
    {
        $k = PengirimanPesanan::query()->where('Uuid', strtoupper($pengiriman))->firstOrFail();
        $this->PastikanOutlet($k->IdOutlet);

        return $bukti->Unduh($k->PathBukti);
    }

    public function UbahStatusPesanan(Request $request, string $pesanan, UbahStatusPesananOnline $ubah): RedirectResponse
    {
        $p = $this->CariPesanan($pesanan);
        $valid = $request->validate([
            'Status' => ['required', Rule::enum(StatusPesananOnline::class)->only(StatusPesananOnline::PilihanStaf())],
            'Alasan' => ['nullable', 'string', 'max:255'],
        ]);
        $ubah->Jalankan($p, StatusPesananOnline::from((string) $valid['Status']), $this->Pelaku()->Id, $valid['Alasan'] ?? null);

        return back()->with('Kilat', "Status {$p->Nomor} diperbarui.");
    }

    public function UbahStatusPengiriman(Request $request, string $pengiriman, UbahStatusPengirimanPesanan $ubah): RedirectResponse
    {
        $k = PengirimanPesanan::query()->where('Uuid', strtoupper($pengiriman))->firstOrFail();
        $p = PesananOnline::query()->findOrFail($k->IdPesananOnline);
        $this->PastikanOutlet($p->IdOutlet);
        $valid = $request->validate([
            'Status' => ['required', Rule::enum(StatusPengirimanPesanan::class)], 'Kurir' => ['nullable', 'ulid'],
            'NamaPenyedia' => ['nullable', 'string', 'max:100'], 'NomorResi' => ['nullable', 'string', 'max:100'],
            'NamaPenerima' => ['nullable', 'string', 'max:100'], 'Alasan' => ['nullable', 'string', 'max:255'],
        ]);
        $idKurir = isset($valid['Kurir']) ? Kurir::query()->where('Uuid', strtoupper((string) $valid['Kurir']))->value('Id') : null;
        $ubah->Jalankan($k, $p, StatusPengirimanPesanan::from((string) $valid['Status']), [...$valid, 'IdKurir' => $idKurir], $this->Pelaku()->Id);

        return back()->with('Kilat', "Pengiriman {$p->Nomor} diperbarui.");
    }

    /** @return array<string, mixed> */
    private function ValidasiZona(Request $request): array
    {
        $valid = $request->validate([
            'Outlet' => ['required', 'ulid'], 'Nama' => ['required', 'string', 'max:100'],
            'KodePos' => ['required', 'array', 'min:1', 'max:200'], 'KodePos.*' => ['required', 'digits:5', 'distinct'],
            'Ongkir' => ['required', 'decimal:0,2', 'min:0'], 'GratisMulai' => ['nullable', 'decimal:0,2', 'min:0'],
            'EstimasiHariMin' => ['required', 'integer', 'min:0', 'max:30'], 'EstimasiHariMaks' => ['required', 'integer', 'gte:EstimasiHariMin', 'max:30'],
            'Urutan' => ['required', 'integer', 'min:0', 'max:65535'], 'Aktif' => ['required', 'boolean'],
        ]);

        return $valid;
    }

    /** @return array<string, mixed> */
    private function ValidasiKurir(Request $request): array
    {
        return $request->validate([
            'Nama' => ['required', 'string', 'max:100'], 'NoHp' => ['nullable', 'string', 'max:30'],
            'Jenis' => ['required', Rule::enum(JenisKurir::class)], 'NamaPenyedia' => ['nullable', 'string', 'max:100'],
            'Status' => ['required', Rule::enum(StatusKurir::class)],
        ]);
    }

    private function CariPesanan(string $uuid): PesananOnline
    {
        $p = PesananOnline::query()->where('Uuid', strtoupper($uuid))->firstOrFail();
        $this->PastikanOutlet($p->IdOutlet);

        return $p;
    }

    private function PastikanOutlet(int $idOutlet): void
    {
        $boleh = $this->IdOutletBoleh();
        abort_if($boleh !== null && ! in_array($idOutlet, $boleh, true), 404);
    }
}
