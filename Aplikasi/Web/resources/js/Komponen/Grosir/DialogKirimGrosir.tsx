import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangDaftarTeks from '@/Komponen/Formulir/BidangDaftarTeks';
import TombolPindaiNomorSeri from '@/Komponen/Persediaan/TombolPindaiNomorSeri';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { FormatJumlahStok } from '@/Pustaka/FormatPersediaan';
import { KalikanDesimal } from '@/Pustaka/HitungDesimal';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisDetailPesananGrosir, OpsiGudangGrosir } from '@/Tipe/Grosir';

/**
 * Dialog penyerahan barang (surat jalan, BR-12.2). Jumlah kirim bawaannya = seluruh sisa baris, karena itulah yang
 * paling sering terjadi; operator menguranginya bila hanya sebagian yang dimuat ke kendaraan.
 *
 * Yang **tidak** ada di sini: harga. Harganya sudah disepakati di pesanan dan disalin server dari snapshot SO, jadi
 * tidak ada bidang yang bisa menggesernya saat barang keluar gudang.
 */
export default function DialogKirimGrosir({
    uuidPesanan,
    baris,
    opsiGudang,
    hariIni,
    saatTutup,
}: {
    uuidPesanan: string;
    baris: BarisDetailPesananGrosir[];
    opsiGudang: OpsiGudangGrosir[];
    hariIni: string;
    saatTutup: () => void;
}) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const bisaDikirim = baris.filter((b) => Number(b.SisaKirim) > 0);
    const [uuidGudang, AturGudang] = useState(opsiGudang[0]?.Uuid ?? '');
    const [tanggal, AturTanggal] = useState(hariIni);
    const [namaPengirim, AturNamaPengirim] = useState('');
    const [nomorKendaraan, AturNomorKendaraan] = useState('');
    const [jumlah, AturJumlah] = useState<Record<number, string>>(
        Object.fromEntries(bisaDikirim.map((b) => [b.Urutan, b.SisaKirim])),
    );
    const [nomorSeri, AturNomorSeri] = useState<Record<number, string[]>>({});
    const [memproses, AturMemproses] = useState(false);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.post(
            `/kelola/grosir/pesanan/${uuidPesanan}/kirim`,
            {
                UuidGudang: uuidGudang,
                Tanggal: tanggal,
                NamaPengirim: namaPengirim,
                NomorKendaraan: nomorKendaraan,
                Baris: bisaDikirim
                    .filter((b) => Number(jumlah[b.Urutan] ?? '0') > 0)
                    .map((b) => ({
                        Urutan: b.Urutan,
                        Jumlah: jumlah[b.Urutan] ?? '0',
                        ...(b.Pelacakan === 'Seri' ? { NomorSeri: nomorSeri[b.Urutan] ?? [] } : {}),
                    })),
            },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: saatTutup,
            },
        );
    };

    const adaYangDikirim = bisaDikirim.some((b) => Number(jumlah[b.Urutan] ?? '0') > 0);

    return (
        <DialogFormulir
            judul="Kirim barang (surat jalan)"
            keterangan="Surat jalan langsung diposting: stok keluar, HPP & pendapatan diakui, dan PPN keluaran terutang pada tanggal penyerahan ini."
            galatUmum={props.errors.Umum}
            saatTutup={saatTutup}
        >
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label="Kirim barang">
                <BidangPilihan
                    label="Ambil stok dari"
                    nilai={uuidGudang}
                    opsi={opsiGudang.map((g) => ({
                        Nilai: g.Uuid,
                        Label: g.NamaOutlet === null ? g.Nama : `${g.Nama} (${g.NamaOutlet})`,
                    }))}
                    saatBerubah={AturGudang}
                    galat={props.errors.UuidGudang}
                    required
                />
                <BidangTeks
                    label="Tanggal penyerahan"
                    nilai={tanggal}
                    saatBerubah={AturTanggal}
                    galat={props.errors.Tanggal}
                    inputMode="numeric"
                    maxLength={10}
                    required
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <BidangTeks
                        label="Nama pengirim"
                        nilai={namaPengirim}
                        saatBerubah={AturNamaPengirim}
                        galat={props.errors.NamaPengirim}
                        maxLength={100}
                    />
                    <BidangTeks
                        label="Nomor kendaraan"
                        nilai={nomorKendaraan}
                        saatBerubah={AturNomorKendaraan}
                        galat={props.errors.NomorKendaraan}
                        maxLength={30}
                    />
                </div>
                <fieldset className="flex flex-col gap-3">
                    <legend className="text-label font-semibold text-teks-sekunder">Jumlah yang diserahkan</legend>
                    {bisaDikirim.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">Semua barang pesanan ini sudah diserahkan.</p>
                    ) : (
                        bisaDikirim.map((b) => (
                            <div key={b.Urutan} className="flex flex-col gap-1">
                                <BidangTeks
                                    label={`${String(b.Urutan)}. ${b.NamaProduk}`}
                                    nilai={jumlah[b.Urutan] ?? ''}
                                    saatBerubah={(nilai) => AturJumlah((lama) => ({ ...lama, [b.Urutan]: nilai }))}
                                    keterangan={`Sisa ${FormatJumlahStok(b.SisaKirim, b.SimbolSatuan)}${b.Pelacakan === 'Batch' ? ' | batch dipilih otomatis (kedaluwarsa terdekat dulu)' : ''}`}
                                    inputMode="decimal"
                                    maxLength={20}
                                />
                                {b.Pelacakan === 'Seri' ? (
                                    <BidangDaftarTeks
                                        label={`Nomor seri ${b.NamaProduk}`}
                                        nilai={nomorSeri[b.Urutan] ?? []}
                                        saatBerubah={(daftar) =>
                                            AturNomorSeri((lama) => ({ ...lama, [b.Urutan]: daftar }))
                                        }
                                        keterangan={`Satu nomor seri per baris; isi ${KalikanDesimal(jumlah[b.Urutan] ?? '0', b.Konversi, 0)} nomor sesuai jumlah yang diserahkan.`}
                                    />
                                ) : null}
                                {b.Pelacakan === 'Seri' ? (
                                    <div>
                                        <TombolPindaiNomorSeri
                                            label={`Pindai nomor seri dengan kamera ${b.NamaProduk}`}
                                            daftar={nomorSeri[b.Urutan] ?? []}
                                            saatBerubah={(daftar) =>
                                                AturNomorSeri((lama) => ({ ...lama, [b.Urutan]: daftar }))
                                            }
                                        />
                                    </div>
                                ) : null}
                            </div>
                        ))
                    )}
                </fieldset>
                <div className="flex flex-wrap justify-end gap-2">
                    <Tombol type="button" varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                    <Tombol type="submit" memproses={memproses} disabled={!adaYangDikirim || uuidGudang === ''}>
                        Kirim & posting
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}
