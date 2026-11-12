import { router, usePage } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import Tombol from '@/Komponen/Formulir/Tombol';
import BarisUntungKhusus, {
    CekBarisLengkap,
    type BarisKhusus,
} from '@/Komponen/Katalog/HargaOtomatis/BarisUntungKhusus';
import BilahSimpanHarga from '@/Komponen/Katalog/HargaOtomatis/BilahSimpanHarga';
import KartuArahHarga from '@/Komponen/Katalog/HargaOtomatis/KartuArahHarga';
import PanelPratinjauHarga from '@/Komponen/Katalog/HargaOtomatis/PanelPratinjauHarga';
import PilihanCepatPersen from '@/Komponen/Katalog/HargaOtomatis/PilihanCepatPersen';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Label } from '@/Komponen/Ui/label';
import { Switch } from '@/Komponen/Ui/switch';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatPerubahanPersen, HitungContohHarga, LabelPembulatanPendek, PersenIsian } from '@/Pustaka/HargaOtomatis';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { ArahHargaOtomatis, MasukanHargaOtomatis, PropsHargaOtomatis, UsulanHargaOtomatis } from '@/Tipe/Katalog';

const Alamat = '/kelola/produk/harga-otomatis';
const PilihanUntung = [10, 15, 20, 25, 30];
const PilihanBatas = [10, 20, 30, 50];

/** Bentuk daftar untung khusus yang dibandingkan untuk tahu ada yang berubah (tanpa `kunci` dan `sunting`). */
function Ringkas(baris: BarisKhusus[]): string {
    return JSON.stringify(baris.map((b) => [b.cakupan, b.uuidKategori, b.produk?.Uuid ?? '', b.persen]));
}

function BarisBaru(): BarisKhusus {
    return {
        kunci: crypto.randomUUID(),
        cakupan: 'Kategori',
        uuidKategori: '',
        produk: null,
        persen: '',
        sunting: true,
    };
}

/**
 * Harga otomatis (D-266, D-268, paket Pro ke atas): harga jual ikut berubah saat harga beli berubah. Pengaturannya tiga
 * langkah (berapa untung, kalau harga beli turun, pengaman salah ketik) ditambah untung khusus yang opsional, dengan
 * pratinjau di sisi kanan yang menunjukkan harga mana yang akan berubah sebelum disimpan. Satu bilah simpan menempel di
 * dasar layar bila ada yang diubah. Harga yang menunggu persetujuan tampil sebagai banner dan daftar bila ada isinya.
 */
export default function HalamanHargaOtomatis(props: PropsHargaOtomatis) {
    const { Pengaturan, Umum, Pengecualian, Usulan, Izin, OpsiPembulatan, OpsiKategori, ProdukBerstok } = props;
    const { props: bersama } = usePage<PropsBersamaAplikasi>();
    const galat = bersama.errors;
    const khususAwal: BarisKhusus[] = Pengecualian.map((a) => ({
        kunci: a.Uuid,
        cakupan: a.Cakupan === 'Produk' ? 'Produk' : 'Kategori',
        uuidKategori: a.UuidKategori ?? '',
        produk: a.Produk === null ? null : { Uuid: a.Produk.Uuid, Nama: a.Produk.Nama },
        persen: PersenIsian(a.PersenUntung),
        sunting: false,
    }));
    const awal = {
        aktif: Pengaturan.Aktif,
        batas: PersenIsian(Pengaturan.BatasPerubahanPersen),
        persen: PersenIsian(Umum.PersenUntung),
        pembulatan: String(Umum.Pembulatan),
        arah: Umum.Arah,
    };
    const [aktif, AturAktif] = useState(awal.aktif);
    const [batas, AturBatas] = useState(awal.batas);
    const [persen, AturPersen] = useState(awal.persen);
    const [pembulatan, AturPembulatan] = useState(awal.pembulatan);
    const [arah, AturArah] = useState<ArahHargaOtomatis>(awal.arah);
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
    const lengkap = persen.trim() !== '' && batas.trim() !== '' && khusus.every(CekBarisLengkap);
    const siapSimpan = berubah && lengkap;
    const contoh = HitungContohHarga(persen, Number(pembulatan));

    /** Isian layar apa adanya, atau null bila belum lengkap (pratinjau menunggu). */
    const masukan = useMemo<MasukanHargaOtomatis | null>(
        () =>
            persen.trim() === '' || batas.trim() === '' || !khusus.every(CekBarisLengkap)
                ? null
                : {
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
        [persen, batas, pembulatan, arah, khusus],
    );

    const UbahBaris = (kunci: string, perubahan: Partial<BarisKhusus>) =>
        AturKhusus((daftar) => daftar.map((b) => (b.kunci === kunci ? { ...b, ...perubahan } : b)));

    const Batalkan = () => {
        AturAktif(awal.aktif);
        AturBatas(awal.batas);
        AturPersen(awal.persen);
        AturPembulatan(awal.pembulatan);
        AturArah(awal.arah);
        AturKhusus(khususAwal);
    };

    const Kirim = (hitungUlang: boolean) => {
        if (masukan === null) {
            return;
        }

        router.put(
            `${Alamat}/pengaturan`,
            { Aktif: aktif, ...masukan, ...(hitungUlang ? { HitungUlang: true } : {}) },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        Kirim(false);
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
                Harga jual ikut harga beli terakhir dari penerimaan barang atau belanja stok.
            </p>

            {Usulan.length > 0 ? (
                <div
                    role="status"
                    className="flex flex-col gap-1 rounded-panel border border-peringatan bg-peringatan-lembut px-4 py-3 text-isi sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-3"
                >
                    <span className="font-semibold text-peringatan">
                        {Usulan.length.toLocaleString('id-ID')} harga menunggu persetujuan Anda
                    </span>
                    <span className="text-teks-utama sm:min-w-64 sm:flex-1">
                        Harga beli naik cukup besar, jadi harga jual belum diubah.
                    </span>
                    <a href="#usulan" className="font-semibold text-brand underline underline-offset-2">
                        Lihat dan putuskan
                    </a>
                </div>
            ) : null}

            <form onSubmit={Simpan} noValidate aria-label="Pengaturan harga otomatis">
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)] lg:items-start">
                    <div className="flex flex-col gap-4">
                        <Panel>
                            <div className="flex flex-wrap items-center gap-3">
                                <Switch
                                    id="saklar-harga-otomatis"
                                    checked={aktif}
                                    disabled={!bolehUbah}
                                    onCheckedChange={AturAktif}
                                />
                                <Label
                                    htmlFor="saklar-harga-otomatis"
                                    className="text-subjudul font-semibold text-teks-utama"
                                >
                                    Harga jual mengikuti harga beli
                                </Label>
                                <span
                                    className={`ml-auto rounded-full px-3 py-0.5 text-label font-semibold ${aktif ? 'bg-brand-tint text-brand' : 'bg-permukaan-redup text-teks-sekunder'}`}
                                >
                                    {aktif ? 'Aktif' : 'Mati'}
                                </span>
                            </div>
                            <p className="text-isi text-teks-sekunder">
                                {aktif
                                    ? `${ProdukBerstok.toLocaleString('id-ID')} produk berstok akan mengikuti aturan ini.`
                                    : 'Saat ini mati, jadi harga jual tidak berubah sendiri. Aturan di bawah tetap tersimpan, tetapi baru dipakai setelah dinyalakan.'}
                            </p>
                            {bolehUbah && Pengaturan.Aktif && !berubah ? (
                                <div>
                                    <Tombol varian="sekunder" onClick={() => AturKonfirmasiHitung(true)}>
                                        Hitung ulang semua harga sekarang
                                    </Tombol>
                                </div>
                            ) : null}
                        </Panel>

                        <Panel
                            judul="1. Berapa untung yang Anda mau?"
                            keterangan="Untung dihitung dari harga beli sebelum pajak."
                        >
                            <PilihanCepatPersen
                                label="Untung dari harga beli"
                                nilai={persen}
                                saatBerubah={AturPersen}
                                pilihan={PilihanUntung}
                                galat={galat.PersenUntung}
                                disabled={!bolehUbah}
                                required
                            />
                            <div className="max-w-sm">
                                <BidangPilihan
                                    label="Bulatkan harga jual ke"
                                    nilai={pembulatan}
                                    opsi={OpsiPembulatan.map((n) => ({
                                        Nilai: String(n),
                                        Label: LabelPembulatanPendek(n),
                                    }))}
                                    saatBerubah={AturPembulatan}
                                    galat={galat.Pembulatan}
                                    disabled={!bolehUbah}
                                />
                            </div>
                            {contoh !== null ? (
                                <p className="rounded-panel bg-permukaan-redup px-3 py-2 text-isi tabular-nums text-teks-utama">
                                    Contoh: beli {FormatRupiah(contoh.Modal)}, untung {persen.replace('.', ',')}%, harga
                                    jual <span className="font-semibold">{FormatRupiah(contoh.Harga)}</span>
                                </p>
                            ) : null}
                        </Panel>

                        <Panel judul="2. Kalau harga beli turun" keterangan="Pilih apa yang terjadi pada harga jual.">
                            <KartuArahHarga nilai={arah} saatBerubah={AturArah} disabled={!bolehUbah} />
                            {galat.Arah ? (
                                <p className="text-keterangan font-semibold text-bahaya">{galat.Arah}</p>
                            ) : null}
                        </Panel>

                        <Panel
                            judul="3. Pengaman salah ketik"
                            keterangan="Kalau harga jual akan berubah terlalu jauh, Anda diminta setuju dulu."
                        >
                            <PilihanCepatPersen
                                label="Minta persetujuan kalau harga berubah lebih dari"
                                nilai={batas}
                                saatBerubah={AturBatas}
                                pilihan={PilihanBatas}
                                digitBulat={3}
                                galat={galat.BatasPerubahanPersen}
                                disabled={!bolehUbah}
                                required
                                keterangan="Isi 1 sampai 100."
                            />
                            <p className="border-l-4 border-aksen bg-peringatan-lembut px-3 py-2 text-isi text-teks-utama">
                                Contoh: harga beli salah diketik 10 kali lipat, jadi harga jual menunggu persetujuan
                                Anda.
                            </p>
                        </Panel>

                        <Panel
                            judul="Untung berbeda untuk produk tertentu"
                            keterangan={
                                persen.trim() === ''
                                    ? 'Produk lain memakai untung utama.'
                                    : `Produk lain tetap memakai untung ${persen.replace('.', ',')}%.`
                            }
                        >
                            {khusus.length > 0 ? (
                                <ul className="flex flex-col gap-2" aria-label="Untung khusus">
                                    {khusus.map((b) => (
                                        <BarisUntungKhusus
                                            key={b.kunci}
                                            baris={b}
                                            opsiKategori={OpsiKategori}
                                            bolehUbah={bolehUbah}
                                            saatUbah={(perubahan) => UbahBaris(b.kunci, perubahan)}
                                            saatHapus={() =>
                                                AturKhusus((daftar) => daftar.filter((x) => x.kunci !== b.kunci))
                                            }
                                        />
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-isi text-teks-sekunder">
                                    Belum ada. Semua produk memakai untung utama.
                                </p>
                            )}
                            {bolehUbah ? (
                                <div>
                                    <Tombol
                                        varian="sekunder"
                                        onClick={() => AturKhusus((daftar) => [...daftar, BarisBaru()])}
                                    >
                                        Tambah untung khusus
                                    </Tombol>
                                </div>
                            ) : null}
                        </Panel>
                    </div>

                    <div className="lg:sticky lg:top-4">
                        <PanelPratinjauHarga masukan={masukan} menyala={Pengaturan.Aktif} />
                    </div>
                </div>

                {bolehUbah && berubah ? (
                    <BilahSimpanHarga
                        aktif={aktif}
                        siapSimpan={siapSimpan}
                        memproses={memproses}
                        saatBatal={Batalkan}
                        saatSimpanDanHitung={() => Kirim(true)}
                    />
                ) : null}
            </form>

            {Usulan.length > 0 ? (
                <div id="usulan" tabIndex={-1} className="scroll-mt-4 outline-none">
                    <Panel
                        judul="Menunggu persetujuan Anda"
                        idJudul="judul-usulan"
                        keterangan="Harga beli naik cukup besar. Setujui bila benar, tolak bila salah ketik."
                    >
                        <ul
                            className="flex flex-col divide-y divide-garis"
                            aria-label="Harga yang menunggu persetujuan"
                        >
                            {Usulan.map((u) => (
                                <li key={u.Uuid} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-semibold text-teks-utama">{u.Produk.Nama}</span>
                                        <span className="block text-isi tabular-nums text-teks-sekunder">
                                            {FormatRupiah(u.HargaLama)} menjadi {FormatRupiah(u.HargaBaru)} (
                                            {FormatPerubahanPersen(u.PerubahanPersen)})
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
                </div>
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
