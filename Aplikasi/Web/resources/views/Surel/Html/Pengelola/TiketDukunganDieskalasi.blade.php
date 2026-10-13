@extends('Surel.TataLetak')

@section('Judul', 'Eskalasi tiket '.$Nomor)
@section('Pratinjau', 'Tiket '.$Nomor.' dari '.$NamaTenant.' dieskalasi ke '.$Tingkat.'. '.$Alasan)

@section('Isi')
    <p style="margin:0 0 20px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo, ada tiket yang dieskalasi ke tim Anda dan perlu segera ditangani.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => [
        'Nomor' => $Nomor,
        'Judul' => $Judul,
        'Tenant' => $NamaTenant,
        'Dieskalasi ke' => $Tingkat,
        'Alasan' => $Alasan,
        'Batas respons pertama (SLA)' => $BatasSla,
    ]])

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Buka tiket di Platform Pengelola'])
@endsection
