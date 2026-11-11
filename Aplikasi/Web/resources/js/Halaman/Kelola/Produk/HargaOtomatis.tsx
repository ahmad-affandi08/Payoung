import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import DialogAturanHargaOtomatis, {
    LabelPembulatan,
    RapikanPersen,
} from '@/Komponen/Katalog/DialogAturanHargaOtomatis';
import Panel from '@/Komponen/Kelola/Panel';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { AturanHargaOtomatis, PropsHargaOtomatis, UsulanHargaOtomatis } from '@/Tipe/Katalog';

const Alamat = '/kelola/produk/harga-otomatis';

/** Persen tanpa ekor nol untuk isian: "20.0000" menjadi "20". */
function PersenIsian(nilai: string): string {
    return nilai.includes('.') ? nilai.replace(/\.?0+$/, '') : nilai;
}

/** Persen bertanda untuk perubahan harga: "+9,1%" atau "-12,5%". */
function FormatPerubahan(nilai: string): string {
    const angka = RapikanPersen(nilai);

    return nilai.startsWith('-') ? `${angka}%` : `+${angka}%`;
}

const OpsiArah = [
    { Nilai: 'NaikTurun', Label: 'Harga jual ikut turun' },
    { Nilai: 'HanyaNaik', Label: 'Harga jual tidak ikut turun' },
];

/**
 * Harga otomatis (D-266, paket Pro ke atas): satu formulir untuk saklar, untung, pembulatan, arah, dan batas persetujuan,
 * dengan satu tombol Simpan. Untung khusus per kategori atau produk dan harga yang menunggu persetujuan hanya tampil
 * bila ada isinya atau fiturnya menyala, supaya layar awal tetap pendek.
 */
export default function HalamanHargaOtomatis(props: PropsHargaOtomatis) {
    const { Pengaturan, Umum, Pengecualian, Usulan, Izin, OpsiPembulatan } = props;
    const { props: bersama } = usePage<PropsBersamaAplikasi>();
    const galat = bersama.errors;
    const awal = {
        aktif: Pengaturan.Aktif,
        batas: PersenIsian(Pengaturan.BatasPerubahanPersen),
        persen: PersenIsian(Umum.PersenUntung),
        pembulatan: String(Umum.Pembulatan),
        arah: Umum.Arah as string,
    };
    const [aktif, AturAktif] = useState(awal.aktif);
    const [batas, AturBatas] = useState(awal.batas);
    const [persen, AturPersen] = useState(awal.persen);
    const [pembulatan, AturPembulatan] = useState(awal.pembulatan);
    const [arah, AturArah] = useState(awal.arah);
    const [memproses, AturMemproses] = useState(false);
    const [dialogKhusus, AturDialogKhusus] = useState<{ aturan: AturanHargaOtomatis | null } | null>(null);
    const [hapus, AturHapus] = useState<AturanHargaOtomatis | null>(null);
    const [konfirmasiHitung, AturKonfirmasiHitung] = useState(false);
    const [sedangMemutuskan, AturSedangMemutuskan] = useState<string | null>(null);
    const bolehUbah = Izin.Ubah;
    const berubah =
        aktif !== awal.aktif ||
        batas !== awal.batas ||
        persen !== awal.persen ||
        pembulatan !== awal.pembulatan ||
        arah !== awal.arah;
    const isianMati = !bolehUbah || !aktif;

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.put(
            `${Alamat}/pengaturan`,
            {
                Aktif: aktif,
                BatasPerubahanPersen: batas,
                PersenUntung: persen,
                Pembulatan: Number(pembulatan),
                Arah: arah,
            },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    const Putuskan = (usulan: UsulanHargaOtomatis, aksi: 'terapkan' | 'tolak') =>
        router.post(
            `${Alamat}/usulan/${usulan.Uuid}/${aksi}`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturSedangMemutuskan(usulan.Uuid),
                onFinish: () => AturSedangMemutuskan(null),
            },
        );

    return (
        <TataLetakAplikasi judul="Harga otomatis">
            <DaftarGalatServer
                galat={galat}
                kecuali={['Umum', 'BatasPerubahanPersen', 'PersenUntung', 'Pembulatan', 'Arah']}
            />
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Harga jual ikut berubah saat harga beli berubah. Harga beli diambil dari penerimaan barang atau belanja
                stok yang terakhir.
            </p>

            <form onSubmit={Simpan} noValidate aria-label="Pengaturan harga otomatis">
                <Panel judul="Pengaturan" idJudul="judul-pengaturan">
                    <KotakCentang
                        label="Harga jual mengikuti harga beli"
                        nilai={aktif}
                        saatBerubah={AturAktif}
                        disabled={!bolehUbah}
                    />
                    <div className="mt-3 grid gap-4 sm:grid-cols-2">
                        <BidangJumlah
                            label="Untung dari harga beli"
                            nilai={persen}
                            saatBerubah={AturPersen}
                            desimal={2}
                            digitBulat={4}
                            akhiran="%"
                            galat={galat.PersenUntung}
                            disabled={isianMati}
                            required
                            keterangan="Contoh: harga beli Rp 10.000 dengan untung 20% menjadi harga jual Rp 12.000."
                        />
                        <BidangPilihan
                            label="Pembulatan harga jual"
                            nilai={pembulatan}
                            opsi={OpsiPembulatan.map((n) => ({ Nilai: String(n), Label: LabelPembulatan(n) }))}
                            saatBerubah={AturPembulatan}
                            galat={galat.Pembulatan}
                            disabled={isianMati}
                        />
                        <BidangPilihan
                            label="Kalau harga beli turun"
                            nilai={arah}
                            opsi={OpsiArah}
                            saatBerubah={AturArah}
                            galat={galat.Arah}
                            disabled={isianMati}
                        />
                        <BidangJumlah
                            label="Minta persetujuan kalau harga berubah lebih dari"
                            nilai={batas}
                            saatBerubah={AturBatas}
                            desimal={2}
                            digitBulat={3}
                            akhiran="%"
                            galat={galat.BatasPerubahanPersen}
                            disabled={isianMati}
                            required
                            keterangan="Supaya salah ketik harga beli tidak langsung mengubah harga di kasir. Isi 1 sampai 100."
                        />
                    </div>
                    {bolehUbah ? (
                        <div className="mt-4 flex flex-wrap items-center gap-2">
                            <Tombol type="submit" memproses={memproses} disabled={!berubah}>
                                Simpan
                            </Tombol>
                            {Pengaturan.Aktif && !berubah ? (
                                <Tombol varian="sekunder" onClick={() => AturKonfirmasiHitung(true)}>
                                    Hitung ulang semua harga sekarang
                                </Tombol>
                            ) : null}
                        </div>
                    ) : null}
                </Panel>
            </form>

            {Usulan.length > 0 ? (
                <Panel
                    judul="Menunggu persetujuan Anda"
                    idJudul="judul-usulan"
                    keterangan="Harga beli berubah cukup besar, jadi harga jual belum diubah. Setujui bila harga belinya benar, tolak bila salah ketik."
                >
                    <ul className="flex flex-col divide-y divide-garis" aria-label="Harga yang menunggu persetujuan">
                        {Usulan.map((u) => (
                            <li key={u.Uuid} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <span className="min-w-0 flex-1">
                                    <span className="block font-semibold text-teks-utama">{u.Produk.Nama}</span>
                                    <span className="block text-isi tabular-nums text-teks-sekunder">
                                        {FormatRupiah(u.HargaLama)} menjadi {FormatRupiah(u.HargaBaru)} (
                                        {FormatPerubahan(u.PerubahanPersen)})
                                    </span>
                                </span>
                                {bolehUbah ? (
                                    <span className="flex gap-2">
                                        <Tombol
                                            memproses={sedangMemutuskan === u.Uuid}
                                            onClick={() => Putuskan(u, 'terapkan')}
                                        >
                                            Setujui
                                        </Tombol>
                                        <Tombol
                                            varian="sekunder"
                                            disabled={sedangMemutuskan === u.Uuid}
                                            onClick={() => Putuskan(u, 'tolak')}
                                        >
                                            Tolak
                                        </Tombol>
                                    </span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}

            {Pengaturan.Aktif || Pengecualian.length > 0 ? (
                <Panel
                    judul="Untung khusus"
                    idJudul="judul-khusus"
                    keterangan="Untuk kategori atau produk yang untungnya berbeda dari pengaturan di atas."
                    aksi={
                        bolehUbah ? (
                            <Tombol varian="sekunder" onClick={() => AturDialogKhusus({ aturan: null })}>
                                Tambah untung khusus
                            </Tombol>
                        ) : undefined
                    }
                >
                    {Pengecualian.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">Belum ada. Semua produk memakai untung di atas.</p>
                    ) : (
                        <ul className="flex flex-col divide-y divide-garis" aria-label="Untung khusus">
                            {Pengecualian.map((a) => (
                                <li key={a.Uuid} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-semibold text-teks-utama">{a.Sasaran}</span>
                                        <span className="block text-isi text-teks-sekunder">
                                            Untung {RapikanPersen(a.PersenUntung)}%{a.Aktif ? '' : ' (tidak dipakai)'}
                                        </span>
                                    </span>
                                    {bolehUbah ? (
                                        <span className="flex gap-2">
                                            <Tombol varian="sekunder" onClick={() => AturDialogKhusus({ aturan: a })}>
                                                Ubah
                                            </Tombol>
                                            <Tombol varian="sekunder" onClick={() => AturHapus(a)}>
                                                Hapus
                                            </Tombol>
                                        </span>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            ) : null}

            {dialogKhusus !== null ? (
                <DialogAturanHargaOtomatis
                    aturan={dialogKhusus.aturan}
                    opsi={props}
                    umum={{ ...Umum, Pembulatan: Number(pembulatan), Arah: arah as PropsHargaOtomatis['Umum']['Arah'] }}
                    saatTutup={() => AturDialogKhusus(null)}
                />
            ) : null}
            {hapus !== null ? (
                <DialogKonfirmasi
                    judul="Hapus untung khusus ini?"
                    labelAksi="Hapus"
                    varian="bahaya"
                    saatBatal={() => AturHapus(null)}
                    saatKonfirmasi={() =>
                        router.delete(`${Alamat}/aturan/${hapus.Uuid}`, {
                            preserveScroll: true,
                            onSuccess: () => AturHapus(null),
                        })
                    }
                >
                    {hapus.Sasaran} akan memakai untung di pengaturan utama. Harga yang sudah berubah tetap seperti
                    sekarang.
                </DialogKonfirmasi>
            ) : null}
            {konfirmasiHitung ? (
                <DialogKonfirmasi
                    judul="Hitung ulang semua harga?"
                    labelAksi="Hitung ulang"
                    saatBatal={() => AturKonfirmasiHitung(false)}
                    saatKonfirmasi={() =>
                        router.post(
                            `${Alamat}/jalankan`,
                            {},
                            { preserveScroll: true, onSuccess: () => AturKonfirmasiHitung(false) },
                        )
                    }
                >
                    Harga jual semua produk berstok dihitung ulang dari harga beli terakhirnya. Perubahan besar menunggu
                    persetujuan Anda. Hasilnya muncul di notifikasi.
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
