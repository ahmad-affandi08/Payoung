import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PemilihProduk from '@/Komponen/Katalog/PemilihProduk';
import Panel from '@/Komponen/Kelola/Panel';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { FormatRupiah } from '@/Pustaka/Format';
import { LabelPembulatan, PersenIsian, RapikanPersen } from '@/Pustaka/HargaOtomatis';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsHargaOtomatis, UsulanHargaOtomatis } from '@/Tipe/Katalog';

const Alamat = '/kelola/produk/harga-otomatis';

/** Persen bertanda untuk perubahan harga: "+9,1%" atau "-12,5%". */
function FormatPerubahan(nilai: string): string {
    const angka = RapikanPersen(nilai);

    return nilai.startsWith('-') ? `${angka}%` : `+${angka}%`;
}

const OpsiArah = [
    { Nilai: 'NaikTurun', Label: 'Harga jual ikut turun' },
    { Nilai: 'HanyaNaik', Label: 'Harga jual tidak ikut turun' },
];

const OpsiCakupanKhusus = [
    { Nilai: 'Kategori', Label: 'Satu kategori' },
    { Nilai: 'Produk', Label: 'Satu produk' },
];

/** Satu baris untung khusus di dalam formulir. `kunci` hanya untuk React, tidak dikirim ke server. */
type BarisKhusus = {
    kunci: string;
    cakupan: 'Kategori' | 'Produk';
    uuidKategori: string;
    produk: { Uuid: string; Nama: string } | null;
    persen: string;
};

/** Bentuk baris yang dibandingkan untuk tahu ada yang berubah (tanpa `kunci`). */
function Ringkas(baris: BarisKhusus[]): string {
    return JSON.stringify(baris.map((b) => [b.cakupan, b.uuidKategori, b.produk?.Uuid ?? '', b.persen]));
}

function BarisBaru(): BarisKhusus {
    return { kunci: crypto.randomUUID(), cakupan: 'Kategori', uuidKategori: '', produk: null, persen: '' };
}

/** Baris sudah lengkap bila sasaran dan untungnya terisi. */
function CekLengkap(b: BarisKhusus): boolean {
    return b.persen.trim() !== '' && (b.cakupan === 'Kategori' ? b.uuidKategori !== '' : b.produk !== null);
}

/**
 * Harga otomatis (D-266, paket Pro ke atas): satu formulir dengan satu tombol Simpan untuk saklar, untung utama,
 * pembulatan, arah, batas persetujuan, dan untung khusus per kategori atau produk. Harga yang menunggu persetujuan
 * hanya tampil bila ada isinya, supaya layar awal tetap pendek.
 */
export default function HalamanHargaOtomatis(props: PropsHargaOtomatis) {
    const { Pengaturan, Umum, Pengecualian, Usulan, Izin, OpsiPembulatan, OpsiKategori } = props;
    const { props: bersama } = usePage<PropsBersamaAplikasi>();
    const galat = bersama.errors;
    const khususAwal: BarisKhusus[] = Pengecualian.map((a) => ({
        kunci: a.Uuid,
        cakupan: a.Cakupan === 'Produk' ? 'Produk' : 'Kategori',
        uuidKategori: a.UuidKategori ?? '',
        produk: a.Produk === null ? null : { Uuid: a.Produk.Uuid, Nama: a.Produk.Nama },
        persen: PersenIsian(a.PersenUntung),
    }));
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
    const [khusus, AturKhusus] = useState<BarisKhusus[]>(khususAwal);
    const [memproses, AturMemproses] = useState(false);
    const [konfirmasiHitung, AturKonfirmasiHitung] = useState(false);
    const [sedangMemutuskan, AturSedangMemutuskan] = useState<string | null>(null);
    const bolehUbah = Izin.Ubah;
    const berubah =
        aktif !== awal.aktif ||
        batas !== awal.batas ||
        persen !== awal.persen ||
        pembulatan !== awal.pembulatan ||
        arah !== awal.arah ||
        Ringkas(khusus) !== Ringkas(khususAwal);
    const isianMati = !bolehUbah || !aktif;
    const siapSimpan = berubah && persen.trim() !== '' && batas.trim() !== '' && khusus.every(CekLengkap);

    const UbahBaris = (kunci: string, perubahan: Partial<BarisKhusus>) =>
        AturKhusus((daftar) => daftar.map((b) => (b.kunci === kunci ? { ...b, ...perubahan } : b)));

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
                Khusus: khusus.map((b) => ({
                    Cakupan: b.cakupan,
                    UuidKategori: b.cakupan === 'Kategori' ? b.uuidKategori : null,
                    UuidProduk: b.cakupan === 'Produk' ? (b.produk?.Uuid ?? null) : null,
                    PersenUntung: b.persen,
                })),
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

                    <section aria-labelledby="judul-khusus" className="mt-6 border-t border-garis pt-4">
                        <h3 id="judul-khusus" className="text-subjudul font-semibold text-teks-utama">
                            Untung khusus
                        </h3>
                        <p className="mt-1 text-keterangan text-teks-sekunder">
                            Untuk kategori atau produk yang untungnya berbeda dari untung di atas.
                            {khusus.length === 0 ? ' Belum ada, semua produk memakai untung di atas.' : ''}
                        </p>
                        {khusus.length > 0 ? (
                            <ul className="mt-3 flex flex-col gap-3" aria-label="Untung khusus">
                                {khusus.map((b) => (
                                    <li
                                        key={b.kunci}
                                        className="grid gap-3 rounded-kontrol border border-garis p-3 sm:grid-cols-[11rem_minmax(0,1fr)_9rem_auto] sm:items-start"
                                    >
                                        <BidangPilihan
                                            label="Berlaku untuk"
                                            nilai={b.cakupan}
                                            opsi={OpsiCakupanKhusus}
                                            saatBerubah={(nilai) =>
                                                UbahBaris(b.kunci, {
                                                    cakupan: nilai === 'Produk' ? 'Produk' : 'Kategori',
                                                    uuidKategori: '',
                                                    produk: null,
                                                })
                                            }
                                            disabled={isianMati}
                                        />
                                        {b.cakupan === 'Kategori' ? (
                                            <BidangPilihan
                                                label="Kategori"
                                                nilai={b.uuidKategori}
                                                opsi={OpsiKategori.map((k) => ({ Nilai: k.Uuid, Label: k.Nama }))}
                                                saatBerubah={(nilai) => UbahBaris(b.kunci, { uuidKategori: nilai })}
                                                disabled={isianMati}
                                            />
                                        ) : (
                                            <PemilihProduk
                                                label="Produk"
                                                jenis={['Stok']}
                                                saatPilih={(terpilih) =>
                                                    UbahBaris(b.kunci, {
                                                        produk: { Uuid: terpilih.Uuid, Nama: terpilih.Nama },
                                                    })
                                                }
                                                disabled={isianMati}
                                                {...(b.produk === null
                                                    ? {}
                                                    : { keterangan: `Dipilih: ${b.produk.Nama}` })}
                                            />
                                        )}
                                        <BidangJumlah
                                            label="Untung"
                                            nilai={b.persen}
                                            saatBerubah={(nilai) => UbahBaris(b.kunci, { persen: nilai })}
                                            desimal={2}
                                            digitBulat={4}
                                            akhiran="%"
                                            disabled={isianMati}
                                        />
                                        {bolehUbah ? (
                                            <div className="sm:pt-6">
                                                <Tombol
                                                    varian="sekunder"
                                                    disabled={isianMati}
                                                    onClick={() =>
                                                        AturKhusus((daftar) =>
                                                            daftar.filter((x) => x.kunci !== b.kunci),
                                                        )
                                                    }
                                                >
                                                    Hapus
                                                </Tombol>
                                            </div>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        {bolehUbah ? (
                            <div className="mt-3">
                                <Tombol
                                    varian="sekunder"
                                    disabled={isianMati}
                                    onClick={() => AturKhusus((daftar) => [...daftar, BarisBaru()])}
                                >
                                    Tambah untung khusus
                                </Tombol>
                            </div>
                        ) : null}
                    </section>

                    {bolehUbah ? (
                        <div className="mt-6 flex flex-wrap items-center gap-2">
                            <Tombol type="submit" memproses={memproses} disabled={!siapSimpan}>
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
