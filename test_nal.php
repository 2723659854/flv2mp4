<?php
$data = file_get_contents('hls/output/360p/segment_1.ts');
echo "File size: " . strlen($data) . " bytes\n";

$pos = strpos($data, "\x00\x00\x00\x01");
if ($pos !== false) {
    echo "Found NAL start code at position: " . $pos . "\n";
    
    $nalData = substr($data, $pos + 4, 32);
    echo "First NAL header: " . bin2hex($nalData) . "\n";
    
    $nalType = (ord($nalData[0]) >> 1) & 0x3F;
    echo "NAL Type: " . $nalType . "\n";
    
    $nalSize = strlen($nalData);
    echo "First NAL size: " . $nalSize . "\n";
} else {
    echo "No NAL start code found!\n";
}

$pattern = "/\x00\x00\x00\x01/";
preg_match_all($pattern, $data, $matches, PREG_OFFSET_CAPTURE);
echo "Total NAL units found: " . count($matches[0]) . "\n";

foreach ($matches[0] as $m) {
    $pos = $m[1];
    $nalType = (ord($data[$pos + 4]) >> 1) & 0x3F;
    $types = [7 => 'SPS', 8 => 'PPS', 5 => 'IDR', 1 => 'P', 3 => 'AUD'];
    $typeName = $types[$nalType] ?? 'Unknown(' . $nalType . ')';
    echo "NAL at pos $pos: type=$typeName\n";
}
