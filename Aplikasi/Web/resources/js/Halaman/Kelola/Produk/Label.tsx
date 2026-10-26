import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import {
    CariMedia,
    HitungTotalStiker,
    KeBarisCetak,
    MaksJumlahPerBaris,
    MaksTotalStiker,
    MuatPreferensi,
    PreferensiBawaan,
    SimpanPreferensi,
    AmbilBarcodeCetak,
    type BarisCetak,
    type PreferensiLabel,
} from '@/Komponen/Katalog/Label/BantuanLabel';
import AreaCetakLabel from '@/Komponen/Katalog/Label/AreaCetakLabel';
import DaftarBarisLabel from '@/Komponen/Katalog/Label/DaftarBarisLabel';
import LembarLabel from '@/Komponen/Katalog/Label/LembarLabel';
import PencarianBarisLabel from '@/Komponen/Katalog/Label/PencarianBarisLabel';
import PengaturanLabel from '@/Komponen/Katalog/Label/PengaturanLabel';
import Panel from '@/Komponen/Kelola/Panel';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { Button } from '@/Komponen/Ui/button';
import { HitungLebarMinimumMm, SusunBarcode } from '@/Pustaka/Barcode';
import { GalatPermintaan, KirimJson } from '@/Pustaka/PermintaanJson';
import {
    BagiKeHalaman,
    CekMediaSah,
    HitungTataHalaman,
    HitungTataIsi,
    MaksGeserMm,
    SebarkanStiker,
} from '@/Pustaka/UkuranLabel';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { BarisLabelServer, HasilBarcodeLabel, PropsLabelProduk } from '@/Tipe/Katalog';

const pikselPerMm = 96 / 25.4;

function Jepit(nilai: number, min: number, maks: number): number {
    return Math.min(maks, Math.max(min, nilai));
}

/** Lebar elemen yang diamati; 0 sebelum terukur (atau di lingkungan tanpa ResizeObserver). */
function useLebarElemen<T extends HTMLElement>(): [React.RefObject<T | null>, number] {
    const rujukan = useRef<T | null>(null);
    const [lebar, AturLebar] = useState(0);

    useEffect(() => {
        const elemen = rujukan.current;
        if (elemen === null || typeof ResizeObserver === 'undefined') {
            return;
        }
        const pengamat = new ResizeObserver(([entri]) => AturLebar(entri?.contentRect.width ?? 0));
        pengamat.observe(elemen);

        return () => pengamat.disconnect();
    }, []);

    return [rujukan, lebar];
}

/**
 * PRD-10 (F-03): cetak label harga/barcode. Banyak barang sekaligus dengan jumlah stiker masing-masing, ke lembar A4
 * stiker atau label gulung lewat jendela cetak peramban (printer sistem). Barang tanpa barcode dicetak sebagai label
 * harga; barcode internal hanya dibuat bila pengguna memintanya dan mengonfirmasi.
 */
export default function HalamanLabelProduk({ Baris, Terpotong, Batas, Izin }: PropsLabelProduk) {
    const [baris, AturBaris] = useState<BarisCetak[]>(() => Baris.map(KeBarisCetak));
    const [preferensi, AturPreferensi] = useState<PreferensiLabel>(() => MuatPreferensi() ?? PreferensiBawaan);
    const [lewati, AturLewati] = useState(0);
    const [halamanAktif, AturHalamanAktif] = useState(0);
    const [jumlahSemua, AturJumlahSemua] = useState('');
    const [antreanBarcode, AturAntreanBarcode] = useState<string[] | null>(null);
    const [membuatBarcode, AturMembuatBarcode] = useState(false);
    const [galatBarcode, AturGalatBarcode] = useState<string | null>(null);
    const [rujukanPratinjau, lebarPratinjau] = useLebarElemen<HTMLDivElement>();

    useEffect(() => SimpanPreferensi(preferensi), [preferensi]);

    const media = CariMedia(preferensi.KodeMedia, preferensi.Kustom);
    const galatMedia = CekMediaSah(media);
    const geserSah =
        Number.isFinite(preferensi.GeserXMm) &&
        Number.isFinite(preferensi.GeserYMm) &&
        Math.abs(preferensi.GeserXMm) <= MaksGeserMm &&
        Math.abs(preferensi.GeserYMm) <= MaksGeserMm;
    const geser = {
        XMm: Number.isFinite(preferensi.GeserXMm) ? Jepit(preferensi.GeserXMm, -MaksGeserMm, MaksGeserMm) : 0,
        YMm: Number.isFinite(preferensi.GeserYMm) ? Jepit(preferensi.GeserYMm, -MaksGeserMm, MaksGeserMm) : 0,
    };
    const tata = useMemo(() => HitungTataHalaman(media, geser), [media, geser.XMm, geser.YMm]); // eslint-disable-line react-hooks/exhaustive-deps
    const lewatiSah = media.Jenis === 'A4' ? Jepit(Math.trunc(lewati), 0, Math.max(0, tata.PerHalaman - 1)) : 0;
    const halaman = useMemo(
        () => (galatMedia === null ? BagiKeHalaman(SebarkanStiker(baris), tata.PerHalaman, lewatiSah) : []),
        [baris, galatMedia, tata.PerHalaman, lewatiSah],
    );
    const total = HitungTotalStiker(baris);
    const nomorHalaman = Math.min(halamanAktif, Math.max(0, halaman.length - 1));

    const aktif = baris.filter((b) => b.Jumlah > 0);
    const tanpaBarcode = aktif.filter((b) => b.Barcode.length === 0);
    const tanpaHarga = aktif.filter((b) => b.Harga === null);
    const lebarDalamMm = media.LebarMm - 2;
    const terlaluSempit = preferensi.Isi.Barcode
        ? aktif.filter((b) => {
              const kode = AmbilBarcodeCetak(b);

              return kode !== null && HitungLebarMinimumMm(SusunBarcode(kode).Modul.length) > lebarDalamMm;
          })
        : [];
    const isiTerlaluBanyak =
        preferensi.Isi.Barcode &&
        aktif.some((b) => AmbilBarcodeCetak(b) !== null) &&
        !HitungTataIsi(media.TinggiMm, preferensi.Isi, true).Muat;
    const tanpaIsi = !Object.values(preferensi.Isi).some(Boolean);
    const bolehCetak = galatMedia === null && total > 0 && total <= MaksTotalStiker && !tanpaIsi && geserSah;

    const UbahBaris = (uuid: string, perubahan: Partial<BarisCetak>) =>
        AturBaris((lama) => lama.map((b) => (b.Uuid === uuid ? { ...b, ...perubahan } : b)));

    const TambahBaris = (baru: BarisLabelServer) =>
        AturBaris((lama) => {
            const ada = lama.find((b) => b.Uuid === baru.Uuid);

            if (ada !== undefined) {
                return lama.map((b) =>
                    b.Uuid === baru.Uuid ? { ...b, Jumlah: Math.min(MaksJumlahPerBaris, b.Jumlah + 1) } : b,
                );
            }

            return lama.length >= Batas.Baris ? lama : [...lama, KeBarisCetak(baru)];
        });

    const BuatBarcode = async () => {
        if (antreanBarcode === null) {
            return;
        }
        AturMembuatBarcode(true);
        AturGalatBarcode(null);

        try {
            const hasil = await KirimJson<HasilBarcodeLabel>('/kelola/produk/label/barcode-internal', {
                Satuan: antreanBarcode,
            });

            AturBaris((lama) =>
                lama.map((b) => {
                    const kena = hasil.Data.find((h) => h.Uuid === b.Uuid);

                    return kena === undefined
                        ? b
                        : {
                              ...b,
                              Barcode: b.Barcode.includes(kena.Barcode) ? b.Barcode : [...b.Barcode, kena.Barcode],
                              BarcodeDipilih: b.BarcodeDipilih ?? kena.Barcode,
                          };
                }),
            );
            AturAntreanBarcode(null);
        } catch (galat) {
            AturGalatBarcode(
                galat instanceof GalatPermintaan
                    ? galat.message
                    : 'Barcode belum bisa dibuat. Coba lagi beberapa saat lagi.',
            );
        } finally {
            AturMembuatBarcode(false);
        }
    };

    const skala = lebarPratinjau > 0 ? Math.min(1.2, lebarPratinjau / (tata.LebarHalamanMm * pikselPerMm)) : 0.6;

    return (
        <TataLetakAplikasi judul="Cetak label">
            <Head title="Cetak label" />
            <div className="flex flex-wrap items-center gap-2">
                <Link href="/kelola/produk" className="text-label font-semibold text-brand underline">
                    Kembali ke daftar produk
                </Link>
            </div>

            {Terpotong ? (
                <Pemberitahuan jenis="peringatan" judul="Daftar dipotong">
                    Hanya {String(Batas.Baris)} baris pertama yang dimuat. Cetak dalam beberapa kali atau kurangi
                    pilihan.
                </Pemberitahuan>
            ) : null}

            <Panel
                judul="Barang yang dicetak"
                keterangan="Satu baris untuk satu satuan: botol dan dus punya label sendiri sesuai barcodenya masing-masing."
            >
                <div className="max-w-lg">
                    <PencarianBarisLabel saatPilih={TambahBaris} />
                </div>

                {baris.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">
                        Belum ada barang. Cari atau pindai barcode di atas, atau pilih beberapa produk di daftar produk
                        lalu pilih Cetak label.
                    </p>
                ) : (
                    <>
                        <div className="flex flex-wrap items-end gap-2">
                            <div className="w-44">
                                <BidangTeks
                                    label="Jumlah stiker untuk semua"
                                    nilai={jumlahSemua}
                                    saatBerubah={(teks) => AturJumlahSemua(teks.replace(/\D/g, ''))}
                                    inputMode="numeric"
                                    maxLength={3}
                                />
                            </div>
                            <Tombol
                                varian="sekunder"
                                disabled={jumlahSemua === ''}
                                onClick={() =>
                                    AturBaris((lama) =>
                                        lama.map((b) => ({
                                            ...b,
                                            Jumlah: Math.min(MaksJumlahPerBaris, Number(jumlahSemua)),
                                        })),
                                    )
                                }
                            >
                                Terapkan ke semua
                            </Tombol>
                            <Tombol varian="sekunder" onClick={() => AturBaris([])}>
                                Kosongkan daftar
                            </Tombol>
                            {Izin.Kelola && baris.some((b) => b.Barcode.length === 0) ? (
                                <Tombol
                                    varian="sekunder"
                                    onClick={() =>
                                        AturAntreanBarcode(
                                            baris.filter((b) => b.Barcode.length === 0).map((b) => b.Uuid),
                                        )
                                    }
                                >
                                    Buat barcode untuk yang belum punya
                                </Tombol>
                            ) : null}
                        </div>
                        <DaftarBarisLabel
                            baris={baris}
                            saatUbahJumlah={(uuid, jumlah) => UbahBaris(uuid, { Jumlah: jumlah })}
                            saatPilihBarcode={(uuid, barcode) => UbahBaris(uuid, { BarcodeDipilih: barcode })}
                            saatHapus={(uuid) => AturBaris((lama) => lama.filter((b) => b.Uuid !== uuid))}
                            bolehBuatBarcode={Izin.Kelola}
                            saatBuatBarcode={(uuid) => AturAntreanBarcode([uuid])}
                        />
                    </>
                )}
            </Panel>

            <Panel judul="Ukuran dan isi label">
                <PengaturanLabel
                    preferensi={preferensi}
                    saatUbah={(perubahan) => AturPreferensi((lama) => ({ ...lama, ...perubahan }))}
                    lewati={lewati}
                    saatLewati={AturLewati}
                    perHalaman={tata.PerHalaman}
                />
            </Panel>

            <Panel
                judul="Pratinjau dan cetak"
                keterangan={
                    total === 0
                        ? 'Tambahkan barang dan isi jumlah stikernya.'
                        : `${String(total)} stiker pada ${String(halaman.length)} ${media.Jenis === 'A4' ? 'lembar' : 'label'}.`
                }
                aksi={
                    <Button disabled={!bolehCetak} onClick={() => window.print()}>
                        Cetak label
                    </Button>
                }
            >
                {galatMedia !== null ? (
                    <Pemberitahuan jenis="bahaya" judul="Ukuran label belum sah">
                        {galatMedia}
                    </Pemberitahuan>
                ) : null}
                {!geserSah ? (
                    <Pemberitahuan jenis="bahaya" judul="Geseran tidak sah">
                        Isi geseran dengan angka antara -{String(MaksGeserMm)} dan {String(MaksGeserMm)} mm.
                    </Pemberitahuan>
                ) : null}
                {tanpaIsi ? (
                    <Pemberitahuan jenis="peringatan" judul="Stiker kosong">
                        Pilih minimal satu isi stiker.
                    </Pemberitahuan>
                ) : null}
                {total > MaksTotalStiker ? (
                    <Pemberitahuan jenis="bahaya" judul="Terlalu banyak stiker">
                        Maksimal {String(MaksTotalStiker)} stiker sekali cetak. Sekarang {String(total)}. Kurangi jumlah
                        atau cetak dalam beberapa kali.
                    </Pemberitahuan>
                ) : null}
                {preferensi.Isi.Barcode && tanpaBarcode.length > 0 ? (
                    <Pemberitahuan jenis="info" judul={`${String(tanpaBarcode.length)} barang belum punya barcode`}>
                        Barang ini dicetak sebagai label harga saja:{' '}
                        {tanpaBarcode
                            .slice(0, 5)
                            .map((b) => `${b.Nama} (${b.SimbolSatuan})`)
                            .join(', ')}
                        {tanpaBarcode.length > 5 ? `, dan ${String(tanpaBarcode.length - 5)} lainnya` : ''}.
                    </Pemberitahuan>
                ) : null}
                {preferensi.Isi.Harga && tanpaHarga.length > 0 ? (
                    <Pemberitahuan jenis="peringatan" judul={`${String(tanpaHarga.length)} barang belum punya harga`}>
                        Harga dicetak sebagai tanda hubung (—):{' '}
                        {tanpaHarga
                            .slice(0, 5)
                            .map((b) => `${b.Nama} (${b.SimbolSatuan})`)
                            .join(', ')}
                        {tanpaHarga.length > 5 ? `, dan ${String(tanpaHarga.length - 5)} lainnya` : ''}.
                    </Pemberitahuan>
                ) : null}
                {terlaluSempit.length > 0 ? (
                    <Pemberitahuan jenis="peringatan" judul="Barcode terlalu rapat untuk lebar label">
                        Barcode {terlaluSempit.map((b) => b.BarcodeDipilih).join(', ')} mungkin sulit dipindai pada
                        label selebar {String(media.LebarMm)} mm. Pilih label yang lebih lebar.
                    </Pemberitahuan>
                ) : null}
                {isiTerlaluBanyak ? (
                    <Pemberitahuan jenis="peringatan" judul="Isi stiker terlalu banyak untuk tinggi label">
                        Barcode jadi terlalu pendek untuk dipindai. Matikan SKU atau nama barang, atau pilih label yang
                        lebih tinggi.
                    </Pemberitahuan>
                ) : null}

                <div ref={rujukanPratinjau} className="flex w-full max-w-3xl flex-col gap-2">
                    {halaman.length > 0 ? (
                        <>
                            <div
                                className="overflow-hidden rounded-kontrol border border-garis"
                                style={{
                                    width: tata.LebarHalamanMm * pikselPerMm * skala,
                                    height: tata.TinggiHalamanMm * pikselPerMm * skala,
                                }}
                            >
                                <div
                                    aria-hidden="true"
                                    style={{
                                        width: `${String(tata.LebarHalamanMm)}mm`,
                                        transform: `scale(${String(skala)})`,
                                        transformOrigin: 'top left',
                                    }}
                                >
                                    <LembarLabel
                                        pratinjau
                                        halaman={[halaman[nomorHalaman] ?? []]}
                                        baris={baris}
                                        isi={preferensi.Isi}
                                        media={media}
                                        tata={tata}
                                    />
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <Tombol
                                    varian="sekunder"
                                    disabled={nomorHalaman === 0}
                                    onClick={() => AturHalamanAktif(nomorHalaman - 1)}
                                >
                                    Sebelumnya
                                </Tombol>
                                <span className="text-isi text-teks-sekunder tabular-nums" aria-live="polite">
                                    Halaman {String(nomorHalaman + 1)} dari {String(halaman.length)}
                                </span>
                                <Tombol
                                    varian="sekunder"
                                    disabled={nomorHalaman >= halaman.length - 1}
                                    onClick={() => AturHalamanAktif(nomorHalaman + 1)}
                                >
                                    Berikutnya
                                </Tombol>
                            </div>
                        </>
                    ) : null}
                </div>

                <p className="text-keterangan text-teks-sekunder">
                    Di jendela cetak: pilih printer dan ukuran kertas{' '}
                    {`${String(tata.LebarHalamanMm)} × ${String(tata.TinggiHalamanMm)} mm`}, skala 100% (bukan Sesuaikan
                    ke halaman), margin Tidak ada, dan matikan Header dan footer. Cetak satu lembar percobaan di kertas
                    biasa lebih dulu. Bila posisinya bergeser, atur geseran di atas.
                </p>
            </Panel>

            {galatMedia === null && halaman.length > 0 ? (
                <AreaCetakLabel tata={tata}>
                    <LembarLabel halaman={halaman} baris={baris} isi={preferensi.Isi} media={media} tata={tata} />
                </AreaCetakLabel>
            ) : null}

            {antreanBarcode !== null ? (
                <DialogKonfirmasi
                    judul="Buat barcode internal?"
                    labelAksi="Buat barcode"
                    varian="utama"
                    memproses={membuatBarcode}
                    saatKonfirmasi={() => void BuatBarcode()}
                    saatBatal={() => {
                        AturAntreanBarcode(null);
                        AturGalatBarcode(null);
                    }}
                >
                    <p>
                        {String(antreanBarcode.length)} barang akan diberi nomor barcode buatan toko yang tersimpan di
                        produk dan dipakai kasir saat memindai.
                    </p>
                    <p>
                        Buat hanya untuk barang yang memang tidak punya barcode dari pabrik. Barang yang sudah punya
                        barcode tidak diubah.
                    </p>
                    {galatBarcode !== null ? (
                        <Pemberitahuan jenis="bahaya" judul="Barcode belum dibuat">
                            {galatBarcode}
                        </Pemberitahuan>
                    ) : null}
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
