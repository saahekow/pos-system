<?php

final class SimpleReceiptPdf
{
    private array $pages=[];
    private string $content='';
    private float $width=595.28;
    private float $height=841.89;
    private ?array $jpeg=null;

    public function __construct(){ $this->newPage(); }
    public function y(float $top):float{return $this->height-$top;}
    public function pageHeight():float{return $this->height;}
    public function newPage():void{if($this->content!=='')$this->pages[]=$this->content;$this->content='';}
    private function clean(string $text):string{$text=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$text)?:$text;return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$text);}
    public function text(float $x,float $top,string $text,float $size=10,bool $bold=false,string $color='173b5f'):void{
        [$r,$g,$b]=array_map(static fn(string $part):float=>hexdec($part)/255,str_split($color,2));
        $this->content.=sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",$bold?'F2':'F1',$size,$r,$g,$b,$x,$this->y($top),$this->clean($text));
    }
    public function line(float $x1,float $top1,float $x2,float $top2,string $color='d9e3ec',float $weight=.7):void{
        [$r,$g,$b]=array_map(static fn(string $part):float=>hexdec($part)/255,str_split($color,2));
        $this->content.=sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",$r,$g,$b,$weight,$x1,$this->y($top1),$x2,$this->y($top2));
    }
    public function rect(float $x,float $top,float $w,float $h,string $fill):void{
        [$r,$g,$b]=array_map(static fn(string $part):float=>hexdec($part)/255,str_split($fill,2));
        $this->content.=sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",$r,$g,$b,$x,$this->y($top+$h),$w,$h);
    }
    public function jpeg(string $path,float $x,float $top,float $w,float $h):void{
        if(!is_file($path))return;$info=@getimagesize($path);$data=@file_get_contents($path);if(!$info||!$data||($info[2]??0)!==IMAGETYPE_JPEG)return;
        $this->jpeg=['data'=>$data,'width'=>(int)$info[0],'height'=>(int)$info[1]];
        $this->content.=sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n",$w,$h,$x,$this->y($top+$h));
    }
    public function output():string{
        if($this->content!==''){$this->pages[]=$this->content;$this->content='';}
        $objects=[];$objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
        $imageId=$this->jpeg?5:null;$pageIds=[];$contentIds=[];$next=$this->jpeg?6:5;
        foreach($this->pages as $_){$pageIds[]=$next++;$contentIds[]=$next++;}
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',array_map(static fn(int $id):string=>"$id 0 R",$pageIds)).'] /Count '.count($pageIds).' >>';
        $objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        if($this->jpeg)$objects[$imageId]='<< /Type /XObject /Subtype /Image /Width '.$this->jpeg['width'].' /Height '.$this->jpeg['height'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($this->jpeg['data'])." >>\nstream\n".$this->jpeg['data']."\nendstream";
        foreach($this->pages as $index=>$stream){$xObject=$imageId?' /XObject << /Im1 '.$imageId.' 0 R >>':'';$objects[$pageIds[$index]]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$this->width.' '.$this->height.'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>'.$xObject.' >> /Contents '.$contentIds[$index].' 0 R >>';$objects[$contentIds[$index]]='<< /Length '.strlen($stream).' >>' . "\nstream\n".$stream."endstream";}
        ksort($objects);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];
        foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$object\nendobj\n";}
        $xref=strlen($pdf);$count=max(array_keys($objects))+1;$pdf.="xref\n0 $count\n0000000000 65535 f \n";
        for($id=1;$id<$count;$id++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$id]??0);
        return $pdf."trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}
