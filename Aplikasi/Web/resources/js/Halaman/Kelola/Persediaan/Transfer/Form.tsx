import { Link, router, usePage } from '@inertiajs/react';
import { Trash2Icon } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihPelacakan from '@/Komponen/Persediaan/Dokumen/PemilihPelacakan';
import { useEntriCepat } from '@/Komponen/Persediaan/EntriCepat';
import PemilihProdukStok, { type ProdukStokTerpilih } from '@/Komponen/Persediaan/PemilihProdukStok';
import { BuatUlid } from '@/Komponen/Persediaan/UlidKlien';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { Button } from '@/Komponen/Ui/button';
import { FormatJumlahStok, FormatLabelGudang } from '@/Pustaka/FormatPersediaan';
import { AmbilTandaDesimal, CekDesimalValid } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisFormTransferStok, PropsFormTransferStok } from '@/Tipe/DokumenPersediaan';

const alamat = '/kelola/persediaan/transfer';

type BarisForm = BarisFormTransferStok & { Kunci: string; SaldoDiGudang: string | null };

let nomorKunci = 0;

function BuatKunci(): string {
    nomorKunci += 1;

    return `transfer-${String(nomorKunci)}`;
}

/** Galat lokal satu baris transfer (server tetap memeriksa ulang). */
export function PeriksaBarisTransfer(baris: BarisFormTransferStok): string | null {
    if (baris.Pelacakan === 'Seri') {
        return baris.UuidNomorSeri ? null : 'Pilih nomor seri.';
    }

    if (!CekDesimalValid(baris.Jumlah) || AmbilTandaDesimal(baris.Jumlah) <= 0) {
        return 'Isi jumlah lebih dari 0.';
    }

    if (!baris.BolehDesimal && baris.Jumlah.includes('.')) {
        return 'Jumlah harus bilangan bulat.';
    }

    return baris.Pelacakan === 'Batch' && !baris.UuidBatchStok ? 'Pilih batch.' : null;
}

/** F-05b: form draf transfer stok (buat/ubah). Pengiriman dilakukan dari halaman detail. */
export default function HalamanFormTransferStok({
    Mode,
    Transfer,
    OpsiGudangAsal,
    OpsiGudangTujuan,
    HariIni,
    BatasBaris,
    Awal,
}: PropsFormTransferStok) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galatServer = props.errors;
    const [uuidBaru] = useState(() => (Transfer === null ? BuatUlid() : null));
    const [asal, AturAsal] = useState(Transfer?.UuidGudangAsal ?? Awal?.UuidGudangAsal ?? '');
    const [tujuan, AturTujuan] = useState(Transfer?.UuidGudangTujuan ?? Awal?.UuidGudangTujuan ?? '');
    const [tanggal, AturTanggal] = useState(Transfer?.Tanggal ?? HariIni);
    const [catatan, AturCatatan] = useState(Transfer?.Catatan ?? '');
    const [daftar, AturDaftar] = useState<BarisForm[]>(() =>
        (Transfer?.Baris ?? Awal?.Baris ?? []).map((b) => ({ ...b, Kunci: BuatKunci(), SaldoDiGudang: null })),
    );
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    // Audit kemudahan pakai #13: tombol utama menyimpan lalu langsung memproses dokumen; draf jadi pilihan kedua.
    const lanjutkan = useRef(false);
    const entri = useEntriCepat('transfer');
    const judul = Mode === 'Buat' ? 'Buat transfer stok' : 'Ubah draf transfer';
    const galatAsal = galatServer.UuidGudangAsal ?? (periksa && asal === '' ? 'Pilih lokasi asal.' : undefined);
    const galatTujuan =
        galatServer.UuidGudangTujuan ??
        (periksa && tujuan === ''
            ? 'Pilih lokasi tujuan.'
            : periksa && tujuan === asal
              ? 'Lokasi tujuan harus berbeda dari lokasi asal.'
              : undefined);
    const galatDaftar =
        galatServer.Baris ?? (periksa && daftar.length === 0 ? 'Tambahkan minimal satu produk.' : undefined);

    const Ubah = (kunci: string, perubahan: Partial<BarisForm>) =>
        AturDaftar((lama) => lama.map((b) => (b.Kunci === kunci ? { ...b, ...perubahan } : b)));

    // Barang baru ditaruh paling atas, tepat di bawah kotak cari, dan jumlahnya langsung difokuskan (entri cepat).
    const Tambah = (p: ProdukStokTerpilih) => {
        const kunci = BuatKunci();

        if (p.Pelacakan !== 'Seri') {
            entri.CatatBarisBaru(kunci);
        }

        AturDaftar((lama) => [
            {
                Kunci: kunci,
                UuidProduk: p.Uuid,
                NamaProduk: p.Nama,
                Sku: p.Sku,
                SimbolSatuan: p.SimbolSatuan,
                BolehDesimal: p.BolehDesimal,
                Pelacakan: p.Pelacakan,
                SaldoDiGudang: p.SaldoDiGudang,
                Jumlah: p.Pelacakan === 'Seri' ? '1' : '',
                UuidBatchStok: null,
                NomorBatch: null,
                UuidNomorSeri: null,
                NomorSeri: null,
            },
            ...lama,
        ]);
    };

    const GantiAsal = (nilai: string) => {
        AturAsal(nilai);
        AturDaftar((lama) =>
            lama.map((b) => ({
                ...b,
                SaldoDiGudang: null,
                UuidBatchStok: null,
                NomorBatch: null,
                UuidNomorSeri: null,
                NomorSeri: null,
            })),
        );
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        if (
            asal === '' ||
            tujuan === '' ||
            asal === tujuan ||
            daftar.length === 0 ||
            daftar.some((b) => PeriksaBarisTransfer(b) !== null)
        ) {
            return;
        }

        const masukan = {
            UuidGudangAsal: asal,
            UuidGudangTujuan: tujuan,
            Tanggal: tanggal,
            Catatan: catatan.trim() === '' ? null : catatan.trim(),
            Baris: daftar.map((b) => ({
                UuidProduk: b.UuidProduk,
                Jumlah: b.Jumlah,
                UuidBatchStok: b.UuidBatchStok,
                UuidNomorSeri: b.UuidNomorSeri,
            })),
        };
        const opsi = { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) };

        if (Transfer === null) {
            router.post(
                alamat,
                {
                    ...masukan,
                    Uuid: uuidBaru ?? BuatUlid(),
                    ...(Awal?.UuidPermintaanStok ? { UuidPermintaanStok: Awal.UuidPermintaanStok } : {}),
                    Lanjutkan: lanjutkan.current,
                },
                opsi,
            );
        } else {
            router.put(
                `${alamat}/${Transfer.Uuid}`,
                { ...masukan, VersiDiubahPada: Transfer.VersiDiubahPada, Lanjutkan: lanjutkan.current },
                opsi,
            );
        }
    };

    return (
        <TataLetakAplikasi judul={judul}>
            <DaftarGalatServer
                galat={galatServer}
                kecuali={['UuidGudangAsal', 'UuidGudangTujuan', 'Tanggal', 'Catatan', 'Baris', 'UuidPermintaanStok']}
            />
            {Awal?.UuidPermintaanStok ? (
                <Pemberitahuan jenis="info" judul={`Menjawab permintaan ${Awal.NomorPermintaan ?? ''}`.trim()}>
                    Barang dan jumlah diisi dari permintaan. Ubah seperlunya, pilih batch atau nomor seri bila produknya
                    dilacak, lalu kirim. Produk bernomor seri tidak diisi otomatis. Permintaan baru terhitung terpenuhi
                    saat transfer ini dikirim.
                    {galatServer.UuidPermintaanStok ? ` ${galatServer.UuidPermintaanStok}` : ''}
                </Pemberitahuan>
            ) : null}
            {Awal?.Catatan ? (
                <Pemberitahuan jenis="info" judul="Isi otomatis dari sisa stok">
                    {Awal.Catatan}
                </Pemberitahuan>
            ) : null}
            <form onSubmit={Simpan} noValidate aria-label={judul} className="flex flex-col gap-4">
                <Panel
                    judul="Dokumen"
                    idJudul="judul-dokumen-transfer"
                    keterangan="Stok berpindah saat transfer dikirim, bukan saat draf disimpan."
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangOutlet
                            label="Dari lokasi"
                            nilai={asal}
                            kosong="Pilih lokasi asal"
                            opsi={OpsiGudangAsal.filter((g) => g.Aktif || g.Uuid === asal).map((g) => ({
                                Nilai: g.Uuid,
                                Label: FormatLabelGudang(g),
                            }))}
                            saatBerubah={GantiAsal}
                            galat={galatAsal}
                        />
                        <BidangOutlet
                            label="Ke lokasi"
                            nilai={tujuan}
                            kosong="Pilih lokasi tujuan"
                            opsi={OpsiGudangTujuan.filter((g) => g.Uuid !== asal && (g.Aktif || g.Uuid === tujuan)).map(
                                (g) => ({ Nilai: g.Uuid, Label: FormatLabelGudang(g) }),
                            )}
                            saatBerubah={AturTujuan}
                            galat={galatTujuan}
                        />
                        <PemilihTanggal
                            id="tanggal-transfer"
                            label="Tanggal kirim"
                            nilai={tanggal}
                            max={HariIni}
                            required
                            saatBerubah={AturTanggal}
                            galat={galatServer.Tanggal}
                        />
                    </div>
                    <BidangTeksPanjang
                        label="Catatan (opsional)"
                        nilai={catatan}
                        saatBerubah={AturCatatan}
                        galat={galatServer.Catatan}
                        baris={2}
                        maksimal={500}
                    />
                </Panel>

                <Panel
                    judul="Barang"
                    idJudul="judul-barang-transfer"
                    keterangan={`${daftar.length.toLocaleString('id-ID')} dari ${BatasBaris.toLocaleString('id-ID')} baris`}
                >
                    <PemilihProdukStok
                        label="Tambah produk"
                        id={entri.idCari}
                        alihkanFokusSetelahPilih={entri.AlihkanFokusSetelahPilih}
                        uuidGudang={asal === '' ? null : asal}
                        saatPilih={Tambah}
                        kecuali={daftar.filter((b) => b.Pelacakan === 'Tidak').map((b) => b.UuidProduk)}
                        disabled={asal === '' || daftar.length >= BatasBaris}
                        keterangan={
                            asal === ''
                                ? 'Pilih lokasi asal dulu agar stok saat ini ikut tampil.'
                                : 'Barang yang dipilih muncul paling atas. Ketik jumlahnya lalu tekan Enter untuk lanjut ke barang berikutnya. Produk batch & nomor seri: satu baris per batch atau per nomor seri.'
                        }
                    />
                    {galatDaftar ? <GalatBidang>{galatDaftar}</GalatBidang> : null}
                    {daftar.length === 0 ? (
                        <p className="rounded-kontrol border border-dashed border-garis-input px-3 py-2 text-isi text-teks-sekunder">
                            Belum ada barang. Cari produk di atas untuk menambah baris.
                        </p>
                    ) : (
                        <ul className="flex flex-col divide-y divide-garis" aria-label="Barang yang dikirim">
                            {daftar.map((b, indeks) => {
                                const galat =
                                    galatServer[`Baris.${String(indeks)}.Jumlah`] ??
                                    (periksa ? (PeriksaBarisTransfer(b) ?? undefined) : undefined);

                                return (
                                    <li
                                        key={b.Kunci}
                                        className="grid gap-3 py-3 sm:grid-cols-[minmax(0,1fr)_12rem_minmax(0,14rem)_auto] sm:items-start"
                                    >
                                        <div className="min-w-0">
                                            <span className="block font-semibold break-words text-teks-utama">
                                                {b.NamaProduk}
                                            </span>
                                            <span className="block text-keterangan text-teks-sekunder">
                                                <span className="font-mono">{b.Sku ?? 'Tanpa SKU'}</span>
                                                {b.SaldoDiGudang !== null
                                                    ? ` | stok asal ${FormatJumlahStok(b.SaldoDiGudang, b.SimbolSatuan)}`
                                                    : null}
                                            </span>
                                        </div>
                                        {b.Pelacakan === 'Seri' ? (
                                            <p className="py-2 text-right text-teks-utama tabular-nums">
                                                1 {b.SimbolSatuan}
                                            </p>
                                        ) : (
                                            <BidangJumlah
                                                label={`Jumlah ${b.NamaProduk}`}
                                                labelTersembunyi
                                                id={entri.IdJumlah(b.Kunci)}
                                                saatEnter={entri.KembaliKeCari}
                                                nilai={b.Jumlah}
                                                saatBerubah={(jumlah) => Ubah(b.Kunci, { Jumlah: jumlah })}
                                                desimal={b.BolehDesimal ? 4 : 0}
                                                akhiran={b.SimbolSatuan}
                                                galat={galat}
                                                required
                                            />
                                        )}
                                        {b.Pelacakan === 'Tidak' ? (
                                            <span className="hidden sm:block" />
                                        ) : (
                                            <PemilihPelacakan
                                                nama={b.NamaProduk}
                                                pelacakan={b.Pelacakan}
                                                uuidProduk={b.UuidProduk}
                                                uuidGudang={asal}
                                                simbolSatuan={b.SimbolSatuan}
                                                nilai={b.Pelacakan === 'Batch' ? b.UuidBatchStok : b.UuidNomorSeri}
                                                saatBerubah={(uuid, teks) =>
                                                    Ubah(
                                                        b.Kunci,
                                                        b.Pelacakan === 'Batch'
                                                            ? { UuidBatchStok: uuid, NomorBatch: teks }
                                                            : { UuidNomorSeri: uuid, NomorSeri: teks },
                                                    )
                                                }
                                                galat={b.Pelacakan === 'Seri' ? galat : undefined}
                                            />
                                        )}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Hapus baris ${b.NamaProduk}`}
                                            onClick={() =>
                                                AturDaftar((lama) => lama.filter((x) => x.Kunci !== b.Kunci))
                                            }
                                        >
                                            <Trash2Icon aria-hidden="true" />
                                        </Button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses} onClick={() => (lanjutkan.current = true)}>
                        Simpan & kirim
                    </Tombol>
                    <Tombol
                        type="submit"
                        varian="sekunder"
                        disabled={memproses}
                        onClick={() => (lanjutkan.current = false)}
                    >
                        Simpan draf
                    </Tombol>
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={Transfer === null ? alamat : `${alamat}/${Transfer.Uuid}`}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
