import { Link } from '@inertiajs/react';
import { useState } from 'react';

import DialogKendaraan, { AlamatKendaraan } from '@/Komponen/Bengkel/DialogKendaraan';
import { AlamatPerintahKerja, BuatKolomPerintahKerja } from '@/Komponen/Bengkel/KolomPerintahKerja';
import Tombol from '@/Komponen/Formulir/Tombol';
import { KartuKeteranganGrosir, KeteranganGrosir } from '@/Komponen/Grosir/BagianDokumenGrosir';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import { JudulUnit, KataUnit, LabelPengenal } from '@/Pustaka/UnitServis';
import type { PropsDetailKendaraan } from '@/Tipe/Bengkel';

/** Riwayat servis satu kendaraan (§9.10): semua perintah kerja beserta penjualan yang menagihnya. */
export default function HalamanDetailKendaraan({ Kendaraan: k, Riwayat }: PropsDetailKendaraan) {
    const [ubah, AturUbah] = useState(false);

    return (
        <TataLetakAplikasi
            judul={`${KataUnit(k.Jenis)} ${JudulUnit(k)}`}
            jejak={[{ label: `${KataUnit(k.Jenis)} pelanggan`, href: AlamatKendaraan }]}
        >
            <div className="flex flex-wrap items-center gap-3">
                <LabelStatus jenis={k.Aktif ? 'sukses' : 'netral'} teks={k.Aktif ? 'Aktif' : 'Diarsipkan'} />
            </div>
            <AksiHalaman>
                <Tombol varian="sekunder" onClick={() => AturUbah(true)}>
                    Ubah {KataUnit(k.Jenis).toLowerCase()}
                </Tombol>
                {k.Aktif ? (
                    <Button asChild className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatPerintahKerja}/buat?kendaraan=${k.Uuid}`}>Buat perintah kerja</Link>
                    </Button>
                ) : null}
            </AksiHalaman>
            <KartuKeteranganGrosir>
                <KeteranganGrosir label={KataUnit(k.Jenis)}>{k.Label}</KeteranganGrosir>
                {k.Jenis === 'Perangkat' ? (
                    <KeteranganGrosir label={LabelPengenal(k.Jenis)}>
                        <span className="font-mono">{k.NomorPolisi ?? '-'}</span>
                    </KeteranganGrosir>
                ) : null}
                <KeteranganGrosir label="Pemilik">
                    {k.Pelanggan.Uuid ? (
                        <Link href={`/kelola/pelanggan/${k.Pelanggan.Uuid}`} className="text-brand underline">
                            {k.Pelanggan.Nama}
                        </Link>
                    ) : (
                        k.Pelanggan.Nama
                    )}
                </KeteranganGrosir>
                {k.Jenis === 'Kendaraan' ? (
                    <KeteranganGrosir label="KM terakhir">
                        {k.KmTerakhir === null ? '-' : `${k.KmTerakhir.toLocaleString('id-ID')} km`}
                    </KeteranganGrosir>
                ) : null}
                <KeteranganGrosir label="Warna">{k.Warna ?? '-'}</KeteranganGrosir>
                {k.Jenis === 'Kendaraan' ? (
                    <>
                        <KeteranganGrosir label="Nomor rangka">
                            <span className="font-mono">{k.NomorRangka ?? '-'}</span>
                        </KeteranganGrosir>
                        <KeteranganGrosir label="Nomor mesin">
                            <span className="font-mono">{k.NomorMesin ?? '-'}</span>
                        </KeteranganGrosir>
                    </>
                ) : null}
                {k.Catatan ? <KeteranganGrosir label="Catatan">{k.Catatan}</KeteranganGrosir> : null}
            </KartuKeteranganGrosir>
            <Panel
                judul="Riwayat servis"
                keterangan={`${String(k.JumlahServis)} kali servis tercatat (tanpa yang dibatalkan).`}
            >
                <TabelData
                    id="bengkel-riwayat-servis"
                    label={`Riwayat servis ${JudulUnit(k)}`}
                    kolom={BuatKolomPerintahKerja()}
                    sumber={{ mode: 'lokal', data: Riwayat }}
                    ambilIdBaris={(p) => p.Uuid}
                    alamatDetail={(p) => `${AlamatPerintahKerja}/${p.Uuid}`}
                    kosong={{ judul: `${KataUnit(k.Jenis)} ini belum pernah diservis di sini.` }}
                />
            </Panel>
            {ubah ? <DialogKendaraan kendaraan={k} pelanggan={null} saatTutup={() => AturUbah(false)} /> : null}
        </TataLetakAplikasi>
    );
}
