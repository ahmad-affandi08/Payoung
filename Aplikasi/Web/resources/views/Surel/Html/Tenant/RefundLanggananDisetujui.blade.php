@extends('Surel.TataLetak')

@section('Judul', 'Refund Anda sudah disetujui')
@section('Pratinjau', 'Refund tagihan '.$NomorTagihan.' sebesar '.$Jumlah.' disetujui.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 20px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Refund untuk tagihan berikut sudah kami setujui. Tim keuangan mengembalikan dananya ke rekening Anda, dan layanan dari tagihan itu disesuaikan.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => array_filter([
        'Nomor tagihan' => $NomorTagihan,
        'Jumlah refund' => $Jumlah,
        'Catatan pengembalian' => $Catatan,
    ])])

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Lihat rincian langganan'])
@endsection
