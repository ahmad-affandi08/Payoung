import { BagianRingkasan, BarisRingkasan, TampilYaTidak } from '@/Komponen/Formulir/Wizard/RingkasanWizard';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import type { Kota, Pilihan } from '@/Tipe/Organisasi';

import type { IsianPeriksaOutlet, KunciLangkahOutlet, LangkahOutlet } from './LangkahOutlet';

type IsianRingkasan = IsianPeriksaOutlet & { Alamat: string; Telepon: string; KodeKota: string; PungutPbjt: boolean };

type PropsRingkasanOutlet = {
    isian: IsianRingkasan;
    langkah: LangkahOutlet[];
    merek: Pilihan[];
    kota: Kota[];
    saatUbah: (kunci: KunciLangkahOutlet) => void;
};

/**
 * Ringkasan baca-saja langkah Tinjau outlet. Kode outlet disorot karena tidak bisa diubah setelah outlet bertransaksi
 * (BR-02.2): inilah alasan outlet baru layak ditinjau dulu.
 */
export default function RingkasanOutlet({ isian, langkah, merek, kota, saatUbah }: PropsRingkasanOutlet) {
    const Cari = (kunci: KunciLangkahOutlet) => langkah.find((item) => item.Kunci === kunci);
    const langkahIdentitas = Cari('Identitas');
    const langkahLokasi = Cari('Lokasi');
    const langkahPajak = Cari('Pajak');
    const langkahKanvas = Cari('Kanvas');
    const kotaTerpilih = kota.find((item) => item.Kode === isian.KodeKota);

    return (
        <div className="flex flex-col gap-4">
            <Pemberitahuan jenis="info" judul="Periksa kode outlet">
                Kode <span className="font-mono font-semibold">{isian.Kode.toUpperCase()}</span> tercetak di nomor
                dokumen dan nama perangkat kasir, dan tidak bisa diubah setelah outlet bertransaksi.
            </Pemberitahuan>
            {langkahIdentitas ? (
                <BagianRingkasan langkah={langkahIdentitas} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Nama outlet">{isian.Nama}</BarisRingkasan>
                    <BarisRingkasan nama="Kode outlet">
                        <span className="font-mono">{isian.Kode.toUpperCase()}</span>
                    </BarisRingkasan>
                    {merek.length > 1 ? (
                        <BarisRingkasan nama="Merek">
                            {merek.find((item) => item.Nilai === isian.Merek)?.Label ?? 'Belum dipilih'}
                        </BarisRingkasan>
                    ) : null}
                </BagianRingkasan>
            ) : null}
            {langkahLokasi ? (
                <BagianRingkasan langkah={langkahLokasi} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Alamat">
                        {isian.Alamat.trim() === '' ? 'Tidak diisi' : isian.Alamat}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Telepon">
                        {isian.Telepon.trim() === '' ? 'Tidak diisi' : isian.Telepon}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Kabupaten/kota">
                        {kotaTerpilih ? kotaTerpilih.Nama : 'Tidak dipilih'}
                    </BarisRingkasan>
                    <BarisRingkasan nama="Zona waktu">{isian.ZonaWaktu}</BarisRingkasan>
                    <BarisRingkasan nama="Jam tutup buku">{isian.JamTutupBuku}</BarisRingkasan>
                </BagianRingkasan>
            ) : null}
            {langkahPajak ? (
                <BagianRingkasan langkah={langkahPajak} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Status PKP">{TampilYaTidak(isian.Pkp)}</BarisRingkasan>
                    {isian.Pkp ? (
                        <BarisRingkasan nama="NITKU">{isian.Nitku === '' ? 'Tidak diisi' : isian.Nitku}</BarisRingkasan>
                    ) : null}
                    <BarisRingkasan nama="Memungut PBJT makanan & minuman">
                        {TampilYaTidak(isian.PungutPbjt)}
                    </BarisRingkasan>
                </BagianRingkasan>
            ) : null}
            {langkahKanvas ? (
                <BagianRingkasan langkah={langkahKanvas} saatUbah={saatUbah}>
                    <BarisRingkasan nama="Outlet kanvas">{TampilYaTidak(isian.Kanvas)}</BarisRingkasan>
                    {isian.Kanvas ? (
                        <BarisRingkasan nama="Nomor kendaraan">
                            {isian.NomorKendaraan.trim() === '' ? 'Tidak diisi' : isian.NomorKendaraan}
                        </BarisRingkasan>
                    ) : null}
                </BagianRingkasan>
            ) : null}
        </div>
    );
}
