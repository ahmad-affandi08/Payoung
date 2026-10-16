import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanBaganAkun from '@/Halaman/Kelola/Akuntansi/Akun/Daftar';
import HalamanJadwalKasBank from '@/Halaman/Kelola/Akuntansi/KasBank/Berulang';
import HalamanBuatTransaksiKasBank from '@/Halaman/Kelola/Akuntansi/KasBank/Buat';
import HalamanDaftarTransaksiKasBank from '@/Halaman/Kelola/Akuntansi/KasBank/Daftar';
import HalamanDetailTransaksiKasBank from '@/Halaman/Kelola/Akuntansi/KasBank/Detail';
import HalamanArusKas from '@/Halaman/Kelola/Akuntansi/Laporan/ArusKas';
import HalamanBukuBesar from '@/Halaman/Kelola/Akuntansi/Laporan/BukuBesar';
import HalamanLabaRugi from '@/Halaman/Kelola/Akuntansi/Laporan/LabaRugi';
import HalamanNeraca from '@/Halaman/Kelola/Akuntansi/Laporan/Neraca';
import HalamanNeracaSaldo from '@/Halaman/Kelola/Akuntansi/Laporan/NeracaSaldo';
import HalamanPemetaanAkun from '@/Halaman/Kelola/Akuntansi/Pemetaan/Daftar';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { AmbilNilaiPilihan, UbahNilai } from '@/Pengujian/InteraksiPilihan';
import { AmbilHrefEkspor, BukaMenu } from '@/Pengujian/InteraksiRadix';
import { CekMenuAktif, SaringMenuTerlihat } from '@/TataLetak/TataLetakAplikasi';
import type {
    BarisJadwalKasBank,
    BarisBaganAkun,
    BarisTransaksiKasBank,
    PropsArusKas,
    PropsBaganAkun,
    PropsBukuBesar,
    PropsBuatTransaksiKasBank,
    PropsDaftarTransaksiKasBank,
    PropsDetailTransaksiKasBank,
    PropsLabaRugi,
    PropsNeraca,
    PropsNeracaSaldo,
    PropsPemetaanAkun,
} from '@/Tipe/Akuntansi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const akunDasar: BarisBaganAkun = {
    Uuid: '01K5AKUN000000000000000001',
    Kode: '1-1200',
    Nama: 'Bank',
    Jenis: 'Aset',
    LabelJenis: 'Aset',
    SaldoNormal: 'Debit',
    Kontra: false,
    KasBank: true,
    Aktif: true,
    Sistem: true,
    Kedalaman: 0,
    UuidInduk: null,
    KodeInduk: null,
    AdaJurnal: true,
    PeranDipetakan: ['Bank'],
    PunyaAnak: true,
    BisaDihapus: false,
};

const propsBagan: PropsBaganAkun = {
    Akun: [
        akunDasar,
        {
            ...akunDasar,
            Uuid: '01K5AKUN000000000000000002',
            Kode: '1-1210',
            Nama: 'Bank BRI Cabang Solo Baru Giro Operasional Harian',
            Sistem: false,
            Kedalaman: 1,
            UuidInduk: akunDasar.Uuid,
            KodeInduk: '1-1200',
            AdaJurnal: false,
            PeranDipetakan: [],
            PunyaAnak: false,
            BisaDihapus: true,
        },
        {
            ...akunDasar,
            Uuid: '01K5AKUN000000000000000003',
            Kode: '4-1100',
            Nama: 'Diskon Penjualan',
            Jenis: 'Pendapatan',
            LabelJenis: 'Pendapatan',
            SaldoNormal: 'Debit',
            Kontra: true,
            KasBank: false,
            PeranDipetakan: ['Diskon penjualan'],
            PunyaAnak: false,
        },
    ],
    OpsiTipe: [
        { Nilai: 'Aset', Label: 'Aset', DigitAwal: '1' },
        { Nilai: 'Pendapatan', Label: 'Pendapatan', DigitAwal: '4' },
        { Nilai: 'Beban', Label: 'Beban', DigitAwal: '6' },
    ],
    Izin: { Kelola: true },
};

describe('F-13a menu Akuntansi', () => {
    it('laporan.keuangan.lihat membuka pekerjaan pembukuan; laporan keuangan pindah ke menu Laporan (D-27)', () => {
        const menu = SaringMenuTerlihat({ Pemilik: false, Izin: ['laporan.keuangan.lihat'] });
        const akuntansi = menu.find(({ menu: induk }) => induk.label === 'Akuntansi');

        expect(akuntansi?.menu.href).toBe('/kelola/akuntansi/jurnal');
        // D-27: Akuntansi berisi pekerjaan pembukuan; Bagan & pemetaan akun pindah ke Pengaturan.
        expect(akuntansi?.sub.map((m) => m.label)).toEqual([
            'Jurnal',
            'Kas & bank',
            // F-08 BR-08.4 (v2.83): pencairan dana non-tunai, pekerjaan pembukuan sejenis Kas & bank.
            'Pencairan dana',
            'Buku besar',
            'Neraca saldo',
            // FIN-10 (v3.38): aset tetap & penyusutan, pekerjaan pembukuan berkala.
            'Aset tetap',
            'Tutup buku',
        ]);
        // Laba rugi, Neraca, dan Arus kas dicari pemilik sebagai laporan, jadi rumahnya di menu Laporan.
        expect(menu.find(({ menu: induk }) => induk.label === 'Laporan')?.sub.map((m) => m.label)).toEqual([
            'Laporan pajak',
            'Laba rugi',
            'Neraca',
            'Arus kas',
            // v6.49: Pusat Unduhan pindah dari Pengaturan ke menu Laporan.
            'Pusat Unduhan',
        ]);
        expect(CekMenuAktif('/kelola/akuntansi/jurnal', '/kelola/akuntansi/laporan/buku-besar?akun=01J9')).toBe(true);
        expect(CekMenuAktif('/kelola/akuntansi/jurnal', '/kelola/akuntansi/kas-bank/01J9ZC5V7Q8R2T4W6Y8A0B2C4D')).toBe(
            true,
        );
    });
});

describe('F-13a bagan akun', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/akuntansi/akun');
        window.history.replaceState({}, '', '/kelola/akuntansi/akun');
    });
    afterEach(() => cleanup());

    it('pohon: anak menjorok, kode Mono, penanda kas/bank & kontra, status', () => {
        RenderUji(<HalamanBaganAkun {...propsBagan} />);

        const kodeAnak = screen.getByText('1-1210');
        expect(kodeAnak.getAttribute('style')).toContain('padding-left: 1.25rem');
        expect(kodeAnak.closest('td')?.className).toContain('font-mono');
        expect(screen.getAllByText('Kas/bank').length).toBeGreaterThan(0);
        expect(screen.getByText('Kontra')).toBeTruthy();
        expect(screen.getAllByText(/Sudah ada jurnal \(kode & tipe terkunci\)/)).toHaveLength(2);
    });

    it('tambah akun anak: tipe mengikuti induk, dikirim dengan UuidInduk; hapus hanya untuk akun yang belum dipakai', () => {
        RenderUji(<HalamanBaganAkun {...propsBagan} />);

        BukaMenu(screen.getByRole('button', { name: 'Aksi untuk akun 1-1200 Bank' }));
        expect(screen.queryByRole('menuitem', { name: 'Hapus akun' })).toBeNull();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Tambah akun anak' }));

        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByRole<HTMLButtonElement>('combobox', { name: 'Tipe akun' }).disabled).toBe(true);
        UbahNilai(within(dialog).getByLabelText('Kode akun'), '1-1220');
        UbahNilai(within(dialog).getByLabelText('Nama akun'), 'Bank Mandiri');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Simpan akun' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/akuntansi/akun',
            {
                Kode: '1-1220',
                Nama: 'Bank Mandiri',
                Jenis: 'Aset',
                Kontra: false,
                KasBank: true,
                UuidInduk: akunDasar.Uuid,
            },
            expect.anything(),
        );
        cleanup();

        RenderUji(<HalamanBaganAkun {...propsBagan} />);
        BukaMenu(screen.getByRole('button', { name: /Aksi untuk akun 1-1210/ }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Hapus akun' }));
        fireEvent.click(screen.getByRole('button', { name: 'Hapus akun' }));
        expect(tiruanRouter.delete).toHaveBeenCalledWith(
            '/kelola/akuntansi/akun/01K5AKUN000000000000000002',
            expect.anything(),
        );
    });

    it('ubah akun berjurnal: kode terkunci; tanpa izin kelola hanya lihat', () => {
        RenderUji(<HalamanBaganAkun {...propsBagan} />);
        BukaMenu(screen.getByRole('button', { name: 'Aksi untuk akun 1-1200 Bank' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Ubah akun' }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByLabelText<HTMLInputElement>('Kode akun').disabled).toBe(true);
        expect(within(dialog).getByText('Tipe dan sifat kontra terkunci karena akun sudah punya jurnal.')).toBeTruthy();
        cleanup();

        RenderUji(<HalamanBaganAkun {...propsBagan} Izin={{ Kelola: false }} />);
        expect(screen.queryByRole('button', { name: 'Tambah akun' })).toBeNull();
        expect(screen.getByText('akuntansi.kelola')).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Aksi untuk akun/ })).toBeNull();
    });
});

const propsPemetaan: PropsPemetaanAkun = {
    Pemetaan: [
        {
            Id: 'DiskonPenjualan|',
            Kunci: 'DiskonPenjualan',
            LabelPeran: 'Diskon penjualan',
            TipeWajib: 'Pendapatan',
            LabelTipeWajib: 'Pendapatan',
            WajibKontra: true,
            UuidOutlet: null,
            NamaOutlet: null,
            UuidAkun: 'A2',
            KodeAkun: '4-1000',
            NamaAkun: 'Penjualan',
            Status: 'TipeSalah',
            PesanStatus: 'Peran "Diskon penjualan" harus memakai akun kontra (4-1000 bukan akun kontra).',
        },
    ],
    OpsiAkun: [
        { Uuid: 'A1', Kode: '4-1100', Nama: 'Diskon Penjualan', Jenis: 'Pendapatan', Kontra: true },
        { Uuid: 'A2', Kode: '4-1000', Nama: 'Penjualan', Jenis: 'Pendapatan', Kontra: false },
        { Uuid: 'A3', Kode: '6-1000', Nama: 'Beban Gaji', Jenis: 'Beban', Kontra: false },
    ],
    OpsiOutlet: [{ Uuid: 'O1', Nama: 'Cabang Solo Baru' }],
    Izin: { Kelola: true, UbahSemuaOutlet: true },
};

describe('F-13a pemetaan akun', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/akuntansi/pemetaan');
        window.history.replaceState({}, '', '/kelola/akuntansi/pemetaan');
    });
    afterEach(() => cleanup());

    it('status tipe salah terlihat dengan teks; pilihan akun hanya tipe & kontra yang sesuai; simpan & override outlet', () => {
        RenderUji(<HalamanPemetaanAkun {...propsPemetaan} />);
        expect(screen.getByText('Tipe akun salah')).toBeTruthy();
        expect(screen.getByText(/harus memakai akun kontra/)).toBeTruthy();

        BukaMenu(screen.getByRole('button', { name: 'Aksi untuk Diskon penjualan semua outlet' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Ganti akun' }));
        const pilihan = within(screen.getByRole('dialog')).getByRole('combobox', { name: 'Akun' });
        expect(AmbilNilaiPilihan(pilihan)).toEqual(['', 'A1']);
        UbahNilai(pilihan, 'A1');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pemetaan' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/akuntansi/pemetaan',
            { Kunci: 'DiskonPenjualan', UuidOutlet: null, UuidAkun: 'A1' },
            expect.anything(),
        );
        cleanup();

        RenderUji(<HalamanPemetaanAkun {...propsPemetaan} Izin={{ Kelola: true, UbahSemuaOutlet: false }} />);
        BukaMenu(screen.getByRole('button', { name: 'Aksi untuk Diskon penjualan semua outlet' }));
        expect(screen.queryByRole('menuitem', { name: 'Ganti akun' })).toBeNull();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Atur akun khusus outlet' }));
        // Hanya ada satu outlet: terisi otomatis & terkunci, tanpa pilihan manual.
        const outletOtomatis = within(screen.getByRole('dialog')).getByRole<HTMLButtonElement>('combobox', {
            name: 'Outlet',
        });
        expect(outletOtomatis.disabled).toBe(true);
        expect(outletOtomatis.textContent).toContain('Cabang Solo Baru');
        UbahNilai(within(screen.getByRole('dialog')).getByRole('combobox', { name: 'Akun' }), 'A1');
        fireEvent.click(screen.getByRole('button', { name: 'Simpan pemetaan' }));
        expect(tiruanRouter.put).toHaveBeenLastCalledWith(
            '/kelola/akuntansi/pemetaan',
            { Kunci: 'DiskonPenjualan', UuidOutlet: 'O1', UuidAkun: 'A1' },
            expect.anything(),
        );
    });
});

const barisKasBank: BarisTransaksiKasBank = {
    Uuid: '01K5KASBANK000000000000001',
    Nomor: 'KB/2026/09/0001',
    Tanggal: '2026-09-20',
    Jenis: 'Pengeluaran',
    LabelJenis: 'Pengeluaran',
    NamaOutlet: 'Cabang Solo Baru',
    AkunSumber: '1-1100 Kas Outlet',
    AkunTujuan: '6-2000 Beban Sewa, Listrik, Air, Internet',
    Jumlah: '1250000.00',
    Keterangan: 'Bayar listrik PLN September',
    Pembalik: false,
    AdaLampiran: true,
    Dibalik: true,
};

const propsKasBank: PropsDaftarTransaksiKasBank = {
    Transaksi: { Data: [barisKasBank], Meta: { Halaman: 1, PerHalaman: 25, Total: 1, JumlahHalaman: 1 } },
    Saldo: [
        { Uuid: 'K1', Kode: '1-1100', Nama: 'Kas Outlet', Aktif: true, Saldo: '-1250000.00' },
        { Uuid: 'K2', Kode: '1-1200', Nama: 'Bank', Aktif: true, Saldo: '123456789012.34' },
    ],
    OpsiJenis: [
        { Nilai: 'Pengeluaran', Label: 'Pengeluaran' },
        { Nilai: 'Penerimaan', Label: 'Penerimaan' },
        { Nilai: 'Transfer', Label: 'Transfer' },
    ],
    OpsiOutlet: [{ Uuid: 'O1', Nama: 'Cabang Solo Baru' }],
    Izin: { Kelola: true },
};

const propsBuatKasBank: PropsBuatTransaksiKasBank = {
    OpsiJenis: propsKasBank.OpsiJenis,
    OpsiOutlet: propsKasBank.OpsiOutlet,
    OpsiAkun: [
        { Uuid: 'K1', Kode: '1-1100', Nama: 'Kas Outlet', Jenis: 'Aset', KasBank: true },
        { Uuid: 'K2', Kode: '1-1200', Nama: 'Bank', Jenis: 'Aset', KasBank: true },
        { Uuid: 'P1', Kode: '1-1400', Nama: 'Piutang Usaha', Jenis: 'Aset', KasBank: false },
        { Uuid: 'B1', Kode: '6-2000', Nama: 'Beban Listrik', Jenis: 'Beban', KasBank: false },
        { Uuid: 'E1', Kode: '3-1000', Nama: 'Modal Pemilik', Jenis: 'Ekuitas', KasBank: false },
    ],
    WajibOutlet: true,
    Lampiran: { Ekstensi: ['jpg', 'png', 'pdf'], UkuranMaksimalKb: 5120 },
};

describe('F-13a kas & bank', () => {
    beforeEach(() => {
        AturHalamanUji({}, '/kelola/akuntansi/kas-bank');
        window.history.replaceState({}, '', '/kelola/akuntansi/kas-bank');
    });
    afterEach(() => cleanup());

    it('saldo per akun kas/bank (Rupiah, negatif), daftar dengan nomor Mono & penanda sudah dibalik', () => {
        RenderUji(<HalamanDaftarTransaksiKasBank {...propsKasBank} />);
        expect(screen.getByText('Rp 123.456.789.012,34').closest('td')?.className).toContain('tabular-nums');
        expect(screen.getAllByText('−Rp 1.250.000').length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'KB/2026/09/0001' }).className).toContain('font-mono');
        expect(screen.getByText('Sudah dibalik')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Catat transaksi kas & bank' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/kas-bank/buat',
        );
        expect(screen.queryByRole('dialog')).toBeNull();
        cleanup();
        RenderUji(<HalamanDaftarTransaksiKasBank {...propsKasBank} Izin={{ Kelola: false }} />);
        expect(screen.queryByRole('link', { name: 'Catat transaksi kas & bank' })).toBeNull();
    });

    it('audit kemudahan pakai #15: nama akun tanpa kode, kas tunggal terisi otomatis, kode tampil di mode akuntan', () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/kas-bank/buat');
        window.localStorage.removeItem('Akuntansi.TampilKodeAkun');
        RenderUji(
            <HalamanBuatTransaksiKasBank
                {...propsBuatKasBank}
                OpsiAkun={propsBuatKasBank.OpsiAkun.filter((a) => a.Uuid !== 'K2')}
            />,
        );
        const formulir = screen.getByRole('form', { name: 'Formulir transaksi kas & bank' });
        const sumber = within(formulir).getByRole('combobox', { name: 'Dibayar dari (kas/bank)' });

        expect(sumber.textContent).toContain('Kas Outlet');
        expect(sumber.textContent).not.toContain('1-1100');
        fireEvent.click(within(formulir).getByRole('checkbox', { name: 'Tampilkan kode akun (untuk akuntan)' }));
        expect(within(formulir).getByRole('combobox', { name: 'Dibayar dari (kas/bank)' }).textContent).toContain(
            '1-1100 Kas Outlet',
        );
        window.localStorage.removeItem('Akuntansi.TampilKodeAkun');
    });

    it('halaman catat: akun disaring per jenis (kas/bank vs lawan), outlet wajib, dikirim sebagai string desimal; Batal kembali ke daftar', () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/kas-bank/buat');
        RenderUji(<HalamanBuatTransaksiKasBank {...propsBuatKasBank} />);
        const formulir = screen.getByRole('form', { name: 'Formulir transaksi kas & bank' });

        expect(AmbilNilaiPilihan(within(formulir).getByRole('combobox', { name: 'Dibayar dari (kas/bank)' }))).toEqual([
            '',
            'K1',
            'K2',
        ]);
        expect(AmbilNilaiPilihan(within(formulir).getByRole('combobox', { name: 'Untuk akun beban/aset' }))).toEqual([
            '',
            'P1',
            'B1',
        ]);
        UbahNilai(within(formulir).getByRole('combobox', { name: 'Jenis transaksi' }), 'Transfer');
        expect(AmbilNilaiPilihan(within(formulir).getByRole('combobox', { name: 'Ke kas/bank' }))).toEqual([
            '',
            'K1',
            'K2',
        ]);
        UbahNilai(within(formulir).getByRole('combobox', { name: 'Jenis transaksi' }), 'Penerimaan');
        expect(AmbilNilaiPilihan(within(formulir).getByRole('combobox', { name: 'Diterima dari akun' }))).toEqual([
            '',
            'P1',
            'E1',
        ]);

        UbahNilai(within(formulir).getByRole('combobox', { name: 'Diterima dari akun' }), 'E1');
        UbahNilai(within(formulir).getByRole('combobox', { name: 'Masuk ke (kas/bank)' }), 'K2');
        // Outlet wajib & hanya satu: terisi otomatis, terkunci, dan menjadi bidang pertama form.
        const kotakOutlet = within(formulir).getByRole<HTMLButtonElement>('combobox', { name: 'Outlet' });
        expect(kotakOutlet.disabled).toBe(true);
        expect(within(formulir).getAllByRole('combobox')[0]).toBe(kotakOutlet);
        UbahNilai(within(formulir).getByLabelText('Jumlah'), '25000000');
        UbahNilai(within(formulir).getByLabelText('Keterangan'), 'Setoran modal awal');
        fireEvent.click(within(formulir).getByRole('button', { name: 'Simpan & jurnal' }));

        expect(tiruanRouter.post).toHaveBeenCalledWith(
            '/kelola/akuntansi/kas-bank',
            expect.objectContaining({
                Jenis: 'Penerimaan',
                UuidOutlet: 'O1',
                UuidAkunSumber: 'E1',
                UuidAkunTujuan: 'K2',
                Jumlah: '25000000',
                Keterangan: 'Setoran modal awal',
                Ulangi: null,
                Lampiran: null,
            }),
            expect.objectContaining({ forceFormData: false }),
        );

        // D-23 D: ulangi otomatis tiap bulan.
        fireEvent.click(within(formulir).getByRole('radio', { name: /Tiap bulan/ }));
        fireEvent.click(within(formulir).getByRole('button', { name: 'Simpan & jurnal' }));
        expect(tiruanRouter.post).toHaveBeenLastCalledWith(
            '/kelola/akuntansi/kas-bank',
            expect.objectContaining({ Ulangi: 'Bulanan' }),
            expect.anything(),
        );

        fireEvent.click(within(formulir).getByRole('button', { name: 'Batal' }));
        expect(tiruanRouter.visit).toHaveBeenCalledWith('/kelola/akuntansi/kas-bank');
    });

    it('transaksi berulang (D-23 D): status & galat tampil, hentikan/aktifkan lewat menu baris', async () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/kas-bank/berulang');
        const baris: BarisJadwalKasBank = {
            Uuid: 'J1',
            Keterangan: 'Sewa ruko Jl. Slamet Riyadi Solo',
            Jenis: 'Pengeluaran',
            LabelJenis: 'Pengeluaran',
            AkunSumber: '1-1100 Kas Outlet',
            AkunTujuan: '6-2000 Beban Sewa',
            Jumlah: '4500000.00',
            Frekuensi: 'Bulanan',
            LabelFrekuensi: 'Tiap bulan',
            TanggalBerikutnya: '2026-02-28',
            Aktif: true,
            JumlahDicatat: 3,
            GalatTerakhir: 'Periode 2026-02 sudah dikunci.',
        };
        RenderUji(
            <HalamanJadwalKasBank
                Jadwal={{ Data: [baris], Meta: { Halaman: 1, PerHalaman: 25, Total: 1, JumlahHalaman: 1 } }}
                Izin={{ Kelola: true }}
            />,
        );
        expect(screen.getAllByText('Sewa ruko Jl. Slamet Riyadi Solo').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Gagal: Periode 2026-02 sudah dikunci.').length).toBeGreaterThan(0);
        await BukaMenu(screen.getAllByRole('button', { name: /Aksi/ })[0] as HTMLElement);
        fireEvent.click(await screen.findByRole('menuitem', { name: 'Hentikan jadwal' }));
        expect(tiruanRouter.put).toHaveBeenCalledWith(
            '/kelola/akuntansi/kas-bank/berulang/J1',
            { Aktif: false },
            expect.anything(),
        );
    });

    it('detail: tautan pembalik, lampiran, jurnal; dokumen pembalik hanya bila belum dibalik', () => {
        const detail: PropsDetailTransaksiKasBank = {
            Transaksi: {
                ...barisKasBank,
                Dibalik: false,
                DibuatOleh: 'Rina Wulandari',
                DibuatPada: '2026-09-20T03:15:00Z',
                UuidDibalik: null,
                NomorDibalik: null,
                UuidPembalik: null,
                NomorPembalik: null,
                Lampiran: { Nama: 'Nota PLN.png', Ukuran: 2048 },
            },
            Jurnal: [
                {
                    Uuid: 'J1',
                    Nomor: 'JU/2026/09/000001',
                    Tanggal: '2026-09-20',
                    Keterangan: 'Pengeluaran',
                    TotalDebit: '1250000.00',
                    Pembalik: false,
                },
            ],
            Izin: { Kelola: true },
        };
        render(<HalamanDetailTransaksiKasBank {...detail} />);
        expect(screen.getByRole('link', { name: /Nota PLN\.png/ }).getAttribute('href')).toBe(
            `/kelola/akuntansi/kas-bank/${barisKasBank.Uuid}/lampiran`,
        );
        expect(screen.getByRole('link', { name: 'JU/2026/09/000001' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal/J1',
        );

        fireEvent.click(screen.getByRole('button', { name: 'Buat dokumen pembalik' }));
        UbahNilai(screen.getByLabelText('Alasan koreksi'), 'Salah input nominal');
        fireEvent.click(screen.getByRole('button', { name: 'Buat pembalik' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith(
            `/kelola/akuntansi/kas-bank/${barisKasBank.Uuid}/pembalik`,
            expect.objectContaining({ Alasan: 'Salah input nominal' }),
            expect.anything(),
        );
        cleanup();

        render(
            <HalamanDetailTransaksiKasBank
                {...detail}
                Transaksi={{ ...detail.Transaksi, Dibalik: true, UuidPembalik: 'P2', NomorPembalik: 'KB/2026/09/0002' }}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Buat dokumen pembalik' })).toBeNull();
        expect(screen.getByRole('link', { name: 'KB/2026/09/0002' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/kas-bank/P2',
        );
    });
});

const saring = { Dari: '2026-09-01', Sampai: '2026-09-30', Outlet: '' };

describe('F-13a laporan keuangan', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/akuntansi/laporan/buku-besar'));
    afterEach(() => cleanup());

    it('buku besar: tanpa akun = ajakan memilih & ekspor nonaktif; dengan akun = ringkasan, saldo berjalan, ekspor bersaringan', () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/laporan/buku-besar');
        const kosong: PropsBukuBesar = {
            Saring: { ...saring, Akun: '' },
            Akun: null,
            Mutasi: { Data: [], Meta: { Halaman: 1, PerHalaman: 25, Total: 0, JumlahHalaman: 1 } },
            OpsiAkun: [{ Nilai: 'K1', Label: '1-1100 Kas Outlet' }],
            OpsiOutlet: [],
        };
        RenderUji(<HalamanBukuBesar {...kosong} />);
        expect(screen.getByText('Pilih akun untuk melihat buku besarnya.')).toBeTruthy();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Ekspor' }).disabled).toBe(true);
        UbahNilai(screen.getByRole('combobox', { name: 'Akun' }), 'K1');
        expect(tiruanRouter.get).toHaveBeenCalledWith(
            '/kelola/akuntansi/laporan/buku-besar?akun=K1&dari=2026-09-01&sampai=2026-09-30',
            {},
            expect.anything(),
        );
        cleanup();

        window.history.replaceState({}, '', '/kelola/akuntansi/laporan/buku-besar?akun=K1');
        RenderUji(
            <HalamanBukuBesar
                {...kosong}
                Saring={{ ...saring, Akun: 'K1' }}
                Akun={{ Uuid: 'K1', Kode: '1-1100', Nama: 'Kas Outlet', SaldoNormal: 'Debit' }}
                Mutasi={{
                    Data: [
                        {
                            Id: '1',
                            Tanggal: '2026-09-05',
                            UuidJurnal: 'J1',
                            NomorJurnal: 'JU/2026/09/000001',
                            Keterangan: 'Pengeluaran KB/2026/09/0001',
                            Memo: 'Bayar parkir',
                            LabelSumber: 'Transaksi kas & bank',
                            NomorSumber: 'KB/2026/09/0001',
                            TautanSumber: '/kelola/akuntansi/kas-bank/X1',
                            NamaOutlet: null,
                            Debit: '0.00',
                            Kredit: '15000.00',
                            Saldo: '985000.00',
                        },
                    ],
                    Meta: { Halaman: 1, PerHalaman: 25, Total: 1, JumlahHalaman: 1 },
                    Ringkasan: {
                        SaldoAwal: '1000000.00',
                        TotalDebit: '0.00',
                        TotalKredit: '15000.00',
                        SaldoAkhir: '985000.00',
                    },
                }}
            />,
        );
        const ringkasan = screen.getByLabelText('Ringkasan buku besar');
        expect(within(ringkasan).getByText('Rp 1.000.000')).toBeTruthy();
        expect(within(ringkasan).getAllByText('Rp 985.000')).toHaveLength(1);
        expect(screen.getByRole('link', { name: 'KB/2026/09/0001' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/kas-bank/X1',
        );
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toBe(
            '/kelola/akuntansi/laporan/buku-besar/ekspor?akun=K1&dari=2026-09-01&sampai=2026-09-30&format=csv',
        );
    });

    it('neraca saldo: status seimbang bertulisan, total debit = kredit, nama akun menuju buku besar', () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/laporan/neraca-saldo');
        const props: PropsNeracaSaldo = {
            Saring: { ...saring, Outlet: 'O1' },
            Laporan: {
                Baris: [
                    {
                        Uuid: 'K1',
                        Kode: '1-1100',
                        Nama: 'Kas Outlet',
                        Jenis: 'Aset',
                        LabelJenis: 'Aset',
                        SaldoAwalDebit: '0.00',
                        SaldoAwalKredit: '0.00',
                        Debit: '0.00',
                        Kredit: '15000.00',
                        SaldoAkhirDebit: '0.00',
                        SaldoAkhirKredit: '15000.00',
                    },
                    {
                        Uuid: 'B1',
                        Kode: '6-9000',
                        Nama: 'Beban Lain-lain',
                        Jenis: 'Beban',
                        LabelJenis: 'Beban',
                        SaldoAwalDebit: '0.00',
                        SaldoAwalKredit: '0.00',
                        Debit: '15000.00',
                        Kredit: '0.00',
                        SaldoAkhirDebit: '15000.00',
                        SaldoAkhirKredit: '0.00',
                    },
                ],
                Total: {
                    SaldoAwalDebit: '0.00',
                    SaldoAwalKredit: '0.00',
                    Debit: '15000.00',
                    Kredit: '15000.00',
                    SaldoAkhirDebit: '15000.00',
                    SaldoAkhirKredit: '15000.00',
                },
                Seimbang: true,
            },
            OpsiOutlet: [{ Uuid: 'O1', Nama: 'Cabang Solo Baru' }],
        };
        RenderUji(<HalamanNeracaSaldo {...props} />);
        expect(screen.getByText('Seimbang')).toBeTruthy();
        expect(within(screen.getByLabelText('Total neraca saldo')).getAllByText('Rp 15.000')).toHaveLength(4);
        expect(screen.getByRole('link', { name: 'Kas Outlet' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/laporan/buku-besar?akun=K1&dari=2026-09-01&sampai=2026-09-30&outlet=O1',
        );
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toBe(
            '/kelola/akuntansi/laporan/neraca-saldo/ekspor?dari=2026-09-01&sampai=2026-09-30&outlet=O1&format=csv',
        );
    });

    it('laba rugi: kelompok, laba kotor & bersih, kolom pembanding; ganti outlet memuat ulang dengan saringan di URL', () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/laporan/laba-rugi');
        const BuatNilai = (n: string, s: string) => ({ Nilai: n, NilaiSebelumnya: s });
        const props: PropsLabaRugi = {
            Saring: saring,
            Laporan: {
                Periode: {
                    Dari: '2026-09-01',
                    Sampai: '2026-09-30',
                    DariSebelumnya: '2026-08-01',
                    SampaiSebelumnya: '2026-08-31',
                },
                Baris: [
                    {
                        Id: 'Kepala|Pendapatan',
                        Jenis: 'Kepala',
                        Kelompok: 'Pendapatan',
                        Kode: null,
                        Label: 'Pendapatan',
                        Nilai: null,
                        NilaiSebelumnya: null,
                    },
                    {
                        Id: 'Akun|1',
                        Jenis: 'Akun',
                        Kelompok: 'Pendapatan',
                        Kode: '4-1000',
                        Label: 'Penjualan',
                        Nilai: '115500.00',
                        NilaiSebelumnya: '0.00',
                    },
                    {
                        Id: 'Subtotal|Pendapatan',
                        Jenis: 'Subtotal',
                        Kelompok: 'Pendapatan',
                        Kode: null,
                        Label: 'Total pendapatan',
                        Nilai: '115500.00',
                        NilaiSebelumnya: '0.00',
                    },
                    {
                        Id: 'Laba|LabaKotor',
                        Jenis: 'Laba',
                        Kelompok: 'LabaKotor',
                        Kode: null,
                        Label: 'Laba kotor',
                        Nilai: '25500.00',
                        NilaiSebelumnya: '0.00',
                    },
                    {
                        Id: 'Laba|LabaBersih',
                        Jenis: 'Laba',
                        Kelompok: 'LabaBersih',
                        Kode: null,
                        Label: 'Laba bersih',
                        Nilai: '-1224500.00',
                        NilaiSebelumnya: '-500000.00',
                    },
                ],
                Ringkasan: {
                    Pendapatan: BuatNilai('115500.00', '0.00'),
                    Hpp: BuatNilai('90000.00', '0.00'),
                    LabaKotor: BuatNilai('25500.00', '0.00'),
                    Beban: BuatNilai('1250000.00', '500000.00'),
                    LabaBersih: BuatNilai('-1224500.00', '-500000.00'),
                },
            },
            OpsiOutlet: [{ Uuid: 'O1', Nama: 'Cabang Solo Baru' }],
        };
        RenderUji(<HalamanLabaRugi {...props} />);
        expect(screen.getByRole('columnheader', { name: /1 Sep.*30 Sep 2026/ })).toBeTruthy();
        expect(screen.getByRole('columnheader', { name: /1 Agu.*31 Agu 2026/ })).toBeTruthy();
        expect(screen.getAllByText('−Rp 1.224.500').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Laba kotor')).toHaveLength(2);

        UbahNilai(screen.getByRole('combobox', { name: 'Outlet' }), 'O1');
        expect(tiruanRouter.get).toHaveBeenCalledWith(
            '/kelola/akuntansi/laporan/laba-rugi?dari=2026-09-01&sampai=2026-09-30&outlet=O1',
            {},
            expect.anything(),
        );
    });

    it('neraca: kelompok aset/kewajiban/ekuitas, laba belum ditutup di ekuitas, kolom posisi awal; tidak seimbang diberi tahu', () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/laporan/neraca');
        const BuatNilai = (n: string, a: string) => ({ Nilai: n, NilaiAwal: a });
        const BuatBaris = (
            Id: string,
            Jenis: PropsNeraca['Laporan']['Baris'][number]['Jenis'],
            Label: string,
            Nilai: string | null,
            NilaiAwal: string | null,
            Kode: string | null = null,
        ) => ({ Id, Jenis, Kelompok: Id.split('|')[1] ?? '', Kode, Label, Nilai, NilaiAwal });
        const props: PropsNeraca = {
            Saring: saring,
            Laporan: {
                Posisi: { Akhir: '2026-09-30', Awal: '2026-08-31' },
                Baris: [
                    BuatBaris('Kepala|Aset', 'Kepala', 'Aset', null, null),
                    BuatBaris('Akun|A1', 'Akun', 'Persediaan Barang Dagang', '12500000.00', '10000000.00', '1-1300'),
                    BuatBaris('Subtotal|Aset', 'Subtotal', 'Total aset', '12500000.00', '10000000.00'),
                    BuatBaris('Kepala|Kewajiban', 'Kepala', 'Kewajiban', null, null),
                    BuatBaris('Akun|K1', 'Akun', 'Hutang Usaha', '2500000.00', '0.00', '2-1000'),
                    BuatBaris('Subtotal|Kewajiban', 'Subtotal', 'Total kewajiban', '2500000.00', '0.00'),
                    BuatBaris('Kepala|Ekuitas', 'Kepala', 'Ekuitas', null, null),
                    BuatBaris('Akun|E1', 'Akun', 'Modal Saldo Awal', '10000000.00', '10000000.00', '3-1000'),
                    BuatBaris('Laba|LabaBerjalan', 'Laba', 'Laba tahun berjalan', '-1224500.00', '0.00'),
                    BuatBaris('Subtotal|Ekuitas', 'Subtotal', 'Total ekuitas', '8775500.00', '10000000.00'),
                    BuatBaris(
                        'Total|KewajibanEkuitas',
                        'Total',
                        'Total kewajiban dan ekuitas',
                        '11275500.00',
                        '10000000.00',
                    ),
                ],
                Ringkasan: {
                    Aset: BuatNilai('12500000.00', '10000000.00'),
                    Kewajiban: BuatNilai('2500000.00', '0.00'),
                    Ekuitas: BuatNilai('8775500.00', '10000000.00'),
                    KewajibanEkuitas: BuatNilai('11275500.00', '10000000.00'),
                    LabaBerjalan: BuatNilai('-1224500.00', '0.00'),
                },
                Seimbang: false,
            },
            OpsiOutlet: [{ Uuid: 'O1', Nama: 'Cabang Solo Baru' }],
        };
        RenderUji(<HalamanNeraca {...props} />);
        expect(screen.getByRole('columnheader', { name: /30 Sep 2026/ })).toBeTruthy();
        expect(screen.getByRole('columnheader', { name: /31 Agu 2026/ })).toBeTruthy();
        expect(screen.getByText('Total kewajiban dan ekuitas')).toBeTruthy();
        expect(screen.getAllByText('Laba tahun berjalan').length).toBeGreaterThan(0);
        expect(screen.getAllByText('−Rp 1.224.500').length).toBeGreaterThan(0);
        expect(screen.getByText('Tidak seimbang')).toBeTruthy();
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toBe(
            '/kelola/akuntansi/laporan/neraca/ekspor?dari=2026-09-01&sampai=2026-09-30&format=csv',
        );
    });

    it('arus kas: aktivitas operasi/investasi/pendanaan, kas awal & akhir; tanpa akun kas diberi tahu', () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/laporan/arus-kas');
        const BuatBaris = (
            Id: string,
            Jenis: PropsArusKas['Laporan']['Baris'][number]['Jenis'],
            Label: string,
            Nilai: string | null,
            Kode: string | null = null,
        ) => ({ Id, Jenis, Aktivitas: 'Operasi', Kode, Label, Nilai });
        const props: PropsArusKas = {
            Saring: saring,
            Laporan: {
                Periode: { Dari: '2026-09-01', Sampai: '2026-09-30' },
                Baris: [
                    BuatBaris('Kepala|Operasi', 'Kepala', 'Aktivitas operasi', null),
                    BuatBaris('Sumber|Penjualan', 'Rincian', 'Penerimaan dari penjualan', '115500.00'),
                    BuatBaris('Akun|B1', 'Rincian', 'Beban Listrik', '-250000.00', '6-2000'),
                    BuatBaris('Subtotal|Operasi', 'Subtotal', 'Arus kas bersih dari aktivitas operasi', '-134500.00'),
                    BuatBaris('Kepala|Pendanaan', 'Kepala', 'Aktivitas pendanaan', null),
                    BuatBaris('Akun|E1', 'Rincian', 'Modal Pemilik', '5000000.00', '3-1000'),
                    BuatBaris(
                        'Subtotal|Pendanaan',
                        'Subtotal',
                        'Arus kas bersih dari aktivitas pendanaan',
                        '5000000.00',
                    ),
                    BuatBaris('Total|Kenaikan', 'Total', 'Kenaikan (penurunan) bersih kas & bank', '4865500.00'),
                    BuatBaris('Total|Awal', 'Saldo', 'Kas & bank awal periode', '0.00'),
                    BuatBaris('Total|Akhir', 'Total', 'Kas & bank akhir periode', '4865500.00'),
                ],
                Ringkasan: {
                    Operasi: '-134500.00',
                    Investasi: '0.00',
                    Pendanaan: '5000000.00',
                    Kenaikan: '4865500.00',
                    SaldoAwal: '0.00',
                    SaldoAkhir: '4865500.00',
                },
                AdaAkunKas: false,
            },
            OpsiOutlet: [{ Uuid: 'O1', Nama: 'Cabang Solo Baru' }],
        };
        RenderUji(<HalamanArusKas {...props} />);
        expect(screen.getByRole('columnheader', { name: /1 Sep.*30 Sep 2026/ })).toBeTruthy();
        expect(screen.getByText('Penerimaan dari penjualan')).toBeTruthy();
        expect(screen.getAllByText('−Rp 250.000').length).toBeGreaterThan(0);
        expect(screen.getByText('Kas & bank akhir periode')).toBeTruthy();
        expect(screen.getAllByText('Rp 4.865.500').length).toBeGreaterThan(0);
        expect(screen.getByText('Belum ada akun kas atau bank')).toBeTruthy();
        expect(AmbilHrefEkspor('csv', 'Ekspor')).toBe(
            '/kelola/akuntansi/laporan/arus-kas/ekspor?dari=2026-09-01&sampai=2026-09-30&format=csv',
        );
    });
});
