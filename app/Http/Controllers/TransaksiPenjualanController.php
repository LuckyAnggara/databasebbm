<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Barang;
use App\Models\Kontak;
use App\Models\TransaksiPenjualan;
use App\Models\DetailPenjualan;
use App\Models\KartuPersediaan;
use App\Models\User;



class TransaksiPenjualanController extends Controller
{
    public function index2(){
        $data = showcik();
        return response()->json($data, 200);

    }
    public function index($dd, $ddd){
        $output = [];
        $dateawal = date("Y-m-d 00:00:00", strtotime($dd));
        $dateakhir = date("Y-m-d 23:59:59", strtotime($ddd));
        $master = DB::table('master_penjualan')
        ->where('created_at','>',$dateawal)    
        ->where('created_at','<',$dateakhir)  
        ->where('deleted_at')    
        ->get();

        foreach ($master as $key => $value) {
        $invoice = [
            'diskon'=>$value->diskon,
            'grandTotal'=>$value->grand_total,
            'ongkir'=>$value->ongkir,
            'pajak'=>$value->pajak_keluaran,
            'total'=>$value->total,
        ];

        $user = $barang = User::find($value->user_id);

        $orders = DB::table('detail_penjualan')
        ->select('detail_penjualan.*', 'barang.nama as nama_barang')
        ->join('barang','detail_penjualan.kode_barang_id','=','barang.kode_barang')    
        ->where('master_penjualan_id','=',$value->id)    
        ->get();

        $pelanggan = DB::table('master_kontak')
        ->where('id','=',$value->kontak_id)
        ->first();

        $bank = DB::table('master_bank')
        ->where('id','=',$value->bank_id)
        ->first();

        $pembayaran = [
            'bank'=>$bank,
            'downPayment'=>$value->down_payment,
            'sisaPembayaran'=>$value->sisa_pembayaran,
            'jenisPembayaran' => caraPembayaran($value->cara_pembayaran),
            'kredit'=>$value->kredit,
            'statusPembayaran'=>metodePembayaran($value->metode_pembayaran),
            'tanggalJatuhTempo'=>$value->tanggal_jatuh_tempo,
        ];

        $data = [
            'id'=>$value->id,
            'nomorTransaksi'=>$value->nomor_transaksi,
            'tanggalTransaksi'=>$value->created_at,
            'invoice'=> $invoice,
            'orders'=>$orders,
            'pelanggan'=>$pelanggan,
            'pembayaran'=>$pembayaran,
            'user'=> $user

        ];

        $output[] = $data;
        }

        return response()->json($output, 200);
    }
 
    public function store(Request $request){
        $nomor_transaksi = $this->makeNomorTrx();

        if($request->pembayaran['statusPembayaran']['value'] == 2){
            $sisa_pembayaran = $request->invoice['grandTotal'];
        }else if($request->pembayaran['statusPembayaran']['value'] == 1){
            $sisa_pembayaran = (float)$request->invoice['grandTotal'] - (float)$request->pembayaran['downPayment'];
        }else{
            $sisa_pembayaran = 0;
        }

        $data = TransaksiPenjualan::create([
            'nomor_transaksi'=> $nomor_transaksi,
            'kontak_id' => $this->cekPelanggan($request->pelanggan),
            'total' => $request->invoice['total'],
            'diskon' => $request->invoice['diskon'],
            'ongkir' => $request->invoice['ongkir'],
            'pajak_keluaran' => $request->invoice['pajak'],
            'grand_total' => $request->invoice['grandTotal'],
            'metode_pembayaran' => $request->pembayaran['statusPembayaran']['title'],
            'kredit' => $request->pembayaran['kredit'],
            'down_payment' => $request->pembayaran['downPayment'],
            'sisa_pembayaran' => $sisa_pembayaran,
            'cara_pembayaran' => $request->pembayaran['jenisPembayaran']['title'],
            'bank_id' => $request->pembayaran['bank'] ? $request->pembayaran['bank']['value'] : null,
            'tanggal_jatuh_tempo' => $request->pembayaran['tanggalJatuhTempo'],
            'retur' => 2,
            'user_id' => 1,
            'sales_id' => $request->sales['value'] ? $request->sales['value'] : null,
            'catatan' => $request->catatan,
        ]);

        $id = $data->id;

        if($id){
            foreach ($request->orders as $key => $value) {
                $detail = DetailPenjualan::create([
                    'master_penjualan_id'=> $id,
                    'kode_barang_id' => $value['kode_barang'],
                    'jumlah' => $value['jumlah'],
                    'harga' => $value['harga'],
                    'diskon' => $value['diskon'],
                    'total' => ($value['jumlah'] * $value['harga']) - $value['diskon'],
                ]);
                
                $kredit = $this->kreditPersediaan($nomor_transaksi, $value, 'Penjualan Transaksi #'); // KREDIT PERSEDIAAN
            }
            $hpp = $this->hpp($request->orders); // CEK TOTAL HPP NYA
            $dd = $request->pembayaran['statusPembayaran']['value']; // CEK STATUS PEMBAYARANNYA KREDIT / LUNAS / COD

            $this->postJurnal(
                $nomor_transaksi,
                'PENJUALAN NOMOR INVOICE #'.$nomor_transaksi,
                $request->invoice['total'],
                $request->invoice['pajak'],
                $request->invoice['ongkir'],
                $request->invoice['diskon'],
                $dd,
                $request->pembayaran['downPayment'],
                $sisa_pembayaran,
                $hpp,
            ); // START POST JURNALNYA
        }else{
            return null;
        }
        return response()->json($data, 200);
    }

    public function kreditPersediaan($nomor_transaksi, $data, $catatan){
        $detail = KartuPersediaan::create([
            'nomor_transaksi'=> $nomor_transaksi,
            'master_barang_id' => $data['id_barang'],
            'kredit' => $data['jumlah'],
            'harga_jual' => $data['harga'],
            'debit' => 0,
            'harga_beli' => 0,
            'catatan' => $catatan.'#'.$nomor_transaksi,
        ]);
    }

    public function hpp($data){

        $hpp = 0;
        foreach ($data as $key => $value) {

            $barang = Barang::find($value['id_barang']);
            if($barang->jenis == 'FIFO'){
                $harga_perolehan = DB::table('detail_pembelian')
                ->select('harga')
                ->where('kode_barang_id', '=',$barang->kode_barang)
                ->orderBy('created_at', 'asc')
                ->first();
            }else{
                $harga_perolehan = DB::table('detail_pembelian')
                ->select(DB::raw('round(AVG(harga),0) as harga'),)
                ->where('kode_barang_id', '=',$barang->kode_barang)
                ->first();
            }
            if($harga_perolehan === null){
                $harga_perolehan->harga = 0;
            }
            $harga = $harga_perolehan->harga * $value['jumlah'];
            $hpp += $harga;
        }

        return  $hpp;
    }

    
    public function getDetailTransaksiByBarang($kode_barang){
        $master = DB::table('detail_penjualan')
        ->select('detail_penjualan.*', 'master_penjualan.nomor_transaksi as nomor_transaksi','master_penjualan.sisa_pembayaran','master_kontak.nama as nama_pelanggan')
        ->where('kode_barang_id','=',$kode_barang)    
        ->join('master_penjualan','detail_penjualan.master_penjualan_id','=','master_penjualan.id')    
        ->join('master_kontak','master_penjualan.kontak_id','=','master_kontak.id')    
        ->get();

        return response()->json($master, 200);
    }


    public function makeNomorTrx(){
        $data = TransaksiPenjualan::all();
        $output = collect($data)->last();
        $date = date("dmy");

        if($output){
            $dd = $output->nomor_transaksi;
            $str = explode('-', $dd);

            if($str[1] == $date){
                $last_prefix = $str[2]+ 1;
                return 'BBM-'.$date.'-'.$last_prefix;
            }

            return 'BBM-'.$date.'-'.'1';
           
        }
        return 'BBM-'.$date.'-'.'1';      
    }

    public function cekPelanggan($data){
        if($data['id'] == null || $data['id'] == '' ){
            $kontak = Kontak::create([
                'nama'=> $data['nama'],
                'tipe'=> 'PELANGGAN',
                'alamat'=> $data['alamat'],
                'telepon'=> $data['nomorTelepon'],
                'wic'=> 1,
            ]);
            return $kontak->id;
        }
        return $data['id'];
    }

    // JURNAL
    
    public function postJurnal($nomor_transaksi,$keterangan, $penjualan, $pajak = 0, $ongkir = 0, $diskon = 0, $metodePembayaran = 0, $dp=0, $sisa_pembayaran=0, $hpp = 0){
        $base_url = keuangan_base_url();
        $reqJurnal = Http::get($base_url.'reqnomorjurnal');
        $nomorJurnal = $reqJurnal->json();
        $kas = $penjualan + $pajak + $ongkir;
        if($metodePembayaran == 0){
            $kas = Http::post($base_url.'store/', [
                'reff'=>$nomor_transaksi,
                'nomor_jurnal'=>$nomorJurnal,
                'master_akun_id'=>'4', // KAS
                'nominal'=> $kas,
                'jenis'=>'DEBIT',
                'keterangan'=>'PENERIMAAN KAS '. $keterangan,
            ]);
            $response['kas'] = $kas->json();
        }else if ($metodePembayaran == 1){
            if($dp !== 0){
                $kas = Http::post($base_url.'store/', [
                    'reff'=>$nomor_transaksi,
                    'nomor_jurnal'=>$nomorJurnal,
                    'master_akun_id'=>'4', // KAS
                    'nominal'=>$dp,
                    'jenis'=>'DEBIT',
                    'keterangan'=>'PENERIMAAN KAS DOWN PAYMENT '. $keterangan,
                ]);
                $response['kas'] = $kas->json();
            }
            $piutang = Http::post($base_url.'store/', [
                'reff'=>$nomor_transaksi,
                'nomor_jurnal'=>$nomorJurnal,
                'master_akun_id'=>'5', // PIUTANG DAGANG
                'nominal'=>$sisa_pembayaran,
                'jenis'=>'DEBIT',
                'keterangan'=>'PIUTANG '. $keterangan,
            ]);
            $response['piutang'] = $piutang->json();
        }else if ($metodePembayaran == 2){
            $cod = Http::post($base_url.'store/', [
                'reff'=>$nomor_transaksi,
                'nomor_jurnal'=>$nomorJurnal,
                'master_akun_id'=>'5', // PIUTANG DAGANG
                'nominal'=>$kas,
                'jenis'=>'DEBIT',
                'keterangan'=>'PIUTANG COD '. $keterangan,
            ]);
            $response['cod'] = $cod->json();
        }
        
        $penjualan = Http::post($base_url.'store/', [
            'reff'=>$nomor_transaksi,
            'nomor_jurnal'=>$nomorJurnal,
            'master_akun_id'=>'32', // PENJUALAN
            'nominal'=>$penjualan,
            'jenis'=>'KREDIT',
            'keterangan'=>$keterangan,
        ]);
        $response['penjualan'] = $penjualan->json();
        
        $persediaan = Http::post($base_url.'store/', [
            'reff'=>$nomor_transaksi,
            'nomor_jurnal'=>$nomorJurnal,
            'master_akun_id'=>'6', // PENJUALAN
            'nominal'=>$hpp,
            'jenis'=>'KREDIT',
            'keterangan'=>$keterangan,
        ]);
        $response['persediaan'] = $persediaan->json();

        $hargapokokpenjualan = Http::post($base_url.'store/', [
            'reff'=>$nomor_transaksi,
            'nomor_jurnal'=>$nomorJurnal,
            'master_akun_id'=>'44', // PENJUALAN
            'nominal'=>$hpp,
            'jenis'=>'DEBIT',
            'keterangan'=>$keterangan,
        ]);
        $response['hargapokokpenjualan'] = $hargapokokpenjualan->json();
        if($pajak !== 0){
            $pajak = Http::post($base_url.'store/', [
                'reff'=>$nomor_transaksi,
                'nomor_jurnal'=>$nomorJurnal,
                'master_akun_id'=>'26', // PAJAK KELUARAN
                'nominal'=>$pajak,
                'jenis'=>'KREDIT',
                'keterangan'=>'PAJAK KELUARAN '. $keterangan,
            ]);
            $response['pajak'] = $pajak->json();
        }
        if($ongkir !== 0){
            $ongkir = Http::post($base_url.'store/', [
                'reff'=>$nomor_transaksi,
                'nomor_jurnal'=>$nomorJurnal,
                'master_akun_id'=>'33', // AKUN PENDAPATAN LAIN LAIN
                'nominal'=>$ongkir,
                'jenis'=>'KREDIT',
                'keterangan'=>'ONGKIR '. $keterangan,
            ]);
            $response['ongkir'] = $ongkir->json();
        }
        if($diskon !== 0){
            $diskon = Http::post($base_url.'store/', [
                'reff'=>$nomor_transaksi,
                'nomor_jurnal'=>$nomorJurnal,
                'master_akun_id'=>'35', // AKUN DISKON PENJUALAN
                'nominal'=>$diskon,
                'jenis'=>'DEBIT',
                'keterangan'=>'DISKON '. $keterangan,
            ]);
            $response['diskon'] = $diskon->json();
        }
        return response()->json($response, 200);
    }
}
