import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanDetailStokOpname from '@/Halaman/Kelola/Persediaan/Opname/Detail';
import HalamanFormPenyesuaianStok from '@/Halaman/Kelola/Persediaan/Penyesuaian/Form';
import HalamanFormPermintaanStok from '@/Halaman/Kelola/Persediaan/Permintaan/Form';
import HalamanFormStokAwal from '@/Halaman/Kelola/Persediaan/StokAwal/Form';
import IsianBarisGrosir, { type BarisIsianGrosir } from '@/Komponen/Grosir/IsianBarisGrosir';
import { AturHalamanUji, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import { AkunSiap, GudangKedua, GudangUtama } from '@/Komponen/Persediaan/DataUjiPersediaan';
import type { IzinDokumenPersediaan } from '@/Tipe/DokumenPersediaan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * Entri cepat barang gelombang 2 (lanjutan permintaan klien untuk Transfer stok & Belanja stok): Penyesuaian stok,
 * Stok awal, Permintaan stok, Opname (barang di luar daftar), dan Pesanan grosir memakai hook `useEntriCepat` yang sama.
 * Barang baru di paling atas, jumlahnya langsung aktif, Enter kembali ke kotak cari dan tidak mengirim formulir.
 */
const BuatProduk = (uuid: string, nama: string, sku: string) => ({
    Uuid: uuid,
    Nama: nama,
    Sku: sku,
    Jenis: 'Stok',
    Pelacakan: 'Tidak',
    SimbolSatuan: 'pcs',
    BolehDesimal: false,
    SaldoDiGudang: '20.0000',
    HppRataRata: '1000.000000',
    StokAwalSudahAda: false,
    Satuan: [{ Uuid: `${uuid}S`, Simbol: 'pcs', Nama: 'Pieces', Konversi: '1.0000', DefaultJual: true }],
});
const Sasa = BuatProduk('01J9PRD0000000000000000001', 'Micin Sasa 250 gr', 'PRD-000297');
const Ajino = BuatProduk('01J9PRD0000000000000000002', 'Ajino Moto 250 gr', 'PRD-000302');

type Kasus = {
    nama: string;
    awalanJumlah: string;
    pasang: () => void;
};

const KasusForm: Kasus[] = [
    {
        nama: 'Penyesuaian stok',
        awalanJumlah: 'Jumlah',
        pasang: () => {
            AturHalamanUji({}, '/kelola/persediaan/penyesuaian/buat');
            RenderUji(
                <HalamanFormPenyesuaianStok
                    Mode="Buat"
                    Penyesuaian={null}
                    OpsiGudang={[GudangUtama]}
                    OpsiAlasan={[{ Nilai: 'Rusak', Label: 'Rusak', BolehMasuk: false, WajibKeterangan: false }]}
                    HariIni="2026-09-24"
                    BatasBaris={500}
                    WajibKedaluwarsaBatch
                />,
            );
        },
    },
    {
        nama: 'Stok awal',
        awalanJumlah: 'Jumlah',
        pasang: () => {
            AturHalamanUji({}, '/kelola/persediaan/stok-awal/buat');
            RenderUji(
                <HalamanFormStokAwal
                    Mode="Buat"
                    StokAwal={null}
                    OpsiGudang={[GudangUtama]}
                    HariIni="2026-09-24"
                    BatasBaris={2000}
                    MaksimalNomorSeriPerBaris={1000}
                    WajibKedaluwarsaBatch
                    KesiapanAkun={AkunSiap}
                />,
            );
        },
    },
    {
        nama: 'Permintaan stok',
        awalanJumlah: 'Jumlah',
        pasang: () => {
            AturHalamanUji({}, '/kelola/persediaan/permintaan/buat');
            RenderUji(
                <HalamanFormPermintaanStok
                    OpsiGudangTujuan={[GudangKedua]}
                    OpsiGudangAsal={[GudangUtama, GudangKedua]}
                    UuidGudangTujuanAwal={GudangKedua.Uuid}
                    HariIni="2026-10-11"
                    BatasBaris={500}
                />,
            );
        },
    },
    {
        nama: 'Pesanan grosir',
        awalanJumlah: 'Jumlah',
        pasang: () => {
            function Pembungkus() {
                const [baris, AturBaris] = useState<BarisIsianGrosir[]>([]);

                return (
                    <form onSubmit={(peristiwa) => tiruanRouter.post('/uji', {}, {}) ?? peristiwa.preventDefault()}>
                        <IsianBarisGrosir
                            baris={baris}
                            saatBerubah={AturBaris}
                            uuidGudang={GudangUtama.Uuid}
                            periksa={false}
                            galatServer={{}}
                            maksimal={100}
                        />
                    </form>
                );
            }

            AturHalamanUji({}, '/kelola/grosir/pesanan/buat');
            RenderUji(<Pembungkus />);
        },
    },
];

async function PilihProduk(kata: string, nama: RegExp) {
    // Daftar dari pilihan sebelumnya harus sudah benar-benar tertutup sebelum yang berikutnya dibuka.
    await waitFor(() => expect(document.querySelector('[data-slot="popover-content"]')).toBeNull());
    fireEvent.click(screen.getByRole('combobox', { name: 'Tambah produk' }));
    const cari = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');
    fireEvent.change(cari, { target: { value: kata } });
    await waitFor(() => expect(screen.getByRole('option', { name: nama })).toBeTruthy());
    fireEvent.keyDown(cari, { key: 'Enter' });
}

const IzinPenuh: IzinDokumenPersediaan = { Lihat: true, Kelola: true, Setujui: true, LihatJurnal: true };

const NamaIsianJumlah = (awalan: string) => new RegExp(`^${awalan} `);

function PasangFetch() {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockImplementation((url: string) =>
            Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ Data: url.includes('ajino') ? [Ajino] : [Sasa] }),
            }),
        ),
    );
}

describe.each(KasusForm)('Entri cepat barang ($nama)', ({ awalanJumlah, pasang }) => {
    beforeEach(PasangFetch);

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('barang yang dipilih langsung punya isian jumlah yang aktif, tanpa mengetuk lagi', async () => {
        pasang();

        await PilihProduk('sasa', /Micin Sasa/);

        const jumlah = await screen.findByRole('textbox', { name: `${awalanJumlah} Micin Sasa 250 gr` });
        await waitFor(() => expect(document.activeElement).toBe(jumlah));
    });

    it('barang berikutnya muncul paling atas, di bawah kotak cari', async () => {
        pasang();

        await PilihProduk('sasa', /Micin Sasa/);
        const pertama = await screen.findByRole('textbox', { name: `${awalanJumlah} Micin Sasa 250 gr` });
        await waitFor(() => expect(document.activeElement).toBe(pertama));
        fireEvent.change(pertama, { target: { value: '5' } });
        await PilihProduk('ajino', /Ajino Moto/);

        const urutan = screen
            .getAllByRole('textbox', { name: NamaIsianJumlah(awalanJumlah) })
            .map((isian) => isian.getAttribute('aria-label') ?? isian.id);
        expect(urutan).toHaveLength(2);
        const isianAjino = screen.getByRole('textbox', { name: `${awalanJumlah} Ajino Moto 250 gr` });
        const isianSasa = screen.getByRole('textbox', { name: `${awalanJumlah} Micin Sasa 250 gr` });
        // Ajino (dipilih belakangan) harus mendahului Sasa dalam urutan dokumen.
        expect(isianAjino.compareDocumentPosition(isianSasa) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('Enter di jumlah membuka kotak cari lagi dan tidak mengirim formulir', async () => {
        pasang();
        await PilihProduk('sasa', /Micin Sasa/);

        const jumlah = await screen.findByRole('textbox', { name: `${awalanJumlah} Micin Sasa 250 gr` });
        await waitFor(() => expect(document.activeElement).toBe(jumlah));
        fireEvent.change(jumlah, { target: { value: '12' } });
        fireEvent.keyDown(jumlah, { key: 'Enter' });

        const kotakCari = await screen.findByPlaceholderText('Cari nama, SKU, atau barcode');
        await waitFor(() => expect(document.activeElement).toBe(kotakCari));
        expect((jumlah as HTMLInputElement).value).toBe('12');
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect(tiruanRouter.put).not.toHaveBeenCalled();
    });
});

describe('Entri cepat barang (Opname, barang di luar daftar)', () => {
    beforeEach(PasangFetch);

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    function PasangOpname() {
        AturHalamanUji({}, '/kelola/persediaan/opname/01J9ZC5V7Q8R2T4W6Y8A0B2C4E');
        RenderUji(
            <HalamanDetailStokOpname
                Opname={{
                    Uuid: '01J9ZC5V7Q8R2T4W6Y8A0B2C4E',
                    Nomor: 'SO/TOKO/2609/001',
                    Status: 'Berlangsung',
                    LabelStatus: 'Sedang dihitung',
                    UuidGudang: GudangUtama.Uuid,
                    NamaGudang: 'Toko Utama',
                    NamaOutlet: 'Outlet Utama',
                    NamaKategori: null,
                    NamaRak: null,
                    HitungButa: true,
                    SistemTersembunyi: true,
                    TanggalSnapshot: '2026-09-24',
                    SnapshotPada: '2026-09-24T01:00:00Z',
                    TanggalPosting: null,
                    Catatan: null,
                    JumlahBaris: 0,
                    JumlahDihitung: 0,
                    TotalNilaiLebih: '0.00',
                    TotalNilaiKurang: '0.00',
                    AlasanBatal: null,
                    DibuatOleh: 'Sari',
                    DiajukanOleh: null,
                    DisetujuiOleh: null,
                    DisetujuiPada: null,
                }}
                Baris={[]}
                Penghitung={[]}
                Jurnal={[]}
                Riwayat={[]}
                Tindakan={{ Hitung: true, Ajukan: true, Kembalikan: false, Setujui: false, Batalkan: true }}
                Izin={IzinPenuh}
            />,
        );
    }

    async function PilihTambahan(kata: string, nama: RegExp) {
        await waitFor(() => expect(document.querySelector('[data-slot="popover-content"]')).toBeNull());
        fireEvent.click(screen.getByRole('combobox', { name: 'Tambah barang yang ditemukan' }));
        const cari = screen.getByPlaceholderText('Cari nama, SKU, atau barcode');
        fireEvent.change(cari, { target: { value: kata } });
        await waitFor(() => expect(screen.getByRole('option', { name: nama })).toBeTruthy());
        fireEvent.keyDown(cari, { key: 'Enter' });
    }

    it('barang tambahan: jumlah langsung aktif, barang berikutnya di atas, Enter kembali ke kotak cari', async () => {
        PasangOpname();

        await PilihTambahan('sasa', /Micin Sasa/);
        const pertama = await screen.findByRole('textbox', { name: 'Fisik Micin Sasa 250 gr' });
        await waitFor(() => expect(document.activeElement).toBe(pertama));
        fireEvent.change(pertama, { target: { value: '7' } });

        await PilihTambahan('ajino', /Ajino Moto/);
        const kedua = await screen.findByRole('textbox', { name: 'Fisik Ajino Moto 250 gr' });
        await waitFor(() => expect(document.activeElement).toBe(kedua));
        expect(kedua.compareDocumentPosition(pertama) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

        fireEvent.keyDown(kedua, { key: 'Enter' });
        const kotakCari = await screen.findByPlaceholderText('Cari nama, SKU, atau barcode');
        await waitFor(() => expect(document.activeElement).toBe(kotakCari));
        expect(tiruanRouter.post).not.toHaveBeenCalled();
        expect((pertama as HTMLInputElement).value).toBe('7');
    });
});
