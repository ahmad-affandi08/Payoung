<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Layanan;

/**
 * Registri data pribadi tenant untuk penghapusan data / penutupan akun (UU PDP No. 27/2022, P-07). Aturannya: data
 * pribadi dan rahasia dihapus; dokumen transaksi dan pembukuan dipertahankan (retensi pajak 10 tahun, CLAUDE.md #8) dengan
 * identitasnya dianonimkan, sehingga angka, jurnal, dan stok tetap utuh. Dijaga `PenghapusanDataTes`: kolom berpola data
 * pribadi di tabel baru yang belum tercatat di sini (diganti, dipertahankan beralasan, atau tabelnya dihapus) membuat
 * test gagal, jadi tabel baru tidak bisa lolos tanpa keputusan tentang data pribadinya.
 *
 * Kode strategi pengganti nilai kolom:
 * - `null`  : dikosongkan (kolom wajib nullable)
 * - `nol`   : diisi 0
 * - `teks:X`: diisi teks tetap X
 * - `unik:X`: diisi X lalu Id baris (untuk kolom unik atau yang perlu dibedakan)
 * - `enkripsi`: diisi teks tetap terenkripsi (kolom wajib berisi dan dibaca lewat cast `encrypted`)
 * - `sekarang`: diisi waktu sekarang bila masih kosong
 */
final class RegistriAnonimisasi
{
    /**
     * Tabel yang barisnya dihapus (kredensial, token, biometrik, pesan terkirim, pengguna dan keanggotaan, berkas ekspor).
     * Berurutan: tabel anak sebelum induknya. Tidak ada tabel dokumen transaksi di sini.
     *
     * @var list<string>
     */
    public const HAPUS_BARIS = [
        'KirimanWebhook', 'WebhookTenant', 'TokenApiTenant', 'GerbangPembayaranTenant', 'WhatsappTenant',
        'KodeAktivasi', 'KodeMasukPelanggan', 'SesiPelangganOnline', 'UndanganPengguna', 'KunciIdempotensi',
        'WajahKaryawan', 'PendaftaranMerchantPembayaran', 'EksporDataTenant', 'BerkasUnduhan', 'CatatanTenant', 'NotifikasiPengguna',
        'PesanKeluar', 'PengingatPiutang', 'PengirimanPesananPembelian', 'PengirimanInvoice', 'PengirimanPenawaran', 'NotifikasiPesananOnline', 'PenerimaKampanye', 'KampanyePesan',
        'OutletPengguna', 'TenantPengguna',
    ];

    /**
     * Tabel => kolom => strategi. Kunci tenant tabel biasa `IdTenant`; `Tenant` memakai `Id` (lihat KUNCI_KHUSUS).
     *
     * @var array<string, array<string, string>>
     */
    public const ANONIMKAN = [
        'Pelanggan' => [
            'Nama' => 'teks:Pelanggan Terhapus', 'NoHp' => 'unik:terhapus-', 'NoHpTerverifikasiPada' => 'null', 'Email' => 'null',
            'TanggalLahir' => 'null', 'Alamat' => 'null', 'Npwp' => 'null', 'Nik' => 'null', 'NamaNpwp' => 'null',
            'AlamatNpwp' => 'null', 'Tag' => 'null', 'Catatan' => 'null', 'SetujuPemasaran' => 'nol',
        ],
        'Karyawan' => [
            'Nama' => 'unik:Karyawan Terhapus #', 'GajiPokok' => 'null', 'TokenAbsen' => 'null', 'HashTokenAbsen' => 'null',
            'TokenAbsenDibuatPada' => 'null', 'TarifLemburPerJam' => 'nol', 'PotonganTerlambatPerMenit' => 'nol',
            'PotonganTidakMasukPerHari' => 'nol',
        ],
        'Kurir' => [
            'Nama' => 'unik:Kurir Terhapus #', 'NoHp' => 'null', 'TokenPortal' => 'null', 'HashTokenPortal' => 'null',
            'TokenPortalDibuatPada' => 'null',
        ],
        'Pemasok' => [
            'Nama' => 'unik:Pemasok Terhapus #', 'NamaKontak' => 'null', 'NoHp' => 'null', 'Email' => 'null', 'Alamat' => 'null',
            'Npwp' => 'null', 'NamaBank' => 'null', 'NomorRekening' => 'null', 'AtasNamaRekening' => 'null', 'Catatan' => 'null',
        ],
        // F-21 bagian 3: nama penyetuju & catatan yang diketik pelanggan di halaman publik penawaran.
        'Penawaran' => ['NamaPemutus' => 'null', 'CatatanKeputusan' => 'null'],
        // F-21 bagian 4: nama pengirim & catatan yang diketik pelanggan di halaman publik invoice.
        'KonfirmasiPembayaranInvoice' => ['NamaPengirim' => 'teks:Pelanggan Terhapus', 'Catatan' => 'null'],
        'Kendaraan' => [
            'NomorPolisi' => 'unik:TERHAPUS-', 'Warna' => 'null', 'NomorRangka' => 'null', 'NomorMesin' => 'null',
            'Catatan' => 'null',
        ],
        'PesananOnline' => [
            'NamaPelanggan' => 'teks:Pelanggan Terhapus', 'NoHp' => 'enkripsi', 'Email' => 'null', 'Alamat' => 'null',
            'Kelurahan' => 'null', 'Kecamatan' => 'null', 'Kota' => 'null', 'Provinsi' => 'null', 'KodePos' => 'null',
            'Catatan' => 'null', 'HashNoHp' => 'null', 'HashIp' => 'null', 'KodeAkses' => 'unik:terhapus-',
        ],
        'PesananOnlineDetail' => ['Catatan' => 'null'],
        'PesananSendiri' => ['NamaPemesan' => 'null', 'Catatan' => 'null', 'HashIp' => 'null'],
        'Penjualan' => ['NamaPemesan' => 'null', 'Catatan' => 'null'],
        'PenjualanDetail' => ['Catatan' => 'null'],
        'PesananTerbukaDetail' => ['Catatan' => 'null'],
        'TiketDapurDetail' => ['Catatan' => 'null'],
        'PesananPenjualan' => ['Catatan' => 'null'],
        'PesananPenjualanDetail' => ['Catatan' => 'null'],
        'Reservasi' => [
            'NamaPelanggan' => 'teks:Pelanggan Terhapus', 'NoHp' => 'teks:terhapus', 'Catatan' => 'null', 'KodeAkses' => 'unik:terhapus-',
        ],
        'ReservasiMeja' => ['NamaPemesan' => 'teks:Pelanggan Terhapus', 'NoHp' => 'teks:terhapus', 'Catatan' => 'null', 'AlasanBatal' => 'null'],
        'TiketLaundry' => ['NamaPelanggan' => 'teks:Pelanggan Terhapus', 'NoHp' => 'null', 'Catatan' => 'null'],
        'ResepPenjualan' => [
            'NamaDokter' => 'teks:Dokter Terhapus', 'NoSipDokter' => 'null', 'NamaPasien' => 'enkripsi', 'UmurPasien' => 'null',
            'AlamatPasien' => 'null',
        ],
        'PengirimanPesanan' => ['NamaPenerima' => 'null', 'PathBukti' => 'null'],
        'SuratJalan' => ['NamaPengirim' => 'null', 'NamaPenerima' => 'null', 'NomorKendaraan' => 'null', 'Catatan' => 'null'],
        'Giro' => ['NamaPihak' => 'teks:Pihak Terhapus'],
        'KunjunganSales' => ['Latitude' => 'null', 'Longitude' => 'null', 'AkurasiMeter' => 'null', 'Catatan' => 'null'],
        'Absensi' => [
            'PathSwafotoMasuk' => 'null', 'PathSwafotoKeluar' => 'null', 'AlasanKoreksi' => 'null',
            'LintangMasuk' => 'null', 'BujurMasuk' => 'null', 'AkurasiMasukMeter' => 'null', 'JarakMasukMeter' => 'null',
            'KemiripanWajahMasuk' => 'null', 'LintangKeluar' => 'null', 'BujurKeluar' => 'null', 'AkurasiKeluarMeter' => 'null',
            'JarakKeluarMeter' => 'null', 'KemiripanWajahKeluar' => 'null',
        ],
        'CutiKaryawan' => ['Alasan' => 'null', 'AlasanBatal' => 'null'],
        'MetodePembayaran' => ['NomorRekening' => 'null', 'NamaPemilikRekening' => 'null', 'PathGambarQris' => 'null'],
        'Perangkat' => [
            'Nama' => 'unik:Perangkat Terhapus #', 'TokenPush' => 'null', 'HashToken' => 'null', 'KunciPinOffline' => 'null',
            'DicabutPada' => 'sekarang',
        ],
        'Produk' => ['PathGambar' => 'null'],
        'Outlet' => [
            'Nama' => 'unik:Outlet Terhapus #', 'Alamat' => 'null', 'Lintang' => 'null', 'Bujur' => 'null', 'NomorKendaraan' => 'null',
            'TokenKios' => 'null', 'TokenLayarAbsen' => 'null', 'HashTokenLayarAbsen' => 'null', 'ProfilPajak' => 'null',
            'PengaturanKasir' => 'null', 'PesanSendiriAktif' => 'nol', 'TokoOnlineAktif' => 'nol', 'KiosAktif' => 'nol',
            'AmbilSendiriAktif' => 'nol', 'KirimAktif' => 'nol',
        ],
        'Merek' => ['Nama' => 'unik:Merek Terhapus #'],
        'Meja' => ['TokenPesanSendiri' => 'null'],
        'LogAudit' => ['Ip' => 'null', 'AgenPengguna' => 'null'],
        'PerintahKerja' => [
            'TokenPersetujuan' => 'null', 'HashTokenPersetujuan' => 'null', 'TokenPersetujuanKedaluwarsaPada' => 'null',
            'HashIpPersetujuan' => 'null', 'CatatanPelanggan' => 'null',
        ],
        'PerintahKerjaDetail' => ['Catatan' => 'null'],
        'PembayaranLangganan' => ['NamaPengirim' => 'null', 'EmailPemberitahuan' => 'null', 'NamaPemberitahuan' => 'null'],
        'TiketDukungan' => ['Judul' => 'teks:Tiket dihapus', 'Konteks' => 'null'],
        'TiketDukunganPesan' => [
            'NamaPengirim' => 'null', 'Isi' => 'teks:Isi pesan dihapus atas permintaan pemilik data.', 'Lampiran' => 'null',
        ],
        'ImporProduk' => ['PathBerkas' => 'teks:-', 'PesanGalat' => 'null'],
        'ImporStokAwal' => ['PathBerkas' => 'teks:-', 'PesanGalat' => 'null'],
        'Tenant' => [
            'Nama' => 'unik:Usaha Terhapus #', 'Slug' => 'unik:ditutup-', 'Npwp' => 'null', 'Pengaturan' => 'null',
            'Status' => 'teks:Ditutup',
        ],
    ];

    /** Tabel yang kunci tenantnya bukan `IdTenant`. */
    public const KUNCI_KHUSUS = ['Tenant' => 'Id'];

    /**
     * Nama class objek `LogAudit` yang nilai lama/barunya memuat data pribadi: kolom `NilaiLama`/`NilaiBaru` baris itu
     * dikosongkan (jejak "siapa melakukan apa kapan" tetap ada). `Ip` dan `AgenPengguna` semua baris dikosongkan lewat
     * entri `LogAudit` di ANONIMKAN.
     *
     * @var list<string>
     */
    public const JENIS_OBJEK_AUDIT_PRIBADI = [
        'Pelanggan', 'Pemasok', 'Karyawan', 'Kurir', 'Kendaraan', 'PesananOnline', 'PesananSendiri', 'Reservasi', 'ReservasiMeja', 'TiketLaundry',
        'ResepPenjualan', 'WajahKaryawan', 'CutiKaryawan', 'Pengguna', 'TenantPengguna', 'PendaftaranMerchantPembayaran', 'MetodePembayaran',
        'UndanganPengguna', 'Tenant', 'Outlet', 'SuratJalan', 'Giro', 'KunjunganSales', 'Absensi', 'Perangkat',
    ];

    /**
     * Kolom berpola data pribadi yang sengaja dipertahankan, beserta alasannya. Kuncinya `Tabel.Kolom`.
     *
     * @var array<string, string>
     */
    public const DIPERTAHANKAN = [
        'FakturPembelian.PathLampiran' => 'Bukti pembukuan: berkas dipertahankan selama masa retensi pajak.',
        'PembayaranHutang.PathLampiran' => 'Bukti pembukuan: berkas dipertahankan selama masa retensi pajak.',
        'PenerimaanBarang.PathLampiran' => 'Bukti pembukuan: berkas dipertahankan selama masa retensi pajak.',
        'MutasiKas.PathLampiran' => 'Bukti pembukuan: berkas dipertahankan selama masa retensi pajak.',
        'TransaksiKasBank.PathLampiran' => 'Bukti pembukuan: berkas dipertahankan selama masa retensi pajak.',
        'KonfirmasiPembayaranInvoice.PathBukti' => 'Bukti pembukuan: foto bukti transfer pelanggan dipertahankan selama masa retensi pajak.',
        'JurnalUmum.PathLampiran' => 'Bukti pembukuan: berkas dipertahankan selama masa retensi pajak.',
        'MutasiBank.SidikBaris' => 'Sidik (hash) baris rekening koran untuk mencegah impor ganda; bukan data pribadi. Keterangan mutasi adalah bukti pembukuan.',
        'PembayaranLangganan.PathBukti' => 'Bukti penerimaan pembayaran langganan milik platform (pendapatan platform), bukan milik tenant.',
        'PembayaranLangganan.NomorRekeningTujuan' => 'Rekening milik platform, bukan data tenant.',
        'PersetujuanDokumenLegal.Ip' => 'Bukti persetujuan dokumen legal; dasar pembelaan hukum yang sah (UU PDP pasal 20).',
        'LogAuditPengelola.Ip' => 'Alamat IP staf platform, bukan data tenant; jejak audit tindakan pengelola.',
        'SesiAksesDukungan.Ip' => 'Alamat IP petugas platform yang masuk lewat akses dukungan, bukan data tenant; jejak audit akses (PGL-16).',
        'SesiAksesDukungan.HashToken' => 'Sidik token tukar sekali pakai milik sesi petugas platform (berlaku 60 detik); bukan data pribadi.',
        'SesiAksesDukungan.TokenKedaluwarsaPada' => 'Batas waktu token tukar sesi petugas platform; bukan data pribadi.',
        'SesiAksesDukungan.TokenDipakaiPada' => 'Waktu token tukar sesi petugas platform dipakai; bukan data pribadi.',
    ];
}
