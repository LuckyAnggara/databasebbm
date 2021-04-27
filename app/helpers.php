<?php
  
function showcik(){
    return 'lucky ganteng';
}

function keuangan_base_url(){
    return 'http://127.0.0.1:8080/api/jurnal/';
}

//  FUNGSI STANDARD
function metodePembayaran($text){
    $title = '';
    $value = 0;
    if($text == 'Lunas'){
        $title = $text;
        $value = 0;
    }else if($text == 'Kredit'){
        $title = $text;
        $value = 1;
    }else if($text == 'Cash On Delivery (COD)'){
        $title = $text;
        $value = 2;
    }
    return [
        'title'=> $title,
        'value' => $value
    ];
}

function caraPembayaran($text){
    $title = '';
    $value = 0;
    if($text == 'Tunai'){
        $title = $text;
        $value = 0;
    }else if($text == 'Transfer'){
        $title = $text;
        $value = 1;
    }
    return [
        'title'=> $title,
        'value' => $value
    ];
}  