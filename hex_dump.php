<?php
$file = 'test_encoder_output.264';
$data = file_get_contents($file);
echo "File size: " . strlen($data) . " bytes\n\n";

for ($i = 0; $i < min(100, strlen($data)); $i++) {
    printf("%02X ", ord($data[$i]));
    if (($i + 1) % 16 == 0) echo "\n";
}
echo "\n";

echo "\n--- NAL unit analysis ---\n";
$offset = 0;
while ($offset < strlen($data)) {
    if ($offset + 4 <= strlen($data) && 
        ord($data[$offset]) == 0x00 && 
        ord($data[$offset+1]) == 0x00 && 
        ord($data[$offset+2]) == 0x00 && 
        ord($data[$offset+3]) == 0x01) {
        
        $nalStart = $offset + 4;
        $nextStart = strpos($data, "\x00\x00\x00\x01", $nalStart);
        if ($nextStart === false) {
            $nalSize = strlen($data) - $nalStart;
        } else {
            $nalSize = $nextStart - $nalStart;
        }
        
        if ($nalStart < strlen($data)) {
            $nalType = ord($data[$nalStart]) & 0x1F;
            $refIdc = (ord($data[$nalStart]) >> 5) & 0x03;
            $typeName = ['SPS', 'PPS', 'SEI', 'IDR', 'Non-IDR', 'Data'][$nalType] ?? "Unknown($nalType)";
            echo "NAL at offset $nalStart: type=$typeName ($nalType), refIdc=$refIdc, size=$nalSize bytes\n";
        }
        $offset = $nalStart + $nalSize;
    } else {
        $offset++;
    }
}
