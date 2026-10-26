import { cleanup, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { RenderUji } from '@/Komponen/Katalog/TiruanInertia';

import PemilihPelacakan from './Dokumen/PemilihPelacakan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

function SiapkanBatch(batch: { Uuid: string; NomorBatch: string }[]) {
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({
                        Batch: batch.map((b) => ({ ...b, TanggalKedaluwarsa: '2026-12-31', JumlahSisa: '10' })),
                        Seri: [],
                    }),
            }),
        ),
    );
}

function Wadah({ pelacakan = 'Batch' as 'Batch' | 'Seri' }) {
    const [pilihan, AturPilihan] = useState<{ uuid: string; teks: string } | null>(null);

    return (
        <>
            <PemilihPelacakan
                nama="Susu UHT"
                pelacakan={pelacakan}
                uuidProduk="P1"
                uuidGudang="G1"
                simbolSatuan="pcs"
                nilai={pilihan?.uuid ?? null}
                saatBerubah={(uuid, teks) => AturPilihan({ uuid, teks })}
            />
            <output aria-label="hasil">{pilihan === null ? '' : `${pilihan.uuid}|${pilihan.teks}`}</output>
        </>
    );
}

describe('PemilihPelacakan: batch terpilih otomatis', () => {
    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('batch pertama (kedaluwarsa terdekat) terpilih sendiri setelah daftar dimuat', async () => {
        SiapkanBatch([
            { Uuid: 'B1', NomorBatch: 'LOT-A' },
            { Uuid: 'B2', NomorBatch: 'LOT-B' },
        ]);
        const tampil = RenderUji(<Wadah />);

        await waitFor(() => expect(tampil.getByLabelText('hasil').textContent).toBe('B1|LOT-A'));
    });

    it('batch tunggal terpilih sendiri. Nomor seri tidak dipilihkan otomatis', async () => {
        SiapkanBatch([{ Uuid: 'B9', NomorBatch: 'LOT-Z' }]);
        const batch = RenderUji(<Wadah />);

        await waitFor(() => expect(batch.getByLabelText('hasil').textContent).toBe('B9|LOT-Z'));
        cleanup();

        SiapkanBatch([{ Uuid: 'B9', NomorBatch: 'LOT-Z' }]);
        const seri = RenderUji(<Wadah pelacakan="Seri" />);

        await waitFor(() => expect(vi.mocked(fetch)).toHaveBeenCalled());
        expect(seri.getByLabelText('hasil').textContent).toBe('');
    });
});
