import { usePage } from '@inertiajs/react';

import Panel from '@/Komponen/Kelola/Panel';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { daftarPengaturan } from '@/Pustaka/DaftarPengaturan';
import {
    BandingkanMenuSektor,
    KumpulkanButirBersektor,
    TentukanMenuSektor,
    type ButirMenuBersektor,
} from '@/Pustaka/MenuSektor';
import { daftarMenu } from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

type PropsPanelMenuSektor = {
    /** Daftar sektor sebelum penggantian jenis usaha (diambil sebelum mengirim); bila ada, perubahan menu ditampilkan. */
    sebelum?: string[] | null;
};

const butirBersektor = KumpulkanButirBersektor(
    daftarMenu,
    daftarPengaturan.flatMap((grup) => grup.butir),
);

function NamaButir(butir: ButirMenuBersektor): string {
    return butir.Lokasi === 'Pengaturan' ? `${butir.Label} (di Pengaturan)` : butir.Label;
}

function DaftarNama({ judul, butir, kosong }: { judul: string; butir: ButirMenuBersektor[]; kosong: string }) {
    return (
        <li className="flex flex-col gap-1">
            <span className="text-label font-semibold text-teks-utama">{judul}</span>
            <span className="text-keterangan text-teks-sekunder">
                {butir.length === 0 ? kosong : butir.map(NamaButir).join(' | ')}
            </span>
        </li>
    );
}

/**
 * Ringkasan menu yang menyesuaikan jenis usaha (audit #33, D-165). Sebagian besar menu sama untuk semua jenis usaha;
 * hanya sedikit butir yang bergantung sektor. Panel ini menjelaskannya dan, sesudah jenis usaha diganti, menunjukkan
 * apa yang baru muncul dan apa yang disembunyikan, supaya perubahannya terlihat.
 */
export default function PanelMenuSektor({ sebelum = null }: PropsPanelMenuSektor) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const sektor = props.SektorOutlet ?? [];
    const menu = TentukanMenuSektor(butirBersektor, sektor);
    const perubahan = sebelum === null ? null : BandingkanMenuSektor(butirBersektor, sebelum, sektor);

    return (
        <div className="grid gap-4">
            {perubahan !== null ? (
                <Pemberitahuan jenis="sukses" judul="Menu sudah menyesuaikan jenis usaha baru">
                    {perubahan.Muncul.length === 0 && perubahan.Hilang.length === 0 ? (
                        <>
                            Tidak ada butir menu yang berubah, karena jenis usaha lama dan baru memakai menu yang sama.
                            Perbedaannya ada di tampilan kasir, akun, kategori, dan fitur outlet.
                        </>
                    ) : (
                        <>
                            {perubahan.Muncul.length > 0
                                ? `Kini tersedia: ${perubahan.Muncul.map(NamaButir).join(' | ')}. `
                                : ''}
                            {perubahan.Hilang.length > 0
                                ? `Disembunyikan karena tidak dipakai jenis usaha ini: ${perubahan.Hilang.map(NamaButir).join(' | ')}.`
                                : ''}
                        </>
                    )}
                </Pemberitahuan>
            ) : null}
            <Panel
                judul="Menu menurut jenis usaha"
                tingkat="h2"
                keterangan="Sebagian besar menu sama untuk semua jenis usaha. Hanya butir berikut yang mengikuti jenis usaha Anda."
            >
                {sektor.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">
                        Jenis usaha belum dipilih, jadi semua menu ditampilkan. Pilih jenis usaha di atas untuk
                        menyesuaikannya.
                    </p>
                ) : (
                    <ul className="grid gap-3" aria-label="Menu menurut jenis usaha">
                        <DaftarNama
                            judul={`Tampil untuk usaha Anda (${String(menu.Tampil.length)})`}
                            butir={menu.Tampil}
                            kosong="Tidak ada."
                        />
                        <DaftarNama
                            judul={`Disembunyikan (${String(menu.Disembunyikan.length)})`}
                            butir={menu.Disembunyikan}
                            kosong="Tidak ada. Semua menu khusus jenis usaha tampil."
                        />
                    </ul>
                )}
            </Panel>
        </div>
    );
}
