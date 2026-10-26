import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDaftarJurnal from '@/Halaman/Kelola/Akuntansi/Jurnal/Daftar';
import HalamanDetailJurnal from '@/Halaman/Kelola/Akuntansi/Jurnal/Detail';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import type { BarisDaftarJurnal, PropsDaftarJurnal, PropsDetailJurnal } from '@/Tipe/Akuntansi';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const barisJurnal: BarisDaftarJurnal = {
    Uuid: '01K5JURNAL0000000000000001',
    Nomor: 'JU/2026/09/000001',
    Tanggal: '2026-09-20',
    JenisSumber: 'StokAwal',
    LabelJenisSumber: 'Stok awal',
    NomorSumber: 'SA/2026/09/0001',
    TautanSumber: '/kelola/persediaan/stok-awal/01K5STOKAWA00000000000001',
    Keterangan: 'Stok awal Gudang Toko Outlet Utama',
    TotalDebit: '123456789012.34',
    Otomatis: true,
    Dibalik: true,
    Pembalik: false,
};

const pembalik: BarisDaftarJurnal = {
    ...barisJurnal,
    Uuid: '01K5JURNAL0000000000000002',
    Nomor: 'JU/2026/09/000002',
    Tanggal: '2026-09-24',
    Keterangan: 'Pembatalan stok awal SA/2026/09/0001: salah input jumlah minyak goreng',
    TotalDebit: '0.01',
    Dibalik: false,
    Pembalik: true,
    TautanSumber: null,
};

function BuatPropsDaftar(data: BarisDaftarJurnal[], jumlahHalaman = 1, kelola = false): PropsDaftarJurnal {
    return {
        Jurnal: {
            Data: data,
            Meta: { Halaman: 1, PerHalaman: 25, Total: jumlahHalaman * 25, JumlahHalaman: jumlahHalaman },
        },
        OpsiJenisSumber: [{ Nilai: 'StokAwal', Label: 'Stok awal' }],
        Izin: { Kelola: kelola },
    };
}

const propsDetail: PropsDetailJurnal = {
    Jurnal: {
        ...barisJurnal,
        TotalDebit: '10601234.56',
        Periode: '2026-09',
        DibuatOleh: 'Rina Wulandari',
        DibuatPada: '2026-09-20T03:15:00Z',
        UuidJurnalDibalik: null,
        NomorJurnalDibalik: null,
        UuidPembalik: pembalik.Uuid,
        NomorPembalik: pembalik.Nomor,
    },
    Baris: [
        {
            Urutan: 1,
            KodeAkun: '1-1400',
            NamaAkun: 'Persediaan barang dagang',
            NamaOutlet: 'Outlet Utama Solo Baru',
            Debit: '10600834.56',
            Kredit: '0.00',
            Memo: null,
        },
        {
            Urutan: 2,
            KodeAkun: '5-1200',
            NamaAkun: 'Selisih HPP',
            NamaOutlet: null,
            Debit: '400.00',
            Kredit: '0.00',
            Memo: 'Stok minus sebelum stok awal',
        },
        {
            Urutan: 3,
            KodeAkun: '3-1000',
            NamaAkun: 'Ekuitas saldo awal',
            NamaOutlet: 'Outlet Utama Solo Baru',
            Debit: '0.00',
            Kredit: '10601234.56',
            Memo: null,
        },
    ],
    Total: { Debit: '10601234.56', Kredit: '10601234.56' },
};

describe('Kelola/Akuntansi/Jurnal (F-05a, DesainF05a E)', () => {
    beforeEach(() => AturHalamanUji({}, '/kelola/akuntansi/jurnal'));
    afterEach(() => cleanup());

    it('daftar: nomor Mono bertautan ke detail, sumber bertautan, nilai Rupiah rata kanan, penanda pembalik', () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/jurnal');
        RenderUji(<HalamanDaftarJurnal {...BuatPropsDaftar([pembalik, barisJurnal])} />);

        const tautanNomor = screen.getByRole('link', { name: 'JU/2026/09/000001' });
        expect(tautanNomor.getAttribute('href')).toBe(`/kelola/akuntansi/jurnal/${barisJurnal.Uuid}`);
        expect(tautanNomor.className).toContain('font-mono');
        expect(screen.getAllByRole('link', { name: 'SA/2026/09/0001' })[0]?.getAttribute('href')).toBe(
            barisJurnal.TautanSumber,
        );
        const nilai = screen.getByText('Rp 123.456.789.012,34').closest('td');
        expect(nilai?.className).toContain('tabular-nums');
        expect(nilai?.className).toContain('text-right');
        expect(screen.getByText('Jurnal pembalik')).toBeTruthy();
        expect(screen.getByText('Sudah dibalik')).toBeTruthy();
        expect(screen.getByText('Rp 0,01')).toBeTruthy();
    });

    it('FIN-05: tombol "Catat jurnal umum" hanya muncul bagi pemegang akuntansi.kelola', () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/jurnal');
        const tampilan = RenderUji(<HalamanDaftarJurnal {...BuatPropsDaftar([barisJurnal], 1, true)} />);
        expect(screen.getByRole('link', { name: 'Catat jurnal umum' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal-umum/buat',
        );
        tampilan.unmount();

        RenderUji(<HalamanDaftarJurnal {...BuatPropsDaftar([barisJurnal], 1, false)} />);
        expect(screen.queryByRole('link', { name: 'Catat jurnal umum' })).toBeNull();
    });

    it('filter (TabelData D-16): sumber & tanggal tersimpan di URL, cari ditahan lalu dikirim ke server', async () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/jurnal');
        const permintaan: string[] = [];
        vi.stubGlobal(
            'fetch',
            vi.fn((url: string) => {
                permintaan.push(url);

                return Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () => Promise.resolve(BuatPropsDaftar([]).Jurnal),
                });
            }),
        );
        RenderUji(<HalamanDaftarJurnal {...BuatPropsDaftar([barisJurnal])} />);

        fireEvent.click(screen.getByRole('button', { name: /^Sumber/ }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Stok awal' }));
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'SA/2026/09' } });

        await waitFor(() =>
            expect(permintaan.at(-1)).toBe(
                '/kelola/akuntansi/jurnal?cari=SA%2F2026%2F09&saring%5BJenisSumber%5D=StokAwal',
            ),
        );
        expect(window.location.search).toBe('?cari=SA%2F2026%2F09&saring%5BJenisSumber%5D=StokAwal');
        vi.unstubAllGlobals();
    });

    it('kosong tanpa filter mengarahkan ke stok awal. Kosong dengan filter menawarkan hapus filter', () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/jurnal');
        const tampilan = RenderUji(<HalamanDaftarJurnal {...BuatPropsDaftar([])} />);
        expect(screen.getByText('Belum ada jurnal. Jurnal terbentuk otomatis saat stok awal diposting.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Buka stok awal' }).getAttribute('href')).toBe(
            '/kelola/persediaan/stok-awal',
        );
        tampilan.unmount();

        window.history.replaceState({}, '', '/kelola/akuntansi/jurnal?cari=tidak+ada');
        RenderUji(<HalamanDaftarJurnal {...BuatPropsDaftar([])} />);
        expect(screen.getByText('Tidak ada hasil untuk pencarian atau filter ini.')).toBeTruthy();
    });

    it('masalah server ditampilkan. Paginasi server tampil bila lebih dari satu halaman', () => {
        window.history.replaceState({}, '', '/kelola/akuntansi/jurnal');
        AturHalamanUji({ Tanggal: 'Periode Agustus 2026 sudah dikunci.' }, '/kelola/akuntansi/jurnal');
        RenderUji(<HalamanDaftarJurnal {...BuatPropsDaftar([barisJurnal], 3)} />);

        expect(screen.getByText('Periode Agustus 2026 sudah dikunci.')).toBeTruthy();
        expect(screen.getByText('Halaman 1 dari 3')).toBeTruthy();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Halaman berikutnya' }).disabled).toBe(false);
    });

    it('detail: baris akun, outlet/tingkat usaha, total seimbang, tautan pembalik dan sumber', () => {
        render(<HalamanDetailJurnal {...propsDetail} />);

        const tabel = screen.getByRole('table');
        const baris = within(tabel).getAllByRole('row');
        expect(baris).toHaveLength(5);
        expect(within(baris[1] as HTMLElement).getByText('1-1400').className).toContain('font-mono');
        expect(within(baris[1] as HTMLElement).getByText('Rp 10.600.834,56')).toBeTruthy();
        expect(within(baris[2] as HTMLElement).getByText('Tingkat usaha')).toBeTruthy();
        expect(within(baris[2] as HTMLElement).getByText('Stok minus sebelum stok awal')).toBeTruthy();
        expect(within(baris[4] as HTMLElement).getByText('Total (seimbang)')).toBeTruthy();
        expect(within(baris[4] as HTMLElement).getAllByText('Rp 10.601.234,56')).toHaveLength(2);

        expect(screen.getByText('Jurnal ini sudah dibalik')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'JU/2026/09/000002' }).getAttribute('href')).toBe(
            `/kelola/akuntansi/jurnal/${pembalik.Uuid}`,
        );
        expect(screen.getByRole('link', { name: 'SA/2026/09/0001' }).getAttribute('href')).toBe(
            barisJurnal.TautanSumber,
        );
        expect(screen.getByText(/Otomatis oleh Rina Wulandari/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Kembali ke daftar jurnal' }).getAttribute('href')).toBe(
            '/kelola/akuntansi/jurnal',
        );
    });

    it('detail jurnal pembalik manual: tautan ke jurnal yang dibalik, sumber tanpa tautan', () => {
        render(
            <HalamanDetailJurnal
                {...propsDetail}
                Jurnal={{
                    ...propsDetail.Jurnal,
                    ...pembalik,
                    Otomatis: false,
                    DibuatOleh: null,
                    UuidJurnalDibalik: barisJurnal.Uuid,
                    NomorJurnalDibalik: barisJurnal.Nomor,
                    UuidPembalik: null,
                    NomorPembalik: null,
                }}
            />,
        );

        expect(
            screen.getByText('Jurnal pembalik', { selector: '[data-slot="alert-title"], [data-slot="alert-title"] *' }),
        ).toBeTruthy();
        expect(screen.getByRole('link', { name: 'JU/2026/09/000001' }).getAttribute('href')).toBe(
            `/kelola/akuntansi/jurnal/${barisJurnal.Uuid}`,
        );
        expect(screen.queryByRole('link', { name: 'SA/2026/09/0001' })).toBeNull();
        expect(screen.getByText('Manual', { selector: '[data-jenis]' })).toBeTruthy();
        expect(screen.queryByText('Jurnal ini sudah dibalik')).toBeNull();
    });
});
